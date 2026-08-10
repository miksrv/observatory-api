<?php

namespace App\Controllers\Web;

use App\Models\SettingModel;
use App\Models\TaskModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Debug UI page: "Settings" — view and edit user-configurable pipeline parameters.
 *
 * Only rows with `type = 'config'` are shown (internal rows like `pipeline_last_seen_at` are
 * hidden). On save, a RESTART signal task is created so the pipeline worker picks up the new
 * configuration — it marks the task completed and exits, letting Docker restart the container
 * with fresh remote settings.
 */
class SettingsController extends Controller
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

            if ($newValue !== $oldValue) {
                $model->update($setting['id'], ['value' => $newValue]);
                $changed++;
            }
        }

        if ($changed === 0) {
            return redirect()->to('/ui/settings')->with('success', 'Изменений нет — всё уже актуально.');
        }

        // Create a RESTART signal task so the pipeline picks up new settings.
        $taskModel = new TaskModel();

        $taskModel->insert([
            'type'        => 'RESTART',
            'status'      => 'PENDING',
            'total_items' => 0,
        ]);

        return redirect()->to('/ui/settings')
            ->with('success', "Сохранено {$changed} параметр(ов). Создана задача RESTART для перезагрузки pipeline.");
    }
}

