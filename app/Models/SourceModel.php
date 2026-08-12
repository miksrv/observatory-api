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
    /**
     * Merge several fragmented `sources` rows into one freshly-created source.
     *
     * Exists for objects catalog-matching could never give a stable identity to across frames —
     * typically an uncatalogued, fast-moving object (e.g. a comet SkyBot/MPC doesn't carry
     * ephemeris data for at all — see observatory-pipeline's CLAUDE.md, Known Issues) whose
     * on-sky motion between exposures exceeds findByCoordinates()'s own ~2" dedup radius, so
     * every single frame it's detected on mints a brand-new `sources` row instead of
     * accumulating observations against one. This is an *operator-driven, retroactive* fix for
     * that fragmentation (via the /ui/charts bulk action) — it does not change
     * findByCoordinates()'s own radius, so a *future* frame of the same still-uncatalogued
     * object will fragment again and need re-merging.
     *
     * Mechanics, in order (see this method's own inline comments for why this order matters):
     *   1. Create a brand-new target `sources` row — deliberately never one of the inputs
     *      reused as a "winner", so every input source is treated identically below (no special
     *      case for "the row that already had rows attached to it").
     *   2. For each input source: re-point (UPDATE, not copy-then-delete) its
     *      `source_observations` and `frame_sources` rows onto the target id, and delete its
     *      `anomalies` rows outright (not reassign — see below).
     *   3. Delete every chart (DB row + PNG file) for the target AND every input source — a
     *      merged source's finder chart is stale the instant its track gains new epochs, and
     *      `source_charts.source_id` carries a UNIQUE key, so a raw reassignment onto the target
     *      would collide the moment a second input source already had its own chart row.
     *   4. Delete the now-empty input `sources` rows — safe only because every row that
     *      mattered was already moved off them in step 2; nothing here relies on
     *      ON DELETE CASCADE to do the data migration.
     *   5. Recompute the target's own `observation_count`/`first_observed_at`/`last_observed_at`
     *      from its (now complete) `source_observations`.
     *
     * Anomalies are deleted, never reassigned, and this method never creates a replacement
     * anomaly for the target itself — `POST /frames/{id}/anomalies` (observatory-pipeline's
     * anomaly_detector.py via a DETECT_ANOMALIES task) REPLACES a frame's entire anomaly set on
     * every real run anyway, so a hand-crafted "merged" anomaly here would just get silently
     * overwritten the next time that task runs — and that real run, now seeing every merged
     * epoch under one source_id, is far better positioned to classify it correctly than this
     * method guessing. The caller (SourcesController::merge()) is expected to tell the operator
     * to submit DETECT_ANOMALIES for the returned frame_ids, then GENERATE_CHARTS for the
     * returned target_id — same decoupled-task convention as everywhere else in this app.
     *
     * The actual anomaly/chart/row deletion (originally inline here) now lives in
     * {@see self::deleteSourcesAndDependents()}/{@see self::purgeChartsForSources()}, shared with
     * {@see self::purgeIfOrphaned()} — FramesController::saveSources()'s reconciliation pass uses
     * the exact same "delete anomalies + charts (row and file) + the source row itself" mechanics
     * for a source a re-analysis no longer detects anywhere.
     *
     * @param string[] $sourceIds At least 2 distinct, existing source ids to merge
     *
     * @return array{target_id: string, frame_ids: string[], merged_count: int}
     *
     * @throws \RuntimeException if fewer than 2 distinct, existing sources are given
     */
    public function mergeSources(array $sourceIds): array
    {
        $sourceIds = array_values(array_unique($sourceIds));

        $existing   = $this->whereIn('id', $sourceIds)->findAll();
        $foundIds   = array_column($existing, 'id');
        $missingIds = array_values(array_diff($sourceIds, $foundIds));

        if (count($foundIds) < 2) {
            throw new \RuntimeException(
                'Нужно минимум 2 существующих источника для объединения (найдено: ' . count($foundIds) . ').'
            );
        }

        $sourceIds = $foundIds; // ignore any stale/unknown ids rather than failing the whole merge

        $frameSourceModel = new FrameSourceModel();
        $obsModel         = new SourceObservationModel();

        $this->db->transStart();

        try {
            // Step 1 — brand-new target, never one of the inputs (see docblock above for why).
            $targetId = $this->insert([
                'catalog_name' => null,
                'catalog_id'   => null,
                'catalog_mag'  => null,
                'object_type'  => null,
            ]);

            $touchedFrameIds = [];

            foreach ($sourceIds as $oldId) {
                // Step 2a — frame_sources: re-link via the existing idempotent helper (guards the
                // uk_frame_source unique key if two inputs both touched the same frame_id), then
                // drop the old rows explicitly rather than relying on the FK cascade to do it
                // before step 4 — at this point in the loop the cascade would be a no-op anyway
                // once the rows are gone, but doing it here keeps this step and step 2b symmetrical.
                foreach ($frameSourceModel->getFrameIdsForSource($oldId) as $frameId) {
                    $frameSourceModel->linkSourceToFrame($frameId, $targetId);
                    $touchedFrameIds[$frameId] = true;
                }
                $frameSourceModel->where('source_id', $oldId)->delete();

                // Step 2b — source_observations: re-point in place. `source_observations` carries
                // its own uk_srcobs_frame_source UNIQUE(frame_id, source_id) — added specifically
                // to stop a duplicate detection on one frame from ever being stored as two rows
                // again (see that migration's own comment for the real incident this closes). If
                // two of the INPUT sources being merged here already had a row on the very same
                // frame_id (a pre-existing, pre-this-constraint fragmentation this merge feature
                // itself wasn't designed to resolve), this UPDATE would violate it — caught below
                // and reported as a normal merge failure rather than an uncaught 500.
                $obsModel->where('source_id', $oldId)->set('source_id', $targetId)->update();
            }

            // Step 2c/3/4 — anomalies (deleted outright, never reassigned — see docblock above),
            // charts (DB row + on-disk file), and the now-empty input `sources` rows themselves.
            // Shared with FramesController::saveSources()'s reconciliation pass, which purges a
            // source the same way once a re-analysis leaves it with zero observations anywhere.
            $this->deleteSourcesAndDependents($sourceIds);

            // Defensive: also purge any chart the brand-new target might already carry. It never
            // should (it was just created above), but this protects against a future caller
            // passing an already-merged target back in, and mirrors the original uniform
            // treatment of "every id involved in this merge, including the target".
            $this->purgeChartsForSources([$targetId]);

            // Step 5 — recompute the target's own aggregates from its complete observation set.
            $stats = $obsModel
                ->select('COUNT(*) AS cnt, MIN(obs_time) AS first_t, MAX(obs_time) AS last_t')
                ->where('source_id', $targetId)
                ->first();

            $this->update($targetId, [
                'observation_count' => (int) ($stats['cnt'] ?? 0),
                'first_observed_at' => $stats['first_t'] ?? null,
                'last_observed_at'  => $stats['last_t'] ?? null,
            ]);

            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw new \RuntimeException(
                'Объединение источников не удалось: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('Объединение источников не удалось (транзакция откатена).');
        }

        return [
            'target_id'    => $targetId,
            'frame_ids'    => array_keys($touchedFrameIds),
            'merged_count' => count($sourceIds),
            'missing_ids'  => $missingIds,
        ];
    }

    /**
     * Delete a source outright if — and only if — it no longer has any
     * `source_observations` on any frame.
     *
     * Used by FramesController::saveSources()'s reconciliation pass: when a
     * re-analysis of an already-registered frame (e.g. after improving the
     * detection algorithm) no longer confirms a source it used to observe on
     * THAT frame, and retracting that one observation leaves the source with
     * zero observations anywhere else either, the source never really
     * existed as a real object — it was itself a stale detection artifact
     * from a now-superseded analysis run. So it's removed outright, along
     * with everything that only makes sense in relation to it: its anomaly
     * history and its finder charts (see deleteSourcesAndDependents()).
     *
     * A source that still has at least one observation elsewhere (a
     * different frame, or even a different epoch of this same frame that
     * this exact call didn't touch) is left completely untouched — this
     * method is a no-op for it.
     *
     * @return bool True if the source was actually purged; false if it
     *              still has observations (or doesn't exist) and was left
     *              alone.
     */
    public function purgeIfOrphaned(string $sourceId): bool
    {
        $remaining = (new SourceObservationModel())
            ->where('source_id', $sourceId)
            ->countAllResults();

        if ($remaining > 0) {
            return false;
        }

        $this->deleteSourcesAndDependents([$sourceId]);

        return true;
    }

    /**
     * Delete one or more `sources` rows outright, along with everything that
     * only makes sense in relation to a source that actually exists:
     * `anomalies` (deleted, not reassigned — an anomaly detached from any
     * real source is unhelpful noise, not history worth keeping) and every
     * `source_charts` row + its on-disk PNG/GIF file (see
     * purgeChartsForSources() — `ON DELETE CASCADE` cleans up the DB row,
     * but never the file).
     *
     * Callers must have already moved off (or otherwise accounted for)
     * anything about these sources that SHOULD survive — mergeSources()
     * re-points `source_observations`/`frame_sources` onto its new target
     * before calling this, and purgeIfOrphaned() only calls this once a
     * source has zero `source_observations` left anywhere.
     *
     * @param string[] $sourceIds
     */
    private function deleteSourcesAndDependents(array $sourceIds): void
    {
        if ($sourceIds === []) {
            return;
        }

        (new AnomalyModel())->whereIn('source_id', $sourceIds)->delete();

        $this->purgeChartsForSources($sourceIds);

        $this->whereIn('id', $sourceIds)->delete();
    }

    /**
     * Delete every `source_charts` row (DB row + on-disk PNG/GIF file) for
     * the given source ids. Split out from deleteSourcesAndDependents() so
     * mergeSources() can also purge the brand-new target's own (normally
     * nonexistent) chart defensively, without touching anomalies or the
     * source row for it.
     *
     * @param string[] $sourceIds
     */
    private function purgeChartsForSources(array $sourceIds): void
    {
        if ($sourceIds === []) {
            return;
        }

        $chartModel = new SourceChartModel();
        $charts     = $chartModel->whereIn('source_id', $sourceIds)->findAll();

        foreach ($charts as $chart) {
            $ext  = in_array($chart['style'], SourceChartModel::GIF_STYLES, true) ? 'gif' : 'png';
            $path = WRITEPATH . 'uploads/charts/' . $chart['source_id'] . '_' . $chart['style'] . '.' . $ext;

            if (is_file($path)) {
                unlink($path);
            }

            // Also clean up a pre-migration, un-suffixed file left over from before
            // 2026-08-11-000001_SourceChartsUniqueByStyle.php, if one still exists.
            $legacyPath = WRITEPATH . 'uploads/charts/' . $chart['source_id'] . '.png';
            if (is_file($legacyPath)) {
                unlink($legacyPath);
            }
        }

        if (count($charts) > 0) {
            $chartModel->whereIn('source_id', $sourceIds)->delete();
        }
    }

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
