<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 2 — Create the `sources` table (Source Catalog).
 *
 * Master catalog of unique celestial sources (stars, galaxies, etc.).
 * A source is identified by its sky coordinates. When a new source is detected
 * at a position where no existing source exists (within matching radius),
 * a new source record is created.
 */
class CreateSourcesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
            ],
            'ra' => [
                'type' => 'DOUBLE',
                'null' => false,
            ],
            'dec' => [
                'type' => 'DOUBLE',
                'null' => false,
            ],
            'catalog_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'catalog_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'catalog_mag' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'object_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'first_observed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_observed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'observation_count' => [
                'type'       => 'INT',
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
        $this->forge->addKey(['ra', 'dec'], false, false, 'idx_sources_coords');
        $this->forge->addKey('catalog_name', false, false, 'idx_sources_catalog');
        $this->forge->addKey('object_type', false, false, 'idx_sources_type');

        $this->forge->createTable('sources', true);

        // Set default for created_at
        $this->db->query('ALTER TABLE `sources` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('sources', true);
    }
}

