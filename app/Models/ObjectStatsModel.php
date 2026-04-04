<?php

namespace App\Models;

/**
 * Model for the `object_stats` table.
 *
 * Stores pre-aggregated statistics for each unique combination of
 * object (target name) and filter. Updated incrementally when frames are added.
 */
class ObjectStatsModel extends BaseModel
{
    protected $table      = 'object_stats';
    protected $primaryKey = 'id';

    protected $useTimestamps = false;

    protected $allowedFields = [
        'id',
        'object',
        'filter',
        'frame_count',
        'total_exposure_sec',
        'first_obs_time',
        'last_obs_time',
        'avg_fwhm',
        'avg_airmass',
    ];

    /**
     * Find stats row by object and filter.
     *
     * @param string      $object Object name
     * @param string|null $filter Filter name (NULL for unfiltered)
     *
     * @return array|null
     */
    public function findByObjectAndFilter(string $object, ?string $filter): ?array
    {
        $builder = $this->where('object', $object);
        
        if ($filter === null) {
            $builder->where('filter IS NULL');
        } else {
            $builder->where('filter', $filter);
        }
        
        return $builder->first();
    }

    /**
     * Increment stats for an object+filter combination.
     * Creates a new row if this is the first frame for this combo.
     *
     * @param string      $object  Object name (e.g., "M51")
     * @param string|null $filter  Filter name (e.g., "L", "Ha", or NULL)
     * @param float       $exptime Exposure time in seconds
     * @param string      $obsTime Observation time (MySQL datetime format)
     * @param float|null  $fwhm    FWHM value (optional)
     * @param float|null  $airmass Airmass value (optional)
     */
    public function incrementStats(
        string $object,
        ?string $filter,
        float $exptime,
        string $obsTime,
        ?float $fwhm = null,
        ?float $airmass = null
    ): void {
        $existing = $this->findByObjectAndFilter($object, $filter);

        if ($existing !== null) {
            // Update existing stats
            $newCount    = (int) $existing['frame_count'] + 1;
            $newTotalExp = (float) $existing['total_exposure_sec'] + $exptime;

            // Running average for FWHM
            $newAvgFwhm = $existing['avg_fwhm'];
            if ($fwhm !== null) {
                if ($existing['avg_fwhm'] !== null) {
                    // Incremental average: new_avg = ((old_avg * old_count) + new_value) / new_count
                    $newAvgFwhm = (($existing['avg_fwhm'] * $existing['frame_count']) + $fwhm) / $newCount;
                } else {
                    $newAvgFwhm = $fwhm;
                }
            }

            // Running average for airmass
            $newAvgAirmass = $existing['avg_airmass'];
            if ($airmass !== null) {
                if ($existing['avg_airmass'] !== null) {
                    $newAvgAirmass = (($existing['avg_airmass'] * $existing['frame_count']) + $airmass) / $newCount;
                } else {
                    $newAvgAirmass = $airmass;
                }
            }

            // Determine first/last observation times
            $firstObs = $existing['first_obs_time'];
            $lastObs  = $existing['last_obs_time'];

            if ($firstObs === null || $obsTime < $firstObs) {
                $firstObs = $obsTime;
            }
            if ($lastObs === null || $obsTime > $lastObs) {
                $lastObs = $obsTime;
            }

            $this->update($existing['id'], [
                'frame_count'        => $newCount,
                'total_exposure_sec' => $newTotalExp,
                'first_obs_time'     => $firstObs,
                'last_obs_time'      => $lastObs,
                'avg_fwhm'           => $newAvgFwhm,
                'avg_airmass'        => $newAvgAirmass,
            ]);
        } else {
            // Create new stats row
            $this->insert([
                'object'             => $object,
                'filter'             => $filter,
                'frame_count'        => 1,
                'total_exposure_sec' => $exptime,
                'first_obs_time'     => $obsTime,
                'last_obs_time'      => $obsTime,
                'avg_fwhm'           => $fwhm,
                'avg_airmass'        => $airmass,
            ]);
        }
    }

    /**
     * Get all stats grouped by object.
     *
     * Returns aggregated data across all filters for each object.
     *
     * @param string|null $objectFilter Optional partial match on object name
     *
     * @return array
     */
    public function getAllObjectsSummary(?string $objectFilter = null): array
    {
        $builder = $this->builder();
        
        if ($objectFilter !== null && $objectFilter !== '') {
            $builder->like('object', $objectFilter);
        }

        $rows = $builder->get()->getResultArray();

        // Group by object
        $grouped = [];
        foreach ($rows as $row) {
            $obj = $row['object'];
            if (!isset($grouped[$obj])) {
                $grouped[$obj] = [
                    'object'             => $obj,
                    'total_frames'       => 0,
                    'total_exposure_sec' => 0.0,
                    'filters'            => [],
                    'first_obs_time'     => null,
                    'last_obs_time'      => null,
                ];
            }

            $grouped[$obj]['total_frames']       += (int) $row['frame_count'];
            $grouped[$obj]['total_exposure_sec'] += (float) $row['total_exposure_sec'];

            // Track filters
            $filterName = $row['filter'] ?? '(unfiltered)';
            if (!in_array($filterName, $grouped[$obj]['filters'], true)) {
                $grouped[$obj]['filters'][] = $filterName;
            }

            // Track first/last obs times
            if ($row['first_obs_time'] !== null) {
                if ($grouped[$obj]['first_obs_time'] === null || $row['first_obs_time'] < $grouped[$obj]['first_obs_time']) {
                    $grouped[$obj]['first_obs_time'] = $row['first_obs_time'];
                }
            }
            if ($row['last_obs_time'] !== null) {
                if ($grouped[$obj]['last_obs_time'] === null || $row['last_obs_time'] > $grouped[$obj]['last_obs_time']) {
                    $grouped[$obj]['last_obs_time'] = $row['last_obs_time'];
                }
            }
        }

        // Convert to indexed array and add hours calculation
        $result = [];
        foreach ($grouped as $data) {
            $data['total_exposure_hours'] = round($data['total_exposure_sec'] / 3600.0, 2);
            
            // Sort filters alphabetically, but keep (unfiltered) at end
            $filters = $data['filters'];
            usort($filters, function ($a, $b) {
                if ($a === '(unfiltered)') return 1;
                if ($b === '(unfiltered)') return -1;
                return strcmp($a, $b);
            });
            $data['filters'] = $filters;
            
            $result[] = $data;
        }

        // Sort by object name
        usort($result, fn($a, $b) => strcmp($a['object'], $b['object']));

        return $result;
    }

    /**
     * Get detailed stats for a specific object, broken down by filter.
     *
     * @param string $object Object name
     *
     * @return array|null Null if object not found
     */
    public function getObjectDetail(string $object): ?array
    {
        $rows = $this->where('object', $object)->findAll();

        if (empty($rows)) {
            return null;
        }

        // Calculate summary
        $totalFrames    = 0;
        $totalExposure  = 0.0;
        $firstObs       = null;
        $lastObs        = null;
        $byFilter       = [];

        foreach ($rows as $row) {
            $totalFrames   += (int) $row['frame_count'];
            $totalExposure += (float) $row['total_exposure_sec'];

            if ($row['first_obs_time'] !== null) {
                if ($firstObs === null || $row['first_obs_time'] < $firstObs) {
                    $firstObs = $row['first_obs_time'];
                }
            }
            if ($row['last_obs_time'] !== null) {
                if ($lastObs === null || $row['last_obs_time'] > $lastObs) {
                    $lastObs = $row['last_obs_time'];
                }
            }

            $byFilter[] = [
                'filter'             => $row['filter'],
                'frame_count'        => (int) $row['frame_count'],
                'total_exposure_sec' => (float) $row['total_exposure_sec'],
                'avg_fwhm'           => $row['avg_fwhm'] !== null ? round((float) $row['avg_fwhm'], 2) : null,
                'avg_airmass'        => $row['avg_airmass'] !== null ? round((float) $row['avg_airmass'], 2) : null,
                'first_obs_time'     => $row['first_obs_time'],
                'last_obs_time'      => $row['last_obs_time'],
            ];
        }

        // Sort by_filter: alphabetically, NULL filter at end
        usort($byFilter, function ($a, $b) {
            if ($a['filter'] === null) return 1;
            if ($b['filter'] === null) return -1;
            return strcmp($a['filter'], $b['filter']);
        });

        return [
            'object'  => $object,
            'summary' => [
                'total_frames'         => $totalFrames,
                'total_exposure_sec'   => $totalExposure,
                'total_exposure_hours' => round($totalExposure / 3600.0, 2),
                'first_obs_time'       => $firstObs,
                'last_obs_time'        => $lastObs,
            ],
            'by_filter' => $byFilter,
        ];
    }
}

