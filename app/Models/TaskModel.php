<?php

namespace App\Models;

/**
 * Model for the `tasks` table — the granular pipeline job queue.
 *
 * A task is one stage's unit of work (ANALYZE / DETECT_ANOMALIES / GENERATE_CHARTS /
 * PREVIEW_CATALOG_MATCH / DELETE_FRAME) over an explicit, itemized scope — see TaskItemModel.
 * DELETE_FRAME is operator-initiated frame deletion: the pipeline moves the frame's file from
 * FITS_ARCHIVE to FITS_REJECTED (never deleting it), and TasksController::postItemsProgress()
 * performs the actual DB-side cascade delete once an item is reported DONE. RESTART is a
 * signal task with no items — the pipeline worker marks it completed and exits so Docker restarts
 * the container with fresh remote settings. This is what observatory-pipeline's worker (and any
 * other consumer) polls to know what's active and how far along it is.
 */
class TaskModel extends BaseModel
{
    protected $table      = 'tasks';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    public const TYPES = ['ANALYZE', 'DETECT_ANOMALIES', 'GENERATE_CHARTS', 'PREVIEW_CATALOG_MATCH', 'DELETE_FRAME', 'RESTART'];

    public const STATUSES = ['PENDING', 'RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED'];

    /**
     * Statuses that mean "nothing more will change on this task" — used to decide whether an
     * item-progress report is still allowed to move it toward COMPLETED.
     */
    public const TERMINAL_STATUSES = ['COMPLETED', 'FAILED', 'CANCELLED'];

    protected $allowedFields = [
        'id',
        'type',
        'status',
        'pipeline_id',
        'claimed_by',
        'scope_object',
        'scope_date_from',
        'scope_date_to',
        'total_items',
        'completed_items',
        'failed_items',
        'parent_task_id',
        'error',
        'started_at',
        'finished_at',
    ];

    /**
     * Create one task with its items in one go — the Web UI's path (Api\V1\TasksController::create()
     * validates a request body first and inserts the same way).
     *
     * `$pipelineId` targets the task: only that pipeline may claim it (see
     * TasksController::update()). NULL = any pipeline, which is right only for work that needs
     * no local FITS file.
     *
     * @return string|false The new task id, or false if the insert failed.
     */
    public function createWithItems(string $type, array $items, ?string $pipelineId = null, ?string $scopeObject = null)
    {
        return $this->insertWithItems([
            'type'         => $type,
            'status'       => 'PENDING',
            'pipeline_id'  => $pipelineId,
            'scope_object' => $scopeObject,
            'total_items'  => count($items),
        ], $items);
    }

    /**
     * Insert a task row and its items in ONE transaction. The task is PENDING from its first
     * row, so without it a worker could claim a large task (thousands of items, inserted in
     * batches) while only part of its items existed yet. Any failure rolls everything back.
     *
     * @param array<string, mixed> $task  The `tasks` row
     * @param list<array>          $items See TaskItemModel::insertForTask()
     *
     * @return string|false The new task id, or false if anything failed.
     */
    public function insertWithItems(array $task, array $items)
    {
        $db = $this->db;
        $db->transException(true)->resetTransStatus();

        try {
            $db->transStart();

            $taskId = $this->insert($task, true);

            if ($taskId === false) {
                $db->transRollback();

                return false;
            }

            if ($items !== []) {
                (new TaskItemModel())->insertForTask((string) $taskId, $items);
            }

            if (! $db->transComplete()) {
                return false;
            }

            return (string) $taskId;
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            // The failing query already rolled the transaction back.
            log_message('error', 'TaskModel::insertWithItems — ' . $e->getMessage());

            return false;
        } finally {
            $db->transException(false);
        }
    }

    /**
     * Split one operator selection into one task per owning pipeline.
     *
     * Frames (and the sources seen on them) live on whichever pipeline registered them, so a
     * selection that spans two observatories has to become two tasks, each claimable only by the
     * pipeline that actually holds the files.
     *
     * @param array<string, list<array>> $itemsByPipeline Items keyed by pipeline id; '' = no
     *                                                    known owner (untargeted task).
     *
     * @return list<array{id: string, pipeline_id: ?string, items: int}>|false False if any insert
     *                                                                        failed.
     */
    public function createPerPipeline(string $type, array $itemsByPipeline, ?string $scopeObject = null)
    {
        ksort($itemsByPipeline, SORT_STRING);

        $created = [];

        foreach ($itemsByPipeline as $pipelineId => $items) {
            $pipelineId = $pipelineId === '' ? null : (string) $pipelineId;
            $taskId     = $this->createWithItems($type, $items, $pipelineId, $scopeObject);

            if ($taskId === false) {
                return false;
            }

            $created[] = ['id' => (string) $taskId, 'pipeline_id' => $pipelineId, 'items' => count($items)];
        }

        return $created;
    }

    /**
     * Queue a RESTART for one pipeline (or for whichever claims it first, when `$pipelineId` is
     * null), cancelling that target's older still-PENDING RESTARTs first: they ask for the same
     * thing, and a pipeline that has been offline for a while would otherwise restart once per
     * settings save it missed.
     *
     * @return string|false The new task id.
     */
    public function queueRestart(?string $pipelineId)
    {
        $superseded = $this->db->table($this->table)
            ->where('type', 'RESTART')
            ->where('status', 'PENDING');
        $superseded = $pipelineId === null
            ? $superseded->where('pipeline_id IS NULL')
            : $superseded->where('pipeline_id', $pipelineId);
        $superseded->update([
            'status'      => 'CANCELLED',
            'error'       => 'Superseded by a newer RESTART',
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->createWithItems('RESTART', [], $pipelineId);
    }

    public static function isValidType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    /**
     * Atomically bump the completed/failed counters by the given deltas, then flip status to
     * COMPLETED once every item has resolved (completed + failed >= total). Runs the counter
     * bump as a single UPDATE ... SET x = x + ? so concurrent item-progress reports for the same
     * task never race each other — two chunks of the same task can safely report in parallel.
     *
     * Deliberately does nothing if the task is already in a terminal state (COMPLETED/FAILED/
     * CANCELLED) — e.g. an operator cancelled the task while a late progress report for it was
     * still in flight; the report is harmless to skip.
     */
    public function bumpProgress(string $taskId, int $completedDelta, int $failedDelta): void
    {
        $task = $this->find($taskId);

        if ($task === null || in_array($task['status'], self::TERMINAL_STATUSES, true)) {
            return;
        }

        if ($completedDelta > 0) {
            $this->db->table($this->table)
                ->where('id', $taskId)
                ->set('completed_items', "completed_items + {$completedDelta}", false)
                ->update();
        }

        if ($failedDelta > 0) {
            $this->db->table($this->table)
                ->where('id', $taskId)
                ->set('failed_items', "failed_items + {$failedDelta}", false)
                ->update();
        }

        $task = $this->find($taskId);

        if (
            $task !== null
            && ((int) $task['completed_items'] + (int) $task['failed_items']) >= (int) $task['total_items']
        ) {
            $this->update($taskId, [
                'status'      => 'COMPLETED',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
