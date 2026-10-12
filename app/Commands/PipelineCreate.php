<?php

namespace App\Commands;

use App\Libraries\PipelineIdentity;
use App\Models\PipelineModel;
use CodeIgniter\CLI\CLI;

/**
 * Register a new observatory-pipeline client and print its API key.
 *
 * Usage: php spark pipeline:create <name>
 */
class PipelineCreate extends PipelineCommand
{
    protected $name        = 'pipeline:create';
    protected $description = 'Register a new pipeline (observatory server) and issue its API key';
    protected $usage       = 'pipeline:create <name>';
    protected $arguments   = ['name' => 'Unique display name, 1-64 chars: letters, digits, ".", "_", "-"'];

    public function run(array $params): void
    {
        $name = $params[0] ?? '';

        if (! PipelineIdentity::isValidName($name)) {
            CLI::error('Usage: php spark ' . $this->usage . ' — the name must be 1-64 characters: letters, digits, ".", "_", "-"');

            return;
        }

        $model = new PipelineModel();

        if ($model->findByName($name) !== null) {
            CLI::error("A pipeline named '{$name}' already exists. To issue it a new key: php spark pipeline:rotate {$name}");

            return;
        }

        $created = $model->createWithKey($name);
        CLI::write("Pipeline '{$name}' registered (id {$created['pipeline']['id']}).", 'green');
        $this->printKey($name, $created['key']);
    }
}
