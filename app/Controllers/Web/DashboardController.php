<?php

namespace App\Controllers\Web;

use App\Models\AnomalyModel;
use App\Models\FrameModel;
use App\Models\SourceChartModel;
use App\Models\SourceModel;
use App\Models\TaskModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Temporary debug web UI — landing page.
 *
 * This whole `Web` namespace is a throwaway operator dashboard for manually driving the
 * pipeline (browse frames, create tasks, look at charts/anomalies). It only ever *reads*
 * through the existing models (same ones Api\V1\* uses) and, where it writes, calls their
 * existing public methods (TaskModel::insert(), TaskItemModel::insertForTask(), ...) — nothing
 * in app/Controllers/Api or app/Models is modified for this to work. See Config/Routes.php's
 * `ui` group docblock for why it's unauthenticated and kept out of the `api/v1` group entirely.
 */
class DashboardController extends Controller
{
    public function index(): ResponseInterface
    {
        $taskModel = new TaskModel();

        $tasksByStatus = [];
        foreach (TaskModel::STATUSES as $status) {
            $tasksByStatus[$status] = $taskModel->where('status', $status)->countAllResults();
        }

        $stats = [
            'frames'    => (new FrameModel())->countAllResults(),
            'sources'   => (new SourceModel())->countAllResults(),
            'anomalies' => (new AnomalyModel())->countAllResults(),
            'alerts'    => (new AnomalyModel())->where('is_alert', 1)->countAllResults(),
            'charts'    => (new SourceChartModel())->countAllResults(),
        ];

        return $this->response->setBody(view('web/dashboard', [
            'stats'         => $stats,
            'tasksByStatus' => $tasksByStatus,
        ]));
    }
}
