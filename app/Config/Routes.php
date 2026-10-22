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
    $routes->get('sources/(:segment)/chart.png', 'Api\V1\SourcesController::chart/$1');

    // Settings (read-only — pipeline pulls its configuration from the DB)
    $routes->get('settings', 'Api\V1\SettingsController::index');

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

// Operator web UI (App\Controllers\Web\*) — a server-rendered console for driving the pipeline:
// pipelines' status, frames, sources, anomalies, charts, the task queue and settings; tasks are
// created from a checked selection. Deliberately outside the `api/v1` group (no X-API-Key, never
// shares route/controller code with the API). Signed in with the one account from .env
// (`ui.username` / `ui.password`, Config\Ui): `ui_auth` sends every page but the sign-in form to
// /ui/login without a session, and `csrf` (session-backed, Config\Security) guards every POST.
$routes->group('ui', ['filter' => ['csrf', 'ui_auth']], static function (RouteCollection $routes): void {
    $routes->get('login', 'Web\AuthController::login');
    $routes->post('login', 'Web\AuthController::attempt');
    $routes->post('logout', 'Web\AuthController::logout');

    $routes->get('/', 'Web\DashboardController::index');

    $routes->get('frames', 'Web\FramesController::index');

    $routes->get('sources', 'Web\SourcesController::index');
    $routes->post('sources/generate-charts', 'Web\SourcesController::createTask');
    $routes->post('sources/merge', 'Web\SourcesController::merge');

    $routes->get('tasks', 'Web\TasksController::index');
    $routes->post('tasks', 'Web\FramesController::createTask');
    $routes->get('tasks/(:segment)', 'Web\TasksController::show/$1');
    $routes->post('tasks/(:segment)/cancel', 'Web\TasksController::cancel/$1');

    $routes->get('charts', 'Web\ChartsController::index');
    $routes->get('charts/(:segment)/image', 'Web\ChartsController::image/$1');
    $routes->post('charts/review', 'Web\ChartsController::review');
    $routes->post('charts/delete', 'Web\ChartsController::deleteSelected');
    $routes->post('charts/(:segment)/delete', 'Web\ChartsController::delete/$1');

    $routes->get('anomalies', 'Web\AnomaliesController::index');
    $routes->post('anomalies/generate-charts', 'Web\AnomaliesController::createTask');
    $routes->post('anomalies/delete', 'Web\AnomaliesController::delete');
    $routes->post('anomalies/review', 'Web\AnomaliesController::review');
    $routes->post('anomalies/unreview', 'Web\AnomaliesController::unreview');

    $routes->get('settings', 'Web\SettingsController::index');
    $routes->post('settings', 'Web\SettingsController::save');
});
