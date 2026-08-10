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
    <th>Источник</th><th>Объект</th><th>Типы</th><th>Alert</th>
    <th>MPC</th><th>RA / Dec</th><th>Кол-во</th><th>Период наблюдений</th>
</tr>
</thead>
<tbody>
<?php if (empty($groups)): ?>
    <tr><td colspan="9" class="text-center text-muted py-4">Ничего не найдено по заданному фильтру.</td></tr>
<?php endif; ?>
<?php foreach ($groups as $idx => $g): ?>
    <tr class="<?= $g['has_alert'] ? 'table-danger' : '' ?>">
        <td>
            <input type="checkbox"
                   name="group_data[]"
                   value="<?= esc(json_encode(['source_id' => $g['source_id'], 'anomaly_ids' => $g['anomaly_ids']])) ?>"
                   class="group-check"
                   data-source-id="<?= esc($g['source_id'] ?? '') ?>">
        </td>
        <td class="small">
            <?php if ($g['source_id']): ?>
                <a href="/ui/charts?source_id=<?= esc($g['source_id']) ?>"><?= esc($g['catalog_name'] ?? $g['source_id']) ?></a>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td><?= esc($g['object'] ?? '—') ?></td>
        <td>
            <?php foreach ($g['types'] as $type): ?>
                <span class="badge bg-secondary"><?= esc($type) ?></span>
            <?php endforeach; ?>
        </td>
        <td><?php if ($g['has_alert']): ?><span class="badge bg-danger">alert</span><?php endif; ?></td>
        <td><?= esc($g['mpc_designation'] ?? '—') ?></td>
        <td class="small"><?= number_format((float) $g['ra'], 5) ?> / <?= number_format((float) $g['dec'], 5) ?></td>
        <td><span class="badge bg-info text-dark"><?= count($g['anomaly_ids']) ?></span></td>
        <td class="small">
            <?php if ($g['first_obs']): ?>
                <?= esc(gmdate('Y-m-d H:i', strtotime($g['first_obs']))) ?>
                <?php if ($g['last_obs'] !== $g['first_obs']): ?>
                    &mdash; <?= esc(gmdate('Y-m-d H:i', strtotime($g['last_obs']))) ?>
                <?php endif; ?>
            <?php else: ?>—<?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</form>

<script>
(function() {
    const form = document.getElementById('anomalyActionForm');
    const checkAll = document.getElementById('checkAll');
    const boxes = () => document.querySelectorAll('.group-check');
    const btnCharts = document.getElementById('btnGenerateCharts');
    const btnDelete = document.getElementById('btnDelete');
    const countEl = document.getElementById('selectedCount');

    function updateState() {
        const checked = document.querySelectorAll('.group-check:checked');
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
        if (e.target.classList.contains('group-check')) updateState();
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
        form.action = '/ui/anomalies/generate-charts';
        form.submit();
    });

    btnDelete.addEventListener('click', function() {
        const count = document.querySelectorAll('.group-check:checked').length;
        if (!confirm('Удалить ' + count + ' группу(ы) аномалий и связанные чарты? Это действие необратимо.')) return;
        form.action = '/ui/anomalies/delete';
        form.submit();
    });
})();
</script>

<?= view('web/partials/footer') ?>
