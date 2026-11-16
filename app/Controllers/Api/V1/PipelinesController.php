<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\PipelineIdentity;
use App\Models\PipelineModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * What a pipeline reports about itself, beyond the heartbeat ApiKeyFilter records on every request.
 */
class PipelinesController extends BaseApiController
{
    /** More entries than any pipeline has catalogs is a malformed report. */
    private const MAX_CATALOGS = 20;

    /** A probe's error message is kept to this many characters. */
    private const MAX_ERROR_LENGTH = 300;

    /** A catalog name, as in the report's entries. */
    private const CATALOG_NAME = '/^[A-Za-z0-9][A-Za-z0-9 ._\/()-]{0,39}$/';

    /**
     * POST /api/v1/pipelines/catalogs
     *
     * Store the calling pipeline's latest check of the online catalogs it uses, replacing the
     * previous one. The check time is the API's receive time (the pipeline's clock is not trusted).
     *
     * Request:
     * {
     *   "catalogs": [
     *     {"name": "Simbad", "ok": false, "latency_ms": null, "error": "connect timeout"},
     *     {"name": "Gaia DR3", "ok": true, "latency_ms": 412, "error": null}
     *   ],
     *   "waiting": {"reason": "catalogs", "catalogs": ["Gaia DR3"], "since": "...", "task_id": "...", "filename": "..."}
     * }
     *
     * `waiting` is optional: absent keeps the stored wait (the pipeline's periodic check omits it),
     * null clears it — see docs/API.md.
     *
     * Response 200: {"data": {"stored": 2, "checked_at": "2026-10-31 20:00:00"}}
     * Response 400: malformed body. Response 503: a migration adding the columns has not been run.
     */
    public function catalogs(): ResponseInterface
    {
        $pipelineId = PipelineIdentity::currentId();

        if ($pipelineId === null) {
            return $this->respondError(401, 'Unauthorized');
        }

        $body     = $this->request->getJSON(true);
        $catalogs = is_array($body) ? ($body['catalogs'] ?? null) : null;

        if (! is_array($catalogs) || ! array_is_list($catalogs) || $catalogs === [] || count($catalogs) > self::MAX_CATALOGS) {
            return $this->respondError(400, 'catalogs must be a non-empty list of at most ' . self::MAX_CATALOGS . ' entries');
        }

        $clean = [];
        foreach ($catalogs as $index => $entry) {
            $name      = is_array($entry) ? ($entry['name'] ?? null) : null;
            $ok        = is_array($entry) ? ($entry['ok'] ?? null) : null;
            $latencyMs = is_array($entry) ? ($entry['latency_ms'] ?? null) : null;
            $error     = is_array($entry) ? ($entry['error'] ?? null) : null;

            if (! is_string($name) || preg_match(self::CATALOG_NAME, $name) !== 1) {
                return $this->respondError(400, 'Invalid catalog name', ['index' => $index]);
            }
            if (! is_bool($ok)) {
                return $this->respondError(400, 'ok must be a boolean', ['index' => $index]);
            }
            if ($latencyMs !== null && (! is_int($latencyMs) || $latencyMs < 0)) {
                return $this->respondError(400, 'latency_ms must be a non-negative integer or null', ['index' => $index]);
            }
            if ($error !== null && ! is_string($error)) {
                return $this->respondError(400, 'error must be a string or null', ['index' => $index]);
            }

            $clean[] = [
                'name'       => $name,
                'ok'         => $ok,
                'latency_ms' => $latencyMs,
                'error'      => $error === null || trim($error) === '' ? null : mb_substr(trim($error), 0, self::MAX_ERROR_LENGTH),
            ];
        }

        // `waiting` (observatory-pipeline 1.7.1+): absent = keep the stored state, null = the wait
        // ended, an object = the worker holds an ANALYZE item until these catalogs answer.
        $setWaiting = is_array($body) && array_key_exists('waiting', $body);
        $waiting    = null;
        if ($setWaiting && $body['waiting'] !== null) {
            $waiting = $this->cleanWaiting($body['waiting']);
            if (is_string($waiting)) {
                return $this->respondError(400, $waiting);
            }
        }

        $checkedAt = gmdate('Y-m-d H:i:s');

        // DBDebug off (production) makes a failed write return false instead of throwing.
        try {
            $saved = (new PipelineModel())->saveCatalogStatus($pipelineId, $clean, $checkedAt, $waiting, $setWaiting);
            $why   = 'update failed';
        } catch (DatabaseException $e) {
            $saved = false;
            $why   = $e->getMessage();
        }

        if (! $saved) {
            log_message('warning', 'Catalog status not recorded (migration not run?): {message}', ['message' => $why]);

            return $this->respondError(503, 'Catalog status cannot be stored yet — run php spark migrate');
        }

        return $this->respondOk(['data' => ['stored' => count($clean), 'checked_at' => $checkedAt]]);
    }

    /**
     * Validate a report's `waiting` object: `{reason, catalogs, since, task_id, filename}`.
     * Returns the cleaned array (since as UTC `Y-m-d H:i:s`), or the 400 message.
     *
     * @return array<string, mixed>|string
     */
    private function cleanWaiting(mixed $waiting): array|string
    {
        if (! is_array($waiting) || array_is_list($waiting)) {
            return 'waiting must be an object or null';
        }

        $reason = $waiting['reason'] ?? null;
        if (! is_string($reason) || preg_match('/^[a-z_]{1,20}$/', $reason) !== 1) {
            return 'waiting.reason must be a short lowercase word';
        }

        $catalogs = $waiting['catalogs'] ?? null;
        if (! is_array($catalogs) || ! array_is_list($catalogs) || $catalogs === [] || count($catalogs) > self::MAX_CATALOGS) {
            return 'waiting.catalogs must be a non-empty list of at most ' . self::MAX_CATALOGS . ' names';
        }
        foreach ($catalogs as $name) {
            if (! is_string($name) || preg_match(self::CATALOG_NAME, $name) !== 1) {
                return 'Invalid catalog name in waiting.catalogs';
            }
        }

        $since = $waiting['since'] ?? null;
        $time  = is_string($since) ? strtotime($since) : false;
        if ($time === false) {
            return 'waiting.since must be a date-time';
        }

        foreach (['task_id' => 64, 'filename' => 255] as $field => $max) {
            $value = $waiting[$field] ?? null;
            if ($value !== null && (! is_string($value) || mb_strlen($value) > $max)) {
                return "waiting.{$field} must be a string of at most {$max} characters, or null";
            }
        }

        return [
            'reason'   => $reason,
            'catalogs' => array_values(array_unique($catalogs)),
            'since'    => gmdate('Y-m-d H:i:s', $time),
            'task_id'  => $waiting['task_id'] ?? null,
            'filename' => $waiting['filename'] ?? null,
        ];
    }
}
