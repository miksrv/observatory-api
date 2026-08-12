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

<!--
    NOTE: checkboxes below are deliberately NOT inside a <form> — each card already has its own
    per-card "Удалить" <form> in the footer, and nested <form> elements are invalid HTML (the
    browser would silently mis-close them). #chartMergeForm below stays empty except for the
    CSRF field; the JS at the bottom builds hidden inputs into it from the checked boxes right
    before submit.
-->
<form method="post" id="chartMergeForm" action="/ui/sources/merge">
<?= csrf_field() ?>
</form>

<div class="mb-2 d-flex align-items-center gap-2">
    <button type="button" class="btn btn-sm btn-warning" id="btnMerge" disabled>
        🔗 Объединить источники
    </button>
    <span class="text-muted small" id="selectedCount">Выбрано: 0</span>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSelectAll">Выбрать все</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDeselectAll">Снять все</button>
    <span class="text-muted small">— отметьте 2+ карточки одного и того же объекта (напр. кометы,
        распознанной SkyBot как разные source_id) и объедините их в один источник.</span>
</div>

<div class="row g-3">
<?php foreach ($charts as $chart): ?>
    <?php $chartId = $chart['source_id'] ?? $chart['task_item_id']; ?>
    <div class="col-12 col-md-6 col-lg-4 col-xl-3">
        <div class="card shadow-sm h-100">
            <?php if ($chart['source_id']): ?>
                <div class="form-check position-absolute top-0 start-0 m-2 bg-white bg-opacity-75 rounded px-1">
                    <input type="checkbox" value="<?= esc($chart['source_id']) ?>"
                           class="form-check-input merge-check" id="merge-<?= esc($chart['source_id']) ?>">
                    <label class="form-check-label small" for="merge-<?= esc($chart['source_id']) ?>">выбрать</label>
                </div>
            <?php endif; ?>
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
                    <?php if (!empty($chart['src_ra']) && !empty($chart['src_dec'])): ?>
                        <div class="small mt-1">
                            📍 <a href="https://aladin.cds.unistra.fr/AladinLite/?target=<?= urlencode($chart['src_ra'] . ' ' . $chart['src_dec']) ?>&fov=0.10&survey=P%2FDSS2%2Fcolor" target="_blank" rel="noopener">
                                <?= esc(number_format((float)$chart['src_ra'], 5)) ?>°, <?= esc(number_format((float)$chart['src_dec'], 5)) ?>°
                            </a>
                        </div>
                    <?php endif; ?>
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

<script>
(function() {
    const mergeForm  = document.getElementById('chartMergeForm');
    const btnMerge    = document.getElementById('btnMerge');
    const countEl     = document.getElementById('selectedCount');
    const boxes       = () => document.querySelectorAll('.merge-check');

    function updateState() {
        const count = document.querySelectorAll('.merge-check:checked').length;
        btnMerge.disabled = count < 2;
        countEl.textContent = 'Выбрано: ' + count;
    }

    document.querySelector('.row.g-3').addEventListener('change', function(e) {
        if (e.target.classList.contains('merge-check')) updateState();
    });

    document.getElementById('btnSelectAll').addEventListener('click', function() {
        boxes().forEach(cb => cb.checked = true);
        updateState();
    });

    document.getElementById('btnDeselectAll').addEventListener('click', function() {
        boxes().forEach(cb => cb.checked = false);
        updateState();
    });

    btnMerge.addEventListener('click', function() {
        const checked = [...document.querySelectorAll('.merge-check:checked')].map(cb => cb.value);
        if (checked.length < 2) return;
        if (!confirm('Объединить ' + checked.length + ' источник(ов) в один новый? '
            + 'Старые графики и аномалии этих источников будут удалены. Это действие необратимо.')) return;

        // Clear any hidden inputs left over from a previous click, then rebuild.
        mergeForm.querySelectorAll('input[name="source_ids[]"]').forEach(el => el.remove());
        checked.forEach(function(sourceId) {
            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'source_ids[]';
            input.value = sourceId;
            mergeForm.appendChild(input);
        });
        mergeForm.submit();
    });

    updateState();
})();
</script>

<?= view('web/partials/footer') ?>
