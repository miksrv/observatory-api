<?php

namespace App\Controllers\Web;

use App\Libraries\UiListQuery;
use App\Models\FrameModel;
use App\Models\PipelineModel;
use App\Models\SourceObservationModel;
use App\Models\TaskModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Web UI page: "Frames" — list already-registered FITS frames, filter by object, and create
 * a task from a checked selection. Part of the Web\* console (see
 * DashboardController's docblock) — reads `frames` via the existing FrameModel and creates tasks
 * via TaskModel/TaskItemModel's existing public methods, the same ones
 * Api\V1\TasksController::create() uses, just without going through that HTTP endpoint.
 */
class FramesController extends BaseWebController
{
    use RedirectsToCreatedTasks;

    /** `pipeline` filter value selecting frames with no recorded owner. */
    private const PIPELINE_NONE = '__none__';

    /**
     * Task types this page can create from a checked set of *already registered* frames.
     * `item_key` is the task_items column that gets populated (see TaskItemModel); `frame_column`
     * is which column of each selected `frames` row supplies that value — 'id' for
     * DETECT_ANOMALIES (task_items.frame_id references frames.id), 'filename' for ANALYZE and
     * PREVIEW_CATALOG_MATCH (task_items.filename is just the name — PREVIEW_CATALOG_MATCH never
     * resolves a frame_id at all, see CLAUDE.md's task_items scope note; re-submitting an
     * already-registered frame's filename to it here is a debug convenience, not its usual input).
     * DELETE_FRAME uses 'frame_id' like DETECT_ANOMALIES — the pipeline resolves filename/object
     * itself via GET /frames/{id} (see observatory-pipeline's worker.py::_run_delete_frame_task()),
     * moves the file to FITS_REJECTED, and TasksController::postItemsProgress() performs the
     * actual DB-side cascade delete once the item is reported DONE.
     * Every type here needs the frame's FITS file, so createTask() targets each task at the
     * pipeline that registered the frame (frames.pipeline_id) — one task per owning pipeline.
     * GENERATE_CHARTS is deliberately not offered here — it scopes by source_id, not
     * frame_id/filename, so it doesn't fit a frame checklist at all.
     */
    private const CREATABLE_TYPES = [
        'DETECT_ANOMALIES'      => ['item_key' => 'frame_id', 'frame_column' => 'id'],
        'ANALYZE'               => ['item_key' => 'filename', 'frame_column' => 'filename'],
        'PREVIEW_CATALOG_MATCH' => ['item_key' => 'filename', 'frame_column' => 'filename'],
        'DELETE_FRAME'          => ['item_key' => 'frame_id', 'frame_column' => 'id'],
    ];

    /** `qc` filter value selecting every frame whose quality_flag is set and isn't OK. */
    private const QC_NOT_OK = 'not_ok';

    /**
     * Most frames one "select every frame matching the filter" task may carry — a forgotten
     * filter must not queue the whole archive by accident.
     */
    private const ALL_MATCHING_MAX = 5000;

    public function index(): ResponseInterface
    {
        $filters = $this->readFilters(fn (string $name): mixed => $this->request->getGet($name));

        $list = new UiListQuery(
            [
                'filename'   => 'filename',
                'object'     => 'object',
                'obs_time'   => 'obs_time',
                'filter'     => 'filter',
                'exptime'    => 'exptime',
                'fov'        => 'fov_deg',
                'qc'         => 'quality_flag',
                'stars'      => 'qc_star_count',
                'fwhm'       => 'qc_fwhm_median',
                'snr'        => 'qc_snr_median',
                'background' => 'qc_sky_background',
            ],
            'obs_time',
            'desc',
            ['obs_time', 'exptime', 'stars', 'snr'],
        );

        $pipelineNames = (new PipelineModel())->getAllNames();
        $model         = $this->applyFilters(new FrameModel(), $filters, $pipelineNames);

        $list->paginate($model->countAllResults(false));
        $frames = $model->orderBy($list->orderBy(), '', false)->findAll($list->perPage, $list->offset());

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

        $qcFlags = (new FrameModel())
            ->distinct()
            ->select('quality_flag')
            ->where('quality_flag IS NOT NULL')
            ->where('quality_flag <>', 'OK')
            ->orderBy('quality_flag', 'ASC')
            ->findAll();

        return $this->response->setBody(view('web/frames_index', [
            'frames'         => $frames,
            'objects'        => array_column($objects, 'object'),
            'pipelines'      => $pipelineNames,
            'pipelineNone'   => self::PIPELINE_NONE,
            'qcFlags'        => array_column($qcFlags, 'quality_flag'),
            'qcNotOk'        => self::QC_NOT_OK,
            'filterPipeline' => $filters['pipeline'],
            'filterObject'   => $filters['object'],
            'filterDateFrom' => $filters['date_from'],
            'filterDateTo'   => $filters['date_to'],
            'filterQc'       => $filters['qc'],
            'list'           => $list,
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

        if ($this->request->getPost('all_matching') === '1') {
            // "Every frame matching the filter": the checked ids are only the visible page — the
            // selection is the POSTed filter instead, re-run here over the whole table.
            $filters = $this->readFilters(fn (string $name): mixed => $this->request->getPost($name));
            $model   = $this->applyFilters(new FrameModel(), $filters, (new PipelineModel())->getAllNames());
            $total   = $model->countAllResults(false);

            if ($total === 0) {
                return redirect()->back()->with('error', 'По фильтру не найдено ни одного кадра.');
            }

            if ($total > self::ALL_MATCHING_MAX) {
                return redirect()->back()->with('error', sprintf(
                    'По фильтру %d кадров — больше %d за одну задачу. Сузьте фильтр.',
                    $total,
                    self::ALL_MATCHING_MAX,
                ));
            }

            $frames = $model->select('id, filename, pipeline_id')->orderBy('obs_time', 'ASC')->findAll();
        } else {
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
        }

        $itemKey     = self::CREATABLE_TYPES[$type]['item_key'];
        $frameColumn = self::CREATABLE_TYPES[$type]['frame_column'];

        $itemsByPipeline = [];
        foreach ($frames as $frame) {
            $itemsByPipeline[$frame['pipeline_id'] ?? ''][] = [$itemKey => $frame[$frameColumn]];
        }

        $taskModel = new TaskModel();
        $created   = $taskModel->createPerPipeline($type, $itemsByPipeline, $scopeObj !== '' ? $scopeObj : null);

        if ($created === false) {
            return redirect()->back()->with('error', 'Не удалось создать задачу: ' . implode(', ', $taskModel->errors()));
        }

        return $this->redirectToCreatedTasks($created, $type);
    }

    /**
     * The list filter (object, pipeline, date_from, date_to, qc) as trimmed strings, read through
     * `$get` — the query string for index(), the POST body for createTask()'s all_matching mode.
     *
     * @param callable(string): mixed $get
     *
     * @return array{object: string, pipeline: string, date_from: string, date_to: string, qc: string}
     */
    private function readFilters(callable $get): array
    {
        $filters = [];
        foreach (['object', 'pipeline', 'date_from', 'date_to', 'qc'] as $name) {
            $value          = $get($name);
            $filters[$name] = is_string($value) ? trim($value) : '';
        }

        return $filters;
    }

    /**
     * Narrow `$model` to the frames matching `$filters` — the one place the frames filter becomes
     * a query, so the list and "select every frame matching the filter" can never disagree.
     *
     * @param array{object: string, pipeline: string, date_from: string, date_to: string, qc: string} $filters
     * @param array<string, string> $pipelineNames id => name; `pipeline` is a pipeline NAME
     *                                             (readable, linkable from the dashboard)
     */
    private function applyFilters(FrameModel $model, array $filters, array $pipelineNames): FrameModel
    {
        if ($filters['object'] !== '') {
            $model->where('object', $filters['object']);
        }

        if ($filters['pipeline'] === self::PIPELINE_NONE) {
            $model->where('pipeline_id', null);
        } elseif ($filters['pipeline'] !== '') {
            $model->where('pipeline_id', array_search($filters['pipeline'], $pipelineNames, true) ?: '');
        }

        foreach (['date_from' => 'obs_time >=', 'date_to' => 'obs_time <'] as $name => $condition) {
            $timestamp = $filters[$name] !== '' ? strtotime($filters[$name]) : false;
            if ($timestamp !== false) {
                $model->where($condition, date('Y-m-d H:i:s', $timestamp));
            }
        }

        // «Не OK» is every flag but OK. frames.quality_flag is NOT NULL (POST /frames requires it),
        // so there is no "not assessed" state to place.
        if ($filters['qc'] === self::QC_NOT_OK) {
            $model->where('quality_flag <>', 'OK');
        } elseif ($filters['qc'] !== '') {
            $model->where('quality_flag', $filters['qc']);
        }

        return $model;
    }
}
