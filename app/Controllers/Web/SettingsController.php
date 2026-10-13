<?php

namespace App\Controllers\Web;

use App\Models\PipelineModel;
use App\Models\SettingModel;
use App\Models\TaskModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Web UI page: "Settings" — view and edit user-configurable pipeline parameters.
 *
 * Only rows with `type = 'config'` are shown (internal rows like `pipeline_last_seen_at` are
 * hidden). On save, a RESTART signal task is created so the pipeline worker picks up the new
 * configuration — it marks the task completed and exits, letting Docker restart the container
 * with fresh remote settings.
 */
class SettingsController extends BaseWebController
{
    /**
     * GET /ui/settings — show all configurable parameters in an editable form.
     */
    public function index(): ResponseInterface
    {
        $settings = (new SettingModel())->getConfigurable();

        // Group settings by prefix (everything before the first underscore) for visual grouping.
        $groups = [];
        foreach ($settings as $setting) {
            $parts = explode('_', $setting['param'], 2);
            $prefix = $parts[0];
            $groups[$prefix][] = $setting;
        }

        return $this->response->setBody(view('web/settings_index', [
            'settings' => $settings,
            'groups'   => $groups,
        ]));
    }

    /**
     * POST /ui/settings — save changed parameters and create a RESTART task.
     */
    public function save(): ResponseInterface
    {
        $model    = new SettingModel();
        $settings = $model->getConfigurable();
        $posted   = $this->request->getPost();

        $changed = 0;

        foreach ($settings as $setting) {
            $key = 'setting_' . $setting['id'];
            if (! array_key_exists($key, $posted)) {
                continue;
            }

            $newValue = trim((string) $posted[$key]);
            $oldValue = $setting['value'] ?? '';

            // The form's switch always posts lowercase true/false; a stored "True" is the same value.
            $isBool = static fn (string $v): bool => in_array(strtolower($v), ['true', 'false'], true);
            if ($isBool($oldValue) && strtolower($newValue) === strtolower($oldValue)) {
                continue;
            }

            if ($newValue !== $oldValue) {
                $model->update($setting['id'], ['value' => $newValue]);
                $changed++;
            }
        }

        if ($changed === 0) {
            return redirect()->to('/ui/settings')->with('success', 'Изменений нет — всё уже актуально.');
        }

        // Create a RESTART signal task so the pipelines pick up new settings. A task is claimed by
        // exactly one worker, so every active pipeline gets its own targeted RESTART; with none
        // registered, one untargeted task as before.
        $taskModel = new TaskModel();
        $pipelines = (new PipelineModel())->getActiveNames();

        foreach ($pipelines === [] ? [null] : array_keys($pipelines) as $pipelineId) {
            $taskModel->queueRestart($pipelineId);
        }

        $targets = $pipelines === [] ? 'pipeline' : 'pipeline: ' . implode(', ', $pipelines);

        return redirect()->to('/ui/settings')
            ->with('success', "Сохранено {$changed} параметр(ов). Создана задача RESTART для перезагрузки {$targets}.");
    }
}

