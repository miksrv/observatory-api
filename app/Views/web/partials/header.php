<?php
/**
 * Shared header for the temporary debug web UI (app/Controllers/Web/*).
 * @var string|null $title
 */
$title        = $title ?? 'Observatory — Debug UI';
$flashSuccess = session()->getFlashdata('success');
$flashError   = session()->getFlashdata('error');
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
            </ul>
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
