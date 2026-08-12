<?php

namespace App\Controllers\Web;

use App\Models\SourceModel;
use App\Models\SourceObservationModel;
use App\Models\TaskItemModel;
use App\Models\TaskModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Debug UI page: "Sources" — the source catalog, each row showing its current position resolved
 * from its most recent `source_observations` row (see CLAUDE.md: `sources` has no ra/dec of its
 * own). Every row with a resolved position links out to Aladin Lite (aladin.cds.unistra.fr)
 * centered on that ra/dec, so an operator can eyeball what the pipeline actually matched against
 * a real sky survey image. Sources with zero observations (shouldn't normally happen — a source
 * is only ever created alongside its first observation) show as unlinked.
 */
class SourcesController extends Controller
{
    public function index(): ResponseInterface
    {
        $catalogName = trim((string) ($this->request->getGet('catalog_name') ?? ''));
        $objectType  = trim((string) ($this->request->getGet('object_type') ?? ''));
        $search      = trim((string) ($this->request->getGet('search') ?? ''));
        $limit       = (int) ($this->request->getGet('limit') ?? 200);
        $limit       = $limit > 0 ? min($limit, 2000) : 200;

        $model = new SourceModel();

        if ($catalogName !== '') {
            $model = $model->where('catalog_name', $catalogName);
        }

        if ($objectType !== '') {
            $model = $model->where('object_type', $objectType);
        }

        if ($search !== '') {
            $model = $model->like('catalog_id', $search);
        }

        $sources = $model->orderBy('last_observed_at', 'DESC')->findAll($limit);

        $positions = (new SourceObservationModel())
            ->getLatestObservationsForSources(array_column($sources, 'id'));

        foreach ($sources as &$source) {
            $position          = $positions[$source['id']] ?? null;
            $source['ra']      = $position['ra'] ?? null;
            $source['dec']     = $position['dec'] ?? null;
            $source['mag']     = $position['mag'] ?? null;
        }
        unset($source);

        $catalogNames = (new SourceModel())
            ->distinct()
            ->select('catalog_name')
            ->where('catalog_name IS NOT NULL')
            ->orderBy('catalog_name', 'ASC')
            ->findAll();

        $objectTypes = (new SourceModel())
            ->distinct()
            ->select('object_type')
            ->where('object_type IS NOT NULL')
            ->orderBy('object_type', 'ASC')
            ->findAll();

        return $this->response->setBody(view('web/sources_index', [
            'sources'          => $sources,
            'catalogNames'     => array_column($catalogNames, 'catalog_name'),
            'objectTypes'      => array_column($objectTypes, 'object_type'),
            'filterCatalog'    => $catalogName,
            'filterObjectType' => $objectType,
            'filterSearch'     => $search,
            'limit'            => $limit,
        ]));
    }

    /**
     * POST /ui/sources/generate-charts — create a GENERATE_CHARTS task for a single source.
     * The task item only carries source_id; observatory-pipeline itself decides which chart
     * styles (track/stamp_strip/before_after) to render and upload for it — see CLAUDE.md /
     * SourceChartModel for why style isn't chosen at task-creation time.
     */
    public function createTask(): ResponseInterface
    {
        $sourceId = trim((string) ($this->request->getPost('source_id') ?? ''));

        if ($sourceId === '') {
            return redirect()->back()->with('error', 'Не указан source_id.');
        }

        if ((new SourceModel())->find($sourceId) === null) {
            return redirect()->back()->with('error', "Источник {$sourceId} не найден.");
        }

        $taskModel = new TaskModel();
        $taskId    = $taskModel->insert([
            'type'        => 'GENERATE_CHARTS',
            'status'      => 'PENDING',
            'total_items' => 1,
        ], true);

        if ($taskId === false) {
            return redirect()->back()->with('error', 'Не удалось создать задачу: ' . implode(', ', $taskModel->errors()));
        }

        (new TaskItemModel())->insertForTask($taskId, [['source_id' => $sourceId]]);

        return redirect()->to('/ui/tasks/' . $taskId)
            ->with('success', "Задача {$taskId} создана: GENERATE_CHARTS для источника {$sourceId}.");
    }

    /**
     * POST /ui/sources/merge — merge several fragmented source_ids (selected via checkboxes on
     * /ui/charts — see that page's own bulk-action bar) into one freshly-created source. See
     * SourceModel::mergeSources() for the actual mechanics and rationale.
     *
     * Deliberately does NOT queue any follow-up task itself — same decoupled-task convention as
     * ANALYZE -> DETECT_ANOMALIES -> GENERATE_CHARTS elsewhere in this app (see
     * observatory-pipeline's CLAUDE.md "Job queue" section): the merged source's old
     * charts/anomalies are simply gone after this call. The success message tells the operator
     * exactly which frame_ids and which new source_id to submit next, via the existing
     * /ui/frames (DETECT_ANOMALIES) and /ui/sources/generate-charts (GENERATE_CHARTS) actions.
     */
    public function merge(): ResponseInterface
    {
        $sourceIds = array_values(array_unique(array_filter(
            (array) ($this->request->getPost('source_ids') ?? []),
            static fn ($v): bool => is_string($v) && $v !== ''
        )));

        if (count($sourceIds) < 2) {
            return redirect()->back()->with('error', 'Выберите минимум 2 источника для объединения.');
        }

        try {
            $result = (new SourceModel())->mergeSources($sourceIds);
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $frameCount = count($result['frame_ids']);
        $frameList  = implode(', ', $result['frame_ids']);

        $msg = "Объединено {$result['merged_count']} источник(ов) в новый источник {$result['target_id']} "
            . "({$frameCount} кадр(ов): {$frameList}). "
            . 'Старые графики/аномалии удалены. Не забудьте создать задачу DETECT_ANOMALIES '
            . 'для затронутых кадров (страница «Кадры») и GENERATE_CHARTS для нового источника.';

        if (! empty($result['missing_ids'])) {
            $msg .= ' Проигнорированы не найденные id: ' . implode(', ', $result['missing_ids']) . '.';
        }

        return redirect()->to('/ui/charts')->with('success', $msg);
    }
}
