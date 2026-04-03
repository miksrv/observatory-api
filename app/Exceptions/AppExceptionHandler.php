<?php

namespace App\Exceptions;

use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * AppExceptionHandler
 *
 * Handles all unhandled exceptions for this API-only application by always
 * returning a JSON error response conforming to the standard error envelope:
 *   { "error": "...", "details": {} }
 *
 * Extends BaseExceptionHandler (abstract, not final) and implements
 * ExceptionHandlerInterface. We do not extend the final ExceptionHandler class
 * introduced in CI4.7.
 */
class AppExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * Handle an exception by returning a JSON response.
     *
     * For API requests (path starts with "api/") a structured JSON body is
     * returned. For any other path the same JSON fallback is used since this
     * application serves no HTML views.
     */
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode
    ): void {
        $response
            ->setStatusCode($statusCode)
            ->setJSON([
                'error'   => $this->resolveMessage($statusCode),
                'details' => [],
            ])
            ->send();
    }

    /**
     * Map an HTTP status code to a human-readable message.
     *
     * 5xx errors use a generic message to avoid leaking internals.
     */
    private function resolveMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400     => 'Bad Request',
            401     => 'Unauthorized',
            403     => 'Forbidden',
            404     => 'Not Found',
            405     => 'Method Not Allowed',
            422     => 'Unprocessable Entity',
            default => 'Internal Server Error',
        };
    }
}
