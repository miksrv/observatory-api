<?= view('web/partials/header', ['title' => 'Задачи (tasks)']) ?>

<h1 class="h4 mb-3">Задачи (tasks)</h1>

<form method="get" action="/ui/tasks" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label mb-0">Статус</label>
        <select name="status" class="form-select">
            <option value="">— все —</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= esc($s) ?>" <?= $s === $filterStatus ? 'selected' : '' ?>><?= esc($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">Тип</label>
        <select name="type" class="form-select">
            <option value="">— все —</option>
            <?php foreach ($types as $t): ?>
                <option value="<?= esc($t) ?>" <?= $t === $filterType ? 'selected' : '' ?>><?= esc($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">Объект (scope_object)</label>
        <input type="text" name="object" class="form-control" value="<?= esc($filterObjectQ) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary">Фильтровать</button>
    </div>
    <div class="col-auto">
        <a href="/ui/tasks" class="btn btn-outline-secondary">Сбросить</a>
    </div>
</form>

<div class="table-responsive border rounded bg-white">
<table class="table table-sm table-hover table-striped mb-0">
<thead class="table-light">
<tr>
    <th>ID</th><th>Тип</th><th>Статус</th><th>Объект</th><th>Прогресс</th>
    <th>Создана (UTC)</th><th>Завершена/начата (UTC)</th><th></th>
</tr>
</thead>
<tbody>
<?php if (empty($tasks)): ?>
    <tr><td colspan="8" class="text-center text-muted py-4">Задач не найдено.</td></tr>
<?php endif; ?>
<?php foreach ($tasks as $task): ?>
    <?php
    $badge = match ($task['status']) {
        'COMPLETED' => 'bg-success',
        'FAILED'    => 'bg-danger',
        'RUNNING'   => 'bg-primary',
        'CANCELLED' => 'bg-secondary',
        default     => 'bg-warning text-dark',
    };
    $lastTime = $task['finished_at'] ?? $task['started_at'];
    ?>
    <tr>
        <td><code class="small"><?= esc($task['id']) ?></code></td>
        <td><?= esc($task['type']) ?></td>
        <td><span class="badge <?= $badge ?>"><?= esc($task['status']) ?></span></td>
        <td><?= esc($task['scope_object'] ?? '—') ?></td>
        <td><?= (int) $task['completed_items'] ?>/<?= (int) $task['total_items'] ?>
            <?php if ((int) $task['failed_items'] > 0): ?>
                <span class="text-danger">(<?= (int) $task['failed_items'] ?> failed)</span>
            <?php endif; ?>
        </td>
        <td class="small"><?= $task['created_at'] ? esc(gmdate('Y-m-d H:i:s', strtotime($task['created_at']))) : '—' ?></td>
        <td class="small"><?= $lastTime ? esc(gmdate('Y-m-d H:i:s', strtotime($lastTime))) : '—' ?></td>
        <td><a class="btn btn-sm btn-outline-primary" href="/ui/tasks/<?= esc($task['id']) ?>">Детали</a></td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?= view('web/partials/footer') ?>
