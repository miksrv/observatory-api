<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\SkyMath;
use App\Models\FrameModel;
use App\Models\FrameSourceModel;
use App\Models\SourceChartModel;
use App\Models\SourceModel;
use App\Models\SourceObservationModel;
use CodeIgniter\HTTP\ResponseInterface;

class SourcesController extends BaseApiController
{
    /**
     * GET /api/v1/sources/near
     *
     * Cone search for sources near a sky position.
     * Uses a bounding-box pre-filter on indexed (ra, dec) columns in
     * `source_observations` (the only table with per-epoch positions —
     * `sources` has none of its own, see SourceModel), then applies the
     * Haversine formula in PHP for precise distance filtering.
     */
    public function near(): ResponseInterface
    {
        $ra           = $this->request->getGet('ra');
        $dec          = $this->request->getGet('dec');
        $radiusArcsec = $this->request->getGet('radius_arcsec');

        // ----------------------------------------------------------------
        // Presence check — ra, dec, radius_arcsec are required
        // ----------------------------------------------------------------
        $missing = [];

        if ($ra === null || $ra === '')           { $missing[] = 'ra'; }
        if ($dec === null || $dec === '')         { $missing[] = 'dec'; }
        if ($radiusArcsec === null || $radiusArcsec === '') { $missing[] = 'radius_arcsec'; }

        if (! empty($missing)) {
            return $this->respondError(400, 'Missing required query parameters', ['missing' => $missing]);
        }

        // ----------------------------------------------------------------
        // Numeric type validation
        // ----------------------------------------------------------------
        if (! is_numeric($ra)) {
            return $this->respondError(400, 'Invalid parameter: ra must be numeric');
        }

        if (! is_numeric($dec)) {
            return $this->respondError(400, 'Invalid parameter: dec must be numeric');
        }

        if (! is_numeric($radiusArcsec)) {
            return $this->respondError(400, 'Invalid parameter: radius_arcsec must be numeric');
        }

        $ra           = (float) $ra;
        $dec          = (float) $dec;
        $radiusArcsec = (float) $radiusArcsec;

        // ----------------------------------------------------------------
        // Cone search — bounding-box pre-filter (declination-scaled RA
        // margin, RA=0/360 seam handled) then exact Haversine, both inside
        // SourceModel so this logic lives in exactly one place.
        // ----------------------------------------------------------------
        $sourceModel = new SourceModel();
        $matches     = $sourceModel->coneSearch($ra, $dec, $radiusArcsec);

        $results = [];

        foreach ($matches as $source) {
            $results[] = [
                'id'                => $source['id'],
                'ra'                => (float) $source['ra'],
                'dec'               => (float) $source['dec'],
                'catalog_name'      => $source['catalog_name'],
                'catalog_id'        => $source['catalog_id'],
                'object_type'       => $source['object_type'],
                'observation_count' => (int) $source['observation_count'],
                'last_observed_at'  => $source['last_observed_at']
                    ? gmdate('Y-m-d\TH:i:s\Z', strtotime($source['last_observed_at']))
                    : null,
            ];
        }

        return $this->respondOk(['data' => $results]);
    }

    /**
     * GET /api/v1/sources/{id}/observations
     *
     * Get the observation history (light curve data) for a specific source.
     *
     * Query parameters:
     *   from_time  string  ISO 8601 — observations after this time (optional)
     *   to_time    string  ISO 8601 — observations before this time (optional)
     *   limit      int     Max observations to return (default 1000)
     */
    public function observations(string $id): ResponseInterface
    {
        // ----------------------------------------------------------------
        // Verify source exists
        // ----------------------------------------------------------------
        $sourceModel = new SourceModel();
        $source      = $sourceModel->find($id);

        if ($source === null) {
            return $this->respondError(404, 'Source not found', ['source_id' => $id]);
        }

        // ----------------------------------------------------------------
        // Parse optional query parameters
        // ----------------------------------------------------------------
        $fromTime = $this->request->getGet('from_time');
        $toTime   = $this->request->getGet('to_time');
        $limit    = $this->request->getGet('limit');

        $fromMysql = null;
        $toMysql   = null;
        $limitInt  = 1000;

        if ($fromTime !== null && $fromTime !== '') {
            $timestamp = strtotime($fromTime);
            if ($timestamp !== false) {
                $fromMysql = date('Y-m-d H:i:s', $timestamp);
            }
        }

        if ($toTime !== null && $toTime !== '') {
            $timestamp = strtotime($toTime);
            if ($timestamp !== false) {
                $toMysql = date('Y-m-d H:i:s', $timestamp);
            }
        }

        if ($limit !== null && is_numeric($limit) && (int) $limit > 0) {
            $limitInt = min((int) $limit, 10000); // Cap at 10k
        }

        // ----------------------------------------------------------------
        // Get observations
        // ----------------------------------------------------------------
        $observationModel = new SourceObservationModel();
        $observations     = $observationModel->getObservationsForSource($id, $fromMysql, $toMysql, $limitInt);

        // `sources` has no ra/dec of its own (see SourceModel docblock) —
        // use the most recent observation as the source's "current" position.
        $latestObs = $observationModel->getLatestObservation($id);

        // Format observations
        $formattedObs = [];
        foreach ($observations as $obs) {
            $formattedObs[] = [
                'frame_id'   => $obs['frame_id'],
                'obs_time'   => gmdate('Y-m-d\TH:i:s\Z', strtotime($obs['obs_time'])),
                'mag'        => $obs['mag'] !== null ? (float) $obs['mag'] : null,
                'mag_err'    => $obs['mag_err'] !== null ? (float) $obs['mag_err'] : null,
                'flux'       => $obs['flux'] !== null ? (float) $obs['flux'] : null,
                'fwhm'       => $obs['fwhm'] !== null ? (float) $obs['fwhm'] : null,
                'snr'        => $obs['snr'] !== null ? (float) $obs['snr'] : null,
            ];
        }

        return $this->respondOk([
            'source' => [
                'id'           => $source['id'],
                'ra'           => $latestObs !== null ? (float) $latestObs['ra'] : null,
                'dec'          => $latestObs !== null ? (float) $latestObs['dec'] : null,
                'catalog_name' => $source['catalog_name'],
                'object_type'  => $source['object_type'],
            ],
            'observations' => $formattedObs,
        ]);
    }

    /**
     * GET /api/v1/sources/{id}/frames
     *
     * Get all frames that contain a specific source.
     */
    public function frames(string $id): ResponseInterface
    {
        // ----------------------------------------------------------------
        // Verify source exists
        // ----------------------------------------------------------------
        $sourceModel = new SourceModel();
        $source      = $sourceModel->find($id);

        if ($source === null) {
            return $this->respondError(404, 'Source not found', ['source_id' => $id]);
        }

        // ----------------------------------------------------------------
        // Get linked frames
        // ----------------------------------------------------------------
        $frameSourceModel = new FrameSourceModel();
        $frameIds         = $frameSourceModel->getFrameIdsForSource($id);

        if (empty($frameIds)) {
            return $this->respondOk([
                'source_id' => $id,
                'data'      => [],
            ]);
        }

        // ----------------------------------------------------------------
        // Fetch frame details
        // ----------------------------------------------------------------
        $frameModel = new FrameModel();
        $frames     = $frameModel->whereIn('id', $frameIds)
            ->orderBy('obs_time', 'ASC')
            ->findAll();

        $formattedFrames = [];
        foreach ($frames as $frame) {
            $formattedFrames[] = [
                'frame_id'   => $frame['id'],
                'filename'   => $frame['filename'],
                'obs_time'   => gmdate('Y-m-d\TH:i:s\Z', strtotime($frame['obs_time'])),
                'ra_center'  => (float) $frame['ra_center'],
                'dec_center' => (float) $frame['dec_center'],
            ];
        }

        return $this->respondOk([
            'source_id' => $id,
            'data'      => $formattedFrames,
        ]);
    }

    /**
     * POST /api/v1/sources/near/batch
     *
     * Batch cone search for sources near multiple sky positions.
     * Returns historical observations (mag, flux, frame_id, obs_time) for anomaly detection.
     * Reduces API calls from O(N) to O(1) when processing frames with many sources.
     */
    public function nearBatch(): ResponseInterface
    {
        $body = $this->request->getJSON(true);

        if (! is_array($body)) {
            return $this->respondError(400, 'Request body must be valid JSON');
        }

        // ----------------------------------------------------------------
        // Validate required fields
        // ----------------------------------------------------------------
        if (! isset($body['positions']) || ! is_array($body['positions'])) {
            return $this->respondError(400, 'Missing required field: positions (must be an array)');
        }

        if (! isset($body['radius_arcsec']) || ! is_numeric($body['radius_arcsec'])) {
            return $this->respondError(400, 'Missing or invalid required field: radius_arcsec');
        }

        $positions    = $body['positions'];
        $radiusArcsec = (float) $body['radius_arcsec'];
        $beforeTime   = $body['before_time'] ?? null;

        // Parse before_time if provided
        $beforeMysql = null;
        if ($beforeTime !== null && $beforeTime !== '') {
            $timestamp = strtotime($beforeTime);
            if ($timestamp === false) {
                return $this->respondError(400, 'Invalid before_time format');
            }
            $beforeMysql = date('Y-m-d H:i:s', $timestamp);
        }

        // ----------------------------------------------------------------
        // Short-circuit for empty positions
        // ----------------------------------------------------------------
        if (count($positions) === 0) {
            return $this->respondOk(['results' => new \stdClass()]);
        }

        // ----------------------------------------------------------------
        // Validate positions and compute a bounding box that safely covers
        // all of them:
        //   - RA margin is declination-scaled per position (SkyMath::raMargin)
        //     so the box doesn't under-cover near the poles.
        //   - The RA span is computed via SkyMath::combinedRaRanges, which is
        //     safe if the batch straddles the RA=0/360 seam (a plain
        //     min/max over raw RA breaks down there — see its docblock).
        // ----------------------------------------------------------------
        $deg = $radiusArcsec / 3600.0;

        $minDec        = PHP_FLOAT_MAX;
        $maxDec        = -PHP_FLOAT_MAX;
        $raWithMargins = [];

        foreach ($positions as $i => $pos) {
            if (! isset($pos['ra']) || ! isset($pos['dec']) ||
                ! is_numeric($pos['ra']) || ! is_numeric($pos['dec'])) {
                return $this->respondError(400, "Invalid position at index {$i}: ra and dec must be numeric");
            }

            $ra  = (float) $pos['ra'];
            $dec = (float) $pos['dec'];

            $minDec = min($minDec, $dec);
            $maxDec = max($maxDec, $dec);

            $raWithMargins[] = [$ra, SkyMath::raMargin($dec, $deg)];
        }

        $minDec -= $deg;
        $maxDec += $deg;

        $raRanges = SkyMath::combinedRaRanges($raWithMargins);

        // ----------------------------------------------------------------
        // Query source_observations, joined with frames to pull each
        // observation's filter (source_observations itself has no filter
        // column — only the frame it came from does). The pipeline's
        // anomaly_detector.py needs this to restrict its historical Δmag
        // comparison to same-filter detections: a star's brightness in
        // filter R differs from filter G/Gaia's broadband G purely from its
        // color (a "color term"), independent of any real change, so
        // comparing magnitudes across filters is not a valid variability
        // signal. A LEFT JOIN (not INNER) so a source_observations row
        // whose frame was since deleted still comes back with filter=null
        // instead of silently disappearing from the history results.
        // ----------------------------------------------------------------
        $db = \Config\Database::connect();

        $raClauses = [];
        $params    = [];

        foreach ($raRanges as [$min, $max]) {
            $raClauses[] = 'so.ra BETWEEN ? AND ?';
            $params[]    = $min;
            $params[]    = $max;
        }

        $sql = 'SELECT so.id, so.source_id, so.frame_id, so.ra, so.dec, so.mag, so.flux, so.fwhm, so.obs_time, f.filter AS filter
                FROM source_observations so
                LEFT JOIN frames f ON f.id = so.frame_id
                WHERE (' . implode(' OR ', $raClauses) . ')
                  AND so.dec BETWEEN ? AND ?';
        $params[] = $minDec;
        $params[] = $maxDec;

        if ($beforeMysql !== null) {
            $sql .= ' AND so.obs_time < ?';
            $params[] = $beforeMysql;
        }

        $candidates = $db->query($sql, $params)->getResultArray();


        // ----------------------------------------------------------------
        // For each position, filter candidates using Haversine
        // ----------------------------------------------------------------
        $results = [];
        $totalMatches = 0;

        foreach ($positions as $i => $pos) {
            $ra  = (float) $pos['ra'];
            $dec = (float) $pos['dec'];
            $posResults = [];

            foreach ($candidates as $obs) {
                $distance = SkyMath::haversineArcsec($ra, $dec, (float) $obs['ra'], (float) $obs['dec']);

                if ($distance <= $radiusArcsec) {
                    $posResults[] = [
                        'ra'       => (float) $obs['ra'],
                        'dec'      => (float) $obs['dec'],
                        'mag'      => $obs['mag'] !== null ? (float) $obs['mag'] : null,
                        'flux'     => $obs['flux'] !== null ? (float) $obs['flux'] : null,
                        'frame_id' => $obs['frame_id'],
                        'obs_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime($obs['obs_time'])),
                        'filter'   => $obs['filter'],
                    ];
                }
            }

            $results[(string) $i] = $posResults;
            $totalMatches += count($posResults);
        }

        // PHP re-canonicalizes numeric-string keys like "0", "1", ... back to
        // int, so $results ends up with plain sequential int keys despite the
        // (string) cast above — json_encode() then serializes it as a JSON
        // array ([...]), not the {"0": ..., "1": ...} object documented in
        // API.md and expected by the pipeline's api_client/client.py. Casting
        // to object forces the intended object encoding regardless of key type.
        return $this->respondOk(['results' => (object) $results]);
    }

    /**
     * GET /api/v1/sources/{id}/track
     *
     * Get the per-epoch position track for a source: every frame the source
     * was observed on, chronologically, with the position it was actually
     * detected at on that specific frame (source_observations.ra/dec — a
     * moving object's position differs epoch to epoch) plus enough frame
     * metadata (filename/object) for a caller with filesystem access to the
     * observatory's FITS archive to locate the file locally.
     *
     * Used by observatory-pipeline's modules/finder_chart.py to build the
     * per-source finder chart uploaded via POST /sources/{id}/chart. Kept as
     * its own endpoint (rather than extending GET /sources/{id}/observations)
     * so that existing light-curve consumers of that endpoint are unaffected.
     */
    public function track(string $id): ResponseInterface
    {
        $sourceModel = new SourceModel();
        $source      = $sourceModel->find($id);

        if ($source === null) {
            return $this->respondError(404, 'Source not found', ['source_id' => $id]);
        }

        $observationModel = new SourceObservationModel();
        // Ordered ASC by obs_time already — a track is chronological by
        // definition, and finder_chart.py relies on that order for the
        // motion-line/numbering on the track-chart style.
        $observations = $observationModel->getObservationsForSource($id, null, null, 10000);

        if (empty($observations)) {
            return $this->respondOk(['source_id' => $id, 'epochs' => []]);
        }

        $frameModel = new FrameModel();
        $framesById = [];
        foreach ($frameModel->whereIn('id', array_column($observations, 'frame_id'))->findAll() as $frame) {
            $framesById[$frame['id']] = $frame;
        }

        $epochs = [];
        foreach ($observations as $obs) {
            $frame = $framesById[$obs['frame_id']] ?? null;
            if ($frame === null) {
                // Defensive: source_observations.frame_id has a FK to
                // frames, so this should never actually happen.
                continue;
            }

            $epochs[] = [
                'frame_id' => $obs['frame_id'],
                'filename' => $frame['filename'],
                'object'   => $frame['object'],
                'obs_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime($obs['obs_time'])),
                'ra'       => (float) $obs['ra'],
                'dec'      => (float) $obs['dec'],
                'mag'      => $obs['mag'] !== null ? (float) $obs['mag'] : null,
            ];
        }

        return $this->respondOk(['source_id' => $id, 'epochs' => $epochs]);
    }

    /**
     * POST /api/v1/sources/tracks/batch
     *
     * Batch version of GET /sources/{id}/track — returns the per-epoch
     * position track for MULTIPLE sources in a single request. Added so
     * observatory-pipeline's modules/finder_chart.py can fetch every
     * anomaly's source track for a frame in one round trip instead of one
     * GET per source_id (see CLAUDE.md's finder_chart.py section).
     *
     * Request body: {"source_ids": ["<id1>", "<id2>", ...]}
     * Response:     {"results": {"<id1>": [epoch, ...], "<id2>": [], ...}}
     *
     * An unknown or malformed source_id resolves to an empty epochs list
     * rather than failing the whole batch — mirrors nearBatch()'s and
     * coveringBatch()'s graceful-degradation style, and lets the caller
     * skip a chart for that one source without losing every other source's
     * track in the same call.
     */
    public function tracksBatch(): ResponseInterface
    {
        $body = $this->request->getJSON(true);

        if (! is_array($body)) {
            return $this->respondError(400, 'Request body must be valid JSON');
        }

        if (! isset($body['source_ids']) || ! is_array($body['source_ids'])) {
            return $this->respondError(400, 'Missing required field: source_ids (must be an array)');
        }

        $sourceIds = array_values(array_unique(array_filter($body['source_ids'], 'is_string')));

        if (count($sourceIds) === 0) {
            return $this->respondOk(['results' => new \stdClass()]);
        }

        // Malformed ids (failing the same whitelist as uploadChart()/chart())
        // are never looked up — they simply resolve to [] below, the same
        // treatment as an id that's well-formed but unknown.
        $validSourceIds = array_values(array_filter(
            $sourceIds,
            fn (string $id): bool => $this->isValidSourceId($id)
        ));

        $observations = [];
        if (count($validSourceIds) > 0) {
            $observationModel = new SourceObservationModel();
            $observations      = $observationModel->whereIn('source_id', $validSourceIds)
                ->orderBy('obs_time', 'ASC')
                ->findAll();
        }

        $framesById = [];
        if (! empty($observations)) {
            $frameModel = new FrameModel();
            foreach ($frameModel->whereIn('id', array_unique(array_column($observations, 'frame_id')))->findAll() as $frame) {
                $framesById[$frame['id']] = $frame;
            }
        }

        $results = [];
        foreach ($sourceIds as $id) {
            $results[$id] = [];
        }

        foreach ($observations as $obs) {
            $frame = $framesById[$obs['frame_id']] ?? null;
            if ($frame === null) {
                // Defensive: source_observations.frame_id has a FK to
                // frames, so this should never actually happen.
                continue;
            }

            $results[$obs['source_id']][] = [
                'frame_id' => $obs['frame_id'],
                'filename' => $frame['filename'],
                'object'   => $frame['object'],
                'obs_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime($obs['obs_time'])),
                'ra'       => (float) $obs['ra'],
                'dec'      => (float) $obs['dec'],
                'mag'      => $obs['mag'] !== null ? (float) $obs['mag'] : null,
            ];
        }

        // Same PHP numeric-string-key re-canonicalization concern as
        // nearBatch()/coveringBatch() — cast to object so json_encode()
        // emits {"<id>": [...]} rather than a plain array if every id
        // happened to look numeric. Source ids are uniqid('', true) output
        // (always containing a '.'), so this is mostly theoretical, but the
        // cast is free and keeps the contract firm regardless.
        return $this->respondOk(['results' => (object) $results]);
    }


    /**
     * POST /api/v1/sources/{id}/chart?style=track&frame_count=5
     *
     * Store the finder-chart PNG for a source, fully replacing any previous
     * chart of the SAME style for this source (a different style already
     * stored for this source_id is untouched — see SourceChartModel's class
     * docblock: one row per (source_id, style) pair, not per source_id
     * alone). observatory-pipeline's modules/finder_chart.py always
     * regenerates the whole image from the current track (see GET
     * .../track) rather than patching an existing file, so this endpoint
     * always fully overwrites within that one style.
     *
     * The request body is the raw PNG bytes — not JSON, not multipart —
     * since the body is entirely consumed by the image; `style` and
     * `frame_count` travel as query parameters instead.
     */
    public function uploadChart(string $id): ResponseInterface
    {
        if (! $this->isValidSourceId($id)) {
            return $this->respondError(400, 'Invalid source id');
        }

        $sourceModel = new SourceModel();
        $source      = $sourceModel->find($id);

        if ($source === null) {
            return $this->respondError(404, 'Source not found', ['source_id' => $id]);
        }

        $style      = $this->request->getGet('style');
        $frameCount = $this->request->getGet('frame_count');

        if (! in_array($style, SourceChartModel::ALLOWED_STYLES, true)) {
            return $this->respondError(400, 'Invalid or missing query parameter: style must be one of '
                . implode(', ', SourceChartModel::ALLOWED_STYLES));
        }

        if ($frameCount === null || ! is_numeric($frameCount) || (int) $frameCount < 1) {
            return $this->respondError(400, 'Invalid or missing query parameter: frame_count must be a positive integer');
        }

        $body = $this->request->getBody();

        if ($body === null || $body === '') {
            return $this->respondError(400, 'Request body must contain PNG image bytes');
        }

        // Minimal sanity check — the 8-byte PNG signature — instead of fully
        // decoding the image; catches "wrong content" mistakes (e.g. an
        // error page proxied through, or a client bug sending JSON here)
        // without pulling an image library into the API.
        if (substr($body, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return $this->respondError(400, 'Request body is not a valid PNG image');
        }

        $dir = WRITEPATH . 'uploads/charts';
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return $this->respondError(500, 'Failed to create chart storage directory');
        }

        // Filename carries the style so a second style for the same source_id
        // (e.g. "stamp_strip" uploaded after "track" already exists) lands in
        // its own file instead of overwriting it — see SourceChartModel's
        // class docblock.
        if (file_put_contents($dir . '/' . $id . '_' . $style . '.png', $body) === false) {
            return $this->respondError(500, 'Failed to store chart image');
        }

        $chartModel = new SourceChartModel();
        $chart      = $chartModel->upsertForSource($id, $style, (int) $frameCount);

        return $this->respondOk([
            'source_id'   => $id,
            'style'       => $chart['style'],
            'frame_count' => (int) $chart['frame_count'],
            'updated_at'  => $chart['updated_at']
                ? gmdate('Y-m-d\TH:i:s\Z', strtotime($chart['updated_at']))
                : null,
        ]);
    }

    /**
     * GET /api/v1/sources/{id}/chart.png?style=track
     *
     * Serve the stored finder-chart PNG for a source as raw image bytes.
     *
     * `style` is optional. When given, only that exact style is served (404
     * if the source has no chart of that style). When omitted — a consumer
     * that predates multi-style charts, or one that genuinely doesn't care
     * which — the most informative available style wins, per
     * SourceChartModel::STYLE_DISPLAY_PRIORITY. A pre-migration file still
     * sitting at the old un-suffixed path ({id}.png, from before
     * 2026-08-11-000001_SourceChartsUniqueByStyle.php) is tried first in
     * that no-style case, so an old chart nobody has re-rendered since stays
     * reachable without a backfill.
     */
    public function chart(string $id): ResponseInterface
    {
        if (! $this->isValidSourceId($id)) {
            return $this->respondError(400, 'Invalid source id');
        }

        $style = $this->request->getGet('style');

        $path = $this->resolveChartPath($id, is_string($style) && $style !== '' ? $style : null);

        if ($path === null) {
            return $this->respondError(404, 'No chart available for this source', ['source_id' => $id]);
        }

        return $this->response
            ->setStatusCode(200)
            ->setContentType('image/png')
            ->setBody(file_get_contents($path));
    }

    /**
     * Resolve the on-disk path for a source's chart PNG.
     *
     * @param string      $id    Source id (already validated by the caller)
     * @param string|null $style Exact style to require, or null to fall back
     *                           through the legacy un-suffixed filename and
     *                           then SourceChartModel::STYLE_DISPLAY_PRIORITY
     */
    private function resolveChartPath(string $id, ?string $style): ?string
    {
        $dir = WRITEPATH . 'uploads/charts';

        if ($style !== null) {
            if (! in_array($style, SourceChartModel::ALLOWED_STYLES, true)) {
                return null;
            }
            $path = $dir . '/' . $id . '_' . $style . '.png';

            return is_file($path) ? $path : null;
        }

        $legacyPath = $dir . '/' . $id . '.png';
        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        foreach (SourceChartModel::STYLE_DISPLAY_PRIORITY as $candidate) {
            $path = $dir . '/' . $id . '_' . $candidate . '.png';
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Guard against path traversal via the {id} route segment before it is
     * ever concatenated into a filesystem path in uploadChart()/chart().
     * Real ids are uniqid('', true) output — hex digits and a single '.' —
     * so a generous alnum+dot whitelist is both safe and non-restrictive.
     */
    private function isValidSourceId(string $id): bool
    {
        return preg_match('/^[a-zA-Z0-9.]{1,64}$/', $id) === 1;
    }
}
