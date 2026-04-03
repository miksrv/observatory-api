<?php

namespace App\Commands;

use App\Models\ObjectStatsModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * CLI command to recalculate all object statistics from scratch.
 *
 * Useful when:
 * - Fixing corrupted statistics
 * - After bulk imports
 * - After schema changes
 *
 * Usage: php spark recalculate:object-stats
 */
class RecalculateObjectStats extends BaseCommand
{
    /**
     * The Command's Group
     */
    protected $group = 'App';

    /**
     * The Command's Name
     */
    protected $name = 'recalculate:object-stats';

    /**
     * The Command's Description
     */
    protected $description = 'Recalculate all object statistics from frames table';

    /**
     * The Command's Usage
     */
    protected $usage = 'recalculate:object-stats [--dry-run]';

    /**
     * The Command's Arguments
     */
    protected $arguments = [];

    /**
     * The Command's Options
     */
    protected $options = [
        '--dry-run' => 'Show what would be done without making changes',
    ];

    /**
     * Actually execute the command.
     */
    public function run(array $params): void
    {
        $dryRun = CLI::getOption('dry-run') !== null;

        if ($dryRun) {
            CLI::write('DRY RUN MODE — no changes will be made', 'yellow');
            CLI::newLine();
        }

        $db = Database::connect();

        // ----------------------------------------------------------------
        // Step 1: Truncate object_stats table
        // ----------------------------------------------------------------
        if (!$dryRun) {
            CLI::write('Truncating object_stats table...', 'yellow');
            $db->table('object_stats')->truncate();
            CLI::write('Done.', 'green');
        } else {
            CLI::write('[DRY RUN] Would truncate object_stats table', 'light_gray');
        }

        CLI::newLine();

        // ----------------------------------------------------------------
        // Step 2: Fetch all frames with object set
        // ----------------------------------------------------------------
        CLI::write('Fetching frames with object names...', 'yellow');

        $frames = $db->table('frames')
            ->select('object, filter, exptime, obs_time, qc_fwhm_median, airmass')
            ->whereNotNull('object')
            ->where('object !=', '')
            ->orderBy('obs_time', 'ASC')
            ->get()
            ->getResultArray();

        $totalFrames = count($frames);
        CLI::write("Found {$totalFrames} frames with object names.", 'green');
        CLI::newLine();

        if ($totalFrames === 0) {
            CLI::write('No frames to process. Done!', 'green');
            return;
        }

        // ----------------------------------------------------------------
        // Step 3: Process each frame
        // ----------------------------------------------------------------
        CLI::write('Processing frames...', 'yellow');

        $statsModel = new ObjectStatsModel();
        $processed  = 0;
        $stats      = []; // Track stats for dry-run display

        foreach ($frames as $frame) {
            $object  = $frame['object'];
            $filter  = $frame['filter'];
            $exptime = (float) ($frame['exptime'] ?? 0);
            $obsTime = $frame['obs_time'];
            $fwhm    = $frame['qc_fwhm_median'] !== null ? (float) $frame['qc_fwhm_median'] : null;
            $airmass = $frame['airmass'] !== null ? (float) $frame['airmass'] : null;

            if (!$dryRun) {
                $statsModel->incrementStats($object, $filter, $exptime, $obsTime, $fwhm, $airmass);
            } else {
                // Track for dry-run display
                $key = $object . '|' . ($filter ?? 'NULL');
                if (!isset($stats[$key])) {
                    $stats[$key] = [
                        'object'      => $object,
                        'filter'      => $filter,
                        'frame_count' => 0,
                        'total_exp'   => 0.0,
                    ];
                }
                $stats[$key]['frame_count']++;
                $stats[$key]['total_exp'] += $exptime;
            }

            $processed++;

            // Progress indicator every 100 frames
            if ($processed % 100 === 0) {
                CLI::showProgress($processed, $totalFrames);
            }
        }

        CLI::showProgress($totalFrames, $totalFrames);
        CLI::newLine();
        CLI::newLine();

        // ----------------------------------------------------------------
        // Step 4: Show summary
        // ----------------------------------------------------------------
        if ($dryRun) {
            CLI::write('DRY RUN — Would create the following stats:', 'yellow');
            CLI::newLine();

            // Sort by object name
            uasort($stats, fn($a, $b) => strcmp($a['object'], $b['object']));

            foreach ($stats as $stat) {
                $filterDisplay = $stat['filter'] ?? '(unfiltered)';
                $hours         = round($stat['total_exp'] / 3600.0, 2);
                CLI::write(sprintf(
                    "  %s [%s]: %d frames, %.1f sec (%.2f hours)",
                    $stat['object'],
                    $filterDisplay,
                    $stat['frame_count'],
                    $stat['total_exp'],
                    $hours
                ), 'light_gray');
            }

            CLI::newLine();
            CLI::write('Total unique object+filter combinations: ' . count($stats), 'yellow');
        } else {
            // Show actual stats from database
            $statsCount = $db->table('object_stats')->countAllResults();
            $objectCount = $db->table('object_stats')->select('object')->distinct()->countAllResults();
            
            CLI::write("Created {$statsCount} stats rows for {$objectCount} unique objects.", 'green');
        }

        CLI::newLine();
        CLI::write("Processed {$processed} frames.", 'green');
        CLI::write('Done!', 'green');
    }
}

