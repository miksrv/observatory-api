<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 7 — Create the `tasks` and `task_items` tables.
 *
 * Backs the granular pipeline job queue: observatory-pipeline no longer has to run its three
 * stages (analyze a frame, detect anomalies, generate finder charts) as one inline sequence per
 * file. Each stage is submitted here as a `tasks` row with an explicit, itemized scope
 * (`task_items`), so a stage can be re-run later — for a single object, a date range, or exactly
 * the frame/source ids a prior stage produced — without re-running whatever came before it. See
 * observatory-pipeline's CLAUDE.md for the pipeline-side design this supports.
 */
class CreateTasksTable extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // tasks — one row per submitted unit of work.
        // ------------------------------------------------------------------
        $this->forge->addField([
            'id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['ANALYZE', 'DETECT_ANOMALIES', 'GENERATE_CHARTS', 'PREVIEW_CATALOG_MATCH', 'DELETE_FRAME', 'RESTART'],
                'null'       => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['PENDING', 'RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED'],
                'default'    => 'PENDING',
                'null'       => false,
            ],
            // Descriptive only — task_items is the authoritative scope. These exist so a task
            // list ("everything queued for M51") can be filtered/displayed without joining and
            // aggregating task_items every time.
            'scope_object' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'scope_date_from' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'scope_date_to' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'total_items' => [
                'type'    => 'INT',
                'default' => 0,
                'null'    => false,
            ],
            'completed_items' => [
                'type'    => 'INT',
                'default' => 0,
                'null'    => false,
            ],
            'failed_items' => [
                'type'    => 'INT',
                'default' => 0,
                'null'    => false,
            ],
            // Points at the task this one re-runs, if any. A re-run is always a brand-new task
            // row rather than a mutation of the old one, so the attempt history stays intact
            // ("task #12 misclassified everything, task #15 re-ran with the fixed algorithm").
            'parent_task_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => true,
            ],
            'error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'started_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'finished_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('status', false, false, 'idx_tasks_status');
        $this->forge->addKey('type', false, false, 'idx_tasks_type');
        $this->forge->addKey('scope_object', false, false, 'idx_tasks_scope_object');
        $this->forge->addKey('parent_task_id', false, false, 'idx_tasks_parent');
        $this->forge->addForeignKey('parent_task_id', 'tasks', 'id', 'CASCADE', 'SET NULL');

        $this->forge->createTable('tasks', true);

        $this->db->query('ALTER TABLE `tasks` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');

        // ------------------------------------------------------------------
        // task_items — one row per unit of work inside a task's scope. Exactly one of
        // filename / frame_id / source_id / anomaly_id is meaningful per row, depending on the
        // parent task's type and how far it has progressed:
        //   ANALYZE               -> filename (frame_id filled in once POST /frames resolves one)
        //   DETECT_ANOMALIES      -> frame_id
        //   GENERATE_CHARTS       -> anomaly_id + source_id (operator creates via UI from the
        //                            anomalies table; source_id is denormalized from the anomaly
        //                            for pipeline convenience; payload carries anomaly_type and
        //                            designation so the pipeline doesn't need a separate fetch)
        //   PREVIEW_CATALOG_MATCH -> filename (never resolves a frame_id — this task type never
        //                            calls POST /frames at all; see observatory-pipeline's
        //                            modules/catalog_preview.py)
        //   DELETE_FRAME          -> frame_id (operator-initiated deletion — the pipeline moves
        //                            the frame's file from FITS_ARCHIVE to FITS_REJECTED, never
        //                            deleting it; the API performs the actual DB-side cascade
        //                            delete once the item is reported DONE — see
        //                            TasksController::postItemsProgress())
        //   RESTART               -> (no items — signal task, no task_items rows created)
        // `payload` isn't just an input either — PREVIEW_CATALOG_MATCH uses it as an OUTPUT slot
        // too: the pipeline writes {"output_path", "matched", "total"} back onto it via
        // POST /tasks/{id}/items/progress once that file's PNG is rendered (see
        // TasksController::postItemsProgress()).
        // Normalized into its own table — rather than a filename list crammed into one column on
        // `tasks` — specifically so a submission of hundreds or thousands of files stays
        // queryable and independently retryable per item, and so progress is a plain COUNT(), not
        // a deserialize-and-count on every poll.
        // ------------------------------------------------------------------
        $this->forge->addField([
            'id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
            ],
            'task_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => false,
            ],
            'seq' => [
                'type' => 'INT',
                'null' => false,
            ],
            'filename' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'frame_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => true,
            ],
            'source_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => true,
            ],
            'anomaly_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => true,
            ],
            // Opaque, item-type-specific metadata the client needs back when this item is
            // processed — e.g. a GENERATE_CHARTS item's {"anomaly_type": ..., "designation": ...},
            // computed once by the DETECT_ANOMALIES task that created it. JSON-encoded by the
            // client; this table/API never inspects its contents, only stores and echoes it back.
            'payload' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['PENDING', 'DONE', 'FAILED'],
                'default'    => 'PENDING',
                'null'       => false,
            ],
            'error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'processed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('task_id', false, false, 'idx_taskitems_task');
        $this->forge->addKey('frame_id', false, false, 'idx_taskitems_frame');
        $this->forge->addKey('source_id', false, false, 'idx_taskitems_source');
        $this->forge->addKey('anomaly_id', false, false, 'idx_taskitems_anomaly');
        $this->forge->addUniqueKey(['task_id', 'seq'], 'uk_taskitems_task_seq');

        $this->forge->addForeignKey('task_id', 'tasks', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('frame_id', 'frames', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('source_id', 'sources', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('anomaly_id', 'anomalies', 'id', 'CASCADE', 'SET NULL');

        $this->forge->createTable('task_items', true);

        $this->db->query('ALTER TABLE `task_items` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('task_items', true);
        $this->forge->dropTable('tasks', true);
    }
}
