<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add `RUNNING` to `task_items.status`, between PENDING and the terminal DONE/FAILED.
 *
 * observatory-pipeline 1.3.0 reports an item RUNNING via POST /tasks/{id}/items/progress when it
 * starts on it, so the web UI can show which item is being processed right now. RUNNING never
 * counts toward the task's completed/failed totals — only DONE/FAILED resolve an item.
 *
 * down() folds any RUNNING item back to PENDING before narrowing the ENUM, so the rollback can't
 * fail on (or silently blank) rows the old ENUM no longer allows.
 */
class AddRunningToTaskItemStatus extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE `task_items` MODIFY `status` ENUM('PENDING', 'RUNNING', 'DONE', 'FAILED') NOT NULL DEFAULT 'PENDING'");
    }

    public function down(): void
    {
        $this->db->query("UPDATE `task_items` SET `status` = 'PENDING' WHERE `status` = 'RUNNING'");
        $this->db->query("ALTER TABLE `task_items` MODIFY `status` ENUM('PENDING', 'DONE', 'FAILED') NOT NULL DEFAULT 'PENDING'");
    }
}
