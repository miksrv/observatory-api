<?= view('web/partials/header', ['title' => 'Аномалии (anomalies)']) ?>

<h1 class="h4 mb-3">Аномалии (anomalies)</h1>

<form method="get" action="/ui/anomalies" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label mb-0">Тип аномалии</label>
        <select name="anomaly_type" class="form-select">
            <option value="">— все —</option>
            <?php foreach ($anomalyTypes as $t): ?>
                <option value="<?= esc($t) ?>" <?= $t === $filters['anomaly_type'] ? 'selected' : '' ?>><?= esc($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">Только алерты</label>
        <select name="is_alert" class="form-select">
            <option value="">— все —</option>
            <option value="1" <?= $filters['is_alert'] === '1' ? 'selected' : '' ?>>да</option>
            <option value="0" <?= $filters['is_alert'] === '0' ? 'selected' : '' ?>>нет</option>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">Объект</label>
        <input type="text" name="object" class="form-control" value="<?= esc($filters['object']) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">frame_id</label>
        <input type="text" name="frame_id" class="form-control" value="<?= esc($filters['frame_id']) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">source_id</label>
        <input type="text" name="source_id" class="form-control" value="<?= esc($filters['source_id']) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary">Фильтровать</button>
    </div>
    <div class="col-auto">
        <a href="/ui/anomalies" class="btn btn-outline-secondary">Сбросить</a>
    </div>
</form>

<form method="post" id="anomalyActionForm">
<?= csrf_field() ?>
<input type="hidden" name="action" id="formAction" value="">

<div class="mb-2 d-flex align-items-center gap-2">
    <button type="button" class="btn btn-sm btn-success" id="btnGenerateCharts" disabled>
        📊 Создать задачу GENERATE_CHARTS
    </button>
    <button type="button" class="btn btn-sm btn-danger" id="btnDelete" disabled>
        🗑️ Удалить выбранные
    </button>
    <span class="text-muted small" id="selectedCount">Выбрано: 0</span>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSelectAll">Выбрать все</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDeselectAll">Снять все</button>
</div>

<div class="table-responsive border rounded bg-white">
<table class="table table-sm table-hover table-striped mb-0">
<thead class="table-light">
<tr>
    <th><input type="checkbox" id="checkAll" title="Выбрать все"></th>
    <th>Obs time (UTC)</th><th>Object</th><th>Frame</th><th>Тип</th><th>Alert</th>
    <th>RA / Dec</th><th>Mag</th><th>Δmag</th><th>MPC</th><th>Источник</th><th>Notes</th>
</tr>
</thead>
<tbody>
<?php if (empty($anomalies)): ?>
    <tr><td colspan="12" class="text-center text-muted py-4">Ничего не найдено по заданному фильтру.</td></tr>
<?php endif; ?>
<?php foreach ($anomalies as $a): ?>
    <tr class="<?= $a['is_alert'] ? 'table-danger' : '' ?>">
        <td>
            <input type="checkbox" name="anomaly_ids[]" value="<?= esc($a['id']) ?>" class="anomaly-check" data-source-id="<?= esc($a['source_id'] ?? '') ?>">
        </td>
        <td class="small"><?= $a['obs_time'] ? esc(gmdate('Y-m-d H:i:s', strtotime($a['obs_time']))) : '—' ?></td>
        <td><?= esc($a['object'] ?? '—') ?></td>
        <td class="text-break small"><?= esc($a['filename'] ?? $a['frame_id']) ?></td>
        <td><?= esc($a['anomaly_type']) ?></td>
        <td><?php if ($a['is_alert']): ?><span class="badge bg-danger">alert</span><?php endif; ?></td>
        <td class="small"><?= number_format((float) $a['ra'], 5) ?> / <?= number_format((float) $a['dec'], 5) ?></td>
        <td><?= $a['magnitude'] !== null ? number_format((float) $a['magnitude'], 2) : '—' ?></td>
        <td><?= $a['delta_mag'] !== null ? number_format((float) $a['delta_mag'], 2) : '—' ?></td>
        <td><?= esc($a['mpc_designation'] ?? '—') ?></td>
        <td class="small">
            <?php if ($a['source_id']): ?>
                <a href="/ui/charts?source_id=<?= esc($a['source_id']) ?>"><?= esc($a['catalog_name'] ?? $a['source_id']) ?></a>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td class="small"><?= esc($a['notes'] ?? '') ?></td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</form>

<script>
(function() {
    const form = document.getElementById('anomalyActionForm');
    const actionField = document.getElementById('formAction');
    const checkAll = document.getElementById('checkAll');
    const boxes = () => document.querySelectorAll('.anomaly-check');
    const btnCharts = document.getElementById('btnGenerateCharts');
    const btnDelete = document.getElementById('btnDelete');
    const countEl = document.getElementById('selectedCount');

    function updateState() {
        const checked = document.querySelectorAll('.anomaly-check:checked');
        const count = checked.length;
        const hasSource = [...checked].some(cb => cb.dataset.sourceId !== '');
        btnCharts.disabled = !hasSource;
        btnDelete.disabled = count === 0;
        countEl.textContent = 'Выбрано: ' + count;
        const all = boxes();
        checkAll.checked = all.length > 0 && count === all.length;
        checkAll.indeterminate = count > 0 && count < all.length;
    }

    checkAll.addEventListener('change', function() {
        boxes().forEach(cb => cb.checked = this.checked);
        updateState();
    });

    document.querySelector('tbody').addEventListener('change', function(e) {
        if (e.target.classList.contains('anomaly-check')) updateState();
    });

    document.getElementById('btnSelectAll').addEventListener('click', function() {
        boxes().forEach(cb => cb.checked = true);
        updateState();
    });

    document.getElementById('btnDeselectAll').addEventListener('click', function() {
        boxes().forEach(cb => cb.checked = false);
        updateState();
    });

    btnCharts.addEventListener('click', function() {
        actionField.value = 'generate_charts';
        form.action = '/ui/anomalies/generate-charts';
        form.submit();
    });

    btnDelete.addEventListener('click', function() {
        const count = document.querySelectorAll('.anomaly-check:checked').length;
        if (!confirm('Удалить ' + count + ' аномали(й) и связанные чарты? Это действие необратимо.')) return;
        actionField.value = 'delete';
        form.action = '/ui/anomalies/delete';
        form.submit();
    });
})();
</script>

<?= view('web/partials/footer') ?>
