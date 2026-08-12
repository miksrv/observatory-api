<?php

namespace App\Controllers\Web;

use App\Models\AnomalyModel;
use App\Models\SourceChartModel;
use App\Models\TaskItemModel;
use App\Models\TaskModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Debug UI page: "Anomalies" — anomalies grouped by source, joined with their frame
 * (object/filename/obs_time) and source (catalog identity) for context. Each row in the list
 * represents one source with all its anomalies aggregated together.
 */
class AnomaliesController extends Controller
{
    public function index(): ResponseInterface
    {
        $filters = [
            'anomaly_type' => trim((string) ($this->request->getGet('anomaly_type') ?? '')),
            'is_alert'     => trim((string) ($this->request->getGet('is_alert') ?? '')),
            'object'       => trim((string) ($this->request->getGet('object') ?? '')),
            'frame_id'     => trim((string) ($this->request->getGet('frame_id') ?? '')),
            'source_id'    => trim((string) ($this->request->getGet('source_id') ?? '')),
        ];

        $model = (new AnomalyModel())
            ->select(
                'anomalies.*, frames.object AS object, frames.filename AS filename, '
                . 'frames.obs_time AS obs_time, sources.catalog_name AS catalog_name, '
                . 'sources.catalog_id AS catalog_id'
            )
            ->join('frames', 'frames.id = anomalies.frame_id', 'left')
            ->join('sources', 'sources.id = anomalies.source_id', 'left');

        if ($filters['anomaly_type'] !== '') {
            $model = $model->where('anomalies.anomaly_type', $filters['anomaly_type']);
        }
        if ($filters['is_alert'] !== '') {
            $model = $model->where('anomalies.is_alert', (int) $filters['is_alert']);
        }
        if ($filters['object'] !== '') {
            $model = $model->where('frames.object', $filters['object']);
        }
        if ($filters['frame_id'] !== '') {
            $model = $model->where('anomalies.frame_id', $filters['frame_id']);
        }
        if ($filters['source_id'] !== '') {
            $model = $model->where('anomalies.source_id', $filters['source_id']);
        }

        $anomalies = $model->orderBy('frames.obs_time', 'DESC')->findAll(500);

        // Group anomalies by source_id (null source_id anomalies each get their own group).
        $groups = [];
        $nullIdx = 0;

        foreach ($anomalies as $a) {
            $key = $a['source_id'] ? 'src_' . $a['source_id'] : 'null_' . ($nullIdx++);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'source_id'    => $a['source_id'],
                    'catalog_name' => $a['catalog_name'] ?? null,
                    'catalog_id'   => $a['catalog_id'] ?? null,
                    'object'       => $a['object'] ?? null,
                    'anomaly_ids'  => [],
                    'types'        => [],
                    // anomaly_ids grouped by their own anomaly_type — a source classified with
                    // more than one type over its lifetime (see createTask()'s docblock) needs
                    // its GENERATE_CHARTS task built per-type, not per-group, so each type's own
                    // chart (style) actually gets (re)generated instead of just one of them.
                    'ids_by_type'  => [],
                    'has_alert'    => false,
                    'first_obs'    => $a['obs_time'],
                    'last_obs'     => $a['obs_time'],
                    'mpc_designation' => $a['mpc_designation'] ?? null,
                    'ra'           => $a['ra'],
                    'dec'          => $a['dec'],
                ];
            }

            $groups[$key]['anomaly_ids'][] = $a['id'];
            $groups[$key]['types'][]       = $a['anomaly_type'];
            $groups[$key]['ids_by_type'][$a['anomaly_type']][] = $a['id'];
            if ($a['is_alert']) {
                $groups[$key]['has_alert'] = true;
            }
            // Track date range.
            if ($a['obs_time'] && ($groups[$key]['first_obs'] === null || $a['obs_time'] < $groups[$key]['first_obs'])) {
                $groups[$key]['first_obs'] = $a['obs_time'];
            }
            if ($a['obs_time'] && ($groups[$key]['last_obs'] === null || $a['obs_time'] > $groups[$key]['last_obs'])) {
                $groups[$key]['last_obs'] = $a['obs_time'];
            }
            // Keep latest MPC designation if set.
            if (! empty($a['mpc_designation'])) {
                $groups[$key]['mpc_designation'] = $a['mpc_designation'];
            }
        }

        // Deduplicate types within each group, and resolve a single display
        // designation: prefer the MPC designation (solar-system objects),
        // falling back to the source's own catalog_id (e.g. a Simbad main_id
        // like "V* BE UMa") for catalog-matched but non-MPC anomaly types
        // (VARIABLE_STAR, BINARY_STAR, SUPERNOVA_CANDIDATE) — mpc_designation
        // is only ever set on MPC matches (see observatory-pipeline's
        // modules/anomaly_detector.py), so without this fallback those types
        // always carried designation=null into the GENERATE_CHARTS task
        // payload below, even though the same source's catalog identity is
        // already visible right here via the sources join.
        foreach ($groups as &$g) {
            $g['types'] = array_unique($g['types']);
            $g['designation'] = $g['mpc_designation'] ?: $g['catalog_id'];
        }
        unset($g);

        return $this->response->setBody(view('web/anomalies_index', [
            'groups'       => array_values($groups),
            'anomalyTypes' => AnomalyModel::ALLOWED_TYPES,
            'filters'      => $filters,
        ]));
    }

    /**
     * POST /ui/anomalies/generate-charts — create a GENERATE_CHARTS task from selected groups.
     *
     * Builds one task item PER UNIQUE anomaly_type within each selected group — not one item per
     * group. A source classified with more than one anomaly_type over its lifetime (e.g. an
     * uncatalogued mover first seen with no history as UNKNOWN, then MOVING_UNKNOWN once it had
     * moved) needs both its "track" and "stamp_strip" charts (re)generated, and
     * observatory-pipeline's finder_chart.py renders one chart per distinct style, keyed by the
     * anomaly_type each GENERATE_CHARTS item carries (see that module's docblock and
     * SourceChartModel's, in the pipeline repo's CLAUDE.md). Submitting only one item per group —
     * the previous behavior, picking a single arbitrary type — silently generated just one of the
     * two charts (real incident, 2026-08-11: source_id 6a7be36b4d7578.98132403, 12 MOVING_UNKNOWN
     * + 1 UNKNOWN anomalies, only ever produced a single chart).
     *
     * Each item's `payload.anomaly_ids` is scoped to just that type's own anomaly ids, for
     * traceability, rather than the whole group's flattened list.
     */
    public function createTask(): ResponseInterface
    {
        $groupsData = $this->collectSelectedGroups();

        if (count($groupsData) === 0) {
            return redirect()->back()->with('error', 'Выберите хотя бы одну группу аномалий.');
        }

        // Filter out groups without source_id.
        $groupsData = array_filter($groupsData, static fn ($g) => ! empty($g['source_id']));

        if (count($groupsData) === 0) {
            return redirect()->back()->with('error', 'Ни одна из выбранных групп не привязана к источнику (source_id).');
        }

        $items = [];
        foreach ($groupsData as $g) {
            foreach ($g['ids_by_type'] as $anomalyType => $anomalyIds) {
                $items[] = [
                    'source_id' => $g['source_id'],
                    'payload'   => [
                        'anomaly_type' => $anomalyType,
                        'designation'  => $g['designation'],
                        'anomaly_ids'  => $anomalyIds,
                    ],
                ];
            }
        }

        $taskModel = new TaskModel();
        $taskId    = $taskModel->insert([
            'type'        => 'GENERATE_CHARTS',
            'status'      => 'PENDING',
            'total_items' => count($items),
        ], true);

        if ($taskId === false) {
            return redirect()->back()->with('error', 'Не удалось создать задачу: ' . implode(', ', $taskModel->errors()));
        }

        (new TaskItemModel())->insertForTask($taskId, $items);

        // count($items) can now exceed count($groupsData) — a group with both MOVING_UNKNOWN and
        // UNKNOWN anomalies contributes 2 items (one chart style each) from a single source — so
        // the message reports item and source counts separately rather than a single ambiguous
        // number.
        return redirect()->to('/ui/tasks/' . $taskId)
            ->with('success', "Задача {$taskId} создана: GENERATE_CHARTS, " . count($items)
                . ' чарт(ов) для ' . count($groupsData) . ' источник(ов).');
    }

    /**
     * POST /ui/anomalies/delete — delete selected groups' anomalies and any associated
     * source_charts (both DB rows and chart image files on disk).
     */
    public function delete(): ResponseInterface
    {
        $groupsData = $this->collectSelectedGroups();

        if (count($groupsData) === 0) {
            return redirect()->back()->with('error', 'Выберите хотя бы одну группу аномалий для удаления.');
        }

        // Flatten all anomaly IDs from selected groups.
        $anomalyIds = [];
        foreach ($groupsData as $g) {
            $anomalyIds = array_merge($anomalyIds, $g['anomaly_ids']);
        }
        $anomalyIds = array_unique($anomalyIds);

        $anomalyModel = new AnomalyModel();
        $anomalies    = $anomalyModel->whereIn('id', $anomalyIds)->findAll();

        if (count($anomalies) === 0) {
            return redirect()->back()->with('error', 'Выбранные аномалии не найдены (устарели?).');
        }

        // Collect unique source_ids that have charts to clean up.
        $sourceIds = array_values(array_unique(array_filter(
            array_column($anomalies, 'source_id')
        )));

        $deletedCharts = 0;

        if (! empty($sourceIds)) {
            $chartModel = new SourceChartModel();
            // A source_id may now have more than one chart row — one per style (see
            // SourceChartModel's class docblock) — so this can return several rows per
            // source_id; each is deleted individually below, by its own style-suffixed file.
            $charts     = $chartModel->whereIn('source_id', $sourceIds)->findAll();

            foreach ($charts as $chart) {
                // Delete chart image file from disk.
                $filePath = WRITEPATH . 'uploads/charts/' . $chart['source_id'] . '_' . $chart['style'] . '.png';
                if (is_file($filePath)) {
                    unlink($filePath);
                }

                // Also clean up a pre-migration, un-suffixed file left over from before
                // 2026-08-11-000001_SourceChartsUniqueByStyle.php, if one still exists.
                $legacyPath = WRITEPATH . 'uploads/charts/' . $chart['source_id'] . '.png';
                if (is_file($legacyPath)) {
                    unlink($legacyPath);
                }
            }

            $deletedCharts = count($charts);

            if ($deletedCharts > 0) {
                $chartModel->whereIn('source_id', $sourceIds)->delete();
            }
        }

        // Delete the anomalies themselves.
        $anomalyModel->whereIn('id', $anomalyIds)->delete();

        $msg = 'Удалено аномалий: ' . count($anomalies);
        if ($deletedCharts > 0) {
            $msg .= ', чартов: ' . $deletedCharts;
        }

        return redirect()->back()->with('success', $msg . '.');
    }

    /**
     * Extract selected groups from POST data.
     *
     * Each group is submitted as group_data[] with JSON-encoded
     * {source_id, ids_by_type: {anomaly_type: [id, ...], ...}, designation} — see
     * anomalies_index.php's checkbox value and createTask()'s docblock for why anomaly_ids are
     * grouped by anomaly_type here rather than a single flat list.
     *
     * @return array<array{source_id: ?string, ids_by_type: array<string, string[]>, anomaly_ids: string[], designation: ?string}>
     */
    private function collectSelectedGroups(): array
    {
        $raw = (array) ($this->request->getPost('group_data') ?? []);

        $groups = [];
        foreach ($raw as $json) {
            if (! is_string($json) || $json === '') {
                continue;
            }
            $decoded = json_decode($json, true);
            if (! is_array($decoded) || empty($decoded['ids_by_type']) || ! is_array($decoded['ids_by_type'])) {
                continue;
            }

            $idsByType  = [];
            $anomalyIds = [];
            foreach ($decoded['ids_by_type'] as $type => $ids) {
                if (! is_string($type) || $type === '' || ! is_array($ids) || count($ids) === 0) {
                    continue;
                }
                $idsByType[$type] = array_values($ids);
                $anomalyIds       = array_merge($anomalyIds, $ids);
            }

            if (empty($idsByType)) {
                continue;
            }

            $groups[] = [
                'source_id'   => $decoded['source_id'] ?? null,
                'ids_by_type' => $idsByType,
                'anomaly_ids' => $anomalyIds,
                'designation' => $decoded['designation'] ?? null,
            ];
        }

        return $groups;
    }
}
