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
                . 'task_items.task_id AS task_id, task_items.filename AS item_filename'
            )
            ->join('sources', 'sources.id = source_charts.source_id', 'left')
            ->join('task_items', 'task_items.id = source_charts.task_item_id', 'left');

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
     * GET /ui/charts/{sourceId}/image — stream the stored chart PNG for inline display.
     */
    public function image(string $sourceId): ResponseInterface
    {
        // Same whitelist as Api\V1\SourcesController::isValidSourceId() — the id ends up as a
        // filename on disk, so it must be constrained before it's ever concatenated into a path.
        if (preg_match('/^[a-zA-Z0-9.]{1,64}$/', $sourceId) !== 1) {
            return $this->response->setStatusCode(400)->setBody('Invalid source id');
        }

        $path = WRITEPATH . 'uploads/charts/' . $sourceId . '.png';

        if (! is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('No chart available for this source');
        }

        return $this->response
            ->setContentType('image/png')
            ->setBody(file_get_contents($path));
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

        // Determine the filesystem key (source_id or task_item_id)
        $fileKey = $chart['source_id'] ?? $chart['task_item_id'] ?? null;

        // Delete the PNG file from disk
        if ($fileKey !== null) {
            $path = WRITEPATH . 'uploads/charts/' . $fileKey . '.png';
            if (is_file($path)) {
                unlink($path);
            }
        }

        // Delete the DB row
        $model->delete($id);

        return redirect()->to('/ui/charts')->with('success', 'График удалён.');
    }
}
