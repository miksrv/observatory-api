<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateFramesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'filename' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'original_filepath' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
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
            'quality_flag' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
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
        $this->forge->addKey(['ra_center', 'dec_center']);
        $this->forge->addKey('obs_time');
        $this->forge->addKey('filename');

        $this->forge->createTable('frames', true);

        // Set created_at default to CURRENT_TIMESTAMP via raw ALTER — forge does not
        // support CURRENT_TIMESTAMP as a column default in addField.
        $this->db->query('ALTER TABLE `frames` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        $this->forge->dropTable('frames', true);
    }
}
