<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\SkyMath;
use App\Models\AnomalyModel;
use App\Models\FrameModel;
use App\Models\FrameSourceModel;
use App\Models\ObjectStatsModel;
use App\Models\SourceModel;
use App\Models\SourceObservationModel;
use CodeIgniter\HTTP\ResponseInterface;

class FramesController extends BaseApiController
{
    /**
     * POST /api/v1/frames
     *
     * Register a newly processed FITS frame and return its generated ID.
     */
    public function create(): ResponseInterface
    {
        $body = $this->request->getJSON(true);


        if (! is_array($body)) {
            return $this->respondError(400, 'Request body must be valid JSON');
        }

        // ----------------------------------------------------------------
        // Required field presence check
        // ----------------------------------------------------------------
        $required = ['filename', 'obs_time', 'ra_center', 'dec_center', 'fov_deg', 'quality_flag'];

        foreach ($required as $field) {
            if (! isset($body[$field]) || $body[$field] === '' || $body[$field] === null) {
                return $this->respondError(400, 'Missing required fields', ['field' => $field]);
            }
        }

        // ----------------------------------------------------------------
        // Type validation for numeric sky coordinates and FOV
        // ----------------------------------------------------------------
        $numericFields = ['ra_center', 'dec_center', 'fov_deg'];

        foreach ($numericFields as $field) {
            if (! is_numeric($body[$field])) {
                return $this->respondError(422, 'Validation failed', [
                    'field'   => $field,
                    'message' => "{$field} must be numeric",
                ]);
            }
        }

        // ----------------------------------------------------------------
        // Flatten nested objects into the DB column layout
        // ----------------------------------------------------------------
        $observation = $body['observation'] ?? [];
        $instrument  = $body['instrument']  ?? [];
        $sensor      = $body['sensor']      ?? [];
        $observer    = $body['observer']    ?? [];
        $software    = $body['software']    ?? [];
        $qc          = $body['qc']          ?? [];

        $data = [
            // Top-level required fields
            'filename'          => $body['filename'],
            'original_filepath' => $body['original_filepath'] ?? null,
            // Convert ISO 8601 (2024-03-15T22:01:34Z) to MySQL DATETIME format
            'obs_time'          => date('Y-m-d H:i:s', strtotime($body['obs_time'])),
            'ra_center'         => (float) $body['ra_center'],
            'dec_center'        => (float) $body['dec_center'],
            'fov_deg'           => (float) $body['fov_deg'],
            'quality_flag'      => $body['quality_flag'],

            // observation.*
            'object'      => $observation['object']     ?? null,
            'exptime'     => isset($observation['exptime'])    ? (float) $observation['exptime']    : null,
            'filter'      => $observation['filter']     ?? null,
            'frame_type'  => $observation['frame_type'] ?? null,
            'airmass'     => isset($observation['airmass'])    ? (float) $observation['airmass']    : null,

            // instrument.*
            'telescope'        => $instrument['telescope']        ?? null,
            'camera'           => $instrument['camera']           ?? null,
            'focal_length_mm'  => isset($instrument['focal_length_mm']) ? (int) $instrument['focal_length_mm'] : null,
            'aperture_mm'      => isset($instrument['aperture_mm'])     ? (int) $instrument['aperture_mm']     : null,

            // sensor.*
            'sensor_temp'          => isset($sensor['temp_celsius'])          ? (float) $sensor['temp_celsius']          : null,
            'sensor_temp_setpoint' => isset($sensor['temp_setpoint_celsius']) ? (float) $sensor['temp_setpoint_celsius'] : null,
            'binning_x'            => isset($sensor['binning_x'])             ? (int)   $sensor['binning_x']             : null,
            'binning_y'            => isset($sensor['binning_y'])             ? (int)   $sensor['binning_y']             : null,
            'gain'                 => isset($sensor['gain'])                  ? (int)   $sensor['gain']                  : null,
            'offset'               => isset($sensor['offset'])                ? (int)   $sensor['offset']                : null,
            'width_px'             => isset($sensor['width_px'])              ? (int)   $sensor['width_px']              : null,
            'height_px'            => isset($sensor['height_px'])             ? (int)   $sensor['height_px']             : null,

            // observer.*
            'observer_name' => $observer['name']       ?? null,
            'site_name'     => $observer['site_name']  ?? null,
            'site_lat'      => isset($observer['site_lat'])    ? (float) $observer['site_lat']    : null,
            'site_lon'      => isset($observer['site_lon'])    ? (float) $observer['site_lon']    : null,
            'site_elev_m'   => isset($observer['site_elev_m']) ? (int)   $observer['site_elev_m'] : null,

            // software.*
            'software_capture' => $software['capture'] ?? null,

            // qc.*
            'qc_fwhm_median'   => isset($qc['fwhm_median'])   ? (float) $qc['fwhm_median']   : null,
            'qc_elongation'    => isset($qc['elongation'])     ? (float) $qc['elongation']    : null,
            'qc_snr_median'    => isset($qc['snr_median'])     ? (float) $qc['snr_median']    : null,
            'qc_sky_background'=> isset($qc['sky_background']) ? (float) $qc['sky_background']: null,
            'qc_star_count'    => isset($qc['star_count'])     ? (int)   $qc['star_count']    : null,
            'qc_eccentricity'  => isset($qc['eccentricity'])   ? (float) $qc['eccentricity']  : null,
        ];

        // ----------------------------------------------------------------
        // Persist
        // ----------------------------------------------------------------
        $model    = new FrameModel();
        $insertId = $model->insert($data, true);

        if ($insertId === false) {
            log_message('error', 'FramesController::create — insert failed: ' . implode(', ', $model->errors()));

            return $this->respondError(500, 'Failed to register frame');
        }

        // ----------------------------------------------------------------
        // Update object statistics (if object is specified)
        // ----------------------------------------------------------------
        if (!empty($data['object'])) {
            $objectStatsModel = new ObjectStatsModel();
            $objectStatsModel->incrementStats(
                object:  $data['object'],
                filter:  $data['filter'] ?? null,
                exptime: $data['exptime'] ?? 0.0,
                obsTime: $data['obs_time'],
                fwhm:    $data['qc_fwhm_median'] ?? null,
                airmass: $data['airmass'] ?? null
            );
        }

        return $this->respondCreated([
            'id'      => (string) $insertId,
            'message' => 'Frame registered successfully',
        ]);
    }

    /**
     * POST /api/v1/frames/{id}/sources
     *
     * Save detected sources for a frame with proper source catalog management.
     *
     * For each source:
     * 1. Check if a matching source exists in the catalog — by stable
     *    catalog identity (catalog_name + catalog_id) when the source was
     *    actually catalog-matched, falling back to position (within 2
     *    arcsec) only for uncatalogued sources, which have no identity to
     *    match on. Catalog identity is required for anything that moves
     *    between frames (e.g. an MPC-matched asteroid) — see SourceModel.
     * 2. If found: use existing source, update observation count
     * 3. If not found: create new source in catalog
     * 4. Create observation record with photometry data
     * 5. Link source to frame
     *
     * The response includes `source_ids`, positionally parallel to the
     * request's `sources` array (null for a skipped/invalid entry), so the
     * caller can correlate each submitted source with its resolved
     * `sources.id` — e.g. to populate `anomalies[].source_id` on a
     * subsequent POST /frames/{id}/anomalies call for the same frame.
     *
     * @param string $id Frame primary key from the URL segment.
     */
    public function saveSources(string $id): ResponseInterface
    {
        $body = $this->request->getJSON(true);


        if (! is_array($body)) {
            return $this->respondError(400, 'Request body must be valid JSON');
        }

        // ----------------------------------------------------------------
        // Required top-level field presence check
        // ----------------------------------------------------------------
        if (! isset($body['filename']) || ! is_string($body['filename']) || $body['filename'] === '') {
            return $this->respondError(400, 'Missing required field: filename');
        }

        if (! array_key_exists('sources', $body) || ! is_array($body['sources'])) {
            return $this->respondError(400, 'Missing required field: sources (must be an array)');
        }

        // ----------------------------------------------------------------
        // Verify the parent frame exists and get obs_time
        // ----------------------------------------------------------------
        $frameModel = new FrameModel();
        $frame      = $frameModel->find($id);

        if ($frame === null) {
            return $this->respondError(404, 'Frame not found', ['frame_id' => $id]);
        }

        $obsTime = $frame['obs_time'];

        // ----------------------------------------------------------------
        // Short-circuit for empty source list
        // ----------------------------------------------------------------
        $sources = $body['sources'];

        if (count($sources) === 0) {
            return $this->respondCreated([
                'message'         => 'Sources saved successfully',
                'count'           => 0,
                'new_sources'     => 0,
                'matched_sources' => 0,
                'source_ids'      => [],
            ]);
        }

        // ----------------------------------------------------------------
        // Process each source
        // ----------------------------------------------------------------
        $sourceModel      = new SourceModel();
        $observationModel = new SourceObservationModel();
        $frameSourceModel = new FrameSourceModel();

        $newSources     = 0;
        $matchedSources = 0;
        $skipped        = 0;

        // Parallel to the input `sources` array (same length, same order) so
        // the caller (the pipeline) can zip its own source list against this
        // response and learn each source's resolved `sources.id` — e.g. to
        // attach it as `anomalies[].source_id` in a later POST /anomalies
        // call. `null` marks a source that was skipped (invalid ra/dec, or
        // an insert failure) and therefore has no resolved id.
        $sourceIds = [];

        foreach ($sources as $source) {
            // Validate required fields
            if (
                ! isset($source['ra'], $source['dec'])
                || ! is_numeric($source['ra'])
                || ! is_numeric($source['dec'])
            ) {
                $sourceIds[] = null;
                $skipped++;
                continue;
            }

            $ra  = (float) $source['ra'];
            $dec = (float) $source['dec'];

            $catalogName = $source['catalog_name'] ?? null;
            $catalogId   = $source['catalog_id'] ?? null;
            $hasCatalogIdentity = $catalogName !== null && $catalogName !== ''
                && $catalogId !== null && $catalogId !== '';

            // Prefer matching by stable catalog identity when the source was
            // actually catalog-matched (Simbad/Gaia DR3/2MASS/Pan-STARRS/MPC).
            // This is essential for moving objects: an MPC-matched asteroid's
            // sky position shifts between frames well beyond any reasonable
            // position-matching radius (Vesta moves ~tens of arcsec/hour), so
            // position-only matching would mint a brand-new `sources` row for
            // it on every single frame instead of accumulating observations
            // against one.
            //
            // Fall back to position matching ONLY when there's no catalog
            // identity to match on at all. Using `??` unconditionally here
            // used to fall back to findByCoordinates() any time
            // findByCatalogIdentity() simply hadn't seen this identity
            // before yet (e.g. the very first observation of a given MPC
            // designation) — silently "adopting" an unrelated pre-existing
            // source's row (e.g. a Gaia DR3 star) whenever the two happened
            // to sit within 2" of each other at this epoch, permanently
            // mislabeling that row's catalog_name/catalog_id since they are
            // only ever set on insert, never refreshed on a match (real
            // incident, 2026-08-06: asteroid 2014 RY1 passing close to a
            // field star repeatedly resolved to that star's `sources` row).
            // A catalog-identified source that doesn't match anything yet
            // must get its own new row instead of risking that.
            if ($hasCatalogIdentity) {
                $existingSource = $sourceModel->findByCatalogIdentity($catalogName, $catalogId);
            } else {
                $existingSource = $sourceModel->findByCoordinates($ra, $dec, 2.0);
            }

            if ($existingSource !== null) {
                // Use existing source
                $sourceId = $existingSource['id'];
                $matchedSources++;

                // Update observation stats
                $sourceModel->update($sourceId, [
                    'last_observed_at'  => $obsTime,
                    'observation_count' => $existingSource['observation_count'] + 1,
                ]);
            } else {
                // Create new source
                $newSourceData = [
                    'catalog_name'      => $catalogName,
                    'catalog_id'        => $catalogId,
                    'catalog_mag'       => isset($source['catalog_mag']) ? (float) $source['catalog_mag'] : null,
                    'object_type'       => $source['object_type'] ?? null,
                    'first_observed_at' => $obsTime,
                    'last_observed_at'  => $obsTime,
                    'observation_count' => 1,
                ];

                $sourceId = $sourceModel->insert($newSourceData, true);

                if ($sourceId === false) {
                    log_message('error', 'Failed to create source at RA=' . $ra . ', Dec=' . $dec);
                    $sourceIds[] = null;
                    $skipped++;
                    continue;
                }

                $newSources++;
            }

            $sourceIds[] = $sourceId;

            // Create observation record
            $mag = $source['mag'] ?? $source['mag_calibrated'] ?? null;

            $observationData = [
                'source_id'  => $sourceId,
                'frame_id'   => $id,
                'ra'         => $ra,
                'dec'        => $dec,
                'mag'        => $mag !== null ? (float) $mag : null,
                'mag_err'    => isset($source['mag_err']) ? (float) $source['mag_err'] : null,
                'flux'       => isset($source['flux']) ? (float) $source['flux'] : null,
                'flux_err'   => isset($source['flux_err']) ? (float) $source['flux_err'] : null,
                'fwhm'       => isset($source['fwhm']) ? (float) $source['fwhm'] : null,
                'snr'        => isset($source['snr']) ? (float) $source['snr'] : null,
                'elongation' => isset($source['elongation']) ? (float) $source['elongation'] : null,
                'obs_time'   => $obsTime,
            ];

            $observationModel->insert($observationData);

            // Link source to frame
            $frameSourceModel->linkSourceToFrame($id, $sourceId);
        }

        // All sources were invalid
        if ($newSources + $matchedSources === 0 && $skipped > 0) {
            return $this->respondError(422, 'No valid sources: every source was missing a numeric ra or dec');
        }

        return $this->respondCreated([
            'message'         => 'Sources saved successfully',
            'count'           => $newSources + $matchedSources,
            'new_sources'     => $newSources,
            'matched_sources' => $matchedSources,
            'source_ids'      => $sourceIds,
        ]);
    }

    /**
     * POST /api/v1/frames/{id}/anomalies
     *
     * Save classified anomalies for a previously registered frame.
     * An empty anomalies array is valid and results in a 201 with count 0 and alerts 0.
     *
     * @param string $id Frame primary key from the URL segment.
     */
    public function saveAnomalies(string $id): ResponseInterface
    {
        $body = $this->request->getJSON(true);


        if (! is_array($body)) {
            return $this->respondError(400, 'Request body must be valid JSON');
        }

        // ----------------------------------------------------------------
        // Required top-level field presence check
        // ----------------------------------------------------------------
        if (! isset($body['filename']) || ! is_string($body['filename']) || $body['filename'] === '') {
            return $this->respondError(400, 'Missing required field: filename');
        }

        if (! array_key_exists('anomalies', $body) || ! is_array($body['anomalies'])) {
            return $this->respondError(400, 'Missing required field: anomalies (must be an array)');
        }

        // ----------------------------------------------------------------
        // Verify the parent frame exists
        // ----------------------------------------------------------------
        $frameModel = new FrameModel();

        if ($frameModel->find($id) === null) {
            return $this->respondError(404, 'Frame not found', ['frame_id' => $id]);
        }

        // ----------------------------------------------------------------
        // Short-circuit for empty anomaly list
        // ----------------------------------------------------------------
        $anomalies = $body['anomalies'];

        if (count($anomalies) === 0) {
            return $this->respondCreated([
                'message' => 'Anomalies saved successfully',
                'count'   => 0,
                'alerts'  => 0,
            ]);
        }

        // ----------------------------------------------------------------
        // Validate anomaly_type against the fixed known set (matches the ENUM
        // constraint on the anomaly_type column) — reject the whole batch
        // atomically if any entry uses an unrecognized value, rather than
        // silently inserting it or letting it fail as a raw SQL error.
        // ----------------------------------------------------------------
        foreach ($anomalies as $i => $anomaly) {
            $type = isset($anomaly['anomaly_type']) ? (string) $anomaly['anomaly_type'] : '';

            if (! AnomalyModel::isValidType($type)) {
                return $this->respondError(400, "Invalid anomaly_type at index {$i}: '{$type}'", [
                    'allowed_types' => AnomalyModel::ALLOWED_TYPES,
                ]);
            }
        }

        // ----------------------------------------------------------------
        // Build rows, flattening the optional ephemeris nested object
        // ----------------------------------------------------------------
        $rows       = [];
        $alertCount = 0;

        foreach ($anomalies as $anomaly) {
            $ephemeris = isset($anomaly['ephemeris']) && is_array($anomaly['ephemeris'])
                ? $anomaly['ephemeris']
                : [];

            $type    = (string) $anomaly['anomaly_type'];
            $isAlert = AnomalyModel::isAlertType($type) ? 1 : 0;

            if ($isAlert === 1) {
                $alertCount++;
            }

            $row = [
                'frame_id'     => $id,
                'source_id'    => $anomaly['source_id'] ?? null,
                'anomaly_type' => $type,
                'ra'           => isset($anomaly['ra'])  ? (float) $anomaly['ra']  : 0.0,
                'dec'          => isset($anomaly['dec']) ? (float) $anomaly['dec'] : 0.0,
                'is_alert'     => $isAlert,
            ];

            // Optional nullable scalar fields
            if (array_key_exists('magnitude', $anomaly)) {
                $row['magnitude'] = $anomaly['magnitude'] !== null ? (float) $anomaly['magnitude'] : null;
            }
            if (array_key_exists('delta_mag', $anomaly)) {
                $row['delta_mag'] = $anomaly['delta_mag'] !== null ? (float) $anomaly['delta_mag'] : null;
            }
            if (isset($anomaly['mpc_designation'])) {
                $row['mpc_designation'] = (string) $anomaly['mpc_designation'];
            }
            if (isset($anomaly['notes'])) {
                $row['notes'] = (string) $anomaly['notes'];
            }

            // Flatten ephemeris nested object
            if (isset($ephemeris['predicted_ra'])) {
                $row['ephemeris_predicted_ra'] = (float) $ephemeris['predicted_ra'];
            }
            if (isset($ephemeris['predicted_dec'])) {
                $row['ephemeris_predicted_dec'] = (float) $ephemeris['predicted_dec'];
            }
            if (isset($ephemeris['predicted_mag'])) {
                $row['ephemeris_predicted_mag'] = (float) $ephemeris['predicted_mag'];
            }
            if (isset($ephemeris['distance_au'])) {
                $row['ephemeris_distance_au'] = (float) $ephemeris['distance_au'];
            }
            if (isset($ephemeris['angular_velocity_arcsec_per_hour'])) {
                $row['ephemeris_angular_velocity'] = (float) $ephemeris['angular_velocity_arcsec_per_hour'];
            }

            $rows[] = $row;
        }

        // ----------------------------------------------------------------
        // Normalize rows so every row has the same set of keys.
        // CI4 insertBatch requires all rows to be key-identical; optional
        // fields conditionally added above can differ between rows, so we
        // compute the union of all keys and back-fill missing ones with null.
        // ----------------------------------------------------------------
        $allKeys = array_keys(array_merge(...$rows));

        foreach ($rows as &$row) {
            foreach ($allKeys as $key) {
                if (! array_key_exists($key, $row)) {
                    $row[$key] = null;
                }
            }
        }
        unset($row);

        // ----------------------------------------------------------------
        // Batch insert
        // ----------------------------------------------------------------
        $anomalyModel = new AnomalyModel();

        if ($anomalyModel->insertBatch($rows) === false) {
            log_message('error', 'FramesController::saveAnomalies — insertBatch failed for frame_id=' . $id);

            return $this->respondError(500, 'Failed to save anomalies');
        }

        return $this->respondCreated([
            'message' => 'Anomalies saved successfully',
            'count'   => count($rows),
            'alerts'  => $alertCount,
        ]);
    }

    /**
     * GET /api/v1/frames/covering
     *
     * Return frames whose field of view covered a given sky point before a given time.
     *
     * Query parameters:
     *   ra           float   Right ascension of the query point (decimal degrees)
     *   dec          float   Declination of the query point (decimal degrees)
     *   before_time  string  ISO 8601 upper bound (strictly before)
     */
    public function covering(): ResponseInterface
    {
        $ra         = $this->request->getGet('ra');
        $dec        = $this->request->getGet('dec');
        $beforeTime = $this->request->getGet('before_time');

        // ----------------------------------------------------------------
        // Validate required parameters
        // ----------------------------------------------------------------
        if ($ra === null || $ra === '') {
            return $this->respondError(400, 'Missing required parameter: ra');
        }

        if ($dec === null || $dec === '') {
            return $this->respondError(400, 'Missing required parameter: dec');
        }

        if ($beforeTime === null || $beforeTime === '') {
            return $this->respondError(400, 'Missing required parameter: before_time');
        }

        if (! is_numeric($ra)) {
            return $this->respondError(400, 'Invalid parameter: ra must be numeric');
        }

        if (! is_numeric($dec)) {
            return $this->respondError(400, 'Invalid parameter: dec must be numeric');
        }

        $ra  = (float) $ra;
        $dec = (float) $dec;

        // ----------------------------------------------------------------
        // Parse before_time — accept ISO 8601 and convert to MySQL DATETIME
        // ----------------------------------------------------------------
        $beforeTimestamp = strtotime($beforeTime);

        if ($beforeTimestamp === false) {
            return $this->respondError(400, 'Invalid parameter: before_time must be a valid ISO 8601 datetime');
        }

        $beforeMysql = date('Y-m-d H:i:s', $beforeTimestamp);

        // ----------------------------------------------------------------
        // Bounding-box pre-filter. The margin is the widest fov_deg on
        // record — deliberately wider than any single frame needs, since
        // Haversine below trims it to each frame's exact fov_deg/2 coverage.
        // The margin is declination-scaled and split across the RA=0/360
        // seam (SkyMath) so real coverage near the poles or the seam is
        // never silently dropped by the pre-filter.
        // ----------------------------------------------------------------
        $db        = \Config\Database::connect();
        $maxFovDeg = (float) ($db->query('SELECT MAX(fov_deg) AS max_fov FROM frames')->getRow()->max_fov ?? 0.0);

        if ($maxFovDeg <= 0.0) {
            // Empty frames table — nothing can possibly cover the point.
            return $this->respondOk(['data' => []]);
        }

        $raRanges = SkyMath::raRanges($ra, SkyMath::raMargin($dec, $maxFovDeg));

        $raClauses = [];
        $params    = [$beforeMysql];

        foreach ($raRanges as [$min, $max]) {
            $raClauses[] = 'ra_center BETWEEN ? AND ?';
            $params[]    = $min;
            $params[]    = $max;
        }

        $sql = 'SELECT id, filename, obs_time, ra_center, dec_center, fov_deg
                   FROM frames
                  WHERE obs_time < ?
                    AND (' . implode(' OR ', $raClauses) . ')
                    AND dec_center BETWEEN ? AND ?';
        $params[] = $dec - $maxFovDeg;
        $params[] = $dec + $maxFovDeg;

        $candidates = $db->query($sql, $params)->getResultObject();

        // ----------------------------------------------------------------
        // Haversine precision filter: keep only frames that truly cover the
        // query point (angular distance from frame center <= fov_deg / 2)
        // ----------------------------------------------------------------
        $results = [];

        foreach ($candidates as $frame) {
            $distArcsec    = SkyMath::haversineArcsec($ra, $dec, (float) $frame->ra_center, (float) $frame->dec_center);
            $radiusArcsec  = ((float) $frame->fov_deg / 2.0) * 3600.0;

            if ($distArcsec <= $radiusArcsec) {
                $results[] = [
                    'id'         => (string) $frame->id,
                    'filename'   => $frame->filename,
                    'obs_time'   => gmdate('Y-m-d\TH:i:s\Z', strtotime($frame->obs_time)),
                    'ra_center'  => (float) $frame->ra_center,
                    'dec_center' => (float) $frame->dec_center,
                    'fov_deg'    => (float) $frame->fov_deg,
                ];
            }
        }

        return $this->respondOk(['data' => $results]);
    }

    /**
     * POST /api/v1/frames/covering/batch
     *
     * Batch lookup for frames covering multiple sky positions.
     * Reduces API calls from O(N) to O(1) when processing frames with many sources.
     */
    public function coveringBatch(): ResponseInterface
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

        if (! isset($body['before_time']) || $body['before_time'] === '') {
            return $this->respondError(400, 'Missing required field: before_time');
        }

        $positions  = $body['positions'];
        $beforeTime = $body['before_time'];

        // Parse before_time
        $beforeTimestamp = strtotime($beforeTime);
        if ($beforeTimestamp === false) {
            return $this->respondError(400, 'Invalid before_time format');
        }
        $beforeMysql = date('Y-m-d H:i:s', $beforeTimestamp);

        // ----------------------------------------------------------------
        // Short-circuit for empty positions
        // ----------------------------------------------------------------
        if (count($positions) === 0) {
            return $this->respondOk(['results' => new \stdClass()]);
        }

        // ----------------------------------------------------------------
        // Validate positions and compute a combined bounding box.
        //
        // The margin is the widest fov_deg on record, declination-scaled
        // per position. The RA span across the whole batch is computed via
        // SkyMath::combinedRaRanges, which stays correct even if the batch
        // straddles the RA=0/360 seam (see its docblock) — a plain
        // raw-value min/max does not.
        // ----------------------------------------------------------------
        $db        = \Config\Database::connect();
        $maxFovDeg = (float) ($db->query('SELECT MAX(fov_deg) AS max_fov FROM frames')->getRow()->max_fov ?? 0.0);

        if ($maxFovDeg <= 0.0) {
            // Empty frames table — nothing can possibly cover any position.
            // See the (object) cast note further down — same reason applies here.
            $results = [];
            foreach ($positions as $i => $pos) {
                $results[(string) $i] = [];
            }
            return $this->respondOk(['results' => (object) $results]);
        }

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

            $raWithMargins[] = [$ra, SkyMath::raMargin($dec, $maxFovDeg)];
        }

        $minDec -= $maxFovDeg;
        $maxDec += $maxFovDeg;

        $raRanges = SkyMath::combinedRaRanges($raWithMargins);

        // ----------------------------------------------------------------
        // Single query to fetch all candidate frames
        // ----------------------------------------------------------------
        $raClauses = [];
        $params    = [$beforeMysql];

        foreach ($raRanges as [$min, $max]) {
            $raClauses[] = 'ra_center BETWEEN ? AND ?';
            $params[]    = $min;
            $params[]    = $max;
        }

        $sql = 'SELECT id, filename, obs_time, ra_center, dec_center, fov_deg
                   FROM frames
                  WHERE obs_time < ?
                    AND (' . implode(' OR ', $raClauses) . ')
                    AND dec_center BETWEEN ? AND ?';
        $params[] = $minDec;
        $params[] = $maxDec;

        $candidates = $db->query($sql, $params)->getResultObject();

        // ----------------------------------------------------------------
        // For each position, check which frames cover it
        // ----------------------------------------------------------------
        $results = [];
        $totalMatches = 0;

        foreach ($positions as $i => $pos) {
            $ra  = (float) $pos['ra'];
            $dec = (float) $pos['dec'];
            $posResults = [];

            foreach ($candidates as $frame) {
                $distArcsec   = SkyMath::haversineArcsec($ra, $dec, (float) $frame->ra_center, (float) $frame->dec_center);
                $radiusArcsec = ((float) $frame->fov_deg / 2.0) * 3600.0;

                if ($distArcsec <= $radiusArcsec) {
                    $posResults[] = [
                        'id'         => (string) $frame->id,
                        'filename'   => $frame->filename,
                        'obs_time'   => gmdate('Y-m-d\TH:i:s\Z', strtotime($frame->obs_time)),
                        'ra_center'  => (float) $frame->ra_center,
                        'dec_center' => (float) $frame->dec_center,
                        'fov_deg'    => (float) $frame->fov_deg,
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
     * GET /api/v1/frames/nearest-before
     *
     * The single most recent frame of a given object strictly before a given
     * time. Used by observatory-pipeline's modules/finder_chart.py to render
     * a "before/after" comparison chart for a source detected on only one
     * frame so far: a crop of an earlier frame of the same object at that
     * exact sky position (nothing expected there yet) next to a crop of the
     * frame the source was actually detected on. The pipeline has no direct
     * database access (see CLAUDE.md) — this is the one query it needs that
     * `GET /frames/covering` doesn't already answer, since that one is a
     * spatial "was this position ever imaged" check, not "what's the most
     * recent frame of this object".
     */
    public function nearestBefore(): ResponseInterface
    {
        $object     = $this->request->getGet('object');
        $beforeTime = $this->request->getGet('before_time');

        if ($object === null || $object === '') {
            return $this->respondError(400, 'Missing required parameter: object');
        }

        if ($beforeTime === null || $beforeTime === '') {
            return $this->respondError(400, 'Missing required parameter: before_time');
        }

        $beforeTimestamp = strtotime($beforeTime);

        if ($beforeTimestamp === false) {
            return $this->respondError(400, 'Invalid parameter: before_time must be a valid ISO 8601 datetime');
        }

        $beforeMysql = date('Y-m-d H:i:s', $beforeTimestamp);

        $frame = (new FrameModel())
            ->where('object', $object)
            ->where('obs_time <', $beforeMysql)
            ->orderBy('obs_time', 'DESC')
            ->first();

        if ($frame === null) {
            return $this->respondOk(['frame' => null]);
        }

        return $this->respondOk([
            'frame' => [
                'id'       => (string) $frame['id'],
                'filename' => $frame['filename'],
                'object'   => $frame['object'],
                'obs_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime($frame['obs_time'])),
            ],
        ]);
    }
}
