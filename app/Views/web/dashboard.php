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
