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

