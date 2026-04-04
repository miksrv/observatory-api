<?php

namespace App\Models;

/**
 * Model for the `anomalies` table.
 *
 * Stores classified anomalies detected in frames.
 * Can optionally link to a source if the anomaly is associated with a known object.
 */
class AnomalyModel extends BaseModel
{
    protected $table      = 'anomalies';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    /**
     * Anomaly types that should trigger alerts.
     */
    public const ALERT_TYPES = [
        'SUPERNOVA_CANDIDATE',
        'MOVING_UNKNOWN',
        'SPACE_DEBRIS',
        'UNKNOWN',
    ];

    protected $allowedFields = [
        'id',
        'frame_id',
        'source_id',
        'anomaly_type',
        'ra',
        'dec',
        'magnitude',
        'delta_mag',
        'mpc_designation',
        'ephemeris_predicted_ra',
        'ephemeris_predicted_dec',
        'ephemeris_predicted_mag',
        'ephemeris_distance_au',
        'ephemeris_angular_velocity',
        'notes',
        'is_alert',
    ];

    /**
     * Check if an anomaly type is alert-worthy.
     *
     * @param string $anomalyType The anomaly type to check
     *
     * @return bool True if alert-worthy
     */
    public static function isAlertType(string $anomalyType): bool
    {
        return in_array($anomalyType, self::ALERT_TYPES, true);
    }
}
