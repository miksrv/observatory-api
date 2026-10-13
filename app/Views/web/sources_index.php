<?php
/**
 * @var list<array>  $sources  each with ra / dec / mag from its latest observation
 * @var list<string> $catalogNames
 * @var list<string> $objectTypes
 * @var string       $filterCatalog
 * @var string       $filterObjectType
 * @var string       $filterSearch
 * @var int          $limit
 */
$this->extend('web/layout');
$this->setVar('title', 'Источники');
$this->setVar('active', 'sources');
$this->setVar('fill', true);

$filtered = $filterCatalog !== '' || $filterObjectType !== '' || $filterSearch !== '';
?>
<?= $this->section('content') ?>

<div class="page-head">
    <div>
        <h1>Источники</h1>
        <p class="sub">
            Каталог обнаруженных объектов. Позиция — по последнему наблюдению (у источника нет своих
            координат: движущиеся объекты смещаются от кадра к кадру). Клик по строке открывает
            <a href="https://aladin.cds.unistra.fr/AladinLite/" target="_blank" rel="noopener">Aladin Lite</a>.
        </p>
    </div>
</div>

<div class="panel">
    <form class="filters" method="get" action="/ui/sources">
        <label class="field wide">
            <span class="label">catalog_id содержит</span>
            <span class="search"><?= ui_icon('search', 14) ?><input class="input" type="search" name="search" value="<?= esc($filterSearch, 'attr') ?>" placeholder="Gaia DR3 …, V* …"></span>
        </label>
        <label class="field">
            <span class="label">Каталог</span>
            <select class="input" name="catalog_name" data-autosubmit>
                <option value="">Все каталоги</option>
                <?php foreach ($catalogNames as $c): ?>
                    <option value="<?= esc($c, 'attr') ?>" <?= $c === $filterCatalog ? 'selected' : '' ?>><?= esc($c) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="label">Тип объекта</span>
            <select class="input" name="object_type" data-autosubmit>
                <option value="">Все типы</option>
                <?php foreach ($objectTypes as $t): ?>
                    <option value="<?= esc($t, 'attr') ?>" <?= $t === $filterObjectType ? 'selected' : '' ?>><?= esc($t) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field narrow">
            <span class="label">Лимит</span>
            <input class="input mono" type="number" name="limit" value="<?= (int) $limit ?>" min="1" max="2000">
        </label>
        <div class="actions">
            <?php if ($filtered): ?><a class="btn ghost" href="/ui/sources"><?= ui_icon('x', 14) ?>Сбросить</a><?php endif; ?>
            <button class="btn" type="submit"><?= ui_icon('search', 14) ?>Показать</button>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Обозначение</th>
                <th>Тип</th>
                <th class="num">Mag кат.</th>
                <th class="num">Mag посл.</th>
                <th>RA / Dec</th>
                <th class="num">Наблюдений</th>
                <th>Период</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($sources)): ?>
                <tr><td colspan="8" class="empty-cell"><div class="empty"><?= ui_icon('sources') ?><strong>Источников не найдено</strong><span>Измените или сбросьте фильтр.</span></div></td></tr>
            <?php endif; ?>
            <?php foreach ($sources as $source): ?>
                <?php
                $hasPosition = $source['ra'] !== null && $source['dec'] !== null;
                $aladinUrl   = $hasPosition ? ui_aladin($source['ra'], $source['dec']) : null;
                ?>
                <tr<?= $hasPosition ? ' data-href="' . esc($aladinUrl, 'attr') . '" data-target="_blank"' : '' ?>>
                    <td>
                        <span class="primary"><?= esc($source['catalog_id'] ?? 'Без обозначения') ?></span>
                        <span class="sub"><?= esc($source['catalog_name'] ?? 'не сопоставлен с каталогом') ?> · <span class="id"><?= esc($source['id']) ?></span></span>
                    </td>
                    <td><?= $source['object_type'] ? ui_chip($source['object_type'], 'cyan') : '<span class="dim">—</span>' ?></td>
                    <td class="num"><?= ui_num($source['catalog_mag'], 2) ?></td>
                    <td class="num"><?= ui_num($source['mag'], 2) ?></td>
                    <td>
                        <?php if ($hasPosition): ?>
                            <a class="coords" href="<?= esc($aladinUrl, 'attr') ?>" target="_blank" rel="noopener" title="Открыть в Aladin Lite">
                                <?= ui_num($source['ra'], 5) ?> / <?= ui_num($source['dec'], 5) ?>
                            </a>
                        <?php else: ?>
                            <span class="dim small">нет наблюдений</span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= (int) $source['observation_count'] ?></td>
                    <td class="mono small nowrap">
                        <?= ui_time($source['first_observed_at']) ?><br>
                        <span class="dim"><?= ui_time($source['last_observed_at']) ?></span>
                    </td>
                    <td class="actions">
                        <span class="row-actions">
                            <a class="btn icon sm" href="/ui/charts?source_id=<?= esc($source['id'], 'url') ?>" title="Графики источника"><?= ui_icon('charts') ?></a>
                            <a class="btn icon sm" href="/ui/anomalies?source_id=<?= esc($source['id'], 'url') ?>" title="Аномалии источника"><?= ui_icon('anomalies') ?></a>
                            <form class="inline" method="post" action="/ui/sources/generate-charts">
                                <?= csrf_field() ?>
                                <input type="hidden" name="source_id" value="<?= esc($source['id'], 'attr') ?>">
                                <button type="submit" class="btn sm" title="Создать задачу GENERATE_CHARTS"><?= ui_icon('refresh', 14) ?>Построить графики</button>
                            </form>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="panel-foot">
        <span>Показано <?= count($sources) ?><?= count($sources) >= $limit ? ' — достигнут лимит ' . $limit : '' ?></span>
    </div>
</div>

<?= $this->endSection() ?>
