<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\FrameModel;
use App\Models\FrameSourceModel;
use App\Models\SourceModel;
use App\Models\SourceObservationModel;
use CodeIgniter\HTTP\ResponseInterface;

class SourcesController extends BaseApiController
{
    /**
     * GET /api/v1/sources/near
     *
     * Cone search for sources near a sky position.
     * Uses a bounding-box pre-filter on indexed (ra, dec) columns, then
     * applies the Haversine formula in PHP for precise distance filtering.
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
        // Bounding-box pre-filter — uses the (ra, dec) index on sources
        // ----------------------------------------------------------------
        $deg = $radiusArcsec / 3600.0;

        $sourceModel = new SourceModel();

        $candidates = $sourceModel
            ->where('ra >=', $ra - $deg)
            ->where('ra <=', $ra + $deg)
            ->where('dec >=', $dec - $deg)
            ->where('dec <=', $dec + $deg)
            ->findAll();

        // ----------------------------------------------------------------
        // Haversine precise filter — discard candidates outside the circle
        // ----------------------------------------------------------------
        $results = [];

        foreach ($candidates as $source) {
            $distance = $this->haversineArcsec($ra, $dec, (float) $source['ra'], (float) $source['dec']);

            if ($distance > $radiusArcsec) {
                continue;
            }

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
                'ra'           => (float) $source['ra'],
                'dec'          => (float) $source['dec'],
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
     * Compute the angular separation in arcseconds between two sky points
     * using the Haversine formula.
     *
     * @param float $ra1  Right ascension of point 1 (decimal degrees)
     * @param float $dec1 Declination of point 1 (decimal degrees)
     * @param float $ra2  Right ascension of point 2 (decimal degrees)
     * @param float $dec2 Declination of point 2 (decimal degrees)
     *
     * @return float Angular separation in arcseconds
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
