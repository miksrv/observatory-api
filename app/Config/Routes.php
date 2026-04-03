<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->group('api/v1', ['filter' => 'api_key'], static function (RouteCollection $routes): void {
    // Frames
    $routes->post('frames', 'Api\V1\FramesController::create');
    $routes->get('frames/covering', 'Api\V1\FramesController::covering');
    $routes->post('frames/(:segment)/sources', 'Api\V1\FramesController::saveSources/$1');
    $routes->post('frames/(:segment)/anomalies', 'Api\V1\FramesController::saveAnomalies/$1');

    // Sources
    $routes->get('sources/near', 'Api\V1\SourcesController::near');
    $routes->get('sources/(:segment)/observations', 'Api\V1\SourcesController::observations/$1');
    $routes->get('sources/(:segment)/frames', 'Api\V1\SourcesController::frames/$1');

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
