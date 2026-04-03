<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ObjectStatsModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Controller for object statistics endpoints.
 */
class StatsController extends BaseApiController
{
    /**
     * GET /api/v1/stats/objects
     *
     * Get a list of all observed objects with their aggregated stats.
     *
     * Query parameters:
     *   object — optional partial match filter on object name
     */
    public function objects(): ResponseInterface
    {
        $objectFilter = $this->request->getGet('object');

        $model   = new ObjectStatsModel();
        $objects = $model->getAllObjectsSummary($objectFilter);

        // Format dates to ISO 8601
        foreach ($objects as &$obj) {
            if ($obj['first_obs_time'] !== null) {
                $obj['first_obs_time'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($obj['first_obs_time']));
            }
            if ($obj['last_obs_time'] !== null) {
                $obj['last_obs_time'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($obj['last_obs_time']));
            }
        }
        unset($obj);

        return $this->respondOk(['data' => $objects]);
    }

    /**
     * GET /api/v1/stats/objects/{object}
     *
     * Get detailed stats for a specific object, broken down by filter.
     *
     * @param string $object Object name (URL-encoded if contains special chars)
     */
    public function objectDetail(string $object): ResponseInterface
    {
        // URL decode the object name
        $object = urldecode($object);

        $model  = new ObjectStatsModel();
        $detail = $model->getObjectDetail($object);

        if ($detail === null) {
            return $this->respondError(404, 'Object not found in statistics', ['object' => $object]);
        }

        // Format dates to ISO 8601
        if ($detail['summary']['first_obs_time'] !== null) {
            $detail['summary']['first_obs_time'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($detail['summary']['first_obs_time']));
        }
        if ($detail['summary']['last_obs_time'] !== null) {
            $detail['summary']['last_obs_time'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($detail['summary']['last_obs_time']));
        }

        foreach ($detail['by_filter'] as &$filterData) {
            if ($filterData['first_obs_time'] !== null) {
                $filterData['first_obs_time'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($filterData['first_obs_time']));
            }
            if ($filterData['last_obs_time'] !== null) {
                $filterData['last_obs_time'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($filterData['last_obs_time']));
            }
        }
        unset($filterData);

        return $this->respondOk($detail);
    }
}

