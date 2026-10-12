<?php

namespace App\Commands;

use App\Models\PipelineModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Shared base for the `pipeline:*` commands that manage observatory-pipeline clients and their API
 * keys (see PipelineModel). Keys are issued here, on the API server's command line, and never
 * through the unauthenticated debug UI.
 */
abstract class PipelineCommand extends BaseCommand
{
    protected $group = 'Pipelines';

    /** The pipeline named by the first argument, or null after printing why not. */
    protected function pipelineFromArgs(array $params): ?array
    {
        $name = $params[0] ?? null;

        if ($name === null || $name === '') {
            CLI::error('Usage: php spark ' . $this->usage);

            return null;
        }

        $pipeline = (new PipelineModel())->findByName($name);

        if ($pipeline === null) {
            CLI::error("No pipeline named '{$name}'. See: php spark pipeline:list");
        }

        return $pipeline;
    }

    /** Print a freshly issued key with the one-time warning. */
    protected function printKey(string $name, string $key): void
    {
        CLI::newLine();
        CLI::write("API key for '{$name}' (shown ONCE — only its hash is stored):", 'yellow');
        CLI::write($key, 'green');
        CLI::newLine();
        CLI::write("Put it into that server's observatory-pipeline .env as API_KEY=..., then:");
        CLI::write('  docker compose restart pipeline worker');
    }
}
