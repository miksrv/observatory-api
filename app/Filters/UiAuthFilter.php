<?php

namespace App\Filters;

use App\Libraries\UiAuth;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Guards the operator web UI (the `ui` route group): every page but the sign-in form needs a
 * signed-in session (UiAuth). Unauthenticated page requests go to /ui/login with the page they
 * asked for in `next`. Also sets the UI's security headers — these responses are never cached
 * and never framed.
 */
class UiAuthFilter implements FilterInterface
{
    /** Paths reachable without a session. */
    private const PUBLIC_PATHS = ['ui/login'];

    public function before(RequestInterface $request, $arguments = null)
    {
        $path = trim($request->getUri()->getPath(), '/');

        if (in_array($path, self::PUBLIC_PATHS, true)) {
            return null;
        }

        $state = (new UiAuth())->resume();

        if ($state['user'] !== null) {
            return null;
        }

        $query = $request->getUri()->getQuery();
        $next  = '/' . $path . ($query !== '' ? '?' . $query : '');
        $login = '/ui/login' . ($request->getMethod() === 'GET' && $path !== 'ui' ? '?next=' . rawurlencode($next) : '');

        $redirect = redirect()->to($login);

        return $state['reason'] === 'expired'
            ? $redirect->with('notice', 'Сессия истекла — войдите снова.')
            : $redirect;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Referrer-Policy', 'same-origin');
        // Chart images may sit in this browser's cache for a bit (the gallery reloads them on every
        // visit otherwise); pages never do — a signed-out browser must not show them from history.
        $response->setHeader('Cache-Control', str_starts_with($response->getHeaderLine('Content-Type'), 'image/')
            ? 'private, max-age=300'
            : 'no-store');
    }
}
