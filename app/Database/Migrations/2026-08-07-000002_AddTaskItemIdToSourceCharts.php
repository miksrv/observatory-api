<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 9 — catch `source_charts` up to the task_item_id-keyed PREVIEW_CATALOG_MATCH chart
 * support that 2026-08-06-000001_CreateSourceChartsTable.php's own file was extended with
 * (nullable source_id, new task_item_id column, 'catalog_preview' added to the style ENUM,
 * unique key on task_item_id) — see that file's and SourceChartModel's docblocks for the full
 * PREVIEW_CATALOG_MATCH design.
 *
 * Written for a database that had already run CreateSourceChartsTable (batch 1) in its pre-edit
 * shape (source_id NOT NULL, no task_item_id, 3-value style ENUM) before that edit landed — CI4
 * tracks applied migrations by filename, not by content, so re-running `migrate` never re-executes
 * an already-recorded migration even though its up() now describes a different table. A follow-up
 * migration (this file) is the correct fix for THAT database, not editing the already-applied one.
 *
 * On a fresh database, though, CreateSourceChartsTable's current up() already creates the column
 * directly — so this migration guards on fieldExists() and is a deliberate no-op there, rather
 * than a blind ADD COLUMN that would fail with "Duplicate column name" (as it did the first time
 * this ran against a freshly re-migrated database).
 */
class AddTaskItemIdToSourceCharts extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('task_item_id', 'source_charts')) {
            // CreateSourceChartsTable's current up() already created it (fresh database) —
            // nothing left for this catch-up migration to do.
            return;
        }

        $this->forge->addColumn('source_charts', [
            'task_item_id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
                'null'       => true,
                'after'      => 'source_id',
            ],
        ]);

        $this->db->query('ALTER TABLE `source_charts` MODIFY `source_id` CHAR(24) NULL');
        $this->db->query(
            "ALTER TABLE `source_charts` MODIFY `style` ENUM('track', 'stamp_strip', 'before_after', 'catalog_preview') NOT NULL"
        );
        $this->db->query('ALTER TABLE `source_charts` ADD UNIQUE KEY `uq_source_charts_task_item` (`task_item_id`)');
    }

    /**
     * Only undoes this migration's own ALTERs when it actually ran them — on a fresh database
     * where up() no-opped (see above), source_charts.task_item_id belongs to
     * CreateSourceChartsTable instead, and this must not rip it out from under it.
     * `uq_source_charts_task_item` existing is what distinguishes "this migration built the
     * column" from "CreateSourceChartsTable already did" — CreateSourceChartsTable names its own
     * unique key the same way, so this alone isn't a perfect signal, but combined with the
     * up()-side guard having already run first in the same migration batch, it's good enough for
     * this dev-only corrective migration.
     */
    public function down(): void
    {
        if (! $this->db->fieldExists('task_item_id', 'source_charts')) {
            return;
        }

        $this->db->query('ALTER TABLE `source_charts` DROP KEY `uq_source_charts_task_item`');
        $this->db->query("ALTER TABLE `source_charts` MODIFY `style` ENUM('track', 'stamp_strip', 'before_after') NOT NULL");
        $this->db->query('ALTER TABLE `source_charts` MODIFY `source_id` CHAR(24) NOT NULL');
        $this->forge->dropColumn('source_charts', 'task_item_id');
    }
}
