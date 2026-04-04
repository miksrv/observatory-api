<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * ApiKeyFilter
 *
 * Validates the X-API-Key header on every request routed through it.
 * Returns 401 Unauthorized when the key is absent or does not match
 * the value configured in app.apiKey (.env).
 */
class ApiKeyFilter implements FilterInterface
{
    /**
     * Checks the incoming X-API-Key header against the configured secret.
     *
     * @param RequestInterface $request
     * @param list<string>|null $arguments
     * @return ResponseInterface|void  Returns a 401 response on failure, null on success.
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $configuredKey = config('App')->apiKey;
        $providedKey   = $request->getHeaderLine('X-API-Key');

        if ($configuredKey === '' || $providedKey !== $configuredKey) {
            return response()
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['error' => 'Unauthorized', 'details' => (object) []]);
        }
    }

    /**
     * No post-processing required for API key authentication.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param list<string>|null $arguments
     * @return void
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        // Nothing to do after the response.
    }
}
