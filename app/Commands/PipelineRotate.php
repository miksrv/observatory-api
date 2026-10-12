<?php

namespace App\Commands;

use App\Models\PipelineModel;
use CodeIgniter\CLI\CLI;

/**
 * Issue a pipeline a new API key; the old one stops working at once. Also re-activates a revoked
 * pipeline. Its frames and tasks stay its own (same id).
 *
 * Usage: php spark pipeline:rotate <name>
 */
class PipelineRotate extends PipelineCommand
{
    protected $name        = 'pipeline:rotate';
    protected $description = 'Replace a pipeline\'s API key (the old key stops working immediately)';
    protected $usage       = 'pipeline:rotate <name>';
    protected $arguments   = ['name' => 'Pipeline name'];

    public function run(array $params): void
    {
        $pipeline = $this->pipelineFromArgs($params);

        if ($pipeline === null) {
            return;
        }

        $key = (new PipelineModel())->rotateKey($pipeline['id']);
        CLI::write("New key issued for '{$pipeline['name']}'; the previous key no longer works.", 'green');
        $this->printKey($pipeline['name'], $key);
    }
}
