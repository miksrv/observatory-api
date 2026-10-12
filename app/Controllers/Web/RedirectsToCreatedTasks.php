<?php

namespace App\Controllers\Web;

use App\Models\PipelineModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Shared "task(s) created" redirect for the Web controllers that turn an operator selection into
 * tasks via TaskModel::createPerPipeline(): one task opens its own page, several (the selection
 * spanned more than one pipeline) open the task list.
 */
trait RedirectsToCreatedTasks
{
    /**
     * @param list<array{id: string, pipeline_id: ?string, items: int}> $created
     */
    private function redirectToCreatedTasks(array $created, string $type, string $details = ''): RedirectResponse
    {
        $details = $details !== '' ? ' ' . $details : '';
        $names   = (new PipelineModel())->getAllNames();

        if (count($created) === 1) {
            $task = $created[0];

            return redirect()->to('/ui/tasks/' . $task['id'])
                ->with('success', "Задача {$task['id']} создана: {$type}, {$task['items']} элемент(ов), "
                    . 'pipeline: ' . self::pipelineLabel($task['pipeline_id'], $names) . '.' . $details);
        }

        $parts = array_map(
            static fn (array $t): string => self::pipelineLabel($t['pipeline_id'], $names) . " — {$t['items']}",
            $created,
        );

        return redirect()->to('/ui/tasks')
            ->with('success', 'Выбор охватывает несколько pipeline — создано ' . count($created)
                . " задач(и) {$type}: " . implode('; ', $parts) . '.' . $details);
    }

    /** @param array<string, string> $names id => pipeline name */
    private static function pipelineLabel(?string $pipelineId, array $names): string
    {
        if ($pipelineId === null) {
            return 'любой (владелец неизвестен)';
        }

        return $names[$pipelineId] ?? $pipelineId;
    }
}
