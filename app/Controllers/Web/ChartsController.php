<?php

namespace App\Controllers\Web;

use App\Models\SourceChartModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Debug UI page: "Charts" — a gallery of generated chart PNGs from `source_charts`, which is
 * dual-keyed (see CLAUDE.md): a per-source finder chart (track/stamp_strip/before_after) links
 * onward to /ui/anomalies?source_id=..., while a PREVIEW_CATALOG_MATCH diagnostic chart
 * (catalog_preview) has no source at all and links onward to its task instead — both are joined
 * in with LEFT JOINs (not INNER) so neither kind of row gets silently dropped from the gallery.
 * image() streams the PNG straight off disk from the same path both
 * Api\V1\SourcesController::chart() and Api\V1\TasksController::itemChart() read
 * (writable/uploads/charts/{source_id|task_item_id}.png) — one id namespace, so the same method
 * serves both kinds of chart. No X-API-Key here since this route lives outside the `api/v1` group
 * entirely (see Config/Routes.php), so it's fine for it to duplicate that one file-read instead of
 * calling into the API controllers.
 */
class ChartsController extends Controller
{
    public function index(): ResponseInterface
    {
        $sourceId = trim((string) ($this->request->getGet('source_id') ?? ''));
        $style    = trim((string) ($this->request->getGet('style') ?? ''));

        $model = (new SourceChartModel())
            ->select(
                'source_charts.*, sources.catalog_name, sources.catalog_id, sources.object_type, '
                . 'task_items.task_id AS task_id, task_items.filename AS item_filename, '
                . 'latest_obs.ra AS src_ra, latest_obs.dec AS src_dec'
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

        $charts = $model->orderBy('source_charts.updated_at', 'DESC')->findAll(200);

        return $this->response->setBody(view('web/charts_index', [
            'charts'         => $charts,
            'styles'         => SourceChartModel::ALLOWED_STYLES,
            'filterSourceId' => $sourceId,
            'filterStyle'    => $style,
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

        // Delete the DB row
        $model->delete($id);

        return redirect()->to('/ui/charts')->with('success', 'График удалён.');
    }
}
