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
     * Register a processed FITS frame and return its ID — or, if this exact
     * `filename` was already registered before, UPDATE that existing row in
     * place and return its existing ID instead of minting a duplicate.
     *
     * This makes the endpoint idempotent for the "re-analysis" use case: an
     * operator re-runs an ANALYZE task on a file already sitting in the
     * archive (e.g. after improving the detection algorithm), and
     * observatory-pipeline's analyze_frame() has no notion of "this frame
     * already exists" of its own — it just POSTs the freshly computed frame
     * metadata unconditionally every time (see that repo's CLAUDE.md,
     * pipeline.py step 6). Without this upsert, every re-analysis of the
     * same file created a second `frames` row with a new frame_id, and the
     * subsequent POST /frames/{id}/sources call for it was then purely
     * additive against that new, empty row — piling up duplicate
     * `source_observations` for the same physical detections alongside the
     * stale first run's instead of ever superseding them (real incident,
     * 2026-08-12).
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
            // Convert ISO 8601 (2024-03-15T22:01:34Z) to MySQL DATETIME format
            'obs_time'          => date('Y-m-d H:i:s', strtotime($body['obs_time'])),
            'ra_center'         => (float) $body['ra_center'],
            'dec_center'        => (float) $body['dec_center'],
            'fov_deg'           => (float) $body['fov_deg'],
            // Optional — see the migration's docblock. null/omitted when the
            // pipeline never solved a WCS for this frame at all.
            'position_angle_deg' => isset($body['position_angle_deg']) ? (float) $body['position_angle_deg'] : null,
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
        // Persist — upsert by filename (see docblock above for why)
        // ----------------------------------------------------------------
        $model      = new FrameModel();
        $existing   = $model->findByFilename($data['filename']);
        $isNewFrame = $existing === null;

        // Mount pointing error (see the migration's docblock) is the one set of
        // fields in this payload that must NOT be refreshed by a re-analysis of
        // an already-registered filename — it characterizes the mount's pointing
        // behavior at this frame's ORIGINAL capture time, not this particular
        // re-run. Only added to $data on the genuinely-new-row path; left out of
        // the update path entirely below so FrameModel::update() never touches
        // these three columns on an existing row, whatever value the pipeline
        // (which has no notion of "already stored" and recomputes/sends this on
        // every call regardless) happens to submit this time.
        if ($isNewFrame) {
            $data['pointing_error_arcsec']     = isset($body['pointing_error_arcsec']) ? (float) $body['pointing_error_arcsec'] : null;
            $data['pointing_error_ra_arcsec']  = isset($body['pointing_error_ra_arcsec']) ? (float) $body['pointing_error_ra_arcsec'] : null;
            $data['pointing_error_dec_arcsec'] = isset($body['pointing_error_dec_arcsec']) ? (float) $body['pointing_error_dec_arcsec'] : null;

            $insertId = $model->insert($data, true);

            if ($insertId === false) {
                log_message('error', 'FramesController::create — insert failed: ' . implode(', ', $model->errors()));

                return $this->respondError(500, 'Failed to register frame');
            }
        } else {
            $insertId = $existing['id'];

            if ($model->update($insertId, $data) === false) {
                log_message('error', 'FramesController::create — update failed for existing frame_id=' . $insertId . ': ' . implode(', ', $model->errors()));

                return $this->respondError(500, 'Failed to update frame');
            }
        }

        // ----------------------------------------------------------------
        // Update object statistics (if object is specified) — only for a
        // genuinely new frame. Re-analyzing an already-registered file must
        // not double-count it in object_stats.frame_count/total_exposure_sec.
        // ----------------------------------------------------------------
        if ($isNewFrame && !empty($data['object'])) {
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

        return $isNewFrame
            ? $this->respondCreated([
                'id'      => (string) $insertId,
                'message' => 'Frame registered successfully',
            ])
            : $this->respondOk([
                'id'      => (string) $insertId,
                'message' => 'Frame updated successfully',
            ]);
    }

    /**
     * POST /api/v1/frames/{id}/sources
     *
     * Save all detected sources for a frame, reconciling against whatever
     * was already saved for this exact `frame_id` on a previous call.
     *
     * For each source in the request:
     * 1. Check if a matching source exists in the catalog — by stable
     *    catalog identity (catalog_name + catalog_id) when the source was
     *    actually catalog-matched, falling back to position (within 2
     *    arcsec) only for uncatalogued sources, which have no identity to
     *    match on. Catalog identity is required for anything that moves
     *    between frames (e.g. an MPC-matched asteroid) — see SourceModel.
     * 2. If found: use existing source; bump observation count/last_observed_at
     *    only the first time THIS frame ever contributes an observation of it.
     * 3. If not found: create new source in catalog.
     * 4. Upsert (not blind-insert) the observation record with photometry data,
     *    keyed by (frame_id, source_id) — see uk_srcobs_frame_source.
     * 5. Link source to frame (idempotent).
     *
     * Then — the reconciliation this method is named for — retract every
     * source that was linked to this `frame_id` before this call but wasn't
     * reconfirmed by it. Without this, a repeat call for the SAME frame_id
     * (exactly what happens when an operator re-runs ANALYZE on an
     * already-processed file, e.g. after improving the detection algorithm)
     * was purely additive: a source the old run found and the new run no
     * longer does — a false detection the improved algorithm now correctly
     * rejects — just sat there forever as stale history, and the old run's
     * `source_observations` row for it would in fact silently fail to
     * duplicate (uk_srcobs_frame_source) rather than ever being replaced. A
     * retracted source that ends up with zero `source_observations` left on
     * ANY frame is purged outright, along with its anomaly history and
     * finder charts — see SourceModel::purgeIfOrphaned(). A source still
     * observed elsewhere is left alone; only this frame's own link is
     * removed. An empty `sources[]` is a valid "found nothing this time"
     * statement and still retracts everything previously linked to this
     * frame_id, same as `POST /frames/{id}/anomalies`'s replace semantics.
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
        $sources = $body['sources'];

        // ----------------------------------------------------------------
        // Process each source
        // ----------------------------------------------------------------
        $sourceModel      = new SourceModel();
        $observationModel = new SourceObservationModel();
        $frameSourceModel = new FrameSourceModel();

        // Snapshot of source_ids currently linked to this frame BEFORE
        // processing this batch — the reconciliation pass below diffs this
        // against what the batch actually confirms.
        $previousSourceIds = $frameSourceModel->getSourceIdsForFrame($id);

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

        // Every source_id this call actually confirmed (new or re-matched) —
        // used below to compute which of $previousSourceIds were NOT
        // reconfirmed and must be retracted.
        $confirmedSourceIds = [];

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

            // Does a source_observations row already exist for this exact
            // (frame_id, source_id) pairing? Decides both whether to bump
            // `sources.observation_count` (only on this pairing's FIRST
            // appearance — never again on a later idempotent re-run of the
            // same frame) and whether to insert or update the observation
            // row itself below.
            $existingObsRow = null;

            if ($existingSource !== null) {
                $sourceId = $existingSource['id'];
                $matchedSources++;

                $existingObsRow = $observationModel
                    ->where('frame_id', $id)
                    ->where('source_id', $sourceId)
                    ->first();

                if ($existingObsRow === null) {
                    $sourceModel->update($sourceId, [
                        'last_observed_at'  => $obsTime,
                        'observation_count' => $existingSource['observation_count'] + 1,
                    ]);
                }
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

            $sourceIds[]                     = $sourceId;
            $confirmedSourceIds[$sourceId]    = true;

            // Upsert the observation record (not a blind insert — see
            // uk_srcobs_frame_source and this method's own docblock: a
            // repeat call for the same frame_id must update, not violate
            // that unique key or silently fail to persist anything new).
            $mag = $source['mag'] ?? $source['mag_calibrated'] ?? null;

            $observationData = [
                'source_id'        => $sourceId,
                'frame_id'         => $id,
                'ra'               => $ra,
                'dec'              => $dec,
                'mag'              => $mag !== null ? (float) $mag : null,
                'mag_err'          => isset($source['mag_err']) ? (float) $source['mag_err'] : null,
                'flux'             => isset($source['flux']) ? (float) $source['flux'] : null,
                'flux_err'         => isset($source['flux_err']) ? (float) $source['flux_err'] : null,
                'fwhm'             => isset($source['fwhm']) ? (float) $source['fwhm'] : null,
                'snr'              => isset($source['snr']) ? (float) $source['snr'] : null,
                'elongation'       => isset($source['elongation']) ? (float) $source['elongation'] : null,
                // Persisted so a later, decoupled DETECT_ANOMALIES task can reconstruct
                // anomaly_detector.py's saturated-suppression and subtraction-coverage-bypass
                // rules purely from stored data (see the migration's docblock for the full
                // rationale). Both default to false when the pipeline omits them.
                'saturated'        => ! empty($source['saturated']) ? 1 : 0,
                'near_edge'        => ! empty($source['near_edge']) ? 1 : 0,
                'from_subtraction' => ! empty($source['from_subtraction']) ? 1 : 0,
                'obs_time'         => $obsTime,
            ];

            if ($existingObsRow !== null) {
                $observationModel->update($existingObsRow['id'], $observationData);
            } else {
                $observationModel->insert($observationData);
            }

            // Link source to frame
            $frameSourceModel->linkSourceToFrame($id, $sourceId);
        }

        // All sources were invalid (only meaningful for a non-empty batch —
        // an empty batch is a valid "found nothing this time" statement, see
        // the reconciliation pass below).
        if (count($sources) > 0 && $newSources + $matchedSources === 0 && $skipped > 0) {
            return $this->respondError(422, 'No valid sources: every source was missing a numeric ra or dec');
        }

        // ----------------------------------------------------------------
        // Reconciliation — retract sources linked to this frame before this
        // call but not reconfirmed by it (see this method's docblock).
        // ----------------------------------------------------------------
        $vacatedSourceIds = array_diff($previousSourceIds, array_keys($confirmedSourceIds));
        $retractedCount   = 0;
        $purgedCount      = 0;

        if ($vacatedSourceIds !== []) {
            $db = \Config\Database::connect();
            $db->transStart();

            foreach ($vacatedSourceIds as $vacatedId) {
                $observationModel->where('frame_id', $id)->where('source_id', $vacatedId)->delete();
                $frameSourceModel->where('frame_id', $id)->where('source_id', $vacatedId)->delete();
                $retractedCount++;

                if ($sourceModel->purgeIfOrphaned($vacatedId)) {
                    $purgedCount++;
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                log_message('error', 'FramesController::saveSources — reconciliation transaction failed for frame_id=' . $id);

                return $this->respondError(500, 'Failed to reconcile stale sources for this frame');
            }
        }

        return $this->respondCreated([
            'message'           => 'Sources saved successfully',
            'count'             => $newSources + $matchedSources,
            'new_sources'       => $newSources,
            'matched_sources'   => $matchedSources,
            'source_ids'        => $sourceIds,
            'retracted_sources' => $retractedCount,
            'purged_sources'    => $purgedCount,
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

        $anomalies = $body['anomalies'];

        // ----------------------------------------------------------------
        // Validate anomaly_type against the fixed known set (matches the ENUM
        // constraint on the anomaly_type column) — reject the whole batch
        // atomically if any entry uses an unrecognized value, rather than
        // silently inserting it or letting it fail as a raw SQL error.
        // Deliberately done BEFORE the replace-delete below, so a malformed
        // batch never gets the chance to wipe the frame's existing anomalies.
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
        // This call always REPLACES the frame's anomaly set rather than
        // appending to it. Without this, re-running anomaly detection for a
        // frame that was already classified (e.g. after fixing a classifier
        // bug, or via a standalone DETECT_ANOMALIES task) would leave the
        // previous run's anomalies sitting alongside the new ones instead of
        // superseding them. A frame classified for the first time simply has
        // nothing to delete here.
        // ----------------------------------------------------------------
        $anomalyModel = new AnomalyModel();
        $anomalyModel->where('frame_id', $id)->delete();

        // ----------------------------------------------------------------
        // Short-circuit for empty anomaly list — the delete above already
        // did the only real work needed (a re-run that now finds nothing).
        // ----------------------------------------------------------------
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
        // Batch insert (into the model instance already created above, for
        // the replace-delete)
        // ----------------------------------------------------------------
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

    /**
     * GET /api/v1/frames
     *
     * List frames, most recent first, optionally filtered by object and/or an obs_time range.
     * This is the scope-resolution query a standalone DETECT_ANOMALIES/GENERATE_CHARTS task uses
     * to turn "object=M51" (or a date range) into a concrete list of frame ids — e.g. re-running
     * anomaly detection across an object's entire observation history, old and new frames alike,
     * something the inline per-frame pipeline flow has no way to express at all.
     *
     * Query parameters (all optional):
     *   object      string    Exact match on `frames.object`
     *   date_from   ISO 8601  obs_time >= this
     *   date_to     ISO 8601  obs_time < this
     *   limit       int       Max rows (default 100, capped at 1000)
     *   offset      int       Pagination offset (default 0)
     */
    public function index(): ResponseInterface
    {
        $model = new FrameModel();

        $object   = $this->request->getGet('object');
        $dateFrom = $this->request->getGet('date_from');
        $dateTo   = $this->request->getGet('date_to');

        if ($object !== null && $object !== '') {
            $model = $model->where('object', $object);
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $timestamp = strtotime($dateFrom);
            if ($timestamp === false) {
                return $this->respondError(400, 'Invalid parameter: date_from must be a valid ISO 8601 datetime');
            }
            $model = $model->where('obs_time >=', date('Y-m-d H:i:s', $timestamp));
        }

        if ($dateTo !== null && $dateTo !== '') {
            $timestamp = strtotime($dateTo);
            if ($timestamp === false) {
                return $this->respondError(400, 'Invalid parameter: date_to must be a valid ISO 8601 datetime');
            }
            $model = $model->where('obs_time <', date('Y-m-d H:i:s', $timestamp));
        }

        $limit  = (int) ($this->request->getGet('limit') ?? 100);
        $limit  = $limit > 0 ? min($limit, 1000) : 100;
        $offset = max(0, (int) ($this->request->getGet('offset') ?? 0));

        $frames = $model->orderBy('obs_time', 'ASC')
            ->findAll($limit, $offset);

        return $this->respondOk(['data' => array_map([$this, 'formatFrame'], $frames)]);
    }

    /**
     * GET /api/v1/frames/{id}
     *
     * A single previously registered frame's full stored record — everything POST /frames
     * accepted, echoed back. Used by a standalone task (DETECT_ANOMALIES re-run, etc.) to
     * reconstruct the `frame_meta` that anomaly_detector.py needs without having local
     * filesystem access to the original FITS file at all.
     */
    public function show(string $id): ResponseInterface
    {
        $frame = (new FrameModel())->find($id);

        if ($frame === null) {
            return $this->respondError(404, 'Frame not found', ['frame_id' => $id]);
        }

        return $this->respondOk(['frame' => $this->formatFrame($frame)]);
    }

    /**
     * GET /api/v1/frames/{id}/sources
     *
     * The sources currently linked to a frame, with their per-frame observation values (this
     * frame's own measured position/mag/flags — not a static catalog position) plus each
     * source's catalog identity. This is the piece a standalone DETECT_ANOMALIES task needs to
     * reconstruct anomaly_detector.py's per-source input for an already-processed frame, entirely
     * from stored data — no re-running astrometry/photometry, no local FITS access required.
     */
    public function sources(string $id): ResponseInterface
    {
        if ((new FrameModel())->find($id) === null) {
            return $this->respondError(404, 'Frame not found', ['frame_id' => $id]);
        }

        $observations = (new SourceObservationModel())->getObservationsForFrame($id);

        if (empty($observations)) {
            return $this->respondOk(['frame_id' => $id, 'data' => []]);
        }

        $sourcesById = [];
        foreach ((new SourceModel())->whereIn('id', array_unique(array_column($observations, 'source_id')))->findAll() as $source) {
            $sourcesById[$source['id']] = $source;
        }

        $data = [];
        foreach ($observations as $obs) {
            $source = $sourcesById[$obs['source_id']] ?? null;

            $data[] = [
                'source_id'        => $obs['source_id'],
                'ra'               => (float) $obs['ra'],
                'dec'              => (float) $obs['dec'],
                'mag'              => $obs['mag'] !== null ? (float) $obs['mag'] : null,
                'mag_err'          => $obs['mag_err'] !== null ? (float) $obs['mag_err'] : null,
                'flux'             => $obs['flux'] !== null ? (float) $obs['flux'] : null,
                'flux_err'         => $obs['flux_err'] !== null ? (float) $obs['flux_err'] : null,
                'fwhm'             => $obs['fwhm'] !== null ? (float) $obs['fwhm'] : null,
                'snr'              => $obs['snr'] !== null ? (float) $obs['snr'] : null,
                'elongation'       => $obs['elongation'] !== null ? (float) $obs['elongation'] : null,
                'saturated'        => (bool) ($obs['saturated'] ?? false),
                'near_edge'        => (bool) ($obs['near_edge'] ?? false),
                'from_subtraction' => (bool) ($obs['from_subtraction'] ?? false),
                // Defensive: source_observations.source_id has a FK to sources, so $source
                // should never actually be null — falls back to nulls rather than skipping the
                // observation entirely if it somehow is.
                'catalog_name'     => $source['catalog_name'] ?? null,
                'catalog_id'       => $source['catalog_id'] ?? null,
                'catalog_mag'      => isset($source['catalog_mag']) ? (float) $source['catalog_mag'] : null,
                'object_type'      => $source['object_type'] ?? null,
            ];
        }

        return $this->respondOk(['frame_id' => $id, 'data' => $data]);
    }

    /**
     * Flatten a `frames` row into the API's public shape — shared by index() and show() so both
     * endpoints stay in sync automatically as columns are added.
     */
    private function formatFrame(array $frame): array
    {
        return [
            'id'                    => (string) $frame['id'],
            'filename'              => $frame['filename'],
            'obs_time'              => gmdate('Y-m-d\TH:i:s\Z', strtotime($frame['obs_time'])),
            'ra_center'             => (float) $frame['ra_center'],
            'dec_center'            => (float) $frame['dec_center'],
            'fov_deg'               => (float) $frame['fov_deg'],
            'position_angle_deg'    => $frame['position_angle_deg'] !== null ? (float) $frame['position_angle_deg'] : null,
            'pointing_error_arcsec'     => $frame['pointing_error_arcsec'] !== null ? (float) $frame['pointing_error_arcsec'] : null,
            'pointing_error_ra_arcsec'  => $frame['pointing_error_ra_arcsec'] !== null ? (float) $frame['pointing_error_ra_arcsec'] : null,
            'pointing_error_dec_arcsec' => $frame['pointing_error_dec_arcsec'] !== null ? (float) $frame['pointing_error_dec_arcsec'] : null,
            'quality_flag'          => $frame['quality_flag'],
            'object'                => $frame['object'],
            'exptime'               => $frame['exptime'] !== null ? (float) $frame['exptime'] : null,
            'filter'                => $frame['filter'],
            'frame_type'            => $frame['frame_type'],
            'airmass'               => $frame['airmass'] !== null ? (float) $frame['airmass'] : null,
            'telescope'             => $frame['telescope'],
            'camera'                => $frame['camera'],
            'focal_length_mm'       => $frame['focal_length_mm'] !== null ? (int) $frame['focal_length_mm'] : null,
            'aperture_mm'           => $frame['aperture_mm'] !== null ? (int) $frame['aperture_mm'] : null,
            'sensor_temp'           => $frame['sensor_temp'] !== null ? (float) $frame['sensor_temp'] : null,
            'sensor_temp_setpoint'  => $frame['sensor_temp_setpoint'] !== null ? (float) $frame['sensor_temp_setpoint'] : null,
            'binning_x'             => $frame['binning_x'] !== null ? (int) $frame['binning_x'] : null,
            'binning_y'             => $frame['binning_y'] !== null ? (int) $frame['binning_y'] : null,
            'gain'                  => $frame['gain'] !== null ? (int) $frame['gain'] : null,
            'offset'                => $frame['offset'] !== null ? (int) $frame['offset'] : null,
            'width_px'              => $frame['width_px'] !== null ? (int) $frame['width_px'] : null,
            'height_px'             => $frame['height_px'] !== null ? (int) $frame['height_px'] : null,
            'observer_name'         => $frame['observer_name'],
            'site_name'             => $frame['site_name'],
            'site_lat'              => $frame['site_lat'] !== null ? (float) $frame['site_lat'] : null,
            'site_lon'              => $frame['site_lon'] !== null ? (float) $frame['site_lon'] : null,
            'site_elev_m'           => $frame['site_elev_m'] !== null ? (int) $frame['site_elev_m'] : null,
            'software_capture'      => $frame['software_capture'],
            'qc_fwhm_median'        => $frame['qc_fwhm_median'] !== null ? (float) $frame['qc_fwhm_median'] : null,
            'qc_elongation'         => $frame['qc_elongation'] !== null ? (float) $frame['qc_elongation'] : null,
            'qc_snr_median'         => $frame['qc_snr_median'] !== null ? (float) $frame['qc_snr_median'] : null,
            'qc_sky_background'    => $frame['qc_sky_background'] !== null ? (float) $frame['qc_sky_background'] : null,
            'qc_star_count'         => $frame['qc_star_count'] !== null ? (int) $frame['qc_star_count'] : null,
            'qc_eccentricity'       => $frame['qc_eccentricity'] !== null ? (float) $frame['qc_eccentricity'] : null,
            'created_at'            => $frame['created_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($frame['created_at'])) : null,
        ];
    }
}
