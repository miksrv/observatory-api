<?php

namespace App\Models;

/**
 * Model for the `task_items` table — one row per unit of work inside a `tasks` scope.
 *
 * Exactly one of `filename` / `frame_id` / `source_id` is meaningful per row, depending on the
 * parent task's `type` (see the CreateTasksTable migration docblock for the mapping). Normalized
 * into its own table rather than a filename list on `tasks` itself so a submission of hundreds or
 * thousands of items stays independently retryable and queryable per item.
 */
class TaskItemModel extends BaseModel
{
    protected $table      = 'task_items';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    public const STATUSES = ['PENDING', 'DONE', 'FAILED'];

    protected $allowedFields = [
        'id',
        'task_id',
        'seq',
        'filename',
        'frame_id',
        'source_id',
        'payload',
        'status',
        'error',
        'processed_at',
    ];

    /**
     * Bulk-insert every item of a newly created task in one query, numbering them by their
     * position in the submitted array so the task's item order is stable and retrievable.
     *
     * @param array $items Each entry: ['filename' => ?, 'frame_id' => ?, 'source_id' => ?, 'payload' => ? (any JSON-encodable value)]
     */
    public function insertForTask(string $taskId, array $items): void
    {
        if (empty($items)) {
            return;
        }

        $rows = [];

        foreach (array_values($items) as $seq => $item) {
            $rows[] = [
                'task_id'   => $taskId,
                'seq'       => $seq,
                'filename'  => $item['filename']  ?? null,
                'frame_id'  => $item['frame_id']  ?? null,
                'source_id' => $item['source_id'] ?? null,
                'payload'   => array_key_exists('payload', $item) && $item['payload'] !== null
                    ? json_encode($item['payload'])
                    : null,
                'status'    => 'PENDING',
            ];
        }

        $this->insertBatch($rows);
    }

    public function getForTask(string $taskId): array
    {
        return $this->where('task_id', $taskId)
            ->orderBy('seq', 'ASC')
            ->findAll();
    }
}
