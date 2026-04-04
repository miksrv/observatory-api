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
            'anomaly_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
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
        
        // Note: source_id has no FK constraint to allow TRUNCATE on sources table.
        // Referential integrity for source_id is managed at the application level.

        $this->forge->createTable('anomalies', true);

        // Set default for created_at
        $this->db->query('ALTER TABLE `anomalies` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('anomalies', true);
    }
}

