<?php

namespace App\Controllers\Web;

use App\Libraries\UiListQuery;
use App\Models\AnomalyModel;
use App\Models\AnomalyReviewModel;
use App\Models\FrameModel;
use App\Models\SourceChartModel;
use App\Models\TaskModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Web UI page: "Charts" — a gallery of generated chart PNGs from `source_charts`, which is
 * dual-keyed (see CLAUDE.md): a per-source finder chart (track/stamp_strip/before_after) links
 * onward to /ui/anomalies?source_id=..., while a PREVIEW_CATALOG_MATCH diagnostic chart
 * (catalog_preview) has no source at all and links onward to its task instead — both are joined
 * in with LEFT JOINs (not INNER) so neither kind of row gets silently dropped from the gallery.
 * image() streams the PNG straight off disk from the same path both
 * Api\V1\SourcesController::chart() and Api\V1\TasksController::itemChart() read
 * (writable/uploads/charts/{source_id|task_item_id}.png) — one id namespace, so the same method
 * serves both kinds of chart. No X-API-Key here since this route lives outside the `api/v1` group
 * entirely, behind the UI's own sign-in (see Config/Routes.php), so it's fine for it to duplicate
 * that one file-read instead of calling into the API controllers.
 *
 * Reviewing: the operator looks through the charts, checks the uninteresting ones and marks them
 * reviewed with a note (review() — every current anomaly of those sources), then deletes the
 * charts (deleteSelected()). A source chart shows how many of its source's anomalies are still
 * unreviewed; `review=new|done` filters by that.
 *
 * A catalog preview is a picture of one FITS frame: its card links to that frame on /ui/frames,
 * and deleteFrames() queues DELETE_FRAME for the frames of the checked previews — a bad frame is
 * seen and deleted from the gallery; the previews go when the pipeline reports the frame deleted
 * (FrameModel::deleteWithDependents()).
 */
class ChartsController extends BaseWebController
{
    public function index(): ResponseInterface
    {
        $sourceId = trim((string) ($this->request->getGet('source_id') ?? ''));
        $style    = trim((string) ($this->request->getGet('style') ?? ''));
        $review   = trim((string) ($this->request->getGet('review') ?? ''));
        if (! in_array($review, ['new', 'done'], true)) {
            $review = '';
        }

        // Per source: its anomalies, and how many of them carry no review yet.
        $anomalyCount = '(SELECT COUNT(*) FROM anomalies a WHERE a.source_id = source_charts.source_id)';
        $openCount    = '(SELECT COUNT(*) FROM anomalies a LEFT JOIN anomaly_reviews r'
            . ' ON r.frame_id = a.frame_id AND r.source_id = a.source_id AND r.anomaly_type = a.anomaly_type'
            . ' WHERE a.source_id = source_charts.source_id AND r.id IS NULL)';

        $list = new UiListQuery(
            [
                'updated'     => 'source_charts.updated_at',
                'epochs'      => 'source_charts.frame_count',
                'designation' => 'sources.catalog_id',
                'style'       => 'source_charts.style',
            ],
            'updated',
            'desc',
            ['updated', 'epochs'],
            'source_charts.id',
            24,
            [24, 48, 96],
        );

        $model = (new SourceChartModel())
            ->select(
                'source_charts.*, sources.catalog_name, sources.catalog_id, sources.object_type, '
                . 'task_items.task_id AS task_id, task_items.filename AS item_filename, '
                . 'latest_obs.ra AS src_ra, latest_obs.dec AS src_dec, '
                . $anomalyCount . ' AS anomaly_count, ' . $openCount . ' AS open_count',
                false
            )
            ->join('sources', 'sources.id = source_charts.source_id', 'left')
            ->join('task_items', 'task_items.id = source_charts.task_item_id', 'left')
            ->join(
                '(SELECT so.source_id, so.ra, so.dec FROM source_observations so '
                . 'INNER JOIN (SELECT source_id, MAX(id) AS max_id FROM source_observations GROUP BY source_id) latest '
                . 'ON so.id = latest.max_id) latest_obs',
                'latest_obs.source_id = source_charts.source_id',
                'left'
            );

        if ($sourceId !== '') {
            $model = $model->where('source_charts.source_id', $sourceId);
        }

        if ($style !== '' && in_array($style, SourceChartModel::ALLOWED_STYLES, true)) {
            $model = $model->where('source_charts.style', $style);
        }

        if ($review === 'new') {
            $model = $model->where($openCount . ' > 0', null, false);
        } elseif ($review === 'done') {
            $model = $model->where('source_charts.source_id IS NOT NULL', null, false)
                ->where($anomalyCount . ' > 0', null, false)
                ->where($openCount . ' = 0', null, false);
        }

        $list->paginate($model->countAllResults(false));
        $charts = $model->orderBy($list->orderBy(), '', false)->findAll($list->perPage, $list->offset());

        return $this->response->setBody(view('web/charts_index', [
            'charts'         => $charts,
            'styles'         => SourceChartModel::ALLOWED_STYLES,
            'filterSourceId' => $sourceId,
            'filterStyle'    => $style,
            'filterReview'   => $review,
            'list'           => $list,
        ]));
    }

    /**
     * GET /ui/charts/{id}/image?style=track — stream the stored chart image for inline display.
     *
     * `id` is a source_id OR a task_item_id (see this class's docblock — one filesystem id
     * namespace serves both). `style` is optional and only meaningful for a source_id: since a
     * source can now hold one chart per style (2026-08-11-000001_SourceChartsUniqueByStyle.php),
     * an id with no style falls back through the legacy un-suffixed filename and then
     * SourceChartModel::STYLE_DISPLAY_PRIORITY — same resolution Api\V1\SourcesController::chart()
     * uses, duplicated here rather than shared since this controller intentionally reads straight
     * off disk instead of calling into the API (see class docblock). Content-Type is derived from
     * the resolved file's own extension (image/gif for a SourceChartModel::GIF_STYLES chart,
     * image/png otherwise) — same reasoning as Api\V1\SourcesController::chart().
     */
    public function image(string $id): ResponseInterface
    {
        // Same whitelist as Api\V1\SourcesController::isValidSourceId() — the id ends up as a
        // filename on disk, so it must be constrained before it's ever concatenated into a path.
        if (preg_match('/^[a-zA-Z0-9.]{1,64}$/', $id) !== 1) {
            return $this->response->setStatusCode(400)->setBody('Invalid source id');
        }

        $style = trim((string) ($this->request->getGet('style') ?? ''));
        $path  = $this->resolveChartPath($id, $style !== '' ? $style : null);

        if ($path === null) {
            return $this->response->setStatusCode(404)->setBody('No chart available for this source');
        }

        $contentType = str_ends_with($path, '.gif') ? 'image/gif' : 'image/png';

        return $this->response
            ->setContentType($contentType)
            ->setBody(file_get_contents($path));
    }

    /**
     * Resolve the on-disk path for a chart image — mirrors
     * Api\V1\SourcesController::resolveChartPath() (see this class's docblock for why the logic
     * is duplicated rather than shared).
     */
    private function resolveChartPath(string $id, ?string $style): ?string
    {
        $dir = WRITEPATH . 'uploads/charts';

        if ($style !== null) {
            if (! in_array($style, SourceChartModel::ALLOWED_STYLES, true)) {
                return null;
            }
            $ext  = in_array($style, SourceChartModel::GIF_STYLES, true) ? 'gif' : 'png';
            $path = $dir . '/' . $id . '_' . $style . '.' . $ext;

            return is_file($path) ? $path : null;
        }

        $legacyPath = $dir . '/' . $id . '.png';
        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        // STYLE_DISPLAY_PRIORITY deliberately excludes SourceChartModel::GIF_STYLES (see that
        // constant's docblock) — a style-less request keeps resolving to the static chart, never
        // an animation, so ".png" here is always correct rather than needing the same per-style
        // extension lookup the explicit-$style branch above does.
        foreach (SourceChartModel::STYLE_DISPLAY_PRIORITY as $candidate) {
            $path = $dir . '/' . $id . '_' . $candidate . '.png';
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * POST /ui/charts/{id}/delete — delete a chart record from DB and its PNG from disk.
     */
    public function delete(string $id): ResponseInterface
    {
        $model = new SourceChartModel();
        $chart = $model->find($id);

        if ($chart === null) {
            return redirect()->to('/ui/charts')->with('error', 'График не найден.');
        }

        $this->deleteChartFiles($chart);
        $model->delete($id);

        return redirect()->to('/ui/charts')->with('success', 'График удалён.');
    }

    /**
     * POST /ui/charts/review — mark every current anomaly of the checked sources (source_ids[])
     * reviewed, with the note. A later anomaly of the same source — a new frame, a new type —
     * comes in unreviewed.
     */
    public function review(): ResponseInterface
    {
        $sourceIds = $this->selectedSourceIds();
        if ($sourceIds === []) {
            return redirect()->back()->with('error', 'Выберите хотя бы один график источника.');
        }

        $anomalies = (new AnomalyModel())->whereIn('source_id', $sourceIds)->findAll();
        if ($anomalies === []) {
            return redirect()->back()->with('error', 'У выбранных источников нет аномалий.');
        }

        $marked = (new AnomalyReviewModel())->markReviewed($anomalies, (string) ($this->request->getPost('note') ?? ''));

        return redirect()->back()->with(
            'success',
            'Отмечено проверенными: ' . $marked . ' ' . ui_plural($marked, 'аномалия', 'аномалии', 'аномалий')
            . ' у ' . count($sourceIds) . ' ' . ui_plural(count($sourceIds), 'источника', 'источников', 'источников') . '.'
        );
    }

    /**
     * POST /ui/charts/delete — delete every chart (all styles) of the checked sources
     * (source_ids[]) and the checked catalog previews (preview_ids[]). Anomalies, reviews and
     * frames stay.
     */
    public function deleteSelected(): ResponseInterface
    {
        $sourceIds  = $this->selectedSourceIds();
        $previewIds = $this->selectedIds('preview_ids');
        if ($sourceIds === [] && $previewIds === []) {
            return redirect()->back()->with('error', 'Выберите хотя бы один график.');
        }

        $model  = new SourceChartModel();
        $charts = [];
        if ($sourceIds !== []) {
            $charts = $model->whereIn('source_id', $sourceIds)->findAll();
        }
        if ($previewIds !== []) {
            $charts = array_merge($charts, (new SourceChartModel())->whereIn('task_item_id', $previewIds)->findAll());
        }
        foreach ($charts as $chart) {
            $this->deleteChartFiles($chart);
        }
        if ($charts !== []) {
            $model->whereIn('id', array_column($charts, 'id'))->delete();
        }

        return redirect()->back()->with(
            'success',
            'Удалено графиков: ' . count($charts) . '.'
        );
    }

    /**
     * POST /ui/charts/delete-frames — queue DELETE_FRAME for the frames the checked catalog
     * previews (preview_ids[] = task_item ids) were made of: the operator saw the frame is bad.
     * The file goes to FITS_REJECTED, the frame and its data leave the database, and so do these
     * previews — once the pipeline holding the file reports it done. One task per pipeline.
     */
    public function deleteFrames(): ResponseInterface
    {
        $previewIds = $this->selectedIds('preview_ids');
        if ($previewIds === []) {
            return redirect()->back()->with('error', 'Выберите хотя бы одно превью кадра («Превью каталога»).');
        }

        // Frames hold the basename; a preview's task item may hold a full path (a task the
        // pipeline queued itself).
        $filenames = array_values(array_unique(array_map('basename', array_filter(array_column(
            (new SourceChartModel())
                ->select('task_items.filename')
                ->join('task_items', 'task_items.id = source_charts.task_item_id', 'inner')
                ->whereIn('source_charts.task_item_id', $previewIds)
                ->findAll(),
            'filename'
        )))));

        $frames = $filenames === []
            ? []
            : (new FrameModel())->select('id, pipeline_id')->whereIn('filename', $filenames)->findAll();

        if ($frames === []) {
            return redirect()->back()->with('error', 'Кадры выбранных превью не зарегистрированы — удалять нечего. Само превью можно удалить кнопкой «Удалить графики».');
        }

        // A preview stays in the gallery until the pipeline has deleted its frame: checking it
        // again must not queue the same frame twice (the second item would fail on a frame gone).
        $queued = array_column(
            db_connect()->table('task_items')
                ->select('task_items.frame_id')
                ->join('tasks', 'tasks.id = task_items.task_id', 'inner')
                ->where('tasks.type', 'DELETE_FRAME')
                ->whereIn('tasks.status', ['PENDING', 'RUNNING'])
                ->whereIn('task_items.status', ['PENDING', 'RUNNING'])
                ->whereIn('task_items.frame_id', array_column($frames, 'id'))
                ->get()->getResultArray(),
            'frame_id'
        );
        $already = count($frames);
        $frames  = array_values(array_filter($frames, static fn (array $f): bool => ! in_array($f['id'], $queued, true)));
        $already -= count($frames);

        if ($frames === []) {
            return redirect()->back()->with('success', 'Эти кадры уже стоят в очереди на удаление — превью исчезнут, когда pipeline выполнит задачу.');
        }

        $itemsByPipeline = [];
        foreach ($frames as $frame) {
            $itemsByPipeline[$frame['pipeline_id'] ?? ''][] = ['frame_id' => $frame['id']];
        }

        $taskModel = new TaskModel();
        if ($taskModel->createPerPipeline('DELETE_FRAME', $itemsByPipeline, null) === false) {
            return redirect()->back()->with('error', 'Не удалось создать задачу: ' . implode(', ', $taskModel->errors()));
        }

        $missing = count($filenames) - count($frames) - $already;

        return redirect()->back()->with(
            'success',
            'Поставлено на удаление: ' . count($frames) . ' ' . ui_plural(count($frames), 'кадр', 'кадра', 'кадров')
            . ' (задача «Удаление кадра»). Их превью исчезнут, когда pipeline её выполнит.'
            . ($missing > 0 ? ' Не зарегистрировано и пропущено: ' . $missing . '.' : '')
            . ($already > 0 ? ' Уже в очереди на удаление: ' . $already . '.' : '')
        );
    }

    /**
     * The checked source ids, as the gallery's checkboxes post them.
     *
     * @return list<string>
     */
    private function selectedSourceIds(): array
    {
        return $this->selectedIds('source_ids');
    }

    /**
     * Ids posted as `$name[]` (source ids or task item ids), validated against the id alphabet.
     *
     * @return list<string>
     */
    private function selectedIds(string $name): array
    {
        $ids = array_filter(
            (array) ($this->request->getPost($name) ?? []),
            static fn ($id): bool => is_string($id) && preg_match('/^[a-zA-Z0-9.]{1,64}$/', $id) === 1
        );

        return array_values(array_unique($ids));
    }

    /**
     * Remove a chart's image file(s) from disk.
     */
    private function deleteChartFiles(array $chart): void
    {
        // Determine the filesystem key (source_id or task_item_id). A source-keyed chart's file
        // carries a style suffix (see SourceChartModel's class docblock); a task_item-keyed one
        // doesn't and never has.
        $fileKey = $chart['source_id'] ?? $chart['task_item_id'] ?? null;

        // Delete the image file from disk
        if ($fileKey !== null) {
            if ($chart['source_id'] !== null) {
                $ext          = in_array($chart['style'], SourceChartModel::GIF_STYLES, true) ? 'gif' : 'png';
                $suffixedPath = WRITEPATH . 'uploads/charts/' . $fileKey . '_' . $chart['style'] . '.' . $ext;
            } else {
                $suffixedPath = WRITEPATH . 'uploads/charts/' . $fileKey . '.png';
            }
            if (is_file($suffixedPath)) {
                unlink($suffixedPath);
            }

            // Also clean up a pre-migration, un-suffixed file left over from before
            // 2026-08-11-000001_SourceChartsUniqueByStyle.php, if one still exists.
            $legacyPath = WRITEPATH . 'uploads/charts/' . $fileKey . '.png';
            if ($legacyPath !== $suffixedPath && is_file($legacyPath)) {
                unlink($legacyPath);
            }
        }
    }
}
