<?php

namespace App\Commands;

use App\Models\PipelineModel;
use CodeIgniter\CLI\CLI;

/**
 * Revoke a pipeline's API key (decommissioned server, leaked key). Its frames stay assigned to it;
 * `php spark pipeline:rotate <name>` brings it back with a new key.
 *
 * Usage: php spark pipeline:revoke <name>
 */
class PipelineRevoke extends PipelineCommand
{
    protected $name        = 'pipeline:revoke';
    protected $description = 'Revoke a pipeline\'s API key';
    protected $usage       = 'pipeline:revoke <name>';
    protected $arguments   = ['name' => 'Pipeline name'];

    public function run(array $params): void
    {
        $pipeline = $this->pipelineFromArgs($params);

        if ($pipeline === null) {
            return;
        }

        (new PipelineModel())->revoke($pipeline['id']);
        CLI::write("Pipeline '{$pipeline['name']}' revoked: its key is rejected from now on.", 'green');
        CLI::write('Tasks still queued for it wait until it is re-activated (pipeline:rotate).');
    }
}
