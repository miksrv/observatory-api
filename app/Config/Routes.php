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
    //
    // Route ordering matters here: `(:segment)` matches any single path segment, so the literal
    // routes below (covering, covering/batch, nearest-before, and bare `frames`) must all be
    // registered before `frames/(:segment)` (show) — CI4 matches in registration order and takes
    // the first match, so a wildcard registered first would swallow e.g. GET /frames/covering.
    // Routes with a second segment (.../sources, .../anomalies) don't have this problem since
    // `frames/(:segment)` only ever matches exactly two segments total.
    $routes->post('frames', 'Api\V1\FramesController::create');
    $routes->get('frames', 'Api\V1\FramesController::index');
    $routes->get('frames/covering', 'Api\V1\FramesController::covering');
    $routes->post('frames/covering/batch', 'Api\V1\FramesController::coveringBatch');
    $routes->get('frames/nearest-before', 'Api\V1\FramesController::nearestBefore');
    $routes->get('frames/(:segment)/sources', 'Api\V1\FramesController::sources/$1');
    $routes->post('frames/(:segment)/sources', 'Api\V1\FramesController::saveSources/$1');
    $routes->post('frames/(:segment)/anomalies', 'Api\V1\FramesController::saveAnomalies/$1');
    $routes->get('frames/(:segment)', 'Api\V1\FramesController::show/$1');

    // Tasks — the granular pipeline job queue (ANALYZE / DETECT_ANOMALIES / GENERATE_CHARTS /
    // PREVIEW_CATALOG_MATCH). Same ordering caveat as above: every literal
    // `tasks/(:segment)/items/...` path has two-plus segments after `tasks`, so it's unaffected
    // by where `tasks/(:segment)` sits — kept below it anyway for readability.
    $routes->post('tasks', 'Api\V1\TasksController::create');
    $routes->get('tasks', 'Api\V1\TasksController::index');
    $routes->get('tasks/(:segment)', 'Api\V1\TasksController::show/$1');
    $routes->patch('tasks/(:segment)', 'Api\V1\TasksController::update/$1');
    $routes->post('tasks/(:segment)/items/progress', 'Api\V1\TasksController::postItemsProgress/$1');
    $routes->post('tasks/(:segment)/items/(:segment)/chart', 'Api\V1\TasksController::uploadItemChart/$1/$2');
    $routes->get('tasks/(:segment)/items/(:segment)/chart.png', 'Api\V1\TasksController::itemChart/$1/$2');

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

// Temporary debug web UI (App\Controllers\Web\*) — a small server-rendered dashboard for
// manually driving the pipeline: browse frames, create tasks from a checked selection, and view
// generated charts/anomalies. Deliberately outside the `api/v1` group so it's unauthenticated (no
// api_key filter — it's operator-only tooling, not a client-facing surface) and so it never shares
// route/controller code with the real API. Meant to be deleted once it's no longer needed; nothing
// under app/Controllers/Api or app/Models is touched by it.
$routes->group('ui', static function (RouteCollection $routes): void {
    $routes->get('/', 'Web\DashboardController::index');

    $routes->get('frames', 'Web\FramesController::index');

    $routes->get('sources', 'Web\SourcesController::index');

    $routes->get('tasks', 'Web\TasksController::index');
    $routes->post('tasks', 'Web\FramesController::createTask');
    $routes->get('tasks/(:segment)', 'Web\TasksController::show/$1');
    $routes->post('tasks/(:segment)/cancel', 'Web\TasksController::cancel/$1');

    $routes->get('charts', 'Web\ChartsController::index');
    $routes->get('charts/(:segment)/image', 'Web\ChartsController::image/$1');

    $routes->get('anomalies', 'Web\AnomaliesController::index');
});
