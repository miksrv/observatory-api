<?php

namespace App\Controllers\Web;

use App\Models\SourceChartModel;
use App\Models\PipelineModel;
use App\Models\TaskItemModel;
use App\Models\TaskModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Web UI page: "Tasks" — browse the tasks/task_items queue, whether a task was created by
 * observatory-pipeline via POST /api/v1/tasks or from this dashboard's
 * Web\FramesController::createTask(). Read-only except cancel(), which flips a task's status via
 * TaskModel::update() — the exact same call PATCH /api/v1/tasks/{id} makes.
 */
class TasksController extends BaseWebController
{
    public function index(): ResponseInterface
    {
        $status   = trim((string) ($this->request->getGet('status') ?? ''));
        $type     = trim((string) ($this->request->getGet('type') ?? ''));
        $object   = trim((string) ($this->request->getGet('object') ?? ''));
        $pipeline = trim((string) ($this->request->getGet('pipeline') ?? ''));

        // `pipeline` is a pipeline NAME (readable, linkable from the dashboard).
        $pipelineNames = (new PipelineModel())->getAllNames();
        $pipelineId    = $pipeline !== '' ? (array_search($pipeline, $pipelineNames, true) ?: '') : null;

        // Every filter but the status: the status tabs count within the rest of the filter.
        $filtered = static function () use ($type, $object, $pipelineId): TaskModel {
            $model = new TaskModel();

            if ($type !== '') {
                $model->where('type', $type);
            }
            if ($object !== '') {
                $model->where('scope_object', $object);
            }
            if ($pipelineId !== null) {
                // Targeted at this pipeline or claimed by it (an untargeted task it picked up).
                $model->groupStart()
                    ->where('pipeline_id', $pipelineId)
                    ->orWhere('claimed_by', $pipelineId)
                    ->groupEnd();
            }

            return $model;
        };

        $counts = array_fill_keys(TaskModel::STATUSES, 0);
        foreach ($filtered()->select('status, COUNT(*) AS n')->groupBy('status')->findAll() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }

        $model = $filtered();
        if ($status !== '') {
            $model->where('status', $status);
        }

        $tasks = $model->orderBy('created_at', 'DESC')->findAll(200);

        return $this->response->setBody(view('web/tasks_index', [
            'tasks'          => $tasks,
            'counts'         => $counts,
            'statuses'       => TaskModel::STATUSES,
            'types'          => TaskModel::TYPES,
            'filterStatus'   => $status,
            'filterType'     => $type,
            'filterObjectQ'  => $object,
            'pipelines'      => $pipelineNames,
            'filterPipeline' => $pipeline,
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
            'task'          => $task,
            'items'         => $items,
            'chartsByItem'  => $chartsByItem,
            'pipelineNames' => (new PipelineModel())->getAllNames(),
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
