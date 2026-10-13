<?php

namespace App\Libraries;

use Config\Ui;

/**
 * Sign-in for the operator web UI: the one account from .env (Config\Ui), its session, and the
 * per-address lock after repeated wrong passwords.
 *
 * The session holds a fingerprint of the configured credentials, so changing the username or
 * password in .env signs every browser out on its next request.
 */
class UiAuth
{
    public const SESSION_KEY = 'ui_auth';

    private Ui $config;

    public function __construct(?Ui $config = null)
    {
        $this->config = $config ?? config(Ui::class);
    }

    /** Both credentials are set — otherwise the UI accepts no sign-in at all. */
    public function isConfigured(): bool
    {
        return $this->config->username !== '' && $this->config->password !== '';
    }

    public function username(): string
    {
        return $this->config->username;
    }

    /**
     * Constant-time check of a submitted pair. Both halves are always evaluated, so a wrong
     * username takes as long as a wrong password.
     */
    public function checkCredentials(string $username, string $password): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $userOk = hash_equals($this->config->username, $username);
        $stored = $this->config->password;

        $passOk = self::isHash($stored)
            ? password_verify($password, $stored)
            : hash_equals($stored, $password);

        return $userOk && $passOk;
    }

    public static function isHash(string $value): bool
    {
        return str_starts_with($value, '$2y$') || str_starts_with($value, '$argon2');
    }

    /** Starts a fresh session (new id, the old one destroyed) for the signed-in operator. */
    public function signIn(): void
    {
        $session = session();
        $session->regenerate(true);
        $session->set(self::SESSION_KEY, $this->sessionPayload());
    }

    /**
     * What a signed-in session holds under SESSION_KEY.
     *
     * @return array{user: string, fingerprint: string, signed_in: int, seen: int}
     */
    public function sessionPayload(): array
    {
        return [
            'user'        => $this->config->username,
            'fingerprint' => $this->fingerprint(),
            'signed_in'   => time(),
            'seen'        => time(),
        ];
    }

    public function signOut(): void
    {
        $session = session();
        $session->remove(self::SESSION_KEY);
        $session->regenerate(true);
    }

    /**
     * The signed-in username, or null. Refreshes the idle clock; a session idle for longer than
     * `idleMinutes`, or signed in with credentials that have since changed, is ended here.
     *
     * @return array{user: ?string, reason: ?string} reason: 'expired' when it just timed out
     */
    public function resume(): array
    {
        $data = session(self::SESSION_KEY);

        if (! is_array($data) || ! isset($data['user'], $data['fingerprint'], $data['seen'])) {
            return ['user' => null, 'reason' => null];
        }

        if (! $this->isConfigured() || ! hash_equals($this->fingerprint(), (string) $data['fingerprint'])) {
            $this->signOut();

            return ['user' => null, 'reason' => null];
        }

        if (time() - (int) $data['seen'] > $this->config->idleMinutes * 60) {
            $this->signOut();

            return ['user' => null, 'reason' => 'expired'];
        }

        $data['seen'] = time();
        session()->set(self::SESSION_KEY, $data);

        return ['user' => (string) $data['user'], 'reason' => null];
    }

    /** Seconds until this address may try again, 0 when it isn't locked. */
    public function lockedFor(string $ip): int
    {
        $entry = cache($this->lockKey($ip));

        if (! is_array($entry) || ($entry['failures'] ?? 0) < $this->config->maxFailures) {
            return 0;
        }

        return max(0, (int) $entry['until'] - time());
    }

    /**
     * Counts a wrong sign-in. The window starts at the first failure; reaching `maxFailures`
     * within it locks the address until the window ends.
     *
     * @return int tries left before the lock (0 = now locked)
     */
    public function recordFailure(string $ip): int
    {
        $key   = $this->lockKey($ip);
        $entry = cache($key);
        $ttl   = $this->config->lockMinutes * 60;

        if (! is_array($entry) || (int) ($entry['until'] ?? 0) <= time()) {
            $entry = ['failures' => 0, 'until' => time() + $ttl];
        }

        $entry['failures']++;

        // Reaching the limit restarts the window, so the lock always lasts the full lockMinutes.
        if ($entry['failures'] >= $this->config->maxFailures) {
            $entry['until'] = time() + $ttl;
        }

        cache()->save($key, $entry, max(1, (int) $entry['until'] - time()));

        return max(0, $this->config->maxFailures - $entry['failures']);
    }

    public function clearFailures(string $ip): void
    {
        cache()->delete($this->lockKey($ip));
    }

    private function lockKey(string $ip): string
    {
        return 'ui_login_' . hash('sha256', $ip);
    }

    private function fingerprint(): string
    {
        return hash_hmac('sha256', $this->config->username . "\0" . $this->config->password, 'observatory-ui-session');
    }
}
