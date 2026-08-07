<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 2 — Create the `sources` table (Source Catalog).
 *
 * Master catalog of unique celestial sources (stars, galaxies, asteroids,
 * etc.). A source is identified primarily by its stable catalog identity —
 * (catalog_name, catalog_id) — enforced by a unique key below. When a new
 * source is detected with no catalog match at all, a positional fallback
 * match is used instead (see SourceModel::findByCoordinates(), which
 * queries `source_observations` — this table intentionally has no ra/dec
 * columns of its own, since a single static position doesn't make sense
 * for objects that move between frames, e.g. MPC-matched asteroids/comets).
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
        $this->forge->addKey('catalog_name', false, false, 'idx_sources_catalog');
        $this->forge->addKey('object_type', false, false, 'idx_sources_type');
        // Enforces one `sources` row per distinct catalog object and backs
        // SourceModel::findByCatalogIdentity(). NULL is not considered equal
        // to NULL by MySQL/MariaDB unique indexes, so uncatalogued sources
        // (catalog_name/catalog_id both null) never collide with each other.
        $this->forge->addUniqueKey(['catalog_name', 'catalog_id'], 'uniq_sources_catalog_identity');

        $this->forge->createTable('sources', true);

        // Set default for created_at
        $this->db->query('ALTER TABLE `sources` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('sources', true);
    }
}

