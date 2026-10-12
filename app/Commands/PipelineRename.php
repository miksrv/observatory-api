<?php

namespace App\Commands;

use App\Libraries\PipelineIdentity;
use App\Models\PipelineModel;
use CodeIgniter\CLI\CLI;

/**
 * Rename a pipeline. Only the display name changes — frames and tasks reference its id, and its
 * key keeps working.
 *
 * Usage: php spark pipeline:rename <name> <new-name>
 */
class PipelineRename extends PipelineCommand
{
    protected $name        = 'pipeline:rename';
    protected $description = 'Rename a pipeline (its key, frames and tasks are unaffected)';
    protected $usage       = 'pipeline:rename <name> <new-name>';
    protected $arguments   = ['name' => 'Current name', 'new-name' => 'New name'];

    public function run(array $params): void
    {
        $pipeline = $this->pipelineFromArgs($params);

        if ($pipeline === null) {
            return;
        }

        $newName = $params[1] ?? '';
        $model   = new PipelineModel();

        if (! PipelineIdentity::isValidName($newName)) {
            CLI::error('The new name must be 1-64 characters: letters, digits, ".", "_", "-"');

            return;
        }

        if ($model->findByName($newName) !== null) {
            CLI::error("A pipeline named '{$newName}' already exists.");

            return;
        }

        $model->update($pipeline['id'], ['name' => $newName]);
        CLI::write("Pipeline '{$pipeline['name']}' renamed to '{$newName}'.", 'green');
    }
}
