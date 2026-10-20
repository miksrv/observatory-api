<?php
/**
 * @var array                 $task
 * @var list<array>           $items
 * @var array<string, array>  $chartsByItem  task_item_id => source_charts row
 * @var array<string, string> $pipelineNames
 */
$this->extend('web/layout');
$this->setVar('title', $task['type'] . ' · задача');
$this->setVar('active', 'tasks');

$terminal = in_array($task['status'], ['COMPLETED', 'FAILED', 'CANCELLED'], true);
if (! $terminal) {
    $this->setVar('autorefresh', 10);
}

$pending = (int) $task['total_items'] - (int) $task['completed_items'] - (int) $task['failed_items'];
?>
<?= $this->section('content') ?>

<div class="page-head">
    <div>
        <div class="crumbs"><a href="/ui/tasks"><?= ui_icon('back', 14) ?>Задачи</a></div>
        <div class="row wrap" style="gap: 12px">
            <h1 class="mono"><?= esc($task['type']) ?></h1>
            <?= ui_task_status($task['status']) ?>
        </div>
        <p class="sub"><span class="mono"><?= esc($task['id']) ?></span> · <?= esc(ui_task_type_hint($task['type'])) ?></p>
    </div>
    <?php if (! $terminal): ?>
        <div class="actions">
            <form method="post" action="/ui/tasks/<?= esc($task['id'], 'url') ?>/cancel"
                  data-confirm="Pipeline прекратит обработку оставшихся элементов." data-confirm-title="Отменить задачу?" data-confirm-ok="Отменить задачу" data-danger>
                <?= csrf_field() ?>
                <button type="submit" class="btn danger"><?= ui_icon('stop', 14) ?>Отменить</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if ($task['error']): ?>
    <div class="notice error" role="alert"><?= ui_icon('alert') ?><div class="body"><strong>Ошибка задачи:</strong> <?= esc($task['error']) ?></div></div>
<?php endif; ?>

<div class="grid-2">
    <div class="panel">
        <div class="panel-body stack">
            <div class="stats">
                <div class="stat"><span class="label eyebrow">Всего</span><span class="value"><?= (int) $task['total_items'] ?></span></div>
                <div class="stat"><span class="label eyebrow">Готово</span><span class="value green"><?= (int) $task['completed_items'] ?></span></div>
                <div class="stat"><span class="label eyebrow">Ошибок</span><span class="value<?= (int) $task['failed_items'] > 0 ? ' red' : '' ?>"><?= (int) $task['failed_items'] ?></span></div>
                <div class="stat"><span class="label eyebrow">Осталось</span><span class="value"><?= max(0, $pending) ?></span></div>
            </div>
            <?= view('web/partials/progress', ['task' => $task, 'large' => true]) ?>
        </div>
    </div>
    <div class="panel">
        <div class="panel-body">
            <dl class="kv">
                <dt>Pipeline</dt><dd><?= view('web/partials/task_pipeline', ['task' => $task, 'names' => $pipelineNames]) ?></dd>
                <dt>Объект</dt><dd><?= esc($task['scope_object'] ?? '—') ?></dd>
                <dt>Создана</dt><dd class="mono small"><?= ui_time($task['created_at'], 'Y-m-d H:i:s') ?> UTC</dd>
                <dt>Начата</dt><dd class="mono small"><?= $task['started_at'] ? ui_time($task['started_at'], 'Y-m-d H:i:s') . ' UTC' : '—' ?></dd>
                <dt>Завершена</dt><dd class="mono small"><?= $task['finished_at'] ? ui_time($task['finished_at'], 'Y-m-d H:i:s') . ' UTC' : '—' ?></dd>
            </dl>
        </div>
    </div>
</div>

<div class="section-title"><h2>Элементы</h2><span class="small dim"><?= count($items) ?></span></div>

<div class="panel">
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th class="num">#</th>
                <th>Цель</th>
                <th>График</th>
                <th>Данные</th>
                <th>Статус</th>
                <th>Обработан</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="6" class="empty-cell"><div class="empty"><?= ui_icon('tasks') ?><span>У задачи нет элементов<?= $task['type'] === 'RESTART' ? ' — RESTART это сигнал, а не работа' : '' ?>.</span></div></td></tr>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <?php
                $chart   = $chartsByItem[$item['id']] ?? null;
                $payload = null;
                if ($item['payload'] !== null && $item['payload'] !== '') {
                    $decoded = json_decode($item['payload'], true);
                    $payload = is_array($decoded) ? $decoded : null;
                }
                ?>
                <tr>
                    <td class="num dim"><?= (int) $item['seq'] ?></td>
                    <td>
                        <?php if ($item['filename']): ?><span class="primary mono small break"><?= esc($item['filename']) ?></span><?php endif; ?>
                        <?php if ($item['frame_id']): ?>
                            <span class="sub">кадр <a class="id" href="/ui/anomalies?frame_id=<?= esc($item['frame_id'], 'url') ?>" title="Аномалии кадра"><?= esc($item['frame_id']) ?></a></span>
                        <?php endif; ?>
                        <?php if ($item['source_id']): ?>
                            <span class="sub">источник <a class="id" href="/ui/charts?source_id=<?= esc($item['source_id'], 'url') ?>" title="Графики источника"><?= esc($item['source_id']) ?></a></span>
                        <?php endif; ?>
                        <?php if (! $item['filename'] && ! $item['frame_id'] && ! $item['source_id']): ?><span class="dim">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($chart): ?>
                            <?php $src = '/ui/charts/' . rawurlencode($item['id']) . '/image?v=' . (ui_ts($chart['updated_at']) ?? 0); ?>
                            <a href="<?= esc($src, 'attr') ?>" data-lightbox data-caption="<?= esc($chart['style'] . ' · ' . ($item['filename'] ?? $item['id']), 'attr') ?>">
                                <img class="thumb" src="<?= esc($src, 'attr') ?>" alt="<?= esc($chart['style'], 'attr') ?>" loading="lazy">
                            </a>
                        <?php else: ?><span class="dim">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($payload !== null && $payload !== []): ?>
                            <dl class="payload">
                                <?php foreach ($payload as $key => $value): ?>
                                    <dt><?= esc((string) $key) ?></dt>
                                    <dd><?= esc(ui_value($value)) ?></dd>
                                <?php endforeach; ?>
                            </dl>
                        <?php else: ?><span class="dim">—</span><?php endif; ?>
                        <?php if ($item['error']): ?><div class="err-text"><?= esc($item['error']) ?></div><?php endif; ?>
                    </td>
                    <td><?= ui_task_status($item['status'], true) ?></td>
                    <td class="small nowrap"><?= ui_when($item['processed_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
