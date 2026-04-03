<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * BaseApiController
 *
 * Shared response helpers for all API controllers.
 * Extend this instead of BaseController in the Api namespace.
 */
class BaseApiController extends BaseController
{
    /**
     * Return a 201 Created JSON response.
     */
    protected function respondCreated(array $data): ResponseInterface
    {
        return $this->response->setStatusCode(201)->setJSON($data);
    }

    /**
     * Return a 200 OK JSON response.
     */
    protected function respondOk(array $data): ResponseInterface
    {
        return $this->response->setStatusCode(200)->setJSON($data);
    }

    /**
     * Return an error JSON response with the given HTTP status code.
     *
     * @param int    $code    HTTP status code (400, 401, 404, 422, etc.)
     * @param string $message Human-readable error description.
     * @param array  $details Optional structured details (field names, etc.).
     */
    protected function respondError(int $code, string $message, array $details = []): ResponseInterface
    {
        return $this->response->setStatusCode($code)->setJSON([
            'error'   => $message,
            'details' => $details,
        ]);
    }
}
