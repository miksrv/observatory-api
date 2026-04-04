<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 6 — Create the `object_stats` table.
 *
 * Stores pre-aggregated statistics for each unique combination of
 * object (target name) and filter. Updated incrementally when frames are added.
 */
class CreateObjectStatsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
            ],
            'object' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'filter' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'frame_count' => [
                'type'       => 'INT',
                'default'    => 0,
                'null'       => false,
            ],
            'total_exposure_sec' => [
                'type'       => 'FLOAT',
                'default'    => 0,
                'null'       => false,
            ],
            'first_obs_time' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_obs_time' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'avg_fwhm' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'avg_airmass' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
            'updated_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('object', false, false, 'idx_objstats_object');
        $this->forge->addKey('filter', false, false, 'idx_objstats_filter');
        
        // Unique constraint on (object, filter) — but MariaDB treats NULL as distinct
        // So we use a regular index and handle uniqueness in application code
        $this->forge->addKey(['object', 'filter'], false, false, 'idx_objstats_object_filter');

        $this->forge->createTable('object_stats', true);

        // Set defaults for created_at and updated_at
        $this->db->query('ALTER TABLE `object_stats` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
        $this->db->query('ALTER TABLE `object_stats` MODIFY `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('object_stats', true);
    }
}

