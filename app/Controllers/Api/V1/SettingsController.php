<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\SettingModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Controller for pipeline configuration settings.
 *
 * Read-only — the pipeline fetches its full configuration from the DB on
 * startup (or periodically) via GET /api/v1/settings so it can be tuned
 * centrally without redeploying .env files on the observatory server.
 */
class SettingsController extends BaseApiController
{
    /**
     * GET /api/v1/settings
     *
     * Return all configuration parameters as a flat { param: value } object.
     *
     * Response 200:
     * {
     *   "data": {
     *     "QC_FWHM_MAX_ARCSEC": "8.0",
     *     "SITE_LAT": "0.0",
     *     ...
     *   }
     * }
     */
    public function index(): ResponseInterface
    {
        $model = new SettingModel();

        return $this->respondOk([
            'data' => (object) $model->getAllAsMap(),
        ]);
    }
}

