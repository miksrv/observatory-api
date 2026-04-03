<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSourcesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'frame_id' => [
                'type'     => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'null'     => false,
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
            'flux' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'fwhm' => [
                'type' => 'FLOAT',
                'null' => true,
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
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['ra', 'dec']);
        $this->forge->addKey('frame_id');
        $this->forge->addKey('catalog_name');
        $this->forge->addForeignKey('frame_id', 'frames', 'id', '', 'CASCADE');

        $this->forge->createTable('sources', true);

        $this->db->query('ALTER TABLE `sources` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('sources', true);
    }
}
