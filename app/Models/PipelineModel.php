<?php

namespace App\Models;

use App\Libraries\PipelineIdentity;

/**
 * Model for the `pipelines` table — one row per observatory-pipeline instance (API client).
 *
 * Each pipeline has its own API key; only its SHA-256 is stored (`key_hash`), so the key is shown
 * exactly once, by `php spark pipeline:create` / `pipeline:rotate`. A pipeline is revoked, never
 * deleted: frames and tasks keep pointing at it.
 */
class PipelineModel extends BaseModel
{
    protected $table      = 'pipelines';
    protected $primaryKey = 'id';

    // created_at is handled by the DB DEFAULT — no CI timestamp management needed.
    protected $useTimestamps = false;

    protected $allowedFields = ['id', 'name', 'key_hash', 'last_seen_at', 'last_ip', 'version', 'revoked_at'];

    /**
     * Seen within this many seconds = online. The worker polls at least once a minute when idle
     * (observatory-pipeline's TASK_POLL_BACKOFF_MAX_SEC) and reports progress after every item
     * when busy; the margin covers one slow item between two requests.
     */
    public const ONLINE_WITHIN_SEC = 300;

    /** Seen within this many seconds (but not ONLINE_WITHIN_SEC) = stale; older = offline. */
    public const STALE_WITHIN_SEC = 1800;

    /**
     * The active (not revoked) pipeline owning this API key, or null. The lookup is by the key's
     * SHA-256 on a unique index: the key carries 256 random bits, so comparing hashes in SQL leaks
     * nothing usable about it.
     */
    public function findByKey(string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        return $this->where('key_hash', PipelineIdentity::hashKey($key))
            ->where('revoked_at', null)
            ->first();
    }

    public function findByName(string $name): ?array
    {
        return $this->where('name', $name)->first();
    }

    /**
     * Register a new pipeline. Returns its row and the plain API key — the only time the key is
     * available.
     *
     * @return array{pipeline: array, key: string}
     */
    public function createWithKey(string $name): array
    {
        $key = PipelineIdentity::generateKey();
        $id  = $this->insert(['name' => $name, 'key_hash' => PipelineIdentity::hashKey($key)], true);

        if ($id === false) {
            throw new \RuntimeException('Failed to create pipeline: ' . implode(', ', $this->errors()));
        }

        return ['pipeline' => $this->find($id), 'key' => $key];
    }

    /**
     * Replace a pipeline's key (the old one stops working immediately) and un-revoke it. Its id —
     * and so everything it owns — stays the same.
     *
     * @return string The new plain API key.
     */
    public function rotateKey(string $id): string
    {
        $key = PipelineIdentity::generateKey();
        $this->update($id, ['key_hash' => PipelineIdentity::hashKey($key), 'revoked_at' => null]);

        return $key;
    }

    public function revoke(string $id): void
    {
        $this->update($id, ['revoked_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * Record a successful request from pipeline `$id`. `$version` is its `X-Pipeline-Version`
     * header: kept only when it looks like a version string, and a request without one (an older
     * pipeline, or a hand-made curl call with the key) leaves the stored version as it was.
     */
    public function touch(string $id, ?string $ip, ?string $version = null): void
    {
        $fields = ['last_seen_at' => gmdate('Y-m-d H:i:s'), 'last_ip' => $ip];

        if ($version !== null && preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,31}$/', $version) === 1) {
            $fields['version'] = $version;
        }

        $this->db->table($this->table)->where('id', $id)->update($fields);
    }

    /** @return array<string, string> id => name of every active (not revoked) pipeline, by name. */
    public function getActiveNames(): array
    {
        $rows = $this->select('id, name')->where('revoked_at', null)->orderBy('name', 'ASC')->findAll();

        return array_column($rows, 'name', 'id');
    }

    /** @return array<string, string> id => name of every pipeline, revoked ones included. */
    public function getAllNames(): array
    {
        return array_column($this->select('id, name')->orderBy('name', 'ASC')->findAll(), 'name', 'id');
    }

    /**
     * 'online' | 'stale' | 'offline' for a `last_seen_at` value (stored as UTC).
     */
    public static function statusFor(?string $lastSeenAt): string
    {
        if ($lastSeenAt === null || $lastSeenAt === '') {
            return 'offline';
        }

        $ago = time() - strtotime($lastSeenAt . ' UTC');

        if ($ago < self::ONLINE_WITHIN_SEC) {
            return 'online';
        }

        return $ago < self::STALE_WITHIN_SEC ? 'stale' : 'offline';
    }

    /**
     * Every pipeline with what the dashboard shows about it: heartbeat status, the task it is
     * running right now, how much is queued for it alone, and how many frames it holds.
     *
     * @return list<array<string, mixed>>
     */
    public function getOverview(): array
    {
        $pipelines = $this->orderBy('revoked_at IS NULL', 'DESC', false)->orderBy('name', 'ASC')->findAll();

        if ($pipelines === []) {
            return [];
        }

        $ids = array_column($pipelines, 'id');

        $running = [];
        foreach ($this->db->table('tasks')
            ->select('id, type, claimed_by, completed_items, failed_items, total_items, started_at')
            ->where('status', 'RUNNING')
            ->whereIn('claimed_by', $ids)
            ->orderBy('started_at', 'DESC')
            ->get()->getResultArray() as $task) {
            $running[$task['claimed_by']] ??= $task;
        }

        $pending = [];
        foreach ($this->db->table('tasks')
            ->select('pipeline_id, COUNT(*) AS n')
            ->where('status', 'PENDING')
            ->whereIn('pipeline_id', $ids)
            ->groupBy('pipeline_id')
            ->get()->getResultArray() as $row) {
            $pending[$row['pipeline_id']] = (int) $row['n'];
        }

        $frames = [];
        foreach ($this->db->table('frames')
            ->select('pipeline_id, COUNT(*) AS n')
            ->whereIn('pipeline_id', $ids)
            ->groupBy('pipeline_id')
            ->get()->getResultArray() as $row) {
            $frames[$row['pipeline_id']] = (int) $row['n'];
        }

        foreach ($pipelines as &$pipeline) {
            $id                       = $pipeline['id'];
            $pipeline['status']       = $pipeline['revoked_at'] !== null
                ? 'revoked'
                : self::statusFor($pipeline['last_seen_at']);
            $pipeline['running_task'] = $running[$id] ?? null;
            $pipeline['pending_own']  = $pending[$id] ?? 0;
            $pipeline['frame_count']  = $frames[$id] ?? 0;
        }
        unset($pipeline);

        return $pipelines;
    }
}
