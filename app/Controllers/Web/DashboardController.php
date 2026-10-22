<?php

namespace App\Controllers\Web;

use App\Models\AnomalyModel;
use App\Models\AnomalyReviewModel;
use App\Models\FrameModel;
use App\Models\PipelineModel;
use App\Models\SourceChartModel;
use App\Models\SourceModel;
use App\Models\TaskModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Web UI — overview: the pipelines, the catalogue's totals, the task queue, recent tasks and
 * alerts.
 *
 * The whole `Web` namespace is the operator console for driving the pipeline (browse frames,
 * create tasks, look at charts/anomalies). It reads through the same models Api\V1\* uses and,
 * where it writes, calls their existing public methods (TaskModel::createPerPipeline(), ...). See
 * Config/Routes.php's `ui` group docblock for how it's signed in to and why it's kept out of the
 * `api/v1` group.
 */
class DashboardController extends BaseWebController
{
    public function index(): ResponseInterface
    {
        $taskModel = new TaskModel();

        $tasksByStatus = [];
        foreach (TaskModel::STATUSES as $status) {
            $tasksByStatus[$status] = $taskModel->where('status', $status)->countAllResults();
        }

        // Anomalies and alerts count what is still to be looked at: a reviewed one (marked
        // "nothing interesting" — AnomalyReviewModel) is counted apart.
        $unreviewed = static fn (): \CodeIgniter\Database\BaseBuilder =>
            AnomalyReviewModel::joinReviews((new AnomalyModel())->builder(), false);

        $stats = [
            'frames'    => (new FrameModel())->countAllResults(),
            'sources'   => (new SourceModel())->countAllResults(),
            'anomalies' => $unreviewed()->countAllResults(),
            'alerts'    => $unreviewed()->where('anomalies.is_alert', 1)->countAllResults(),
            'reviewed'  => AnomalyReviewModel::joinReviews((new AnomalyModel())->builder(), true)->countAllResults(),
            'charts'    => (new SourceChartModel())->countAllResults(),
        ];

        $recentTasks = (new TaskModel())
            ->where('type !=', 'RESTART')
            ->orderBy('created_at', 'DESC')
            ->findAll(6);

        $recentAlerts = AnomalyReviewModel::joinReviews((new AnomalyModel())->builder(), false)
            ->select(
                'anomalies.id, anomalies.anomaly_type, anomalies.source_id, anomalies.mpc_designation, '
                . 'anomalies.ra, anomalies.dec, frames.object, frames.obs_time, sources.catalog_id'
            )
            ->join('frames', 'frames.id = anomalies.frame_id', 'left')
            ->join('sources', 'sources.id = anomalies.source_id', 'left')
            ->where('anomalies.is_alert', 1)
            ->orderBy('frames.obs_time', 'DESC')
            ->limit(6)
            ->get()->getResultArray();

        return $this->response->setBody(view('web/dashboard', [
            'stats'         => $stats,
            'recentTasks'   => $recentTasks,
            'recentAlerts'  => $recentAlerts,
            'tasksByStatus' => $tasksByStatus,
            'pipelines'     => (new PipelineModel())->getOverview(),
            'pipelineNames' => (new PipelineModel())->getAllNames(),
            // Untargeted PENDING tasks — any pipeline may take them.
            'pendingShared' => (new TaskModel())->where('status', 'PENDING')->where('pipeline_id', null)->countAllResults(),
        ]));
    }
}
