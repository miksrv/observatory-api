<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Multi-pipeline support: several observatory-pipeline instances (one per observatory server,
 * each holding its own FITS files locally) write into this one database, each with its own API
 * key.
 *
 *   - `pipelines` — one row per client: display `name`, SHA-256 of its API key (the key itself is
 *     never stored), heartbeat, `revoked_at`. Managed with `php spark pipeline:*`
 *     (app/Commands/Pipeline*.php); ApiKeyFilter authenticates against it.
 *   - `frames.pipeline_id` — which pipeline holds the frame's file. NULL for frames registered
 *     before this migration; a later re-analysis fills it.
 *   - `tasks.pipeline_id` — the only pipeline allowed to claim the task; NULL = any pipeline.
 *   - `tasks.claimed_by` — which pipeline actually claimed it (display only).
 *
 * The single key that existed before (`app.apiKey` in .env) is registered here as pipeline
 * `default`, so the pipeline already deployed keeps working without any change on its side.
 *
 * Pipelines are revoked, never deleted (frames and tasks keep pointing at them); the foreign keys
 * use SET NULL only so a manual DELETE can't fail or cascade into science data.
 */
class AddPipelineRouting extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'key_hash' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => false,
            ],
            'last_seen_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'revoked_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name', 'uk_pipelines_name');
        $this->forge->addUniqueKey('key_hash', 'uk_pipelines_key_hash');
        $this->forge->createTable('pipelines', true, ['ENGINE' => 'InnoDB']);
        $this->db->query('ALTER TABLE `pipelines` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');

        // The foreign keys below need `pipelines.id` in exactly the charset/collation of the
        // referencing columns (errno 150 otherwise — see CreateSourceChartsTable). Those follow
        // whatever `frames` was created with, which need not match this connection's default
        // (the `tests` group connects as utf8), so copy it from `frames` itself.
        $collation = $this->db->query(
            'SELECT TABLE_COLLATION AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable('frames')],
        )->getRow()->c ?? null;

        if (is_string($collation) && preg_match('/^([a-z0-9]+)_[a-z0-9_]+$/', $collation, $m) === 1) {
            $this->db->query("ALTER TABLE `pipelines` CONVERT TO CHARACTER SET {$m[1]} COLLATE {$collation}");
        }

        $this->forge->addColumn('frames', [
            'pipeline_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => true,
                'after'      => 'filename',
            ],
        ]);
        $this->db->query('ALTER TABLE `frames` ADD INDEX `idx_frames_pipeline` (`pipeline_id`), '
            . 'ADD CONSTRAINT `frames_pipeline_id_foreign` FOREIGN KEY (`pipeline_id`) '
            . 'REFERENCES `pipelines` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');

        $this->forge->addColumn('tasks', [
            'pipeline_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => true,
                'after'      => 'status',
            ],
            'claimed_by' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => true,
                'after'      => 'pipeline_id',
            ],
        ]);
        // The worker's poll: WHERE status = 'PENDING' AND (pipeline_id = ? OR pipeline_id IS NULL).
        $this->db->query('ALTER TABLE `tasks` ADD INDEX `idx_tasks_status_pipeline` (`status`, `pipeline_id`), '
            . 'ADD INDEX `idx_tasks_claimed_by` (`claimed_by`), '
            . 'ADD CONSTRAINT `tasks_pipeline_id_foreign` FOREIGN KEY (`pipeline_id`) '
            . 'REFERENCES `pipelines` (`id`) ON DELETE SET NULL ON UPDATE CASCADE, '
            . 'ADD CONSTRAINT `tasks_claimed_by_foreign` FOREIGN KEY (`claimed_by`) '
            . 'REFERENCES `pipelines` (`id`) ON DELETE SET NULL ON UPDATE CASCADE');

        $legacyKey = (string) config('App')->apiKey;

        if ($legacyKey !== '') {
            $this->db->table('pipelines')->insert([
                'id'       => uniqid('', true),
                'name'     => 'default',
                'key_hash' => hash('sha256', $legacyKey),
            ]);
        }
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `tasks` DROP FOREIGN KEY `tasks_pipeline_id_foreign`, '
            . 'DROP FOREIGN KEY `tasks_claimed_by_foreign`, '
            . 'DROP INDEX `idx_tasks_status_pipeline`, DROP INDEX `idx_tasks_claimed_by`');
        $this->forge->dropColumn('tasks', ['pipeline_id', 'claimed_by']);

        $this->db->query('ALTER TABLE `frames` DROP FOREIGN KEY `frames_pipeline_id_foreign`, '
            . 'DROP INDEX `idx_frames_pipeline`');
        $this->forge->dropColumn('frames', 'pipeline_id');

        $this->forge->dropTable('pipelines', true);
    }
}
