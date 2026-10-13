<?php

namespace Tests\Support;

use App\Libraries\UiAuth;
use Config\Services;
use Config\Ui;

/**
 * For feature tests that drive the web UI (/ui): configures the UI account and signs every
 * following request in, the way a browser that went through /ui/login would. The session also
 * holds a known CSRF token (session-backed — see Config\Security); wrap a UI form's fields in
 * uiForm() to submit it, like the hidden field every UI form carries.
 *
 * Use alongside CodeIgniter\Test\FeatureTestTrait and call signInToUi() in setUp().
 */
trait SignsInToUi
{
    protected const UI_USER     = 'operator';
    protected const UI_PASSWORD = 'correct horse battery staple';
    protected const UI_CSRF     = '0123456789abcdef0123456789abcdef';

    /** The UI account the tests sign in with, set on the shared Config\Ui instance. */
    protected function configureUi(array $overrides = []): Ui
    {
        $config = config(Ui::class);

        foreach ($overrides + ['username' => self::UI_USER, 'password' => self::UI_PASSWORD] as $key => $value) {
            $config->{$key} = $value;
        }

        return $config;
    }

    protected function signInToUi(): void
    {
        $this->configureUi();

        $this->withSession([
            UiAuth::SESSION_KEY           => (new UiAuth())->sessionPayload(),
            config('Security')->tokenName => self::UI_CSRF,
        ]);
    }

    /**
     * A UI form's fields plus the CSRF token signInToUi() put in the session.
     *
     * Also drops the shared `security` service: it reads the session's token once, when it's
     * built, and outlives a single test request — a browser's request always gets a fresh one.
     */
    protected function uiForm(array $fields = []): array
    {
        Services::resetSingle('security');

        return $fields + [config('Security')->tokenName => self::UI_CSRF];
    }
}
