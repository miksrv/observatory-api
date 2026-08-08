<?php

namespace App\Models;

use App\Libraries\SkyMath;

/**
 * Model for the `source_observations` table (Photometry History).
 *
 * Stores time-varying measurements of each source from individual frames.
 * This is the key table for analyzing variability, light curves, etc.
 *
 * Also the only table carrying per-epoch (ra, dec) for a source now that
 * `sources` no longer has its own ra/dec columns (see SourceModel docblock)
 * — so the positional-search methods below (used for dedup fallback and
 * the /sources/near endpoints) query this table directly.
 */
class SourceObservationModel extends BaseModel
{
    protected $table      = 'source_observations';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    protected $allowedFields = [
        'id',
        'source_id',
        'frame_id',
        'ra',
        'dec',
        'mag',
        'mag_err',
        'flux',
        'flux_err',
        'fwhm',
        'snr',
        'elongation',
        'saturated',
        'from_subtraction',
        'obs_time',
    ];

    /**
     * Get all observations for a source, ordered by observation time.
     *
     * @param string      $sourceId  Source ID
     * @param string|null $fromTime  Optional: only observations after this time (MySQL datetime)
     * @param string|null $toTime    Optional: only observations before this time (MySQL datetime)
     * @param int         $limit     Max observations to return (default 1000)
     *
     * @return array Array of observation records
     */
    public function getObservationsForSource(
        string $sourceId,
        ?string $fromTime = null,
        ?string $toTime = null,
        int $limit = 1000
    ): array {
        $builder = $this->where('source_id', $sourceId);

        if ($fromTime !== null) {
            $builder->where('obs_time >=', $fromTime);
        }

        if ($toTime !== null) {
            $builder->where('obs_time <', $toTime);
        }

        return $builder->orderBy('obs_time', 'ASC')
            ->limit($limit)
            ->findAll();
    }

    /**
     * Get all observations for a frame.
     *
     * @param string $frameId Frame ID
     *
     * @return array Array of observation records
     */
    public function getObservationsForFrame(string $frameId): array
    {
        return $this->where('frame_id', $frameId)
            ->findAll();
    }

    /**
     * Count `source_observations` rows per frame, for a batch of frame ids — i.e. how many of
     * each frame's detected stars actually got recognized/persisted as a source measurement
     * (vs `frames.qc_star_count`, the pipeline's raw detection count before catalog matching).
     * Used by the /ui/frames "Stars" column (всего/распознано); one query for the whole page
     * instead of one per row.
     *
     * @param string[] $frameIds
     *
     * @return array<string, int> Keyed by frame_id; frames with zero observations are absent.
     */
    public function countByFrameIds(array $frameIds): array
    {
        if ($frameIds === []) {
            return [];
        }

        $rows = $this->select('frame_id, COUNT(*) AS cnt')
            ->whereIn('frame_id', $frameIds)
            ->groupBy('frame_id')
            ->findAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['frame_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Get the most recent observation for a source — used as its "current"
     * position, since `sources` itself no longer stores ra/dec (a static
     * first-detection snapshot would be actively misleading for anything
     * that moves, e.g. an MPC-matched asteroid).
     *
     * @param string $sourceId Source ID
     *
     * @return array|null Most recent observation record, or null if the
     *                     source has no observations (shouldn't normally
     *                     happen — a source is only ever created alongside
     *                     its first observation).
     */
    public function getLatestObservation(string $sourceId): ?array
    {
        return $this->where('source_id', $sourceId)
            ->orderBy('obs_time', 'DESC')
            ->first();
    }

    /**
     * Batch version of getLatestObservation() — one query for a whole page of sources instead of
     * N+1 per row. Used by the /ui/sources listing to show each source's current position (ra/dec)
     * without a per-source round trip.
     *
     * Fetches every observation row for the given source ids ordered so each source's rows are
     * grouped together with its most recent obs_time first, then keeps only the first row seen per
     * source_id in PHP — MariaDB has no simple "latest row per group" without window functions, and
     * nothing else in this codebase relies on those yet.
     *
     * @param string[] $sourceIds
     *
     * @return array<string, array> Keyed by source_id; sources with no observations are absent.
     */
    public function getLatestObservationsForSources(array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $rows = $this->whereIn('source_id', $sourceIds)
            ->orderBy('source_id', 'ASC')
            ->orderBy('obs_time', 'DESC')
            ->findAll();

        $latest = [];
        foreach ($rows as $row) {
            if (! isset($latest[$row['source_id']])) {
                $latest[$row['source_id']] = $row;
            }
        }

        return $latest;
    }

    /**
     * Find every observation within radius of (ra, dec), nearest-first,
     * regardless of which source it belongs to.
     *
     * Uses a bounding-box pre-filter then Haversine for precise matching —
     * same approach as SourceModel used against `sources` before ra/dec
     * moved here.
     *
     * @param float $ra           RA in degrees
     * @param float $dec          Dec in degrees
     * @param float $radiusArcsec Search radius in arcseconds
     *
     * @return array List of ['source_id', 'ra', 'dec', 'obs_time', 'distance_arcsec'],
     *                sorted by distance ascending
     */
    private function matchesWithinRadius(float $ra, float $dec, float $radiusArcsec): array
    {
        $matches = [];

        foreach ($this->boundingBoxCandidates($ra, $dec, $radiusArcsec / 3600.0) as $obs) {
            $distance = SkyMath::haversineArcsec($ra, $dec, (float) $obs['ra'], (float) $obs['dec']);

            if ($distance <= $radiusArcsec) {
                $matches[] = [
                    'source_id'       => $obs['source_id'],
                    'ra'              => (float) $obs['ra'],
                    'dec'             => (float) $obs['dec'],
                    'obs_time'        => $obs['obs_time'],
                    'distance_arcsec' => $distance,
                ];
            }
        }

        usort($matches, static fn (array $a, array $b): int => $a['distance_arcsec'] <=> $b['distance_arcsec']);

        return $matches;
    }

    /**
     * Find the source_id of the nearest observation within radius of
     * (ra, dec) — the positional dedup fallback for sources with no
     * catalog identity to match on (see SourceModel::findByCoordinates()).
     *
     * @return string|null Nearest matching source_id, or null if none within radius
     */
    public function findNearestSourceId(float $ra, float $dec, float $radiusArcsec): ?string
    {
        $matches = $this->matchesWithinRadius($ra, $dec, $radiusArcsec);

        return $matches[0]['source_id'] ?? null;
    }

    /**
     * Cone search deduplicated to one (nearest) match per distinct source_id
     * — the "which known sources are near this position" query used by
     * SourceModel::coneSearch() / GET /sources/near. Unlike
     * findNearestSourceId(), returns every distinct source within radius,
     * not just the closest.
     *
     * @return array List of ['source_id', 'ra', 'dec', 'obs_time', 'distance_arcsec'],
     *               nearest-first, one entry per source_id
     */
    public function coneSearchDistinctSources(float $ra, float $dec, float $radiusArcsec): array
    {
        $seen    = [];
        $results = [];

        foreach ($this->matchesWithinRadius($ra, $dec, $radiusArcsec) as $match) {
            if (isset($seen[$match['source_id']])) {
                continue;
            }

            $seen[$match['source_id']] = true;
            $results[]                 = $match;
        }

        return $results;
    }

    /**
     * Bounding-box pre-filter on (ra, dec), corrected for two effects a naive
     * `ra BETWEEN ra-margin AND ra+margin` clause gets wrong:
     *
     *  - Declination scaling: the RA margin is widened by 1/cos(dec) so a
     *    fixed angular radius is still fully covered near the poles, where
     *    meridians converge and the same angular distance spans more RA
     *    degrees.
     *  - RA=0/360 seam: the RA window is split into two ranges (OR'd) when
     *    it straddles the seam, so e.g. a query at ra=0.001 still matches a
     *    source at ra=359.999.
     *
     * Callers apply the exact Haversine filter afterwards.
     *
     * @param float $ra        RA in degrees
     * @param float $dec       Dec in degrees
     * @param float $marginDeg Angular margin in degrees (radius, not diameter)
     *
     * @return array Candidate observation records
     */
    private function boundingBoxCandidates(float $ra, float $dec, float $marginDeg): array
    {
        $raMargin = SkyMath::raMargin($dec, $marginDeg);
        $raRanges = SkyMath::raRanges($ra, $raMargin);

        $builder = $this->groupStart();

        foreach ($raRanges as $i => [$min, $max]) {
            if ($i === 0) {
                $builder->where('ra >=', $min)->where('ra <=', $max);
            } else {
                $builder->orGroupStart()->where('ra >=', $min)->where('ra <=', $max)->groupEnd();
            }
        }

        return $builder->groupEnd()
            ->where('dec >=', $dec - $marginDeg)
            ->where('dec <=', $dec + $marginDeg)
            ->findAll();
    }
}

