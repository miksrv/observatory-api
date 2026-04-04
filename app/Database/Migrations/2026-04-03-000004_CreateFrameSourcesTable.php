<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 4 — Create the `frame_sources` table (Many-to-Many Link).
 *
 * Quick lookup table linking frames to sources without the full observation data.
 * Useful for queries like "which sources are in this frame?" or
 * "which frames contain this source?"
 */
class CreateFrameSourcesTable extends Migration
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
                'null'       => false,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('frame_id', false, false, 'idx_frmsrc_frame');
        $this->forge->addKey('source_id', false, false, 'idx_frmsrc_source');
        $this->forge->addUniqueKey(['frame_id', 'source_id'], 'uk_frame_source');

        $this->forge->addForeignKey('frame_id', 'frames', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('source_id', 'sources', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('frame_sources', true);

        // Set default for created_at
        $this->db->query('ALTER TABLE `frame_sources` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('frame_sources', true);
    }
}

