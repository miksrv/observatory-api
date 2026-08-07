<?php

namespace App\Models;

/**
 * Model for the `sources` table (Source Catalog).
 *
 * Master catalog of unique celestial sources (stars, galaxies, asteroids,
 * etc.). A source is identified primarily by its stable catalog identity
 * — (catalog_name, catalog_id), e.g. ("MPC", "Vesta") or ("Gaia DR3", "Gaia
 * DR3 1234567890") — via findByCatalogIdentity(). Position-based matching
 * (findByCoordinates(), below) is only a fallback for sources with no
 * catalog match at all.
 *
 * This table intentionally has NO ra/dec columns of its own: a single
 * static position only makes sense for objects that don't move, and this
 * catalog also has to hold asteroids/comets (matched via MPC) whose sky
 * position shifts well beyond any reasonable fixed-position matching
 * radius between frames. Position-based dedup on a static (ra, dec) column
 * would silently mint a brand-new `sources` row for a moving object on
 * every single frame instead of accumulating observations against one
 * source — exactly the bug this design replaces. Per-epoch positions live
 * in `source_observations` (one row per frame) and that table is what all
 * positional lookups here delegate to, via SourceObservationModel.
 */
class SourceModel extends BaseModel
{
    protected $table      = 'sources';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    protected $allowedFields = [
        'id',
        'catalog_name',
        'catalog_id',
        'catalog_mag',
        'object_type',
        'first_observed_at',
        'last_observed_at',
        'observation_count',
    ];

    /**
     * Find a source by its stable catalog identity — the preferred match
     * for any source a catalog cross-match actually identified (Simbad,
     * Gaia DR3, 2MASS, Pan-STARRS, MPC). Unlike position matching, this is
     * unaffected by real on-sky motion between frames.
     *
     * @param string|null $catalogName e.g. "MPC", "Gaia DR3" — null means "no catalog match"
     * @param string|null $catalogId   catalog's own identifier/designation
     *
     * @return array|null Source record, or null if either field is missing/empty or no match exists
     */
    public function findByCatalogIdentity(?string $catalogName, ?string $catalogId): ?array
    {
        if ($catalogName === null || $catalogName === '' || $catalogId === null || $catalogId === '') {
            return null;
        }

        return $this->where('catalog_name', $catalogName)
            ->where('catalog_id', $catalogId)
            ->first();
    }

    /**
     * Positional dedup fallback for sources with no catalog identity at
     * all (catalog_name/catalog_id both null) — position is the only
     * signal available for those. Delegates to SourceObservationModel
     * since `sources` no longer carries its own ra/dec.
     *
     * @param float $ra           RA in degrees
     * @param float $dec          Dec in degrees
     * @param float $radiusArcsec Matching radius in arcseconds (default 2)
     *
     * @return array|null Source record or null if not found
     */
    public function findByCoordinates(float $ra, float $dec, float $radiusArcsec = 2.0): ?array
    {
        $sourceId = (new SourceObservationModel())->findNearestSourceId($ra, $dec, $radiusArcsec);

        return $sourceId !== null ? $this->find($sourceId) : null;
    }

    /**
     * Cone search: all known sources within a radius of given coordinates,
     * nearest-first. Positions come from each source's nearest matching
     * observation (see SourceObservationModel::coneSearchDistinctSources()),
     * since `sources` itself has no static ra/dec anymore.
     *
     * @param float $ra           RA in degrees
     * @param float $dec          Dec in degrees
     * @param float $radiusArcsec Search radius in arcseconds
     *
     * @return array List of source records, each merged with its matched
     *               ra/dec, e.g. [['id'=>, 'ra'=>, 'dec'=>, 'catalog_name'=>, ...]]
     */
    public function coneSearch(float $ra, float $dec, float $radiusArcsec): array
    {
        $nearest = (new SourceObservationModel())->coneSearchDistinctSources($ra, $dec, $radiusArcsec);

        if (empty($nearest)) {
            return [];
        }

        $sourceRows = $this->whereIn('id', array_column($nearest, 'source_id'))->findAll();
        $byId       = [];

        foreach ($sourceRows as $row) {
            $byId[$row['id']] = $row;
        }

        $results = [];

        foreach ($nearest as $match) {
            $row = $byId[$match['source_id']] ?? null;

            // Defensive: source_observations.source_id has a FK to sources,
            // so this should never actually be missing.
            if ($row === null) {
                continue;
            }

            $results[] = [
                'id'                => $row['id'],
                'ra'                => $match['ra'],
                'dec'               => $match['dec'],
                'catalog_name'      => $row['catalog_name'],
                'catalog_id'        => $row['catalog_id'],
                'object_type'       => $row['object_type'],
                'observation_count' => (int) $row['observation_count'],
                'last_observed_at'  => $row['last_observed_at'],
            ];
        }

        return $results;
    }
}
