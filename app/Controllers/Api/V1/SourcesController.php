<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class SourcesController extends BaseApiController
{
    /**
     * GET /api/v1/sources/near
     *
     * Cone search for historical sources near a sky position.
     * Uses a bounding-box pre-filter on indexed (ra, dec) columns, then
     * applies the Haversine formula in PHP for precise distance filtering.
     */
    public function near(): ResponseInterface
    {
        $ra            = $this->request->getGet('ra');
        $dec           = $this->request->getGet('dec');
        $radiusArcsec  = $this->request->getGet('radius_arcsec');
        $beforeTime    = $this->request->getGet('before_time');

        // ----------------------------------------------------------------
        // Presence check — all four params are required
        // ----------------------------------------------------------------
        $missing = [];

        if ($ra === null || $ra === '')           { $missing[] = 'ra'; }
        if ($dec === null || $dec === '')         { $missing[] = 'dec'; }
        if ($radiusArcsec === null || $radiusArcsec === '') { $missing[] = 'radius_arcsec'; }
        if ($beforeTime === null || $beforeTime === '')     { $missing[] = 'before_time'; }

        if (! empty($missing)) {
            return $this->respondError(400, 'Missing required query parameters', ['missing' => $missing]);
        }

        // ----------------------------------------------------------------
        // Numeric type validation for sky coordinates and radius
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
        // Parse before_time (ISO 8601) → MySQL DATETIME string
        // ----------------------------------------------------------------
        $beforeTimestamp = strtotime($beforeTime);

        if ($beforeTimestamp === false) {
            return $this->respondError(400, 'Invalid parameter: before_time must be a valid ISO 8601 datetime string');
        }

        $beforeMysql = date('Y-m-d H:i:s', $beforeTimestamp);

        // ----------------------------------------------------------------
        // Bounding-box pre-filter — uses the (ra, dec) index on sources
        // and the obs_time index on frames to narrow the candidate set.
        // The bounding box is a square in degree-space whose half-width
        // equals radius_arcsec converted to degrees.
        // ----------------------------------------------------------------
        $deg = $radiusArcsec / 3600.0;

        $db = \Config\Database::connect();

        $candidates = $db->table('sources s')
            ->select('s.ra, s.dec, s.mag, s.flux, s.frame_id, f.obs_time')
            ->join('frames f', 's.frame_id = f.id', 'inner')
            ->where('s.ra >=', $ra - $deg)
            ->where('s.ra <=', $ra + $deg)
            ->where('s.dec >=', $dec - $deg)
            ->where('s.dec <=', $dec + $deg)
            ->where('f.obs_time <', $beforeMysql)
            ->get()
            ->getResult();

        // ----------------------------------------------------------------
        // Haversine precise filter — discard candidates outside the circle
        // ----------------------------------------------------------------
        $results = [];

        foreach ($candidates as $row) {
            $distance = $this->haversineArcsec($ra, $dec, (float) $row->ra, (float) $row->dec);

            if ($distance > $radiusArcsec) {
                continue;
            }

            // Normalise obs_time to ISO 8601 UTC string
            $obsTimeIso = (new \DateTime($row->obs_time, new \DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s\Z');

            $results[] = [
                'ra'       => (float) $row->ra,
                'dec'      => (float) $row->dec,
                'mag'      => $row->mag !== null ? (float) $row->mag : null,
                'flux'     => $row->flux !== null ? (float) $row->flux : null,
                'frame_id' => (string) $row->frame_id,
                'obs_time' => $obsTimeIso,
            ];
        }

        return $this->respondOk(['data' => $results]);
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
