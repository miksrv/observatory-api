<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 13 — clear `is_alert` on every stored SPACE_DEBRIS anomaly.
 *
 * `anomalies.is_alert` is derived at insert time from AnomalyModel::ALERT_TYPES and never
 * recomputed. SPACE_DEBRIS left that list on 2026-09-22 (a satellite/aircraft trail is recorded
 * so a fast mover's track is never erased, but it is nothing an operator must act on), so rows
 * written before then still carry `is_alert = 1` and would keep counting as alerts in the
 * dashboard and the /ui/anomalies filter. Data-only; no schema change.
 */
class SpaceDebrisIsNotAnAlert extends Migration
{
    public function up(): void
    {
        $this->db->query("UPDATE `anomalies` SET `is_alert` = 0 WHERE `anomaly_type` = 'SPACE_DEBRIS'");
    }

    public function down(): void
    {
        $this->db->query("UPDATE `anomalies` SET `is_alert` = 1 WHERE `anomaly_type` = 'SPACE_DEBRIS'");
    }
}
