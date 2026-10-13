<?php

namespace App\Controllers\Web;

use App\Libraries\UiAuth;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Sign-in and sign-out for the operator web UI. The account is the one in .env (`ui.username`,
 * `ui.password` — see Config\Ui); UiAuthFilter sends every other /ui page here without a session.
 */
class AuthController extends BaseWebController
{
    public function login(): ResponseInterface
    {
        $auth = new UiAuth();

        if ($auth->resume()['user'] !== null) {
            return redirect()->to($this->safeNext($this->request->getGet('next')));
        }

        return $this->form($auth, [
            'next'     => $this->safeNext($this->request->getGet('next')),
            'username' => '',
        ]);
    }

    public function attempt(): ResponseInterface
    {
        $auth     = new UiAuth();
        $ip       = $this->request->getIPAddress();
        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');
        $next     = $this->safeNext($this->request->getPost('next'));
        $data     = ['next' => $next, 'username' => $username];

        if (! $auth->isConfigured()) {
            return $this->form($auth, $data);
        }

        $locked = $auth->lockedFor($ip);
        if ($locked > 0) {
            log_message('warning', 'UI sign-in refused for {ip}: address locked', ['ip' => $ip]);

            return $this->form($auth, $data + ['locked' => $locked], 429);
        }

        if ($auth->checkCredentials($username, $password)) {
            $auth->clearFailures($ip);
            $auth->signIn();
            log_message('info', 'UI sign-in: {user} from {ip}', ['user' => $username, 'ip' => $ip]);

            return redirect()->to($next);
        }

        $left = $auth->recordFailure($ip);
        log_message('warning', 'UI sign-in failed for {user} from {ip}, {left} tries left', [
            'user' => $username, 'ip' => $ip, 'left' => $left,
        ]);

        return $left === 0
            ? $this->form($auth, $data + ['locked' => $auth->lockedFor($ip)], 429)
            : $this->form($auth, $data + ['error' => 'Неверный логин или пароль.', 'left' => $left], 401);
    }

    public function logout(): ResponseInterface
    {
        (new UiAuth())->signOut();

        return redirect()->to('/ui/login')->with('notice', 'Вы вышли из системы.');
    }

    /** @param array<string, mixed> $data */
    private function form(UiAuth $auth, array $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setBody(view('web/login', $data + [
            'configured' => $auth->isConfigured(),
            'notice'     => session()->getFlashdata('notice'),
        ]));
    }

    /**
     * Where to go after signing in: a path inside the UI only — never another host
     * (`//evil.example`, `/\evil.example`) or anything outside /ui.
     */
    private function safeNext(mixed $next): string
    {
        $next = is_string($next) ? $next : '';

        if (preg_match('#^/ui(?:[/?][^\\\\]*)?$#', $next) !== 1 || str_starts_with($next, '//')
            || str_contains($next, '/ui/login')) {
            return '/ui';
        }

        return $next;
    }
}
