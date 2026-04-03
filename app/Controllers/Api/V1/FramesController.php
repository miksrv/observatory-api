<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
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

        // Log incoming JSON request
        log_message('info', '[FramesController::create] Incoming JSON: ' . json_encode($body));

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
     * 1. Check if a matching source exists (within 2 arcsec) in the catalog
     * 2. If found: use existing source, update observation count
     * 3. If not found: create new source in catalog
     * 4. Create observation record with photometry data
     * 5. Link source to frame
     *
     * @param string $id Frame primary key from the URL segment.
     */
    public function saveSources(string $id): ResponseInterface
    {
        $body = $this->request->getJSON(true);

        // Log incoming JSON request
        log_message('info', '[FramesController::saveSources] frame_id=' . $id . ' Incoming JSON: ' . json_encode($body));

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

        foreach ($sources as $source) {
            // Validate required fields
            if (
                ! isset($source['ra'], $source['dec'])
                || ! is_numeric($source['ra'])
                || ! is_numeric($source['dec'])
            ) {
                $skipped++;
                continue;
            }

            $ra  = (float) $source['ra'];
            $dec = (float) $source['dec'];

            // Try to find existing source within 2 arcsec
            $existingSource = $sourceModel->findByCoordinates($ra, $dec, 2.0);

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
                    'ra'                => $ra,
                    'dec'               => $dec,
                    'catalog_name'      => $source['catalog_name'] ?? null,
                    'catalog_id'        => $source['catalog_id'] ?? null,
                    'catalog_mag'       => isset($source['catalog_mag']) ? (float) $source['catalog_mag'] : null,
                    'object_type'       => $source['object_type'] ?? null,
                    'first_observed_at' => $obsTime,
                    'last_observed_at'  => $obsTime,
                    'observation_count' => 1,
                ];

                $sourceId = $sourceModel->insert($newSourceData, true);

                if ($sourceId === false) {
                    log_message('error', 'Failed to create source at RA=' . $ra . ', Dec=' . $dec);
                    $skipped++;
                    continue;
                }

                $newSources++;
            }

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

        // Log incoming JSON request
        log_message('info', '[FramesController::saveAnomalies] frame_id=' . $id . ' Incoming JSON: ' . json_encode($body));

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
        // Build rows, flattening the optional ephemeris nested object
        // ----------------------------------------------------------------
        $rows       = [];
        $alertCount = 0;

        foreach ($anomalies as $anomaly) {
            $ephemeris = isset($anomaly['ephemeris']) && is_array($anomaly['ephemeris'])
                ? $anomaly['ephemeris']
                : [];

            $type    = isset($anomaly['anomaly_type']) ? (string) $anomaly['anomaly_type'] : '';
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
        // Bounding-box pre-filter using fov_deg as the half-width margin.
        // This is deliberately wider than needed — Haversine below trims it
        // to exact coverage.
        // ----------------------------------------------------------------
        $db = \Config\Database::connect();

        $candidates = $db->query(
            'SELECT id, filename, obs_time, ra_center, dec_center, fov_deg
               FROM frames
              WHERE obs_time < ?
                AND ra_center  BETWEEN (? - fov_deg) AND (? + fov_deg)
                AND dec_center BETWEEN (? - fov_deg) AND (? + fov_deg)',
            [$beforeMysql, $ra, $ra, $dec, $dec]
        )->getResultObject();

        // ----------------------------------------------------------------
        // Haversine precision filter: keep only frames that truly cover the
        // query point (angular distance from frame center <= fov_deg / 2)
        // ----------------------------------------------------------------
        $results = [];

        foreach ($candidates as $frame) {
            $distArcsec    = $this->haversineArcsec($ra, $dec, (float) $frame->ra_center, (float) $frame->dec_center);
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
     * Haversine great-circle distance between two sky positions.
     *
     * @param float $ra1  Right ascension of point 1 (decimal degrees)
     * @param float $dec1 Declination of point 1 (decimal degrees)
     * @param float $ra2  Right ascension of point 2 (decimal degrees)
     * @param float $dec2 Declination of point 2 (decimal degrees)
     * @return float      Angular separation in arcseconds
     */
    private function haversineArcsec(float $ra1, float $dec1, float $ra2, float $dec2): float
    {
        $ra1  = deg2rad($ra1);  $dec1 = deg2rad($dec1);
        $ra2  = deg2rad($ra2);  $dec2 = deg2rad($dec2);
        $dra  = $ra2 - $ra1;
        $ddec = $dec2 - $dec1;
        $a    = sin($ddec / 2) ** 2 + cos($dec1) * cos($dec2) * sin($dra / 2) ** 2;

        return 2 * asin(sqrt($a)) * (180.0 / M_PI) * 3600.0;
    }
}
