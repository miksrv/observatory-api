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

