<?php

namespace App\Models;

/**
 * Model for the `sources` table (Source Catalog).
 *
 * Master catalog of unique celestial sources (stars, galaxies, etc.).
 * A source is identified by its sky coordinates.
 */
class SourceModel extends BaseModel
{
    protected $table      = 'sources';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    protected $allowedFields = [
        'id',
        'ra',
        'dec',
        'catalog_name',
        'catalog_id',
        'catalog_mag',
        'object_type',
        'first_observed_at',
        'last_observed_at',
        'observation_count',
    ];

    /**
     * Find a source within a matching radius of given coordinates.
     *
     * Uses a bounding-box pre-filter then Haversine for precise matching.
     *
     * @param float $ra           RA in degrees
     * @param float $dec          Dec in degrees
     * @param float $radiusArcsec Matching radius in arcseconds (default 2)
     *
     * @return array|null Source record or null if not found
     */
    public function findByCoordinates(float $ra, float $dec, float $radiusArcsec = 2.0): ?array
    {
        // Convert arcsec to degrees for bounding box
        $deg = $radiusArcsec / 3600.0;

        // Bounding-box pre-filter
        $candidates = $this->where('ra >=', $ra - $deg)
            ->where('ra <=', $ra + $deg)
            ->where('dec >=', $dec - $deg)
            ->where('dec <=', $dec + $deg)
            ->findAll();

        // Haversine precise filter
        foreach ($candidates as $source) {
            $distance = $this->haversineArcsec($ra, $dec, (float)$source['ra'], (float)$source['dec']);
            if ($distance <= $radiusArcsec) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Calculate angular distance between two sky points using the Haversine formula.
     *
     * @param float $ra1  RA of point 1 (degrees)
     * @param float $dec1 Dec of point 1 (degrees)
     * @param float $ra2  RA of point 2 (degrees)
     * @param float $dec2 Dec of point 2 (degrees)
     *
     * @return float Distance in arcseconds
     */
    private function haversineArcsec(float $ra1, float $dec1, float $ra2, float $dec2): float
    {
        $ra1  = deg2rad($ra1);
        $dec1 = deg2rad($dec1);
        $ra2  = deg2rad($ra2);
        $dec2 = deg2rad($dec2);

        $dra  = $ra2 - $ra1;
        $ddec = $dec2 - $dec1;

        $a = sin($ddec / 2) ** 2 + cos($dec1) * cos($dec2) * sin($dra / 2) ** 2;

        return 2 * asin(sqrt($a)) * (180.0 / M_PI) * 3600.0;
    }
}
