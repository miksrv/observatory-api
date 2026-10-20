<?php

namespace App\Filters;

use App\Libraries\PipelineIdentity;
use App\Models\PipelineModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * ApiKeyFilter
 *
 * Validates the X-API-Key header on every request routed through it. Each observatory-pipeline
 * instance has its own key, stored as a SHA-256 in `pipelines` (issued with
 * `php spark pipeline:create`); the matching row becomes the request's identity
 * (PipelineIdentity). Returns 401 Unauthorized when the key is absent, unknown or revoked.
 *
 * `app.apiKey` in .env is no longer read here — the migration that introduced `pipelines`
 * registered its value as pipeline `default`.
 */
class ApiKeyFilter implements FilterInterface
{
    /**
     * Resolves the X-API-Key header to its pipeline.
     *
     * @param RequestInterface $request
     * @param list<string>|null $arguments
     * @return ResponseInterface|void  Returns a 401 response on failure, null on success.
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        PipelineIdentity::set(null);

        $pipeline = (new PipelineModel())->findByKey($request->getHeaderLine('X-API-Key'));

        if ($pipeline === null) {
            return response()
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED)
                ->setJSON(['error' => 'Unauthorized', 'details' => (object) []]);
        }

        PipelineIdentity::set($pipeline);
    }

    /**
     * After a successful API request, update the calling pipeline's heartbeat so the UI can tell
     * whether it is online — its own `pipelines` row (with the version it reports in
     * `X-Pipeline-Version`), plus the legacy single `pipeline_last_seen_at` setting.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param list<string>|null $arguments
     * @return void
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        // Only record heartbeat for successful (2xx) responses.
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return;
        }

        $pipelineId = PipelineIdentity::currentId();

        if ($pipelineId !== null) {
            $version = trim($request->getHeaderLine('X-Pipeline-Version'));

            (new PipelineModel())->touch($pipelineId, $request->getIPAddress(), $version !== '' ? $version : null);
        }

        db_connect()
            ->table('settings')
            ->where('param', 'pipeline_last_seen_at')
            ->update(['value' => gmdate('Y-m-d H:i:s')]);
    }
}
