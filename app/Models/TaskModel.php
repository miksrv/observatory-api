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
