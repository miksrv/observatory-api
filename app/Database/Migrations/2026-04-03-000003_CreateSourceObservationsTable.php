<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 3 — Create the `source_observations` table (Photometry History).
 *
 * Stores time-varying measurements of each source from individual frames.
 * This is the key table for analyzing variability, light curves, etc.
 */
class CreateSourceObservationsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
            ],
            'source_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => false,
            ],
            'frame_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => false,
            ],
            'ra' => [
                'type' => 'DOUBLE',
                'null' => false,
            ],
            'dec' => [
                'type' => 'DOUBLE',
                'null' => false,
            ],
            'mag' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'mag_err' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'flux' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'flux_err' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'fwhm' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'snr' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'elongation' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            // Mirrors observatory-pipeline's astrometry.py `saturated` flag: raw ADU at the
            // detection's peak was at or above SATURATION_ADU. Persisted (rather than left as an
            // in-memory-only pipeline flag) so a later, decoupled DETECT_ANOMALIES task can
            // reconstruct anomaly_detector.py's saturated-artifact suppression rule purely from
            // stored data, without re-running astrometry/photometry on the original FITS file.
            'saturated' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
            ],
            // Mirrors observatory-pipeline's subtraction.py `_from_subtraction` flag: this
            // observation came from image-subtraction candidate detection, not the normal
            // detection path. Persisted for the same reason as `saturated` above — without it, a
            // decoupled anomaly-detection re-run can't tell a subtraction-confirmed candidate from
            // an ordinary detection, and would wrongly apply the stricter coverage-check gate to it.
            'from_subtraction' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
            ],
            // Mirrors observatory-pipeline's astrometry.py / subtraction.py `near_edge` flag:
            // pixel position within EDGE_MARGIN_FRAC of any frame edge, where coma inflates a
            // star's measured elongation for purely optical reasons. Persisted so a decoupled
            // DETECT_ANOMALIES re-run can reconstruct anomaly_detector.py's edge-aware
            // SPACE_DEBRIS_EDGE_ELONGATION_MIN threshold purely from stored data without
            // re-running astrometry on the original FITS file.
            'near_edge' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
            ],
            'obs_time' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('source_id', false, false, 'idx_srcobs_source');
        $this->forge->addKey('frame_id', false, false, 'idx_srcobs_frame');
        $this->forge->addKey('obs_time', false, false, 'idx_srcobs_time');
        $this->forge->addKey(['source_id', 'obs_time'], false, false, 'idx_srcobs_lightcurve');
        // Bounding-box pre-filter for positional lookups (SourceModel's
        // dedup fallback and GET /sources/near) now live entirely on this
        // table, since `sources` carries no ra/dec of its own — see
        // SourceModel and SourceObservationModel docblocks.
        $this->forge->addKey(['ra', 'dec'], false, false, 'idx_srcobs_coords');
        // One photometric measurement per source per frame — enforced at the DB level rather than
        // trusted to ingestion-side dedup alone. Without this, an uncatalogued object's own normal
        // sep detection and its own image-subtraction candidate (which share no catalog identity
        // for observatory-pipeline's pipeline.py _dedupe_by_catalog_identity() to collapse) both
        // landed here as separate rows for the very same physical detection — inflating
        // observation_count and producing duplicate anomalies for one real event (real incident,
        // 2026-08-11, C_2020_R4_ATLAS frames; also independently hit two ordinary Gaia DR3 stars
        // whose two same-frame detections both resolved to the same source_id via
        // SourceModel::findByCoordinates()). observatory-pipeline's own fix
        // (_dedupe_uncatalogued_subtraction_pair(), added the same day) stops new duplicates at the
        // source; this key is the hard backstop for whatever ingestion path — this one or any
        // future one — might still slip one through.
        $this->forge->addUniqueKey(['frame_id', 'source_id'], 'uk_srcobs_frame_source');

        $this->forge->addForeignKey('source_id', 'sources', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('frame_id', 'frames', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('source_observations', true);

        // Set default for created_at
        $this->db->query('ALTER TABLE `source_observations` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('source_observations', true);
    }
}

