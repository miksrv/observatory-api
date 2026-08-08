<?php

namespace App\Controllers\Web;

use App\Models\SourceChartModel;
use App\Models\TaskItemModel;
use App\Models\TaskModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Debug UI page: "Tasks" — browse the tasks/task_items queue, whether a task was created by
 * observatory-pipeline via POST /api/v1/tasks or from this dashboard's
 * Web\FramesController::createTask(). Read-only except cancel(), which flips a task's status via
 * TaskModel::update() — the exact same call PATCH /api/v1/tasks/{id} makes.
 */
class TasksController extends Controller
{
    public function index(): ResponseInterface
    {
        $model = new TaskModel();

        $status = trim((string) ($this->request->getGet('status') ?? ''));
        $type   = trim((string) ($this->request->getGet('type') ?? ''));
        $object = trim((string) ($this->request->getGet('object') ?? ''));

        if ($status !== '') {
            $model = $model->where('status', $status);
        }
        if ($type !== '') {
            $model = $model->where('type', $type);
        }
        if ($object !== '') {
            $model = $model->where('scope_object', $object);
        }

        $tasks = $model->orderBy('created_at', 'DESC')->findAll(200);

        return $this->response->setBody(view('web/tasks_index', [
            'tasks'         => $tasks,
            'statuses'      => TaskModel::STATUSES,
            'types'         => TaskModel::TYPES,
            'filterStatus'  => $status,
            'filterType'    => $type,
            'filterObjectQ' => $object,
        ]));
    }

    public function show(string $id): ResponseInterface
    {
        $task = (new TaskModel())->find($id);

        if ($task === null) {
            return redirect()->to('/ui/tasks')->with('error', "Задача {$id} не найдена.");
        }

        $items = (new TaskItemModel())->getForTask($id);

        // PREVIEW_CATALOG_MATCH items have no frame_id/source_id at all (see CLAUDE.md's
        // source_charts note) — their only artifact is a diagnostic chart keyed by task_item_id,
        // so it has to be looked up separately and matched onto each item below rather than
        // coming back from getForTask() itself.
        $chartsByItem = [];
        $itemIds      = array_column($items, 'id');

        if (count($itemIds) > 0) {
            foreach ((new SourceChartModel())->whereIn('task_item_id', $itemIds)->findAll() as $chart) {
                $chartsByItem[$chart['task_item_id']] = $chart;
            }
        }

        return $this->response->setBody(view('web/tasks_show', [
            'task'         => $task,
            'items'        => $items,
            'chartsByItem' => $chartsByItem,
        ]));
    }

    public function cancel(string $id): ResponseInterface
    {
        $model = new TaskModel();
        $task  = $model->find($id);

        if ($task === null) {
            return redirect()->to('/ui/tasks')->with('error', "Задача {$id} не найдена.");
        }

        if (! in_array($task['status'], TaskModel::TERMINAL_STATUSES, true)) {
            $model->update($id, ['status' => 'CANCELLED', 'finished_at' => date('Y-m-d H:i:s')]);
        }

        return redirect()->to('/ui/tasks/' . $id)->with('success', 'Задача отменена.');
    }
}
