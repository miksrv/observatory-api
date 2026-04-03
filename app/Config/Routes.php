<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->group('api/v1', ['filter' => 'api_key'], static function (RouteCollection $routes): void {
    $routes->post('frames', 'Api\V1\FramesController::create');
    $routes->get('frames/covering', 'Api\V1\FramesController::covering');
    $routes->post('frames/(:num)/sources', 'Api\V1\FramesController::saveSources/$1');
    $routes->post('frames/(:num)/anomalies', 'Api\V1\FramesController::saveAnomalies/$1');
    $routes->get('sources/near', 'Api\V1\SourcesController::near');

    // Catch-all: return JSON 404 for any unmatched route under /api/v1/
    $routes->add('(:any)', static function (): \CodeIgniter\HTTP\Response {
        return service('response')
            ->setStatusCode(404)
            ->setJSON(['error' => 'Not Found', 'details' => []]);
    });
});
