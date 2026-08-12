<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\FrameModel;
use App\Models\SourceChartModel;
use App\Models\TaskItemModel;
use App\Models\TaskModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The granular pipeline job queue's API surface.
 *
 * observatory-pipeline submits one task per stage (ANALYZE / DETECT_ANOMALIES /
 * GENERATE_CHARTS) instead of running all three inline per file, so any stage can be re-run
 * later for an explicit scope (an object, a date range, or exactly the frame/source ids a prior
 * stage produced) without re-running whatever came before it. RESTART is a signal task (no items)
 * — the worker marks it completed and exits so Docker restarts the container with fresh settings.
 * DELETE_FRAME is operator-initiated frame deletion — the pipeline only relocates the frame's file
 * (FITS_ARCHIVE -> FITS_REJECTED, never deleted); postItemsProgress() below performs the actual
 * DB-side cascade delete (FrameModel::deleteWithDependents()) once an item is reported DONE.
 */
class TasksController extends BaseApiController
{
    /**
     * POST /api/v1/tasks
     *
     * Create a task with its full, fixed item list.
     *
     * Body:
     * {
     *   "type": "ANALYZE",
     *   "scope": {"object": "M51", "date_from": "...", "date_to": "..."},   // optional, descriptive only
     *   "parent_task_id": "...",                                             // optional, for re-runs
     *   "items": [
     *     {"filename": "..."} | {"frame_id": "..."} | {"source_id": "..."}
     *   ]
     * }
     */
    public function create(): ResponseInterface
    {
        $body = $this->request->getJSON(true);

        if (! is_array($body)) {
            return $this->respondError(400, 'Request body must be valid JSON');
        }

        $type = $body['type'] ?? null;

        if (! is_string($type) || ! TaskModel::isValidType($type)) {
            return $this->respondError(400, 'Invalid or missing type', ['allowed_types' => TaskModel::TYPES]);
        }

        // RESTART is a signal task — no items needed (the task itself IS the action).
        $isSignalTask = ($type === 'RESTART');

        $items = $body['items'] ?? [];

        if (! $isSignalTask) {
            if (! is_array($items) || count($items) === 0) {
                return $this->respondError(400, 'Missing required field: items (must be a non-empty array)');
            }

            foreach ($items as $i => $item) {
                if (! is_array($item) || (
                    ! isset($item['filename']) && ! isset($item['frame_id']) && ! isset($item['source_id'])
                )) {
                    return $this->respondError(400, "Invalid item at index {$i}: must have filename, frame_id, or source_id");
                }
            }
        } else {
            // Signal tasks ignore any items the caller may have passed.
            $items = [];
        }

        $scope = $body['scope'] ?? [];

        if (! is_array($scope)) {
            return $this->respondError(400, 'Invalid field: scope must be an object');
        }

        $scopeDateFrom = null;
        $scopeDateTo   = null;

        if (isset($scope['date_from']) && $scope['date_from'] !== '') {
            $timestamp = strtotime($scope['date_from']);
            if ($timestamp === false) {
                return $this->respondError(400, 'Invalid field: scope.date_from must be a valid ISO 8601 datetime');
            }
            $scopeDateFrom = date('Y-m-d H:i:s', $timestamp);
        }

        if (isset($scope['date_to']) && $scope['date_to'] !== '') {
            $timestamp = strtotime($scope['date_to']);
            if ($timestamp === false) {
                return $this->respondError(400, 'Invalid field: scope.date_to must be a valid ISO 8601 datetime');
            }
            $scopeDateTo = date('Y-m-d H:i:s', $timestamp);
        }

        $parentTaskId = $body['parent_task_id'] ?? null;

        if ($parentTaskId !== null && (new TaskModel())->find($parentTaskId) === null) {
            return $this->respondError(400, 'parent_task_id does not refer to an existing task');
        }

        $taskModel = new TaskModel();

        $taskId = $taskModel->insert([
            'type'            => $type,
            'status'          => 'PENDING',
            'scope_object'    => $scope['object'] ?? null,
            'scope_date_from' => $scopeDateFrom,
            'scope_date_to'   => $scopeDateTo,
            'total_items'     => count($items),
            'parent_task_id'  => $parentTaskId,
        ], true);

        if ($taskId === false) {
            log_message('error', 'TasksController::create — insert failed: ' . implode(', ', $taskModel->errors()));

            return $this->respondError(500, 'Failed to create task');
        }

        if (! empty($items)) {
            (new TaskItemModel())->insertForTask($taskId, $items);
        }

        return $this->respondCreated([
            'id'          => (string) $taskId,
            'type'        => $type,
            'status'      => 'PENDING',
            'total_items' => count($items),
            'message'     => 'Task created successfully',
        ]);
    }

    /**
     * GET /api/v1/tasks
     *
     * List tasks. Filters (all optional): status, type, object.
     *
     * `order` (optional, `asc`|`desc`, default `desc`) controls sort by `created_at`. Default
     * `desc` (most recent first) suits a human/dashboard view; a FIFO worker polling for
     * `status=PENDING` work should pass `order=asc` to claim the oldest queued task first rather
     * than repeatedly picking up whatever was just submitted.
     */
    public function index(): ResponseInterface
    {
        $model = new TaskModel();

        $status = $this->request->getGet('status');
        $type   = $this->request->getGet('type');
        $object = $this->request->getGet('object');
        $order  = $this->request->getGet('order');

        if ($status !== null && $status !== '') {
            $model = $model->where('status', $status);
        }
        if ($type !== null && $type !== '') {
            $model = $model->where('type', $type);
        }
        if ($object !== null && $object !== '') {
            $model = $model->where('scope_object', $object);
        }

        $sortDir = (is_string($order) && strtolower($order) === 'asc') ? 'ASC' : 'DESC';

        $limit = (int) ($this->request->getGet('limit') ?? 50);
        $limit = $limit > 0 ? min($limit, 500) : 50;

        $tasks = $model->orderBy('created_at', $sortDir)->findAll($limit);

        return $this->respondOk(['data' => array_map([$this, 'formatTask'], $tasks)]);
    }

    /**
     * GET /api/v1/tasks/{id}
     *
     * Task detail including its full item list (each item's current status/error).
     */
    public function show(string $id): ResponseInterface
    {
        $task = (new TaskModel())->find($id);

        if ($task === null) {
            return $this->respondError(404, 'Task not found', ['task_id' => $id]);
        }

        $items = (new TaskItemModel())->getForTask($id);

        return $this->respondOk([
            'task'  => $this->formatTask($task),
            'items' => array_map(static function (array $item): array {
                $payload = null;
                if ($item['payload'] !== null && $item['payload'] !== '') {
                    $decoded = json_decode($item['payload'], true);
                    $payload = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
                }

                return [
                    'id'           => $item['id'],
                    'seq'          => (int) $item['seq'],
                    'filename'     => $item['filename'],
                    'frame_id'     => $item['frame_id'],
                    'source_id'    => $item['source_id'],
                    'payload'      => $payload,
                    'status'       => $item['status'],
                    'error'        => $item['error'],
                    'processed_at' => $item['processed_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($item['processed_at'])) : null,
                ];
            }, $items),
        ]);
    }

    /**
     * PATCH /api/v1/tasks/{id}
     *
     * Update a task's own status directly — e.g. the pipeline flips PENDING -> RUNNING when its
     * worker picks the task up, or an operator sets CANCELLED. Reaching COMPLETED is normally
     * automatic (see TaskModel::bumpProgress(), driven by postItemsProgress() below) — this
     * endpoint can also set it directly for a task with zero items, or to force a state.
     */
    public function update(string $id): ResponseInterface
    {
        $model = new TaskModel();
        $task  = $model->find($id);

        if ($task === null) {
            return $this->respondError(404, 'Task not found', ['task_id' => $id]);
        }

        $body = $this->request->getJSON(true);

        if (! is_array($body)) {
            return $this->respondError(400, 'Request body must be valid JSON');
        }

        $status = $body['status'] ?? null;

        if (! is_string($status) || ! TaskModel::isValidStatus($status)) {
            return $this->respondError(400, 'Invalid or missing status', ['allowed_statuses' => TaskModel::STATUSES]);
        }

        $data = ['status' => $status];

        if ($status === 'RUNNING' && $task['started_at'] === null) {
            $data['started_at'] = date('Y-m-d H:i:s');
        }

        if (in_array($status, TaskModel::TERMINAL_STATUSES, true)) {
            $data['finished_at'] = date('Y-m-d H:i:s');
        }

        if (isset($body['error'])) {
            $data['error'] = (string) $body['error'];
        }

        $model->update($id, $data);

        return $this->respondOk(['task' => $this->formatTask($model->find($id))]);
    }

    /**
     * POST /api/v1/tasks/{id}/items/progress
     *
     * Report the outcome of one or more items in a single call. The pipeline can call this after
     * every individual item for maximum progress granularity, or batch many at once to cut
     * request count — the endpoint doesn't care which; that trade-off lives entirely on the
     * pipeline side. Updates each item row and the parent task's aggregate counters, and
     * auto-completes the task once every item has resolved (TaskModel::bumpProgress()).
     *
     * Body: {"items": [{"item_id": "...", "status": "DONE"|"FAILED", "frame_id": "...", "error": "...", "payload": {...}}]}
     * `frame_id` is only meaningful for an ANALYZE task's item, once POST /frames has resolved
     * one for it — DETECT_ANOMALIES/GENERATE_CHARTS items already carry their frame_id/source_id
     * from task creation and don't need to report one back. `payload` here overwrites the item's
     * stored payload with a RESULT (e.g. PREVIEW_CATALOG_MATCH's {"output_path", "matched",
     * "total"}) — the same column TasksController::create() uses for input metadata, just written
     * at completion time instead of creation time.
     */
    public function postItemsProgress(string $id): ResponseInterface
    {
        $taskModel = new TaskModel();
        $task      = $taskModel->find($id);

        if ($task === null) {
            return $this->respondError(404, 'Task not found', ['task_id' => $id]);
        }

        $body = $this->request->getJSON(true);

        if (! is_array($body) || ! isset($body['items']) || ! is_array($body['items'])) {
            return $this->respondError(400, 'Missing required field: items (must be an array)');
        }

        $itemModel     = new TaskItemModel();
        $completedIncr = 0;
        $failedIncr    = 0;
        $results       = [];

        foreach ($body['items'] as $entry) {
            $itemId = is_array($entry) ? ($entry['item_id'] ?? null) : null;
            $status = is_array($entry) ? ($entry['status'] ?? null) : null;

            if (! is_string($itemId) || ! in_array($status, ['DONE', 'FAILED'], true)) {
                $results[] = ['item_id' => $itemId, 'status' => 'error', 'error' => 'Invalid item_id or status'];
                continue;
            }

            // Scoped by task_id too, not just item id — an item_id belonging to a different task
            // must not be resolvable (or countable) from here.
            $item = $itemModel->where('task_id', $id)->find($itemId);

            if ($item === null) {
                $results[] = ['item_id' => $itemId, 'status' => 'error', 'error' => 'Item not found on this task'];
                continue;
            }

            // A previously-resolved item being re-reported (retry, duplicate delivery) must not
            // double-count the parent task's completed/failed totals.
            if ($item['status'] !== 'PENDING') {
                $results[] = ['item_id' => $itemId, 'status' => 'ok', 'note' => 'Already resolved, counters unchanged'];
                continue;
            }

            $update = ['status' => $status, 'processed_at' => date('Y-m-d H:i:s')];

            if (isset($entry['frame_id'])) {
                $update['frame_id'] = (string) $entry['frame_id'];
            }
            if (isset($entry['error'])) {
                $update['error'] = (string) $entry['error'];
            }
            // `payload` here is a RESULT being written back, not the input payload set at task
            // creation (e.g. PREVIEW_CATALOG_MATCH reporting {"matched", "total", "quality_flag"}
            // once a file's chart has been uploaded via POST .../items/{item_id}/chart below) —
            // same column, just read at creation time for one task type and written at completion
            // time for another. Overwrites whatever the item carried before, same as every other
            // field here.
            if (array_key_exists('payload', $entry) && $entry['payload'] !== null) {
                $update['payload'] = json_encode($entry['payload']);
            }

            $itemModel->update($itemId, $update);

            // DELETE_FRAME's own DB-side cascade delete runs here, the moment the pipeline reports
            // the file successfully relocated to FITS_REJECTED (status DONE) — see
            // FrameModel::deleteWithDependents() and observatory-pipeline's
            // worker.py::_run_delete_frame_task(). A failure here is logged but does not turn this
            // item's own progress report into an error: the file move itself already succeeded,
            // which is what DONE is reporting; only the follow-up DB cleanup failed.
            if ($task['type'] === 'DELETE_FRAME' && $status === 'DONE' && $item['frame_id'] !== null) {
                try {
                    (new FrameModel())->deleteWithDependents($item['frame_id']);
                } catch (\Throwable $e) {
                    log_message('error', 'DELETE_FRAME cascade failed for frame_id=' . $item['frame_id'] . ': ' . $e->getMessage());
                    $results[] = ['item_id' => $itemId, 'status' => 'ok', 'note' => 'File relocated, but DB cleanup failed: ' . $e->getMessage()];
                    $status === 'DONE' ? $completedIncr++ : $failedIncr++;
                    continue;
                }
            }

            $status === 'DONE' ? $completedIncr++ : $failedIncr++;

            $results[] = ['item_id' => $itemId, 'status' => 'ok'];
        }

        if ($completedIncr > 0 || $failedIncr > 0) {
            $taskModel->bumpProgress($id, $completedIncr, $failedIncr);
        }

        return $this->respondOk([
            'results' => $results,
            'task'    => $this->formatTask($taskModel->find($id)),
        ]);
    }

    /**
     * POST /api/v1/tasks/{task_id}/items/{item_id}/chart?style=catalog_preview&frame_count=1
     *
     * Store the diagnostic chart PNG for a PREVIEW_CATALOG_MATCH task item, fully replacing any
     * previous one — the task_item_id-keyed counterpart of
     * SourcesController::uploadChart()/chart(), for a chart with no source at all (see
     * SourceChartModel's docblock). Same raw-PNG-body shape as that endpoint, for the same reason:
     * the request body is entirely consumed by the image.
     */
    public function uploadItemChart(string $taskId, string $itemId): ResponseInterface
    {
        if (! $this->isValidId($itemId)) {
            return $this->respondError(400, 'Invalid item id');
        }

        $item = (new TaskItemModel())->where('task_id', $taskId)->find($itemId);

        if ($item === null) {
            return $this->respondError(404, 'Task item not found', ['task_id' => $taskId, 'item_id' => $itemId]);
        }

        $style      = $this->request->getGet('style');
        $frameCount = $this->request->getGet('frame_count');

        if (! in_array($style, SourceChartModel::ALLOWED_STYLES, true)) {
            return $this->respondError(400, 'Invalid or missing query parameter: style must be one of '
                . implode(', ', SourceChartModel::ALLOWED_STYLES));
        }

        if ($frameCount === null || ! is_numeric($frameCount) || (int) $frameCount < 1) {
            return $this->respondError(400, 'Invalid or missing query parameter: frame_count must be a positive integer');
        }

        $body = $this->request->getBody();

        if ($body === null || $body === '') {
            return $this->respondError(400, 'Request body must contain PNG image bytes');
        }

        if (substr($body, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return $this->respondError(400, 'Request body is not a valid PNG image');
        }

        $dir = WRITEPATH . 'uploads/charts';
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return $this->respondError(500, 'Failed to create chart storage directory');
        }

        if (file_put_contents($dir . '/' . $itemId . '.png', $body) === false) {
            return $this->respondError(500, 'Failed to store chart image');
        }

        $chartModel = new SourceChartModel();
        $chart      = $chartModel->upsertForTaskItem($itemId, $style, (int) $frameCount);

        return $this->respondOk([
            'task_item_id' => $itemId,
            'style'        => $chart['style'],
            'frame_count'  => (int) $chart['frame_count'],
            'updated_at'   => $chart['updated_at']
                ? gmdate('Y-m-d\TH:i:s\Z', strtotime($chart['updated_at']))
                : null,
        ]);
    }

    /**
     * GET /api/v1/tasks/{task_id}/items/{item_id}/chart.png
     *
     * Serve the stored diagnostic chart PNG for a PREVIEW_CATALOG_MATCH task item as raw image
     * bytes — the task_item_id-keyed counterpart of SourcesController::chart().
     */
    public function itemChart(string $taskId, string $itemId): ResponseInterface
    {
        if (! $this->isValidId($itemId)) {
            return $this->respondError(400, 'Invalid item id');
        }

        $path = WRITEPATH . 'uploads/charts/' . $itemId . '.png';

        if (! is_file($path)) {
            return $this->respondError(404, 'No chart available for this task item', [
                'task_id' => $taskId, 'item_id' => $itemId,
            ]);
        }

        return $this->response
            ->setStatusCode(200)
            ->setContentType('image/png')
            ->setBody(file_get_contents($path));
    }

    /**
     * Guard against path traversal via the {item_id} route segment before it is ever concatenated
     * into a filesystem path — same whitelist as SourcesController::isValidSourceId(), since both
     * id spaces come from the same BaseModel::generateId() (uniqid('', true): hex digits + '.').
     */
    private function isValidId(string $id): bool
    {
        return preg_match('/^[a-zA-Z0-9.]{1,64}$/', $id) === 1;
    }

    private function formatTask(array $task): array
    {
        return [
            'id'              => $task['id'],
            'type'            => $task['type'],
            'status'          => $task['status'],
            'scope_object'    => $task['scope_object'],
            'scope_date_from' => $task['scope_date_from'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($task['scope_date_from'])) : null,
            'scope_date_to'   => $task['scope_date_to']   ? gmdate('Y-m-d\TH:i:s\Z', strtotime($task['scope_date_to']))   : null,
            'total_items'     => (int) $task['total_items'],
            'completed_items' => (int) $task['completed_items'],
            'failed_items'    => (int) $task['failed_items'],
            'parent_task_id'  => $task['parent_task_id'],
            'error'           => $task['error'],
            'created_at'      => $task['created_at']  ? gmdate('Y-m-d\TH:i:s\Z', strtotime($task['created_at']))  : null,
            'started_at'      => $task['started_at']   ? gmdate('Y-m-d\TH:i:s\Z', strtotime($task['started_at']))  : null,
            'finished_at'     => $task['finished_at']  ? gmdate('Y-m-d\TH:i:s\Z', strtotime($task['finished_at'])) : null,
        ];
    }
}
