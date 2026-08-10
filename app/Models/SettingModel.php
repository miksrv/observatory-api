<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `settings` table.
 *
 * Stores pipeline configuration parameters (key-value pairs) that can be
 * dynamically read (and eventually updated) via the API.
 *
 * Unlike other application models this one extends CodeIgniter\Model directly
 * instead of App\Models\BaseModel — the `settings` table uses a plain
 * auto-increment INT primary key, not the uniqid()-based string IDs that
 * BaseModel generates.
 */
class SettingModel extends Model
{
    protected $table      = 'settings';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    // created_at / updated_at are handled by DB DEFAULT CURRENT_TIMESTAMP.
    protected $useTimestamps = false;

    protected $allowedFields = [
        'param',
        'value',
        'description',
        'type',
    ];

    /**
     * Return every setting row, ordered alphabetically by param name.
     *
     * @return array<int, array{id: int, param: string, value: string|null, description: string|null, type: string}>
     */
    public function getAll(): array
    {
        return $this->orderBy('param', 'ASC')->findAll();
    }

    /**
     * Return only user-configurable settings (type = 'config'), ordered alphabetically.
     *
     * @return array<int, array{id: int, param: string, value: string|null, description: string|null, type: string}>
     */
    public function getConfigurable(): array
    {
        return $this->where('type', 'config')->orderBy('param', 'ASC')->findAll();
    }

    /**
     * Return all configurable settings as a flat param → value map (no metadata).
     *
     * This is the format the pipeline client expects: a simple object whose
     * keys are parameter names and whose values are their current string
     * values — easy to merge on top of the local .env defaults.
     *
     * @return array<string, string|null>
     */
    public function getAllAsMap(): array
    {
        $rows = $this->where('type', 'config')->orderBy('param', 'ASC')->findAll();
        $map  = [];

        foreach ($rows as $row) {
            $map[$row['param']] = $row['value'];
        }

        return $map;
    }
}

