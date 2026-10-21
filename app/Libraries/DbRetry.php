<?php

namespace App\Libraries;

use Throwable;

/**
 * Re-run a unit of database work that lost a lock conflict.
 *
 * MariaDB/MySQL resolve a deadlock (1213) by rolling one transaction back, and a lock wait
 * timeout (1205) aborts the waiting statement; both say nothing about the data, so the whole
 * transaction can simply be run again. Two pipelines saving overlapping fields in a different
 * order (POST /frames/{id}/sources) can hit either. Every other error is rethrown at once.
 */
final class DbRetry
{
    /** ER_LOCK_DEADLOCK, ER_LOCK_WAIT_TIMEOUT */
    public const LOCK_CONFLICT_CODES = [1213, 1205];

    /** Whether `$e` (or anything it wraps) is a deadlock or a lock wait timeout. */
    public static function isLockConflict(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (in_array((int) $cause->getCode(), self::LOCK_CONFLICT_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run `$attempt` (a whole transaction, already rolled back when it throws), retrying it on a
     * lock conflict up to `$maxAttempts` times in all with a short, growing, jittered pause.
     * `$beforeRetry` restores whatever in-memory state the failed attempt changed.
     *
     * @template T
     *
     * @param callable(): T               $attempt
     * @param callable(int, Throwable)|null $beforeRetry receives the failed attempt's number and error
     *
     * @return T
     */
    public static function onLockConflict(callable $attempt, int $maxAttempts = 3, ?callable $beforeRetry = null, int $baseDelayMs = 100): mixed
    {
        for ($n = 1; ; $n++) {
            try {
                return $attempt();
            } catch (Throwable $e) {
                if ($n >= $maxAttempts || ! self::isLockConflict($e)) {
                    throw $e;
                }

                if ($beforeRetry !== null) {
                    $beforeRetry($n, $e);
                }

                if ($baseDelayMs > 0) {
                    usleep(($baseDelayMs * $n + random_int(0, $baseDelayMs)) * 1000);
                }
            }
        }
    }
}
