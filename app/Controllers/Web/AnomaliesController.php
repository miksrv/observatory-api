<?php

namespace App\Controllers\Web;

use App\Models\AnomalyModel;
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
}
