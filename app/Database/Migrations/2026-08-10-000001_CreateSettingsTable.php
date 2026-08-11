<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration — Create the `settings` table.
 *
 * Stores pipeline configuration parameters that can be dynamically updated.
 */
class CreateSettingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'auto_increment' => true,
            ],
            'param' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'value' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'description' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['config', 'internal'],
                'default'    => 'config',
                'null'       => false,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
            'updated_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('param');
        $this->forge->addKey('type', false, false, 'idx_settings_type');
        $this->forge->addKey('created_at', false, false, 'idx_settings_created_at');
        $this->forge->addKey('updated_at', false, false, 'idx_settings_updated_at');

        $this->forge->createTable('settings', true);

        // Set default for created_at and updated_at via raw SQL
        $this->db->query('ALTER TABLE `settings` MODIFY `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP');
        $this->db->query('ALTER TABLE `settings` MODIFY `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

        // Insert default values from config.py
        $this->insertDefaultSettings();
    }

    public function down(): void
    {
        $this->forge->dropTable('settings', true);
    }

    private function insertDefaultSettings(): void
    {
        // Get the list of all configuration parameters from the pipeline config
        // and insert them into the database with their default values

        $defaultSettings = [
            // FITS directory paths
            ['param' => 'FITS_INCOMING', 'value' => '/fits/incoming', 'description' => 'Incoming FITS files directory'],
            ['param' => 'FITS_ARCHIVE', 'value' => '/fits/archive', 'description' => 'Archived FITS files directory'],
            ['param' => 'FITS_REJECTED', 'value' => '/fits/rejected', 'description' => 'Rejected FITS files directory'],

            // ASTAP plate solver
            ['param' => 'ASTAP_BINARY', 'value' => '/usr/local/bin/astap', 'description' => 'Path to astap binary'],
            ['param' => 'ASTAP_CATALOGS', 'value' => '/astap/catalogs', 'description' => 'Path to astap catalogs'],
            ['param' => 'ASTAP_FOV_HINT', 'value' => '0', 'description' => 'Optional FOV hint in degrees (0 = auto-detect from FITS headers)'],

            // Quality control thresholds
            ['param' => 'QC_FWHM_MAX_ARCSEC', 'value' => '8.0', 'description' => 'Maximum acceptable median FWHM in arcseconds'],
            ['param' => 'QC_ELONGATION_MAX', 'value' => '2.0', 'description' => 'Maximum acceptable median elongation'],
            ['param' => 'QC_SNR_MIN', 'value' => '5.0', 'description' => 'Minimum acceptable median SNR'],
            ['param' => 'QC_STARS_MIN', 'value' => '10', 'description' => 'Minimum stars threshold for good frames'],
            ['param' => 'QC_SKY_BACKGROUND_MAX', 'value' => '50000.0', 'description' => 'Maximum acceptable median sky background (ADU)'],
            ['param' => 'QC_STARS_MIN_NARROWBAND', 'value' => '5', 'description' => 'Star-count floor for narrowband frames'],

            // Narrowband filters
            ['param' => 'NARROWBAND_FILTERS', 'value' => 'Ha,OIII,SII,NII', 'description' => 'Emission-line filters whose bandpass is too narrow to carry a representative sample of field stars'],

            // Star detection filtering
            ['param' => 'STAR_FWHM_MIN_ARCSEC', 'value' => '2.5', 'description' => 'Minimum acceptable star FWHM in arcseconds'],
            ['param' => 'STAR_FWHM_MAX_ARCSEC', 'value' => '8.0', 'description' => 'Maximum acceptable star FWHM in arcseconds'],
            ['param' => 'STAR_ELONGATION_MAX', 'value' => '1.5', 'description' => 'Maximum acceptable star elongation'],
            ['param' => 'STAR_SNR_MIN', 'value' => '50.0', 'description' => 'Minimum acceptable star SNR'],

            // SEP source extraction parameters
            ['param' => 'SEP_DETECT_THRESH', 'value' => '10.0', 'description' => 'Detection threshold (sigma above background)'],
            ['param' => 'SEP_MIN_AREA', 'value' => '15', 'description' => 'Minimum connected pixels'],

            // Streak masking
            ['param' => 'STREAK_DETECT_SIGMA', 'value' => '3.0', 'description' => 'Coarse detection threshold for streak masking (sigma above background)'],
            ['param' => 'STREAK_ELONGATION_MIN', 'value' => '5.0', 'description' => 'Minimum elongation for a feature to be considered a streak'],
            ['param' => 'STREAK_MIN_LENGTH_ARCSEC', 'value' => '30.0', 'description' => 'Minimum length of a streak in arcseconds'],
            ['param' => 'STREAK_MASK_DILATE_ARCSEC', 'value' => '3.0', 'description' => 'Dilation applied to the coarse streak mask in arcseconds'],

            // Saturation detection
            ['param' => 'SATURATION_ADU', 'value' => '60000', 'description' => 'Sensor pixel value (ADU) at/above which a pixel is considered saturated'],
            ['param' => 'SATURATION_MASK_RADIUS_ARCSEC', 'value' => '10.0', 'description' => 'Radius around a saturated pixel that is excluded from difference-image source detection in arcseconds'],

            // Cross-matching
            ['param' => 'MATCH_CONE_ARCSEC', 'value' => '5.0', 'description' => 'Cross-matching cone search radius in arcseconds'],
            ['param' => 'MOVING_CONE_ARCSEC', 'value' => '120.0', 'description' => 'Widened cone search radius for moving objects in arcseconds'],
            ['param' => 'DELTA_MAG_ALERT', 'value' => '0.5', 'description' => 'Magnitude change threshold for alerting anomalies'],
            ['param' => 'MPC_MAG_LIMIT', 'value' => '19.0', 'description' => 'Faintest predicted visual magnitude (V) for an MPC/SkyBot object to be eligible for source matching'],

            // Edge-of-frame geometry
            ['param' => 'EDGE_MARGIN_FRAC', 'value' => '0.1', 'description' => 'Fraction of the frame\'s width/height treated as "near the edge"'],
            ['param' => 'SPACE_DEBRIS_ELONGATION_MIN', 'value' => '3.0', 'description' => 'Elongation threshold for single-exposure trail classification'],
            ['param' => 'SPACE_DEBRIS_EDGE_ELONGATION_MIN', 'value' => '6.0', 'description' => 'Elongation threshold for single-exposure trail classification near frame edges'],

            // Image subtraction
            ['param' => 'SUBTRACTION_MIN_FRAMES', 'value' => '3', 'description' => 'Minimum number of archived reference frames required to attempt subtraction'],
            ['param' => 'SUBTRACTION_DETECT_SIGMA', 'value' => '5.0', 'description' => 'Detection threshold on the difference image (multiples of background RMS)'],

            // Forced photometry
            ['param' => 'FORCED_PHOTOMETRY_ENABLED', 'value' => 'true', 'description' => 'Enable or disable the reverse-matching forced photometry pass'],
            ['param' => 'FORCED_PHOTOMETRY_MAG_LIMIT', 'value' => '20.0', 'description' => 'Faintest Gaia DR3 G-band magnitude eligible for forced photometry'],
            ['param' => 'FORCED_PHOTOMETRY_MIN_SNR', 'value' => '3.0', 'description' => 'Minimum significance (net_flux / flux_err) for a forced-photometry measurement to be reported'],

            // Observatory site coordinates
            ['param' => 'SITE_LAT', 'value' => '0.0', 'description' => 'Site latitude in degrees (positive = North)'],
            ['param' => 'SITE_LON', 'value' => '0.0', 'description' => 'Site longitude in degrees (positive = East)'],
            ['param' => 'SITE_ELEV', 'value' => '0', 'description' => 'Site elevation in metres above sea level'],

            // Finder charts
            ['param' => 'CHART_ENABLED', 'value' => 'true', 'description' => 'Enable or disable chart generation'],
            ['param' => 'CHART_STAMP_SIZE_ARCSEC', 'value' => '60.0', 'description' => 'Half-width of the per-epoch crop used by the "stamp_strip" style in arcseconds'],
            ['param' => 'CHART_MAX_EPOCHS', 'value' => '12', 'description' => 'Cap on the number of epochs drawn on one chart'],

            // Normalization settings
            ['param' => 'NORMALIZE_ENABLED', 'value' => 'true', 'description' => 'Enable or disable FITS header normalization'],

            // Catalog query cache
            ['param' => 'CATALOG_CACHE_DIR', 'value' => '/cache/catalog', 'description' => 'On-disk cache directory for external catalog query results'],
            ['param' => 'CACHE_TTL_HOURS', 'value' => '1.0', 'description' => 'How long a cached catalog query result stays valid in hours'],

            // Watcher batching
            ['param' => 'WATCHER_DEBOUNCE_SEC', 'value' => '5.0', 'description' => 'How long to wait after the most recently arrived FITS file before submitting tasks'],
            ['param' => 'WATCHER_MAX_BATCH_SIZE', 'value' => '200', 'description' => 'Flush the pending batch immediately once it reaches this many files'],

            // Task queue worker
            ['param' => 'TASK_POLL_INTERVAL_SEC', 'value' => '10.0', 'description' => 'How often the worker polls GET /tasks?status=PENDING when idle, in seconds'],
            ['param' => 'TASK_POLL_BACKOFF_MAX_SEC', 'value' => '60.0', 'description' => 'Idle polling backs off exponentially up to this ceiling'],

            // Logging
            ['param' => 'LOG_LEVEL', 'value' => 'INFO', 'description' => 'Log verbosity level'],

            // Pipeline heartbeat
            ['param' => 'pipeline_last_seen_at', 'value' => null, 'description' => 'UTC timestamp of the last authenticated API request from observatory-pipeline', 'type' => 'internal'],
        ];

        foreach ($defaultSettings as $setting) {
            $sql = "INSERT INTO settings (param, value, description, type) VALUES (?, ?, ?, ?)";
            $this->db->query($sql, [$setting['param'], $setting['value'], $setting['description'], $setting['type'] ?? 'config']);
        }
    }
}