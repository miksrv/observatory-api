<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAnomaliesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'frame_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => false,
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
                'null'       => false,
                'default'    => 0,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('frame_id');
        $this->forge->addKey('anomaly_type');
        $this->forge->addKey('is_alert');
        $this->forge->addKey(['ra', 'dec']);
        $this->forge->addForeignKey('frame_id', 'frames', 'id', '', 'CASCADE');

        $this->forge->createTable('anomalies', true);

        $this->db->query('ALTER TABLE `anomalies` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('anomalies', true);
    }
}
