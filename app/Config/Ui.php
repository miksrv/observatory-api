<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Operator web UI (/ui, App\Controllers\Web\*) sign-in. Every property is overridable from .env
 * as `ui.<property>` (BaseConfig), which is where the credentials are meant to live.
 *
 * With `username` or `password` empty the UI refuses every sign-in — it is never served
 * unauthenticated.
 */
class Ui extends BaseConfig
{
    public string $username = '';

    /**
     * Either the password itself or a password_hash() of it (`$2y$…` / `$argon2…`), recognised by
     * its prefix. Generate a hash with `php spark ui:password`.
     */
    public string $password = '';

    /** Sign the browser out after this many minutes without a request. */
    public int $idleMinutes = 120;

    /** This many wrong sign-ins from one address within `lockMinutes` lock it for `lockMinutes`. */
    public int $maxFailures = 5;

    public int $lockMinutes = 15;
}
