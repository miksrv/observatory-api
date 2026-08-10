<?= view('web/partials/header', ['title' => 'Графики (charts)']) ?>

<h1 class="h4 mb-3">Графики источников (source_charts)</h1>

<form method="get" action="/ui/charts" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label mb-0">source_id</label>
        <input type="text" name="source_id" class="form-control" value="<?= esc($filterSourceId) ?>" placeholder="точный id">
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">Стиль</label>
        <select name="style" class="form-select">
            <option value="">— все —</option>
            <?php foreach ($styles as $s): ?>
                <option value="<?= esc($s) ?>" <?= $s === $filterStyle ? 'selected' : '' ?>><?= esc($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary">Фильтровать</button>
    </div>
    <div class="col-auto">
        <a href="/ui/charts" class="btn btn-outline-secondary">Сбросить</a>
    </div>
</form>

<?php if (empty($charts)): ?>
    <p class="text-muted">Графиков не найдено.</p>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($charts as $chart): ?>
    <?php $chartId = $chart['source_id'] ?? $chart['task_item_id']; ?>
    <div class="col-12 col-md-6 col-lg-4 col-xl-3">
        <div class="card shadow-sm h-100">
            <a href="/ui/charts/<?= esc($chartId) ?>/image" target="_blank" rel="noopener">
                <img src="/ui/charts/<?= esc($chartId) ?>/image" class="card-img-top chart-thumb p-2" loading="lazy" alt="chart">
            </a>
            <div class="card-body">
                <?php if ($chart['source_id']): ?>
                    <div class="small text-muted">source_id</div>
                    <code class="small"><?= esc($chart['source_id']) ?></code>
                    <div class="small text-muted mt-1">
                        <?= esc($chart['catalog_name'] ?? '—') ?> <?= esc($chart['catalog_id'] ?? '') ?>
                        <?php if ($chart['object_type']): ?><br><?= esc($chart['object_type']) ?><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="small text-muted">task_item_id (PREVIEW_CATALOG_MATCH)</div>
                    <code class="small"><?= esc($chart['task_item_id']) ?></code>
                    <div class="small text-muted mt-1 text-break"><?= esc($chart['item_filename'] ?? '—') ?></div>
                <?php endif; ?>
                <div class="mt-2">
                    <span class="badge bg-info text-dark"><?= esc($chart['style']) ?></span>
                    <?php if ($chart['source_id']): ?>· <?= (int) $chart['frame_count'] ?> эпох<?php endif; ?>
                </div>
                <div class="small text-muted mt-1">
                    обновлён: <?= $chart['updated_at'] ? esc(gmdate('Y-m-d H:i:s', strtotime($chart['updated_at']))) : '—' ?>
                </div>
            </div>
            <div class="card-footer bg-transparent">
                <?php if ($chart['source_id']): ?>
                    <a class="btn btn-sm btn-primary w-100 mb-1" href="/ui/anomalies?source_id=<?= esc($chart['source_id']) ?>">
                        Аномалии этого источника →
                    </a>
                <?php elseif ($chart['task_id']): ?>
                    <a class="btn btn-sm btn-primary w-100 mb-1" href="/ui/tasks/<?= esc($chart['task_id']) ?>">
                        К задаче →
                    </a>
                <?php endif; ?>
                <form method="post" action="/ui/charts/<?= esc($chart['id']) ?>/delete" class="d-inline w-100"
                      onsubmit="return confirm('Удалить этот график? Файл тоже будет удалён с диска.')">
                    <button type="submit" class="btn btn-sm btn-outline-danger w-100">Удалить</button>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?= view('web/partials/footer') ?>
