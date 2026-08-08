<?= view('web/partials/header', ['title' => 'Источники (sources)']) ?>

<h1 class="h4 mb-3">Источники (sources)</h1>
<p class="text-muted small">
    Позиция каждого источника берётся из его самого свежего наблюдения
    (<code>source_observations</code>) — у <code>sources</code> нет собственных ra/dec, см. CLAUDE.md.
    Клик по строке открывает <a href="https://aladin.cds.unistra.fr/AladinLite/" target="_blank" rel="noopener">Aladin Lite</a>
    с центром на этих координатах.
</p>

<form method="get" action="/ui/sources" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label mb-0">Каталог</label>
        <select name="catalog_name" class="form-select">
            <option value="">— все —</option>
            <?php foreach ($catalogNames as $c): ?>
                <option value="<?= esc($c) ?>" <?= $c === $filterCatalog ? 'selected' : '' ?>><?= esc($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">Тип объекта</label>
        <select name="object_type" class="form-select">
            <option value="">— все —</option>
            <?php foreach ($objectTypes as $t): ?>
                <option value="<?= esc($t) ?>" <?= $t === $filterObjectType ? 'selected' : '' ?>><?= esc($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">catalog_id содержит</label>
        <input type="text" name="search" class="form-control" value="<?= esc($filterSearch) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">Лимит</label>
        <input type="number" name="limit" class="form-control" style="width: 100px" value="<?= (int) $limit ?>" min="1" max="2000">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary">Фильтровать</button>
    </div>
    <div class="col-auto">
        <a href="/ui/sources" class="btn btn-outline-secondary">Сбросить</a>
    </div>
</form>

<div class="table-responsive border rounded bg-white">
<table class="table table-sm table-hover table-striped mb-0">
<thead class="table-light sticky-top">
<tr>
    <th>Каталог</th><th>ID каталога</th><th>Тип</th><th>Mag (каталог)</th><th>Mag (посл.)</th>
    <th>RA / Dec</th><th>Наблюдений</th><th>Первое / последнее</th><th></th>
</tr>
</thead>
<tbody>
<?php if (empty($sources)): ?>
    <tr><td colspan="9" class="text-center text-muted py-4">Ничего не найдено по заданному фильтру.</td></tr>
<?php endif; ?>
<?php foreach ($sources as $source): ?>
    <?php
    $hasPosition = $source['ra'] !== null && $source['dec'] !== null;
    $aladinUrl   = $hasPosition
        ? 'https://aladin.cds.unistra.fr/AladinLite/?target='
            . rawurlencode(number_format((float) $source['ra'], 6, '.', '') . ' ' . number_format((float) $source['dec'], 6, '.', ''))
            . '&fov=0.25&survey=P%2FDSS2%2Fcolor'
        : null;
    ?>
    <tr class="<?= $hasPosition ? 'cursor-pointer' : '' ?>"
        <?php if ($hasPosition): ?>
            onclick="window.open('<?= esc($aladinUrl, 'attr') ?>', '_blank', 'noopener')" role="link"
        <?php endif; ?>
    >
        <td><?= esc($source['catalog_name'] ?? '—') ?></td>
        <td class="text-break small"><?= esc($source['catalog_id'] ?? '—') ?></td>
        <td><?= esc($source['object_type'] ?? '—') ?></td>
        <td><?= $source['catalog_mag'] !== null ? number_format((float) $source['catalog_mag'], 2) : '—' ?></td>
        <td><?= $source['mag'] !== null ? number_format((float) $source['mag'], 2) : '—' ?></td>
        <td class="small">
            <?php if ($hasPosition): ?>
                <a href="<?= esc($aladinUrl) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">
                    <?= number_format((float) $source['ra'], 5) ?> / <?= number_format((float) $source['dec'], 5) ?>
                </a>
            <?php else: ?>
                <span class="text-muted">нет наблюдений</span>
            <?php endif; ?>
        </td>
        <td><?= (int) $source['observation_count'] ?></td>
        <td class="small">
            <?= $source['first_observed_at'] ? esc(gmdate('Y-m-d H:i', strtotime($source['first_observed_at']))) : '—' ?>
            /
            <?= $source['last_observed_at'] ? esc(gmdate('Y-m-d H:i', strtotime($source['last_observed_at']))) : '—' ?>
        </td>
        <td class="small text-nowrap">
            <a href="/ui/charts?source_id=<?= esc($source['id']) ?>" onclick="event.stopPropagation()">графики</a>
            ·
            <a href="/ui/anomalies?source_id=<?= esc($source['id']) ?>" onclick="event.stopPropagation()">аномалии</a>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<style>.cursor-pointer { cursor: pointer; }</style>

<?= view('web/partials/footer') ?>
