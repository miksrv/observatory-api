<?php

namespace App\Models;

/**
 * Model for the `frames` table.
 *
 * Stores metadata for FITS image frames processed by the pipeline.
 */
class FrameModel extends BaseModel
{
    protected $table      = 'frames';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    protected $allowedFields = [
        'id',
        'filename',
        'original_filepath',
        'obs_time',
        'ra_center',
        'dec_center',
        'fov_deg',
        'quality_flag',
        'object',
        'exptime',
        'filter',
        'frame_type',
        'airmass',
        'telescope',
        'camera',
        'focal_length_mm',
        'aperture_mm',
        'sensor_temp',
        'sensor_temp_setpoint',
        'binning_x',
        'binning_y',
        'gain',
        'offset',
        'width_px',
        'height_px',
        'observer_name',
        'site_name',
        'site_lat',
        'site_lon',
        'site_elev_m',
        'software_capture',
        'qc_fwhm_median',
        'qc_elongation',
        'qc_snr_median',
        'qc_sky_background',
        'qc_star_count',
        'qc_eccentricity',
    ];
}
