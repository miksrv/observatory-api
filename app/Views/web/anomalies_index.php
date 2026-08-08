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

<div class="table-responsive border rounded bg-white">
<table class="table table-sm table-hover table-striped mb-0">
<thead class="table-light">
<tr>
    <th>Obs time (UTC)</th><th>Object</th><th>Frame</th><th>Тип</th><th>Alert</th>
    <th>RA / Dec</th><th>Mag</th><th>Δmag</th><th>MPC</th><th>Источник</th><th>Notes</th>
</tr>
</thead>
<tbody>
<?php if (empty($anomalies)): ?>
    <tr><td colspan="11" class="text-center text-muted py-4">Ничего не найдено по заданному фильтру.</td></tr>
<?php endif; ?>
<?php foreach ($anomalies as $a): ?>
    <tr class="<?= $a['is_alert'] ? 'table-danger' : '' ?>">
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

<?= view('web/partials/footer') ?>
