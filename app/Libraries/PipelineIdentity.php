<?php

namespace App\Libraries;

/**
 * Which observatory-pipeline instance the current API request comes from.
 *
 * Several pipelines (one per observatory server, each with its own local FITS archive) share
 * this API and its database. Each authenticates with its own API key (`pipelines.key_hash`);
 * ApiKeyFilter::before() resolves the key to its `pipelines` row and records it here, so the
 * identity cannot be misconfigured or claimed by another client. It is what:
 *   - `frames.pipeline_id` records — the pipeline that holds the frame's file;
 *   - `tasks.pipeline_id` targets — only that pipeline may claim the task (NULL = any);
 *   - the per-pipeline heartbeat updates (ApiKeyFilter::after()).
 *
 * Request-scoped: set at the start of every /api/v1 request (reset first, so nothing can leak
 * between requests of a long-lived worker process), unset for the Web UI.
 */
final class PipelineIdentity
{
    /** @var array<string, mixed>|null */
    private static ?array $current = null;

    /** Display names: same character set as a hostname/slug, safe in the UI and logs. */
    private const NAME_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public static function set(?array $pipeline): void
    {
        self::$current = $pipeline;
    }

    /** @return array<string, mixed>|null The authenticated `pipelines` row. */
    public static function current(): ?array
    {
        return self::$current;
    }

    /** The authenticated pipeline's id, or null outside an authenticated API request. */
    public static function currentId(): ?string
    {
        return self::$current['id'] ?? null;
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /** A new API key: 256 random bits, hex-encoded. Shown once, only its hash is stored. */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }
}
