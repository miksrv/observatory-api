<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 1 — Create the `frames` table.
 *
 * Stores metadata for FITS image frames processed by the pipeline.
 * Uses CHAR(24) primary key for uniqid-generated IDs.
 */
class CreateFramesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'CHAR',
                'constraint' => 24,
            ],
            'filename' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'obs_time' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'ra_center' => [
                'type' => 'DOUBLE',
                'null' => false,
            ],
            'dec_center' => [
                'type' => 'DOUBLE',
                'null' => false,
            ],
            'fov_deg' => [
                'type' => 'FLOAT',
                'null' => false,
            ],
            // This frame's own orientation on the sky (0 = North up, increasing
            // clockwise toward the image's +X pixel axis), derived by the pipeline
            // from the solved WCS — see observatory-pipeline's CLAUDE.md, "camera
            // rotation" discussion. Nullable: astrometry may never have run (a
            // QC-rejected frame) or its WCS round-trip may have failed. Purely
            // diagnostic (e.g. spotting a ~180 deg difference between sessions,
            // such as a meridian flip, without re-opening archived FITS files) —
            // nothing in this schema enforces anything based on its value.
            'position_angle_deg' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            // Mount pointing error for this frame: the angular distance between the
            // mount's own reported target position (RA/DEC or OBJCTRA/OBJCTDEC FITS
            // header keywords) and this frame's actual plate-solved centre
            // (ra_center/dec_center) — i.e. how far off the mount's pointing was at
            // capture time. Computed by observatory-pipeline's
            // pipeline._compute_pointing_error() — see that repo's CLAUDE.md, pipeline.py
            // step 11. Nullable for the same reasons as position_angle_deg: no
            // mount-reported target in the header, or astrometry never solved this frame.
            //
            // Unlike every other column here, these three are NOT overwritten by a later
            // re-analysis of the same filename (see FramesController::create()) — they
            // characterize the mount's pointing behavior at the frame's ORIGINAL capture
            // time, and a re-analysis re-solving the same archived pixels would just
            // report substantially the same number again, not a meaningful update.
            'pointing_error_arcsec' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            // Signed East-West component of the same offset (positive = solve is East
            // of the mount's reported position) — diagnostic only, e.g. characterizing a
            // systematic polar-alignment drift direction. Same preserve-on-re-analysis
            // rule as pointing_error_arcsec.
            'pointing_error_ra_arcsec' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            // Signed North-South component of the same offset. Same preserve-on-re-analysis
            // rule as pointing_error_arcsec.
            'pointing_error_dec_arcsec' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'quality_flag' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'OK',
            ],
            'object' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'exptime' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'filter' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'frame_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'airmass' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'telescope' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'camera' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'focal_length_mm' => [
                'type' => 'INT',
                'null' => true,
            ],
            'aperture_mm' => [
                'type' => 'INT',
                'null' => true,
            ],
            'sensor_temp' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'sensor_temp_setpoint' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'binning_x' => [
                'type' => 'TINYINT',
                'null' => true,
            ],
            'binning_y' => [
                'type' => 'TINYINT',
                'null' => true,
            ],
            'gain' => [
                'type' => 'INT',
                'null' => true,
            ],
            'offset' => [
                'type' => 'INT',
                'null' => true,
            ],
            'width_px' => [
                'type' => 'INT',
                'null' => true,
            ],
            'height_px' => [
                'type' => 'INT',
                'null' => true,
            ],
            'observer_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'site_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'site_lat' => [
                'type' => 'DOUBLE',
                'null' => true,
            ],
            'site_lon' => [
                'type' => 'DOUBLE',
                'null' => true,
            ],
            'site_elev_m' => [
                'type' => 'INT',
                'null' => true,
            ],
            'software_capture' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'qc_fwhm_median' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'qc_elongation' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'qc_snr_median' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'qc_sky_background' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'qc_star_count' => [
                'type' => 'INT',
                'null' => true,
            ],
            'qc_eccentricity' => [
                'type' => 'FLOAT',
                'null' => true,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['ra_center', 'dec_center'], false, false, 'idx_frames_coords');
        $this->forge->addKey('obs_time', false, false, 'idx_frames_obs_time');
        // Unique, not just indexed: one FITS file = one `frames` row. Without this,
        // re-running an ANALYZE task on a file that was already registered (e.g.
        // re-analysis after improving the detection algorithm) mints a brand-new
        // frame_id every time instead of updating the existing row — the exact bug
        // FramesController::create()'s upsert-by-filename logic exists to close (real
        // incident, 2026-08-12: one re-analyzed frame produced a second `frames` row
        // and its `source_observations` piled up alongside the stale first run's
        // instead of replacing them). This also doubles as the DB-level backstop for
        // that upsert's own find-then-insert-or-update race.
        $this->forge->addUniqueKey('filename', 'uniq_frames_filename');

        $this->forge->createTable('frames', true);

        // Set default for created_at via raw SQL (CI4 forge doesn't support CURRENT_TIMESTAMP default well)
        $this->db->query('ALTER TABLE `frames` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('frames', true);
    }
}

