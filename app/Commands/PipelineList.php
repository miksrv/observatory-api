<?php

namespace App\Commands;

use App\Models\PipelineModel;
use CodeIgniter\CLI\CLI;

/**
 * List every registered pipeline with its heartbeat.
 *
 * Usage: php spark pipeline:list
 */
class PipelineList extends PipelineCommand
{
    protected $name        = 'pipeline:list';
    protected $description = 'List registered pipelines and when each was last seen';
    protected $usage       = 'pipeline:list';

    public function run(array $params): void
    {
        $rows = [];

        foreach ((new PipelineModel())->getOverview() as $p) {
            $rows[] = [$p['name'], $p['status'], $p['last_seen_at'] ?? '—', $p['last_ip'] ?? '—', $p['version'] ?? '—', $p['frame_count'], $p['pending_own']];
        }

        if ($rows === []) {
            CLI::write('No pipelines registered. Create one: php spark pipeline:create <name>');

            return;
        }

        CLI::table($rows, ['Name', 'Status', 'Last seen (UTC)', 'Last IP', 'Version', 'Frames', 'Queued']);
    }
}
