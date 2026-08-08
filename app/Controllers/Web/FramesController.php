<?php

namespace App\Controllers\Web;

use App\Models\FrameModel;
use App\Models\SourceObservationModel;
use App\Models\TaskItemModel;
use App\Models\TaskModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Debug UI page: "Frames" — list already-registered FITS frames, filter by object, and create
 * a task from a checked selection. Part of the temporary Web\* dashboard (see
 * DashboardController's docblock) — reads `frames` via the existing FrameModel and creates tasks
 * via TaskModel/TaskItemModel's existing public methods, the same ones
 * Api\V1\TasksController::create() uses, just without going through that HTTP endpoint.
 */
class FramesController extends Controller
{
    /**
     * Task types this page can create from a checked set of *already registered* frames.
     * `item_key` is the task_items column that gets populated (see TaskItemModel); `frame_column`
     * is which column of each selected `frames` row supplies that value — 'id' for
     * DETECT_ANOMALIES (task_items.frame_id references frames.id), 'filename' for ANALYZE and
     * PREVIEW_CATALOG_MATCH (task_items.filename is just the name — PREVIEW_CATALOG_MATCH never
     * resolves a frame_id at all, see CLAUDE.md's task_items scope note; re-submitting an
     * already-registered frame's filename to it here is a debug convenience, not its usual input).
     * GENERATE_CHARTS is deliberately not offered here — it scopes by source_id, not
     * frame_id/filename, so it doesn't fit a frame checklist at all.
     */
    private const CREATABLE_TYPES = [
        'DETECT_ANOMALIES'      => ['item_key' => 'frame_id', 'frame_column' => 'id'],
        'ANALYZE'               => ['item_key' => 'filename', 'frame_column' => 'filename'],
        'PREVIEW_CATALOG_MATCH' => ['item_key' => 'filename', 'frame_column' => 'filename'],
    ];

    public function index(): ResponseInterface
    {
        $object   = trim((string) ($this->request->getGet('object') ?? ''));
        $dateFrom = trim((string) ($this->request->getGet('date_from') ?? ''));
        $dateTo   = trim((string) ($this->request->getGet('date_to') ?? ''));
        $limit    = (int) ($this->request->getGet('limit') ?? 200);
        $limit    = $limit > 0 ? min($limit, 2000) : 200;

        $model = new FrameModel();

        if ($object !== '') {
            $model = $model->where('object', $object);
        }

        if ($dateFrom !== '') {
            $timestamp = strtotime($dateFrom);
            if ($timestamp !== false) {
                $model = $model->where('obs_time >=', date('Y-m-d H:i:s', $timestamp));
            }
        }

        if ($dateTo !== '') {
            $timestamp = strtotime($dateTo);
            if ($timestamp !== false) {
                $model = $model->where('obs_time <', date('Y-m-d H:i:s', $timestamp));
            }
        }

        $frames = $model->orderBy('obs_time', 'DESC')->findAll($limit);

        // "Stars" column: qc_star_count (raw pipeline detection count) vs. how many of a
        // frame's stars actually got recognized/persisted as source_observations rows — one
        // batched count query for the whole page rather than N+1 per row.
        $recognizedCounts = (new SourceObservationModel())
            ->countByFrameIds(array_column($frames, 'id'));

        foreach ($frames as &$frame) {
            $frame['recognized_star_count'] = $recognizedCounts[$frame['id']] ?? 0;
        }
        unset($frame);

        $objects = (new FrameModel())
            ->distinct()
            ->select('object')
            ->where('object IS NOT NULL')
            ->orderBy('object', 'ASC')
            ->findAll();

        return $this->response->setBody(view('web/frames_index', [
            'frames'         => $frames,
            'objects'        => array_column($objects, 'object'),
            'filterObject'   => $object,
            'filterDateFrom' => $dateFrom,
            'filterDateTo'   => $dateTo,
            'limit'          => $limit,
            'taskTypes'      => array_keys(self::CREATABLE_TYPES),
        ]));
    }

    /**
     * POST /ui/tasks — create a task from the frame checkboxes selected on GET /ui/frames.
     */
    public function createTask(): ResponseInterface
    {
        $type     = (string) ($this->request->getPost('type') ?? '');
        $frameIds = (array) ($this->request->getPost('frame_ids') ?? []);
        $scopeObj = trim((string) ($this->request->getPost('scope_object') ?? ''));

        if (! isset(self::CREATABLE_TYPES[$type])) {
            return redirect()->back()->with('error', 'Неизвестный или отсутствующий тип задачи.');
        }

        $frameIds = array_values(array_unique(array_filter(
            $frameIds,
            static fn ($v): bool => is_string($v) && $v !== ''
        )));

        if (count($frameIds) === 0) {
            return redirect()->back()->with('error', 'Выберите хотя бы один кадр (чекбокс в таблице).');
        }

        $frames = (new FrameModel())->whereIn('id', $frameIds)->findAll();

        if (count($frames) === 0) {
            return redirect()->back()->with('error', 'Выбранные кадры не найдены (устарели?).');
        }

        $itemKey     = self::CREATABLE_TYPES[$type]['item_key'];
        $frameColumn = self::CREATABLE_TYPES[$type]['frame_column'];

        $items = [];
        foreach ($frames as $frame) {
            $items[] = [$itemKey => $frame[$frameColumn]];
        }

        $taskModel = new TaskModel();
        $taskId    = $taskModel->insert([
            'type'         => $type,
            'status'       => 'PENDING',
            'scope_object' => $scopeObj !== '' ? $scopeObj : null,
            'total_items'  => count($items),
        ], true);

        if ($taskId === false) {
            return redirect()->back()->with('error', 'Не удалось создать задачу: ' . implode(', ', $taskModel->errors()));
        }

        (new TaskItemModel())->insertForTask($taskId, $items);

        return redirect()->to('/ui/tasks/' . $taskId)
            ->with('success', "Задача {$taskId} создана: {$type}, " . count($items) . ' элемент(ов).');
    }
}
