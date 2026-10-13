<?php
/**
 * @var list<array>           $groups  anomalies grouped by source (AnomaliesController::index())
 * @var list<string>          $anomalyTypes
 * @var array<string, string> $filters
 */
$this->extend('web/layout');
$this->setVar('title', 'Аномалии');
$this->setVar('active', 'anomalies');
$this->setVar('fill', true);

$filtered = $filters['anomaly_type'] !== '' || $filters['object'] !== '' || $filters['frame_id'] !== '' || $filters['source_id'] !== '';
$typeTone = static fn (string $type): string => match ($type) {
    'SUPERNOVA_CANDIDATE', 'MOVING_UNKNOWN', 'UNKNOWN' => 'red',
    'ASTEROID', 'COMET'                                 => 'blue',
    'VARIABLE_STAR', 'BINARY_STAR'                      => 'violet',
    'SPACE_DEBRIS'                                      => 'gray',
    default                                             => 'cyan',
};
?>
<?= $this->section('content') ?>

<div class="page-head">
    <div>
        <h1>Аномалии</h1>
        <p class="sub">Сгруппированы по источнику: одна строка — все аномалии объекта за время наблюдений. Красная метка слева — есть алерт.</p>
    </div>
</div>

<div class="panel" data-select-scope>
    <nav class="tabs" aria-label="Алерты">
        <a href="<?= esc(ui_query(['is_alert' => null]), 'attr') ?>" class="<?= $filters['is_alert'] === '' ? 'active' : '' ?>">Все</a>
        <a href="<?= esc(ui_query(['is_alert' => '1']), 'attr') ?>" class="<?= $filters['is_alert'] === '1' ? 'active' : '' ?>">Только алерты</a>
        <a href="<?= esc(ui_query(['is_alert' => '0']), 'attr') ?>" class="<?= $filters['is_alert'] === '0' ? 'active' : '' ?>">Без алертов</a>
    </nav>

    <form class="filters" method="get" action="/ui/anomalies" style="border-radius: 0">
        <?php if ($filters['is_alert'] !== ''): ?><input type="hidden" name="is_alert" value="<?= esc($filters['is_alert'], 'attr') ?>"><?php endif; ?>
        <label class="field wide">
            <span class="label">Тип</span>
            <select class="input" name="anomaly_type" data-autosubmit>
                <option value="">Все типы</option>
                <?php foreach ($anomalyTypes as $t): ?>
                    <option value="<?= esc($t, 'attr') ?>" <?= $t === $filters['anomaly_type'] ? 'selected' : '' ?>><?= esc($t) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="label">Объект</span>
            <input class="input" type="text" name="object" value="<?= esc($filters['object'], 'attr') ?>" placeholder="M31">
        </label>
        <label class="field">
            <span class="label">frame_id</span>
            <input class="input mono" type="text" name="frame_id" value="<?= esc($filters['frame_id'], 'attr') ?>">
        </label>
        <label class="field">
            <span class="label">source_id</span>
            <input class="input mono" type="text" name="source_id" value="<?= esc($filters['source_id'], 'attr') ?>">
        </label>
        <div class="actions">
            <?php if ($filtered): ?><a class="btn ghost" href="<?= esc(ui_query(['anomaly_type' => null, 'object' => null, 'frame_id' => null, 'source_id' => null]), 'attr') ?>"><?= ui_icon('x', 14) ?>Сбросить</a><?php endif; ?>
            <button class="btn" type="submit"><?= ui_icon('search', 14) ?>Показать</button>
        </div>
    </form>

    <form method="post" action="/ui/anomalies/generate-charts">
        <?= csrf_field() ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th class="check-col"><input class="check" type="checkbox" data-pick-all aria-label="Выбрать все"></th>
                    <th>Источник</th>
                    <th>Объект</th>
                    <th>Типы</th>
                    <th>RA / Dec</th>
                    <th class="num">Аномалий</th>
                    <th>Период наблюдений (UTC)</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($groups)): ?>
                    <tr><td colspan="8" class="empty-cell"><div class="empty"><?= ui_icon('anomalies') ?><strong>Аномалий не найдено</strong><span><?= $filtered || $filters['is_alert'] !== '' ? 'Измените или сбросьте фильтр.' : 'Pipeline ещё ничего не нашёл.' ?></span></div></td></tr>
                <?php endif; ?>
                <?php foreach ($groups as $g): ?>
                    <tr class="<?= $g['has_alert'] ? 'alert' : '' ?>">
                        <td class="check-col">
                            <input type="checkbox"
                                   name="group_data[]"
                                   value="<?= esc(json_encode(['source_id' => $g['source_id'], 'ids_by_type' => $g['ids_by_type'], 'designation' => $g['designation'] ?? null]), 'attr') ?>"
                                   class="check js-pick"
                                   data-has-source="<?= $g['source_id'] ? '1' : '0' ?>"
                                   aria-label="Выбрать">
                        </td>
                        <td>
                            <span class="primary"><?= esc($g['designation'] ?? 'Без обозначения') ?></span>
                            <span class="sub">
                                <?= esc($g['catalog_name'] ?? 'без каталога') ?>
                                <?php if ($g['source_id']): ?> · <span class="id"><?= esc($g['source_id']) ?></span><?php endif; ?>
                            </span>
                        </td>
                        <td class="nowrap"><?= esc($g['object'] ?? '—') ?></td>
                        <td>
                            <span class="chips">
                                <?php if ($g['has_alert']): ?><?= ui_chip('alert', 'red', 'Есть алерт', true) ?><?php endif; ?>
                                <?php foreach ($g['types'] as $type): ?><?= ui_chip($type, $typeTone($type)) ?><?php endforeach; ?>
                            </span>
                        </td>
                        <td>
                            <a class="coords" href="<?= esc(ui_aladin($g['ra'], $g['dec'], 0.1), 'attr') ?>" target="_blank" rel="noopener" title="Открыть в Aladin Lite">
                                <?= ui_num($g['ra'], 5) ?> / <?= ui_num($g['dec'], 5) ?>
                            </a>
                        </td>
                        <td class="num"><?= count($g['anomaly_ids']) ?></td>
                        <td class="mono small nowrap">
                            <?php if ($g['first_obs']): ?>
                                <?= ui_time($g['first_obs']) ?>
                                <?php if ($g['last_obs'] !== $g['first_obs']): ?><br><span class="dim"><?= ui_time($g['last_obs']) ?></span><?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="actions">
                            <?php if ($g['source_id']): ?>
                                <span class="row-actions">
                                    <a class="btn icon sm" href="/ui/charts?source_id=<?= esc($g['source_id'], 'url') ?>" title="Графики источника"><?= ui_icon('charts') ?></a>
                                    <a class="btn icon sm" href="/ui/anomalies?source_id=<?= esc($g['source_id'], 'url') ?>" title="Только этот источник"><?= ui_icon('search') ?></a>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-foot">
            <span><?= count($groups) ?> <?= ui_plural(count($groups), 'группа', 'группы', 'групп') ?><?= array_sum(array_map(static fn ($g) => count($g['anomaly_ids']), $groups)) >= 500 ? ' — из последних 500 аномалий' : '' ?></span>
            <span>Shift+клик выделяет диапазон</span>
        </div>

        <div class="selbar" data-empty>
            <span class="count"><b data-sel-count>0</b>выбрано</span>
            <span class="sep"></span>
            <button class="btn primary" type="submit" data-min-select="1" data-needs="hasSource" title="По одному графику на каждый тип аномалии источника">
                <?= ui_icon('charts', 14) ?>Построить графики
            </button>
            <button class="btn danger solid" type="submit" formaction="/ui/anomalies/delete"
                    data-confirm="Будут удалены аномалии выбранных групп ({n}) и все графики их источников. Это необратимо."
                    data-confirm-title="Удалить аномалии?" data-confirm-ok="Удалить" data-danger>
                <?= ui_icon('trash', 14) ?>Удалить
            </button>
            <button class="btn icon" type="button" data-pick-none title="Снять выделение" aria-label="Снять выделение"><?= ui_icon('x') ?></button>
        </div>
    </form>
</div>

<?= $this->endSection() ?>
