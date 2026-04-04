<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Base model with UUID generation for all entities.
 *
 * All application models should extend this class to get automatic
 * ID generation using PHP's uniqid() with more entropy.
 *
 * ID Format: 23 characters, e.g. "6612f8a5e3b9c9.12345678"
 */
class BaseModel extends Model
{
    /**
     * Disable auto-increment — we use string IDs.
     */
    protected $useAutoIncrement = false;

    /**
     * Primary key field name.
     */
    protected $primaryKey = 'id';

    /**
     * Return type for find operations.
     */
    protected $returnType = 'array';

    /**
     * Generate a unique ID for new records.
     *
     * Uses PHP uniqid() with more_entropy for sufficient uniqueness.
     * Format: 23 characters (13 from uniqid + '.' + 8 random hex digits)
     *
     * Example: "6612f8a5e3b9c9.12345678"
     *
     * @return string
     */
    protected function generateId(): string
    {
        return uniqid('', true);
    }

    /**
     * Override insert to auto-generate ID if not provided.
     *
     * @param array|object|null $row       Data to insert
     * @param bool              $returnID  Whether to return the inserted ID
     *
     * @return bool|int|string
     */
    public function insert($row = null, bool $returnID = true)
    {
        if ($row === null) {
            return parent::insert($row, $returnID);
        }

        // Generate ID if not provided
        if (is_array($row)) {
            if (!isset($row['id']) || $row['id'] === null || $row['id'] === '') {
                $row['id'] = $this->generateId();
            }
        } elseif (is_object($row)) {
            if (!isset($row->id) || $row->id === null || $row->id === '') {
                $row->id = $this->generateId();
            }
        }

        $result = parent::insert($row, $returnID);

        // If returnID is true, return the ID we generated/used
        if ($returnID && $result !== false) {
            if (is_array($row)) {
                return $row['id'];
            } elseif (is_object($row)) {
                return $row->id;
            }
        }

        return $result;
    }

    /**
     * Override insertBatch to auto-generate IDs for each row.
     *
     * @param array|null $set       Array of data rows
     * @param bool|null  $escape    Whether to escape values
     * @param int        $batchSize Batch size for insert
     * @param bool       $testing   Whether in testing mode
     *
     * @return bool|int
     */
    public function insertBatch(?array $set = null, ?bool $escape = null, int $batchSize = 100, bool $testing = false)
    {
        if ($set === null) {
            return parent::insertBatch($set, $escape, $batchSize, $testing);
        }

        // Generate IDs for rows that don't have one
        foreach ($set as &$row) {
            if (is_array($row)) {
                if (!isset($row['id']) || $row['id'] === null || $row['id'] === '') {
                    $row['id'] = $this->generateId();
                }
            } elseif (is_object($row)) {
                if (!isset($row->id) || $row->id === null || $row->id === '') {
                    $row->id = $this->generateId();
                }
            }
        }
        unset($row);

        return parent::insertBatch($set, $escape, $batchSize, $testing);
    }
}

