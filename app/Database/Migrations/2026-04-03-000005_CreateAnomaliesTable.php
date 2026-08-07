<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 5 — Create the `anomalies` table.
 *
 * Stores classified anomalies detected in frames.
 * Can optionally link to a source if the anomaly is associated with a known object.
 */
class CreateAnomaliesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
            ],
            'frame_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => false,
            ],
            'source_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => true,
            ],
            // Fixed set of classification types produced by anomaly_detector.py in the
            // observatory-pipeline repo (AnomalyType enum there) — see AnomalyModel::ALLOWED_TYPES
            // for the PHP-side mirror used to validate incoming values before insert.
            'anomaly_type' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'FIRST_OBSERVATION',
                    'KNOWN_CATALOG_NEW',
                    'VARIABLE_STAR',
                    'BINARY_STAR',
                    'SUPERNOVA_CANDIDATE',
                    'UNKNOWN',
                    'ASTEROID',
                    'COMET',
                    'MOVING_UNKNOWN',
                    'SPACE_DEBRIS',
                ],
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
            'magnitude' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'delta_mag' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'mpc_designation' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'ephemeris_predicted_ra' => [
                'type' => 'DOUBLE',
                'null' => true,
            ],
            'ephemeris_predicted_dec' => [
                'type' => 'DOUBLE',
                'null' => true,
            ],
            'ephemeris_predicted_mag' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'ephemeris_distance_au' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'ephemeris_angular_velocity' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'is_alert' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('frame_id', false, false, 'idx_anomalies_frame');
        $this->forge->addKey('source_id', false, false, 'idx_anomalies_source');
        $this->forge->addKey('anomaly_type', false, false, 'idx_anomalies_type');
        $this->forge->addKey('is_alert', false, false, 'idx_anomalies_alert');
        $this->forge->addKey(['ra', 'dec'], false, false, 'idx_anomalies_coords');

        // FK for frame_id — anomalies are deleted when frame is deleted
        $this->forge->addForeignKey('frame_id', 'frames', 'id', 'CASCADE', 'CASCADE');

        // FK for source_id — anomalies is the primary detection-event record, so a
        // source being removed from the catalog should NOT cascade-delete the
        // anomaly history; it just detaches it (ON DELETE SET NULL). ON UPDATE
        // CASCADE keeps it in sync if a source's id ever changes. NULL is allowed
        // (source_id itself is nullable) for anomalies not yet linked to a
        // resolved `sources` row. Truncating `sources` with FOREIGN_KEY_CHECKS=0
        // (as done for local dev resets) still works fine with this FK in place.
        $this->forge->addForeignKey('source_id', 'sources', 'id', 'CASCADE', 'SET NULL');

        $this->forge->createTable('anomalies', true);

        // Set default for created_at
        $this->db->query('ALTER TABLE `anomalies` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('anomalies', true);
    }
}

