<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add the frame's processing outcome — what the pipeline's analysis came to beyond QC — sent by
 * observatory-pipeline 1.7.0+ as `POST /frames`' `processing` block (docs/API.md).
 *
 * `quality_flag` only says whether QC accepted the frame. A QC-OK frame can still be analysed
 * incompletely: astap found no solution, Gaia answered 503 so nothing was calibrated, a step
 * raised. The pipeline logs and goes on, and the frame looked like any other in every list — these
 * columns let the operator find such frames and re-run ANALYZE on them.
 *
 * All NULL for a frame registered by an older pipeline (or before this migration): "unknown",
 * shown as such, never as OK.
 */
class AddFrameProcessing extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('frames', [
            'processing_status' => [
                'type'       => 'ENUM',
                'constraint' => ['OK', 'DEGRADED', 'FAILED', 'SKIPPED'],
                'null'       => true,
                'after'      => 'qc_eccentricity',
            ],
            // JSON array of issue codes, e.g. ["catalog_failed:Gaia DR3", "calibration_failed"].
            'processing_issues' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'processing_status',
            ],
            'astrometry_solved' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'after'      => 'processing_issues',
            ],
            // JSON object catalog => ok | failed | disabled | not_applicable.
            'catalog_status' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'astrometry_solved',
            ],
            'calibration' => [
                'type'       => 'ENUM',
                'constraint' => ['calibrated', 'skipped', 'failed'],
                'null'       => true,
                'after'      => 'catalog_status',
            ],
            'zero_point' => [
                'type'  => 'FLOAT',
                'null'  => true,
                'after' => 'calibration',
            ],
            'zero_point_err' => [
                'type'  => 'FLOAT',
                'null'  => true,
                'after' => 'zero_point',
            ],
            'subtraction_performed' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'after'      => 'zero_point_err',
            ],
            'subtraction_reference_count' => [
                'type'       => 'SMALLINT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'subtraction_performed',
            ],
        ]);

        $this->forge->addKey('processing_status', false, false, 'idx_frames_processing_status');
        $this->forge->processIndexes('frames');
    }

    public function down(): void
    {
        $this->forge->dropKey('frames', 'idx_frames_processing_status', false);
        $this->forge->dropColumn('frames', [
            'processing_status',
            'processing_issues',
            'astrometry_solved',
            'catalog_status',
            'calibration',
            'zero_point',
            'zero_point_err',
            'subtraction_performed',
            'subtraction_reference_count',
        ]);
    }
}
