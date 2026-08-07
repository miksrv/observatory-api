<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', static function (): \CodeIgniter\HTTP\Response {
    return service('response')
        ->setStatusCode(200)
        ->setJSON([
            'name'    => 'Observatory API',
            'version' => 'v1',
            'status'  => 'ok',
            'base_url' => '/api/v1',
        ]);
});

$routes->add('(:any)', static function (): \CodeIgniter\HTTP\Response {
    return service('response')
        ->setStatusCode(404)
        ->setJSON(['error' => 'Not Found', 'details' => []]);
});

$routes->group('api/v1', ['filter' => 'api_key'], static function (RouteCollection $routes): void {
    // Frames
    $routes->post('frames', 'Api\V1\FramesController::create');
    $routes->get('frames/covering', 'Api\V1\FramesController::covering');
    $routes->post('frames/covering/batch', 'Api\V1\FramesController::coveringBatch');
    $routes->get('frames/nearest-before', 'Api\V1\FramesController::nearestBefore');
    $routes->post('frames/(:segment)/sources', 'Api\V1\FramesController::saveSources/$1');
    $routes->post('frames/(:segment)/anomalies', 'Api\V1\FramesController::saveAnomalies/$1');

    // Sources
    $routes->get('sources/near', 'Api\V1\SourcesController::near');
    $routes->post('sources/near/batch', 'Api\V1\SourcesController::nearBatch');
    $routes->get('sources/(:segment)/observations', 'Api\V1\SourcesController::observations/$1');
    $routes->get('sources/(:segment)/frames', 'Api\V1\SourcesController::frames/$1');
    $routes->get('sources/(:segment)/track', 'Api\V1\SourcesController::track/$1');
    $routes->post('sources/tracks/batch', 'Api\V1\SourcesController::tracksBatch');
    $routes->post('sources/(:segment)/chart', 'Api\V1\SourcesController::uploadChart/$1');
    $routes->post('sources/charts/batch', 'Api\V1\SourcesController::uploadChartsBatch');
    $routes->get('sources/(:segment)/chart.png', 'Api\V1\SourcesController::chart/$1');

    // Statistics
    $routes->get('stats/objects', 'Api\V1\StatsController::objects');
    $routes->get('stats/objects/(:segment)', 'Api\V1\StatsController::objectDetail/$1');

    // Catch-all: return JSON 404 for any unmatched route under /api/v1/
    $routes->add('(:any)', static function (): \CodeIgniter\HTTP\Response {
        return service('response')
            ->setStatusCode(404)
            ->setJSON(['error' => 'Not Found', 'details' => []]);
    });
});
