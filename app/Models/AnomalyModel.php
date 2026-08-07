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
     * The full, fixed set of anomaly_type values — must match the ENUM constraint
     * on the `anomaly_type` column (2026-04-03-000005_CreateAnomaliesTable.php) and
     * the AnomalyType enum in observatory-pipeline's modules/anomaly_detector.py.
     */
    public const ALLOWED_TYPES = [
        'FIRST_OBSERVATION',
        'KNOWN_CATALOG_NEW',
        'VARIABLE_STAR',
        'BINARY_STAR',
        'SUPERNOVA_CANDIDATE',
        'UNKNOWN',
        'ASTEROID',
        'COMET',
        'MOVING_UNKNOWN',
        'SPACE_DEBRIS',
    ];

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

    /**
     * Check if a string is one of the recognized anomaly_type values.
     *
     * @param string $anomalyType The anomaly type to check
     *
     * @return bool True if it's a valid, known anomaly type
     */
    public static function isValidType(string $anomalyType): bool
    {
        return in_array($anomalyType, self::ALLOWED_TYPES, true);
    }
}
