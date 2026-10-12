<?= view('web/partials/header', ['title' => 'Observatory — Debug UI']) ?>

<h1 class="h4 mb-3">Отладочная панель Observatory API</h1>
<p class="text-muted">
    Временный интерфейс для ручной проверки пайплайна — не заменяет и не трогает <code>/api/v1/*</code>,
    только читает те же таблицы через существующие модели. Будет удалён, когда отладка не нужна.
</p>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <div class="fs-3 fw-bold"><?= (int) $stats['frames'] ?></div>
                <div class="text-muted">Кадры (frames)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <a href="/ui/sources" class="text-decoration-none text-body">
            <div class="card text-center shadow-sm">
                <div class="card-body">
                    <div class="fs-3 fw-bold"><?= (int) $stats['sources'] ?></div>
                    <div class="text-muted">Источники (sources)</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <div class="fs-3 fw-bold"><?= (int) $stats['anomalies'] ?></div>
                <div class="text-muted">Аномалии
                    (<span class="text-danger"><?= (int) $stats['alerts'] ?> алертов</span>)
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center shadow-sm">
            <div class="card-body">
                <div class="fs-3 fw-bold"><?= (int) $stats['charts'] ?></div>
                <div class="text-muted">Графики (charts)</div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between">
        <span>Pipeline</span>
        <span class="text-muted small">Общих задач в очереди (для любого pipeline): <?= (int) $pendingShared ?></span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th><th>Статус</th><th>Последний запрос (UTC)</th><th>IP</th>
                    <th>Выполняет</th><th>В очереди (свои)</th><th>Кадров</th><th>Зарегистрирован (UTC)</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($pipelines)): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">
                    Pipeline не зарегистрированы. На сервере API:
                    <code>php spark pipeline:create &lt;имя&gt;</code> — выдаст ключ для <code>API_KEY</code> в его <code>.env</code>.
                </td></tr>
            <?php endif; ?>
            <?php foreach ($pipelines as $p): ?>
                <?php
                [$badge, $label] = match ($p['status']) {
                    'online'  => ['bg-success', 'online'],
                    'stale'   => ['bg-warning text-dark', 'давно не выходил на связь'],
                    'revoked' => ['bg-secondary', 'ключ отозван'],
                    default   => ['bg-danger', 'offline'],
                };
                $running = $p['running_task'];
                ?>
                <tr>
                    <td><code><?= esc($p['name']) ?></code></td>
                    <td><span class="badge <?= $badge ?>"><?= esc($label) ?></span></td>
                    <td class="small"><?= esc($p['last_seen_at'] ?? '—') ?></td>
                    <td class="small"><?= esc($p['last_ip'] ?? '—') ?></td>
                    <td class="small">
                        <?php if ($running !== null): ?>
                            <a href="/ui/tasks/<?= esc($running['id']) ?>"><?= esc($running['type']) ?></a>
                            <?= (int) $running['completed_items'] + (int) $running['failed_items'] ?>/<?= (int) $running['total_items'] ?>
                        <?php else: ?>
                            <span class="text-muted">простаивает</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="/ui/tasks?status=PENDING&amp;pipeline=<?= esc($p['name'], 'url') ?>"><?= (int) $p['pending_own'] ?></a></td>
                    <td><a href="/ui/frames?pipeline=<?= esc($p['name'], 'url') ?>"><?= (int) $p['frame_count'] ?></a></td>
                    <td class="small"><?= esc($p['created_at'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header">Задачи по статусам</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0 text-center">
            <thead class="table-light">
                <tr><?php foreach ($tasksByStatus as $status => $count): ?><th><?= esc($status) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
                <tr><?php foreach ($tasksByStatus as $status => $count): ?><td><?= (int) $count ?></td><?php endforeach; ?></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-primary" href="/ui/frames">Кадры → создать задачу</a>
    <a class="btn btn-outline-secondary" href="/ui/sources">Источники</a>
    <a class="btn btn-outline-secondary" href="/ui/tasks">Список задач</a>
    <a class="btn btn-outline-secondary" href="/ui/charts">Графики</a>
    <a class="btn btn-outline-secondary" href="/ui/anomalies">Аномалии</a>
</div>

<?= view('web/partials/footer') ?>
