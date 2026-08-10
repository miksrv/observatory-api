<?php

namespace App\Controllers\Web;

use App\Models\AnomalyModel;
use App\Models\TaskItemModel;
use App\Models\TaskModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Debug UI page: "Anomalies" — anomalies joined with their frame (object/filename/obs_time) and
 * source (catalog identity) for context. The typical way in is a click-through from
 * /ui/charts?source_id=... (Web\ChartsController) or from a task's item list
 * (Web\TasksController::show()), but every filter is also a plain query-string param here.
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
                . 'frames.obs_time AS obs_time, sources.catalog_name AS catalog_name'
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

        return $this->response->setBody(view('web/anomalies_index', [
            'anomalies'    => $anomalies,
            'anomalyTypes' => AnomalyModel::ALLOWED_TYPES,
            'filters'      => $filters,
        ]));
    }

    /**
     * POST /ui/anomalies/generate-charts — create a GENERATE_CHARTS task from selected anomalies'
     * source_ids. Deduplicates source_ids so the task doesn't chart the same source twice.
     */
    public function createTask(): ResponseInterface
    {
        $anomalyIds = $this->collectAnomalyIds();

        if (count($anomalyIds) === 0) {
            return redirect()->back()->with('error', 'Выберите хотя бы одну аномалию.');
        }

        // Resolve unique source_ids from selected anomalies (skip those without a source).
        $anomalies = (new AnomalyModel())->whereIn('id', $anomalyIds)->findAll();
        $sourceIds = array_values(array_unique(array_filter(
            array_column($anomalies, 'source_id')
        )));

        if (count($sourceIds) === 0) {
            return redirect()->back()->with('error', 'Ни одна из выбранных аномалий не привязана к источнику (source_id).');
        }

        $items = [];
        foreach ($sourceIds as $sid) {
            $items[] = ['source_id' => $sid];
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
     * POST /ui/anomalies/delete — delete selected anomalies and any associated source_charts
     * (both DB rows and chart image files on disk).
     */
    public function delete(): ResponseInterface
    {
        $anomalyIds = $this->collectAnomalyIds();

        if (count($anomalyIds) === 0) {
            return redirect()->back()->with('error', 'Выберите хотя бы одну аномалию для удаления.');
        }

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
     * Extract and validate anomaly_ids[] from POST data.
     *
     * @return string[]
     */
    private function collectAnomalyIds(): array
    {
        $ids = (array) ($this->request->getPost('anomaly_ids') ?? []);

        return array_values(array_unique(array_filter(
            $ids,
            static fn ($v): bool => is_string($v) && $v !== ''
        )));
    }
}
