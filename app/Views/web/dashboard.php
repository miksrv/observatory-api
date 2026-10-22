<?php
/**
 * @var array<string, int>    $stats
 * @var array<string, int>    $tasksByStatus
 * @var list<array>           $pipelines     PipelineModel::getOverview()
 * @var array<string, string> $pipelineNames
 * @var int                   $pendingShared
 * @var list<array>           $recentTasks
 * @var list<array>           $recentAlerts
 */
$this->extend('web/layout');
$this->setVar('title', 'Обзор');
$this->setVar('active', 'overview');

$taskTotal  = array_sum($tasksByStatus);
$statusTone = ['PENDING' => 'amber', 'RUNNING' => 'blue', 'COMPLETED' => 'green', 'FAILED' => 'red', 'CANCELLED' => 'gray'];
$online     = count(array_filter($pipelines, static fn ($p) => $p['status'] === 'online'));
$registered = count(array_filter($pipelines, static fn ($p) => $p['status'] !== 'revoked'));
?>
<?= $this->section('content') ?>

<div class="page-head">
    <div>
        <h1>Обзор</h1>
        <p class="sub">
            <?php if ($registered > 0): ?>
                На связи <?= $online ?> из <?= $registered ?> pipeline<?= $tasksByStatus['RUNNING'] > 0 ? ', выполняется задач: ' . $tasksByStatus['RUNNING'] : '' ?>.
            <?php else: ?>
                Ни один pipeline ещё не зарегистрирован.
            <?php endif; ?>
        </p>
    </div>
    <div class="actions">
        <a class="btn" href="/ui/anomalies?is_alert=1"><?= ui_icon('anomalies') ?>Алерты</a>
        <a class="btn primary" href="/ui/frames"><?= ui_icon('play') ?>Новая задача</a>
    </div>
</div>

<div class="metrics">
    <a class="panel metric" href="/ui/frames">
        <div class="metric-top"><span class="eyebrow">Кадры</span><span class="metric-icon"><?= ui_icon('frames') ?></span></div>
        <div class="metric-value"><?= ui_num($stats['frames']) ?></div>
        <div class="metric-foot">зарегистрировано FITS</div>
    </a>
    <a class="panel metric" href="/ui/sources">
        <div class="metric-top"><span class="eyebrow">Источники</span><span class="metric-icon cyan"><?= ui_icon('sources') ?></span></div>
        <div class="metric-value"><?= ui_num($stats['sources']) ?></div>
        <div class="metric-foot">в каталоге наблюдений</div>
    </a>
    <a class="panel metric" href="/ui/anomalies">
        <div class="metric-top"><span class="eyebrow">Аномалии</span><span class="metric-icon red"><?= ui_icon('anomalies') ?></span></div>
        <div class="metric-value"><?= ui_num($stats['anomalies']) ?></div>
        <div class="metric-foot"><?= $stats['alerts'] > 0 ? '<span style="color: var(--red)">' . ui_num($stats['alerts']) . ' ' . ui_plural($stats['alerts'], 'алерт', 'алерта', 'алертов') . '</span>' : 'алертов нет' ?><?= $stats['reviewed'] > 0 ? ' · ' . ui_num($stats['reviewed']) . ' проверено' : '' ?></div>
    </a>
    <a class="panel metric" href="/ui/charts">
        <div class="metric-top"><span class="eyebrow">Графики</span><span class="metric-icon violet"><?= ui_icon('charts') ?></span></div>
        <div class="metric-value"><?= ui_num($stats['charts']) ?></div>
        <div class="metric-foot">карт и стрипов</div>
    </a>
</div>

<div class="section-title">
    <h2>Pipeline</h2>
    <?php if ($pendingShared > 0): ?>
        <a class="small" href="/ui/tasks?status=PENDING">Общих задач в очереди (для любого pipeline): <?= $pendingShared ?></a>
    <?php else: ?>
        <span class="small dim">Общих задач в очереди нет</span>
    <?php endif; ?>
</div>

<?php if ($pipelines === []): ?>
    <div class="panel">
        <div class="empty">
            <?= ui_icon('server') ?>
            <strong>Pipeline не зарегистрированы</strong>
            <span>На сервере API выполните <code>php spark pipeline:create &lt;имя&gt;</code> — команда выдаст ключ для <code>API_KEY</code> в <code>.env</code> pipeline.</span>
        </div>
    </div>
<?php else: ?>
    <?php // One pipeline is the usual setup: it gets the whole row, laid out sideways. ?>
    <div class="pipes<?= count($pipelines) === 1 ? ' single' : '' ?>">
        <?php foreach ($pipelines as $p): ?>
            <?php
            [$tone, $label] = ui_pipeline_status($p['status']);
            $running        = $p['running_task'];
            ?>
            <div class="panel pipe">
                <div class="pipe-head">
                    <span class="pipe-icon"><?= ui_icon('server', 18) ?><span class="led <?= $tone ?>"></span></span>
                    <div class="grow">
                        <div class="pipe-title">
                            <div class="pipe-name" title="<?= esc($p['name'], 'attr') ?>"><?= esc($p['name']) ?></div>
                            <?php if (($p['version'] ?? null) !== null): ?>
                                <span class="pipe-version" title="Версия pipeline">v<?= esc($p['version']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="pipe-state"><?= ui_chip($label, $tone, $p['status'], true) ?><?= ui_when($p['last_seen_at'], 'никогда') ?></div>
                    </div>
                </div>
                <div class="pipe-body">
                    <dl class="kv">
                        <dt>IP</dt><dd class="mono small"><?= esc($p['last_ip'] ?? '—') ?></dd>
                        <dt>Зарегистрирован</dt><dd class="small"><?= ui_time($p['created_at'], 'Y-m-d') ?></dd>
                    </dl>
                </div>
                <?php if ($running !== null): ?>
                    <a class="pipe-task" href="/ui/tasks/<?= esc($running['id'], 'url') ?>">
                        <span class="pipe-task-top">
                            <span class="eyebrow">Текущая задача</span>
                            <span class="dim small">начата <?= ui_when($running['started_at']) ?></span>
                        </span>
                        <span class="pipe-task-title">
                            <span class="mono"><?= esc($running['type']) ?></span><?= ui_task_status('RUNNING') ?>
                            <span class="open"><?= ui_icon('chevron', 14) ?></span>
                        </span>
                        <?= view('web/partials/progress', ['task' => $running, 'large' => count($pipelines) === 1]) ?>
                    </a>
                <?php else: ?>
                    <div class="pipe-task idle">
                        <span class="eyebrow">Текущая задача</span>
                        <span class="pipe-task-title dim">
                            <?= ui_icon($p['status'] === 'revoked' ? 'lock' : 'clock', 16) ?>
                            <?= $p['status'] === 'revoked' ? 'Ключ отозван — задачи не получает' : 'Простаивает, ждёт задач' ?>
                        </span>
                    </div>
                <?php endif; ?>
                <div class="pipe-foot">
                    <a href="/ui/tasks?status=PENDING&amp;pipeline=<?= esc($p['name'], 'url') ?>">
                        <span class="eyebrow">В очереди</span><b><?= (int) $p['pending_own'] ?></b>
                    </a>
                    <a href="/ui/frames?pipeline=<?= esc($p['name'], 'url') ?>">
                        <span class="eyebrow">Кадров</span><b><?= ui_num($p['frame_count']) ?></b>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="section-title"><h2>Задачи</h2><a class="small" href="/ui/tasks">Все задачи</a></div>

<div class="panel">
    <div class="panel-body">
        <div class="row between" style="margin-bottom: 12px">
            <span class="eyebrow">Всего <?= ui_num($taskTotal) ?></span>
        </div>
        <div class="spectrum" role="img" aria-label="Задачи по статусам">
            <?php foreach ($tasksByStatus as $status => $count): ?>
                <?php if ($count > 0): ?>
                    <span class="bg-<?= $statusTone[$status] ?>" style="flex: <?= (int) $count ?>" title="<?= esc(ui_task_status_label($status) . ': ' . $count, 'attr') ?>"></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="legend">
            <?php foreach ($tasksByStatus as $status => $count): ?>
                <a href="/ui/tasks?status=<?= $status ?>" title="<?= $status ?>">
                    <span class="sw bg-<?= $statusTone[$status] ?>"></span><?= esc(ui_task_status_label($status)) ?> <b><?= (int) $count ?></b>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-top: 20px">
    <div class="panel">
        <div class="panel-head">
            <div class="title"><?= ui_icon('tasks') ?><h3>Последние задачи</h3></div>
            <a class="small" href="/ui/tasks">Все</a>
        </div>
        <?php if ($recentTasks === []): ?>
            <div class="empty"><?= ui_icon('tasks') ?><span>Задач пока не было.</span></div>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($recentTasks as $t): ?>
                    <li>
                        <div class="main-col">
                            <a class="mono" href="/ui/tasks/<?= esc($t['id'], 'url') ?>"><?= esc($t['type']) ?></a>
                            <span class="meta">
                                <?= ui_when($t['created_at']) ?> · <?= view('web/partials/task_pipeline', ['task' => $t, 'names' => $pipelineNames]) ?><?= $t['scope_object'] ? ' · ' . esc($t['scope_object']) : '' ?>
                            </span>
                        </div>
                        <span class="dim mono small nowrap"><?= (int) $t['completed_items'] + (int) $t['failed_items'] ?>/<?= (int) $t['total_items'] ?></span>
                        <?= ui_task_status($t['status']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div class="title"><?= ui_icon('alert') ?><h3>Последние алерты</h3></div>
            <a class="small" href="/ui/anomalies?is_alert=1">Все</a>
        </div>
        <?php if ($recentAlerts === []): ?>
            <div class="empty"><?= ui_icon('anomalies') ?><span>Алертов нет — небо спокойно.</span></div>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($recentAlerts as $a): ?>
                    <li>
                        <div class="main-col">
                            <?php if ($a['source_id']): ?>
                                <a href="/ui/anomalies?source_id=<?= esc($a['source_id'], 'url') ?>"><?= esc($a['mpc_designation'] ?: ($a['catalog_id'] ?: 'Без обозначения')) ?></a>
                            <?php else: ?>
                                <span><?= esc($a['mpc_designation'] ?: 'Без обозначения') ?></span>
                            <?php endif; ?>
                            <span class="meta"><?= esc($a['object'] ?? '—') ?> · <?= ui_time($a['obs_time']) ?> UTC · <span class="coords"><?= ui_num($a['ra'], 4) ?> / <?= ui_num($a['dec'], 4) ?></span></span>
                        </div>
                        <?= ui_chip($a['anomaly_type'], 'red') ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
