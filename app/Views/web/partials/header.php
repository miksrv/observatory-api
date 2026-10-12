<?php
/**
 * Shared header for the temporary debug web UI (app/Controllers/Web/*).
 * @var string|null $title
 */
$title        = $title ?? 'Observatory — Debug UI';
$flashSuccess = session()->getFlashdata('success');
$flashError   = session()->getFlashdata('error');

// Pipeline heartbeats — read directly, no controller dependency. One badge per active (not
// revoked) pipeline in `pipelines`; with none registered, the legacy single
// `pipeline_last_seen_at` setting.
$_agoText = static function (?string $utc): string {
    if ($utc === null || $utc === '') {
        return 'нет данных';
    }
    $ago = max(0, time() - strtotime($utc . ' UTC'));

    return match (true) {
        $ago < 60    => $ago . ' сек назад',
        $ago < 3600  => intdiv($ago, 60) . ' мин назад',
        $ago < 86400 => intdiv($ago, 3600) . ' ч назад',
        default      => intdiv($ago, 86400) . ' д назад',
    };
};
$_statusClass = [
    'online'  => 'text-success',
    'stale'   => 'text-warning',
    'offline' => 'text-danger',
];

try {
    $_pipelines = (new \App\Models\PipelineModel())->where('revoked_at', null)->orderBy('name', 'ASC')->findAll();
} catch (\Throwable $e) {
    $_pipelines = [];  // migration not run yet
}

$_heartbeats = [];
foreach ($_pipelines as $_p) {
    $_heartbeats[] = [
        'label' => $_p['name'],
        'text'  => $_agoText($_p['last_seen_at']),
        'class' => $_statusClass[\App\Models\PipelineModel::statusFor($_p['last_seen_at'])],
    ];
}

if ($_heartbeats === []) {
    $_lastSeen = db_connect()->table('settings')
        ->where('param', 'pipeline_last_seen_at')
        ->get()->getRowArray();
    $_lastSeenValue = $_lastSeen['value'] ?? null;

    $_heartbeats[] = [
        'label' => 'Pipeline',
        'text'  => $_agoText($_lastSeenValue),
        'class' => ($_lastSeenValue === null || $_lastSeenValue === '')
            ? 'text-secondary'
            : $_statusClass[\App\Models\PipelineModel::statusFor($_lastSeenValue)],
    ];
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f6fa; }
        .navbar-brand { font-weight: 600; }
        td, th { vertical-align: middle; }
        .chart-thumb { width: 100%; max-height: 180px; object-fit: contain; background: #111; border-radius: 4px; }
        .table-responsive { max-height: 75vh; }
        code { word-break: break-all; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="/ui">Observatory · Debug UI</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#uiNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="uiNav">
            <ul class="navbar-nav">
                <li class="nav-item"><a class="nav-link" href="/ui/frames">Кадры (frames)</a></li>
                <li class="nav-item"><a class="nav-link" href="/ui/sources">Источники (sources)</a></li>
                <li class="nav-item"><a class="nav-link" href="/ui/tasks">Задачи (tasks)</a></li>
                <li class="nav-item"><a class="nav-link" href="/ui/charts">Графики (charts)</a></li>
                <li class="nav-item"><a class="nav-link" href="/ui/anomalies">Аномалии (anomalies)</a></li>
                <li class="nav-item"><a class="nav-link" href="/ui/settings">Настройки</a></li>
            </ul>
            <span class="navbar-text ms-auto small">
                <?php foreach ($_heartbeats as $_i => $_hb): ?>
                    <?= $_i > 0 ? '<span class="mx-1">·</span>' : '' ?>
                    <?= esc($_hb['label']) ?>: <span class="<?= $_hb['class'] ?> fw-semibold"><?= esc($_hb['text']) ?></span>
                <?php endforeach; ?>
            </span>
        </div>
    </div>
</nav>
<div class="container-fluid px-4 pb-5">
    <?php if ($flashSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= esc($flashSuccess) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= esc($flashError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
