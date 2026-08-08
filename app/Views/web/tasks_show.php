<?= view('web/partials/header', ['title' => 'Задача ' . $task['id']]) ?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">Задача <code><?= esc($task['id']) ?></code></h1>
        <div class="text-muted">
            <?= esc($task['type']) ?>
            · создана <?= $task['created_at'] ? esc(gmdate('Y-m-d H:i:s', strtotime($task['created_at']))) : '—' ?> UTC
            <?php if ($task['scope_object']): ?> · объект: <?= esc($task['scope_object']) ?><?php endif; ?>
        </div>
    </div>
    <a href="/ui/tasks" class="btn btn-outline-secondary">← к списку задач</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm"><div class="card-body">
            <div class="fs-5 fw-bold"><?= esc($task['status']) ?></div>
            <div class="text-muted">Статус</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm"><div class="card-body">
            <div class="fs-5 fw-bold"><?= (int) $task['total_items'] ?></div>
            <div class="text-muted">Всего элементов</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm"><div class="card-body">
            <div class="fs-5 fw-bold text-success"><?= (int) $task['completed_items'] ?></div>
            <div class="text-muted">Выполнено</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm"><div class="card-body">
            <div class="fs-5 fw-bold text-danger"><?= (int) $task['failed_items'] ?></div>
            <div class="text-muted">Ошибок</div>
        </div></div>
    </div>
</div>

<?php if ($task['error']): ?>
    <div class="alert alert-danger"><strong>Ошибка задачи:</strong> <?= esc($task['error']) ?></div>
<?php endif; ?>

<?php if (! in_array($task['status'], ['COMPLETED', 'FAILED', 'CANCELLED'], true)): ?>
    <form method="post" action="/ui/tasks/<?= esc($task['id']) ?>/cancel" class="mb-3" onsubmit="return confirm('Отменить задачу?');">
        <button type="submit" class="btn btn-outline-danger btn-sm">Отменить задачу</button>
    </form>
<?php endif; ?>

<div class="table-responsive border rounded bg-white">
<table class="table table-sm table-striped mb-0">
<thead class="table-light">
<tr>
    <th>#</th><th>Filename</th><th>Frame ID</th><th>Source ID</th><th>Chart</th>
    <th>Payload</th><th>Статус</th><th>Ошибка</th><th>Обработан (UTC)</th>
</tr>
</thead>
<tbody>
<?php if (empty($items)): ?>
    <tr><td colspan="9" class="text-center text-muted py-4">У задачи нет элементов.</td></tr>
<?php endif; ?>
<?php foreach ($items as $item): ?>
    <?php
    $chart = $chartsByItem[$item['id']] ?? null;
    $payload = null;
    if ($item['payload'] !== null && $item['payload'] !== '') {
        $decoded = json_decode($item['payload'], true);
        $payload = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
    ?>
    <tr>
        <td><?= (int) $item['seq'] ?></td>
        <td class="text-break small"><?= esc($item['filename'] ?? '—') ?></td>
        <td class="small">
            <?php if ($item['frame_id']): ?>
                <a href="/ui/anomalies?frame_id=<?= esc($item['frame_id']) ?>"><?= esc($item['frame_id']) ?></a>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td class="small">
            <?php if ($item['source_id']): ?>
                <a href="/ui/charts?source_id=<?= esc($item['source_id']) ?>"><?= esc($item['source_id']) ?></a>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td>
            <?php if ($chart): ?>
                <a href="/ui/charts/<?= esc($item['id']) ?>/image" target="_blank" rel="noopener">
                    <img src="/ui/charts/<?= esc($item['id']) ?>/image" alt="<?= esc($chart['style']) ?>"
                         style="max-width: 90px; max-height: 60px; object-fit: contain; background: #111;" loading="lazy">
                </a>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td class="small">
            <?php if ($payload !== null): ?>
                <?php foreach ($payload as $key => $value): ?>
                    <div><span class="text-muted"><?= esc((string) $key) ?>:</span>
                        <?= esc(is_scalar($value) ? (string) $value : json_encode($value)) ?></div>
                <?php endforeach; ?>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td><?= esc($item['status']) ?></td>
        <td class="text-danger small"><?= esc($item['error'] ?? '') ?></td>
        <td class="small"><?= $item['processed_at'] ? esc(gmdate('Y-m-d H:i:s', strtotime($item['processed_at']))) : '—' ?></td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?= view('web/partials/footer') ?>
