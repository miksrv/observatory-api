<?php

namespace Tests\Feature;

use App\Libraries\UiAuth;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Ui;
use Tests\Support\DatabaseTestCase;
use Tests\Support\SignsInToUi;

/**
 * Sign-in for the web UI (Web\AuthController, UiAuthFilter, App\Libraries\UiAuth): every /ui page
 * but the form needs a session, the account comes from Config\Ui (.env), wrong passwords lock the
 * address, and every POST needs the session's CSRF token.
 *
 * @internal
 */
final class UiAuthTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use SignsInToUi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureUi(['maxFailures' => 5, 'lockMinutes' => 15, 'idleMinutes' => 120]);
        $this->clearLocks();
    }

    protected function tearDown(): void
    {
        $this->clearLocks();
        parent::tearDown();
    }

    /** The test requests' client address — whatever it is, no lock may leak between tests. */
    private function clearLocks(): void
    {
        foreach (['0.0.0.0', '127.0.0.1', '::1'] as $ip) {
            (new UiAuth())->clearFailures($ip);
        }
    }

    /** A sign-in attempt from a browser that loaded the form (its session holds the CSRF token). */
    private function attempt(string $username, string $password, string $next = '/ui')
    {
        return $this->withSession([config('Security')->tokenName => self::UI_CSRF])
            ->post('/ui/login', $this->uiForm(['username' => $username, 'password' => $password, 'next' => $next]));
    }

    // -------------------------------------------------------------------------
    // The guard
    // -------------------------------------------------------------------------

    public function testAPageWithoutASessionGoesToSignInAndComesBack(): void
    {
        $this->get('/ui/frames?object=M31')
            ->assertRedirectTo('/ui/login?next=' . rawurlencode('/ui/frames?object=M31'));
    }

    public function testTheOverviewWithoutASessionGoesToSignIn(): void
    {
        $this->get('/ui')->assertRedirectTo('/ui/login');
    }

    public function testAPostWithoutASessionDoesNothing(): void
    {
        $tasks = $this->db()->table('tasks')->countAllResults();

        $this->withSession([config('Security')->tokenName => self::UI_CSRF])
            ->post('/ui/settings', $this->uiForm(['setting_1' => 'x']))
            ->assertRedirectTo('/ui/login');

        $this->assertSame($tasks, $this->db()->table('tasks')->countAllResults());
    }

    public function testTheSignInFormIsOpen(): void
    {
        $result = $this->get('/ui/login');

        $result->assertStatus(200);
        $result->assertSee('name="password"', null);
        $result->assertHeader('X-Frame-Options', 'DENY');
    }

    public function testSignedInPagesAreNeverCachedOrFramed(): void
    {
        $this->signInToUi();
        $result = $this->get('/ui');

        $result->assertStatus(200);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $result->assertHeader('X-Frame-Options', 'DENY');
        $result->assertSee(self::UI_USER);
    }

    public function testTheApiIsNotBehindTheUiSignIn(): void
    {
        $this->withHeaders(['X-API-Key' => (string) config('App')->apiKey])
            ->get('/api/v1/settings')
            ->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // Signing in and out
    // -------------------------------------------------------------------------

    public function testTheRightCredentialsSignInAndOpenTheRequestedPage(): void
    {
        $result = $this->attempt(self::UI_USER, self::UI_PASSWORD, '/ui/tasks?status=FAILED');

        $result->assertRedirectTo('/ui/tasks?status=FAILED');
        $result->assertSessionHas(UiAuth::SESSION_KEY);
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $result = $this->attempt(self::UI_USER, 'wrong');

        $result->assertStatus(401);
        $result->assertSee('Неверный логин или пароль');
        $result->assertSessionMissing(UiAuth::SESSION_KEY);
    }

    public function testAWrongUsernameIsRefused(): void
    {
        $this->attempt('admin', self::UI_PASSWORD)->assertStatus(401);
    }

    public function testAHashedPasswordFromEnvWorks(): void
    {
        $this->configureUi(['password' => password_hash(self::UI_PASSWORD, PASSWORD_DEFAULT)]);

        $this->attempt(self::UI_USER, self::UI_PASSWORD)->assertSessionHas(UiAuth::SESSION_KEY);
        $this->attempt(self::UI_USER, 'wrong')->assertStatus(401);
    }

    public function testWithoutCredentialsInEnvNobodySignsIn(): void
    {
        $this->configureUi(['username' => '', 'password' => '']);

        $result = $this->attempt('', '');

        $result->assertStatus(200);
        $result->assertSee('Вход отключён');
        $result->assertSessionMissing(UiAuth::SESSION_KEY);
    }

    public function testASignInWithoutTheCsrfTokenIsRefused(): void
    {
        $result = $this->post('/ui/login', ['username' => self::UI_USER, 'password' => self::UI_PASSWORD]);

        $result->assertSessionMissing(UiAuth::SESSION_KEY);
        $result->assertSessionHas('error');
    }

    /**
     * @dataProvider unsafeNextProvider
     */
    public function testSignInNeverRedirectsOutsideTheUi(string $next): void
    {
        $this->attempt(self::UI_USER, self::UI_PASSWORD, $next)->assertRedirectTo('/ui');
    }

    public static function unsafeNextProvider(): array
    {
        return [
            'another host'          => ['https://evil.example/ui'],
            'protocol-relative'     => ['//evil.example/ui'],
            'backslash host'        => ['/ui\\..\\\\evil.example'],
            'outside the ui'        => ['/api/v1/settings'],
            'back to the sign-in'   => ['/ui/login'],
            'look-alike prefix'     => ['/uix'],
        ];
    }

    public function testSigningOutEndsTheSession(): void
    {
        $this->signInToUi();

        $result = $this->post('/ui/logout', $this->uiForm());

        $result->assertRedirectTo('/ui/login');
        $result->assertSessionMissing(UiAuth::SESSION_KEY);
    }

    // -------------------------------------------------------------------------
    // The session's lifetime
    // -------------------------------------------------------------------------

    public function testAnIdleSessionExpires(): void
    {
        $this->configureUi();
        $payload         = (new UiAuth())->sessionPayload();
        $payload['seen'] = time() - config(Ui::class)->idleMinutes * 60 - 1;

        $result = $this->withSession([UiAuth::SESSION_KEY => $payload])->get('/ui');

        $result->assertRedirectTo('/ui/login');
        $result->assertSessionHas('notice');
        $result->assertSessionMissing(UiAuth::SESSION_KEY);
    }

    public function testChangingThePasswordInEnvSignsEveryoneOut(): void
    {
        $this->signInToUi();
        $this->configureUi(['password' => 'a new password']);

        $this->get('/ui')->assertRedirectTo('/ui/login');
    }

    // -------------------------------------------------------------------------
    // Lock after wrong passwords
    // -------------------------------------------------------------------------

    public function testTooManyWrongPasswordsLockTheAddressEvenForTheRightOne(): void
    {
        $this->configureUi(['maxFailures' => 3]);

        $this->attempt(self::UI_USER, 'wrong-1')->assertStatus(401);

        $second = $this->attempt(self::UI_USER, 'wrong-2');
        $second->assertStatus(401);
        $second->assertSee('Осталось 1');

        $third = $this->attempt(self::UI_USER, 'wrong-3');
        $third->assertStatus(429);
        $third->assertSee('заблокирован');

        $result = $this->attempt(self::UI_USER, self::UI_PASSWORD);
        $result->assertStatus(429);
        $result->assertSessionMissing(UiAuth::SESSION_KEY);
    }

    public function testASuccessfulSignInForgetsEarlierFailures(): void
    {
        $this->configureUi(['maxFailures' => 3]);

        $this->attempt(self::UI_USER, 'wrong-1');
        $this->attempt(self::UI_USER, 'wrong-2');
        $this->attempt(self::UI_USER, self::UI_PASSWORD)->assertSessionHas(UiAuth::SESSION_KEY);

        // The counter restarted: two more failures still don't lock.
        $this->attempt(self::UI_USER, 'wrong-3')->assertStatus(401);
        $this->attempt(self::UI_USER, 'wrong-4')->assertStatus(401);
    }
}
