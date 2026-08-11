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
     * Each task item carries `source_id` and a `payload` with all anomaly_ids in that group.
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
            $items[] = [
                'source_id' => $g['source_id'],
                'payload'   => [
                    'anomaly_type' => $g['anomaly_type'],
                    'designation'  => $g['designation'],
                    'anomaly_ids'  => $g['anomaly_ids'],
                ],
            ];
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

        return redirect()->to('/ui/tasks/' . $taskId)
            ->with('success', "Задача {$taskId} создана: GENERATE_CHARTS, " . count($items) . ' источник(ов).');
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
            $charts     = $chartModel->whereIn('source_id', $sourceIds)->findAll();

            foreach ($charts as $chart) {
                // Delete chart image file from disk.
                $filePath = WRITEPATH . 'uploads/charts/' . $chart['source_id'] . '.png';
                if (is_file($filePath)) {
                    unlink($filePath);
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
     * Each group is submitted as group_data[] with JSON-encoded {source_id, anomaly_ids[]}.
     *
     * @return array<array{source_id: ?string, anomaly_ids: string[]}>
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
            if (! is_array($decoded) || empty($decoded['anomaly_ids'])) {
                continue;
            }
            $groups[] = [
                'source_id'    => $decoded['source_id'] ?? null,
                'anomaly_ids'  => (array) $decoded['anomaly_ids'],
                'anomaly_type' => $decoded['anomaly_type'] ?? null,
                'designation'  => $decoded['designation'] ?? null,
            ];
        }

        return $groups;
    }
}
