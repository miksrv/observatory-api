<?php
/**
 * @var list<array>           $tasks
 * @var array<string, int>    $counts  per status, within the other filters
 * @var list<string>          $statuses
 * @var list<string>          $types
 * @var string                $filterStatus
 * @var string                $filterType
 * @var string                $filterObjectQ
 * @var array<string, string> $pipelines id => name
 * @var string                $filterPipeline
 */
$this->extend('web/layout');
$this->setVar('title', 'Задачи');
$this->setVar('active', 'tasks');
$this->setVar('fill', true);

// Refresh while anything in view is still moving.
if ($counts['RUNNING'] > 0 || $counts['PENDING'] > 0) {
    $this->setVar('autorefresh', 15);
}

$filtered = $filterType !== '' || $filterObjectQ !== '' || $filterPipeline !== '';
?>
<?= $this->section('content') ?>

<div class="page-head">
    <div>
        <h1>Задачи</h1>
        <p class="sub">Очередь заданий pipeline. Задачи создаются на страницах кадров, источников и аномалий
            или самим pipeline.<?= $counts['RUNNING'] > 0 || $counts['PENDING'] > 0 ? ' Страница обновляется автоматически.' : '' ?></p>
    </div>
    <div class="actions">
        <a class="btn primary" href="/ui/frames"><?= ui_icon('play') ?>Новая задача</a>
    </div>
</div>

<div class="panel">
    <nav class="tabs" aria-label="Статус">
        <a href="<?= esc(ui_query(['status' => null]), 'attr') ?>" class="<?= $filterStatus === '' ? 'active' : '' ?>">Все <span class="count"><?= array_sum($counts) ?></span></a>
        <?php foreach ($statuses as $s): ?>
            <a href="<?= esc(ui_query(['status' => $s]), 'attr') ?>" class="<?= $s === $filterStatus ? 'active' : '' ?>" title="<?= $s ?>">
                <?= esc(ui_task_status_label($s)) ?> <span class="count"><?= (int) $counts[$s] ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <form class="filters" method="get" action="/ui/tasks" style="border-radius: 0">
        <?php if ($filterStatus !== ''): ?><input type="hidden" name="status" value="<?= esc($filterStatus, 'attr') ?>"><?php endif; ?>
        <label class="field wide">
            <span class="label">Тип</span>
            <select class="input" name="type" data-autosubmit>
                <option value="">Все типы</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= esc($t, 'attr') ?>" <?= $t === $filterType ? 'selected' : '' ?>><?= esc($t) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="label">Pipeline</span>
            <select class="input" name="pipeline" data-autosubmit>
                <option value="">Все</option>
                <?php foreach ($pipelines as $name): ?>
                    <option value="<?= esc($name, 'attr') ?>" <?= $name === $filterPipeline ? 'selected' : '' ?>><?= esc($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="label">Объект</span>
            <input class="input" type="text" name="object" value="<?= esc($filterObjectQ, 'attr') ?>" placeholder="scope_object">
        </label>
        <div class="actions">
            <?php if ($filtered): ?><a class="btn ghost" href="<?= esc(ui_query(['type' => null, 'object' => null, 'pipeline' => null]), 'attr') ?>"><?= ui_icon('x', 14) ?>Сбросить</a><?php endif; ?>
            <button class="btn" type="submit"><?= ui_icon('search', 14) ?>Показать</button>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Задача</th>
                <th>Статус</th>
                <th>Прогресс</th>
                <th>Pipeline</th>
                <th>Объект</th>
                <th>Создана</th>
                <th>Начата / завершена</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($tasks)): ?>
                <tr><td colspan="8" class="empty-cell"><div class="empty"><?= ui_icon('tasks') ?><strong>Задач не найдено</strong><span>Измените фильтр или создайте задачу на странице кадров.</span></div></td></tr>
            <?php endif; ?>
            <?php foreach ($tasks as $task): ?>
                <tr data-href="/ui/tasks/<?= esc($task['id'], 'url') ?>">
                    <td>
                        <a class="primary mono" href="/ui/tasks/<?= esc($task['id'], 'url') ?>"><?= esc($task['type']) ?></a>
                        <span class="sub id"><?= esc($task['id']) ?></span>
                    </td>
                    <td><?= ui_task_status($task['status']) ?></td>
                    <td><?= view('web/partials/progress', ['task' => $task]) ?></td>
                    <td><?= view('web/partials/task_pipeline', ['task' => $task, 'names' => $pipelines]) ?></td>
                    <td class="nowrap"><?= esc($task['scope_object'] ?? '—') ?></td>
                    <td class="small nowrap"><?= ui_when($task['created_at']) ?></td>
                    <td class="small nowrap">
                        <?= $task['started_at'] ? ui_when($task['started_at']) : '<span class="dim">не начата</span>' ?>
                        <?php if ($task['finished_at']): ?><span class="sub"><?= ui_when($task['finished_at']) ?></span><?php endif; ?>
                    </td>
                    <td class="actions"><span class="dim"><?= ui_icon('chevron') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="panel-foot">
        <span>Показано <?= count($tasks) ?><?= count($tasks) >= 200 ? ' — последние 200' : '' ?></span>
    </div>
</div>

<?= $this->endSection() ?>
