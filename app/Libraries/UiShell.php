<?php

namespace App\Libraries;

use App\Models\PipelineModel;
use App\Models\TaskModel;

/**
 * What the web UI's layout (app/Views/web/layout.php) shows around every page: each active
 * pipeline's heartbeat in the sidebar and the task queue's counters on the nav. A few cheap
 * queries per page, read here so no controller has to pass them along.
 */
class UiShell
{
    /**
     * @return array{
     *     pipelines: list<array{name: string, status: string, last_seen_at: ?string}>,
     *     running: int,
     *     pending: int,
     *     version: ?string,
     * }
     */
    public static function data(): array
    {
        $shell = self::status();
        $shell['version'] = self::version();

        return $shell;
    }

    /**
     * The release this is: the top `## X.Y.Z` heading of CHANGELOG.md, where the version lives
     * (see CLAUDE.md, "Versioning"). Null when the file isn't there.
     */
    public static function version(): ?string
    {
        static $version = false;

        if ($version === false) {
            $version = null;
            $file    = ROOTPATH . 'CHANGELOG.md';

            if (is_file($file) && preg_match('/^## (\d+\.\d+\.\d+)\s*$/m', (string) file_get_contents($file), $m) === 1) {
                $version = $m[1];
            }
        }

        return $version;
    }

    /** @return array{pipelines: list<array>, running: int, pending: int} */
    private static function status(): array
    {
        try {
            $pipelines = (new PipelineModel())
                ->select('name, last_seen_at')
                ->where('revoked_at', null)
                ->orderBy('name', 'ASC')
                ->findAll();
        } catch (\Throwable) {
            $pipelines = []; // migrations not run yet
        }

        $rows = [];
        foreach ($pipelines as $p) {
            $rows[] = [
                'name'         => $p['name'],
                'status'       => PipelineModel::statusFor($p['last_seen_at']),
                'last_seen_at' => $p['last_seen_at'],
            ];
        }

        // No pipeline registered yet: the legacy single heartbeat setting.
        if ($rows === []) {
            $lastSeen = db_connect()->table('settings')
                ->where('param', 'pipeline_last_seen_at')
                ->get()->getRowArray()['value'] ?? null;

            $rows[] = [
                'name'         => 'pipeline',
                'status'       => PipelineModel::statusFor($lastSeen),
                'last_seen_at' => $lastSeen,
            ];
        }

        $counts = [];
        foreach ((new TaskModel())
            ->select('status, COUNT(*) AS n')
            ->whereIn('status', ['PENDING', 'RUNNING'])
            ->groupBy('status')
            ->findAll() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }

        return [
            'pipelines' => $rows,
            'running'   => $counts['RUNNING'] ?? 0,
            'pending'   => $counts['PENDING'] ?? 0,
        ];
    }
}
