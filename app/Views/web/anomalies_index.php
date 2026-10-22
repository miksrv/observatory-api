<?php
/**
 * @var list<array>           $groups  anomalies grouped by source (AnomaliesController::index())
 * @var list<string>          $anomalyTypes
 * @var array<string, string> $filters
 * @var \App\Libraries\UiListQuery $list
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
        <p class="sub">Сгруппированы по источнику: одна строка — все аномалии объекта за время наблюдений (число — по выбранной вкладке; действия касаются всей группы). Красная метка слева — есть алерт.
            Просмотренные и неинтересные отметьте «Проверено» — они уйдут из списка и счётчиков; отметка переживает повторный поиск аномалий.</p>
    </div>
</div>

<div class="panel" data-select-scope>
    <nav class="tabs" aria-label="Проверка">
        <a href="<?= esc(ui_query(['review' => null, 'page' => null]), 'attr') ?>" class="<?= $filters['review'] === 'new' ? 'active' : '' ?>">Не проверенные</a>
        <a href="<?= esc(ui_query(['review' => 'done', 'page' => null]), 'attr') ?>" class="<?= $filters['review'] === 'done' ? 'active' : '' ?>">Проверенные</a>
        <a href="<?= esc(ui_query(['review' => 'all', 'page' => null]), 'attr') ?>" class="<?= $filters['review'] === 'all' ? 'active' : '' ?>">Все</a>
    </nav>
    <nav class="tabs" aria-label="Алерты">
        <a href="<?= esc(ui_query(['is_alert' => null, 'page' => null]), 'attr') ?>" class="<?= $filters['is_alert'] === '' ? 'active' : '' ?>">Все</a>
        <a href="<?= esc(ui_query(['is_alert' => '1', 'page' => null]), 'attr') ?>" class="<?= $filters['is_alert'] === '1' ? 'active' : '' ?>">Только алерты</a>
        <a href="<?= esc(ui_query(['is_alert' => '0', 'page' => null]), 'attr') ?>" class="<?= $filters['is_alert'] === '0' ? 'active' : '' ?>">Без алертов</a>
    </nav>

    <form class="filters" method="get" action="/ui/anomalies" style="border-radius: 0">
        <?php foreach ($list->keptParams('anomaly_type', 'object', 'frame_id', 'source_id', 'page') as $name => $value): ?>
            <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
        <?php endforeach; ?>
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
            <?php if ($filtered): ?><a class="btn ghost" href="<?= esc(ui_query(['anomaly_type' => null, 'object' => null, 'frame_id' => null, 'source_id' => null, 'page' => null]), 'attr') ?>"><?= ui_icon('x', 14) ?>Сбросить</a><?php endif; ?>
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
                    <?= ui_th($list, 'designation', 'Источник') ?>
                    <?= ui_th($list, 'object', 'Объект') ?>
                    <?= ui_th($list, 'alert', 'Типы', '', 'Сортировка — сначала с алертами или без') ?>
                    <th>RA / Dec</th>
                    <?= ui_th($list, 'count', 'Аномалий', 'num') ?>
                    <?= ui_th($list, 'last_obs', 'Период наблюдений (UTC)', '', 'Сортировка — по последнему наблюдению') ?>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($groups)): ?>
                    <tr><td colspan="8" class="empty-cell"><div class="empty"><?= ui_icon('anomalies') ?><strong>Аномалий не найдено</strong><span><?= $filtered || $filters['is_alert'] !== '' ? 'Измените или сбросьте фильтр.' : match ($filters['review']) {
                        'new'   => 'Всё просмотрено — непроверенных аномалий нет.',
                        'done'  => 'Проверенных аномалий пока нет.',
                        default => 'Pipeline ещё ничего не нашёл.',
                    } ?></span></div></td></tr>
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
                                <?php if ($g['reviewed'] > 0): ?>
                                    <?= ui_chip($g['reviewed'] === count($g['anomaly_ids']) ? 'проверено' : 'проверено ' . $g['reviewed'] . '/' . count($g['anomaly_ids']), 'green', $g['review_notes'] !== [] ? implode(' · ', $g['review_notes']) : 'Без заметки') ?>
                                <?php endif; ?>
                            </span>
                            <?php if ($g['review_notes'] !== []): ?>
                                <span class="sub"><?= esc(implode(' · ', $g['review_notes'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="coords" href="<?= esc(ui_aladin($g['ra'], $g['dec'], 0.1), 'attr') ?>" target="_blank" rel="noopener" title="Открыть в Aladin Lite">
                                <?= ui_num($g['ra'], 5) ?> / <?= ui_num($g['dec'], 5) ?>
                            </a>
                        </td>
                        <td class="num"><?= $g['shown'] ?></td>
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

        <div class="selbar" data-empty>
            <span class="count"><b data-sel-count>0</b>выбрано</span>
            <span class="sep"></span>
            <button class="btn primary" type="submit" data-min-select="1" data-needs="hasSource" title="По одному графику на каждый тип аномалии источника">
                <?= ui_icon('charts', 14) ?>Построить графики
            </button>
            <span class="sep"></span>
            <input class="input" type="text" name="note" maxlength="1000" placeholder="Заметка: что это на кадре" aria-label="Заметка к проверке" data-enter="review">
            <button class="btn" type="submit" formaction="/ui/anomalies/review" data-min-select="1" data-enter-target="review" data-needs="hasSource"
                    title="Отметить: посмотрел, ничего интересного. Уйдут из непроверенных и из счётчиков">
                <?= ui_icon('check', 14) ?>Проверено
            </button>
            <?php if ($filters['review'] !== 'new'): ?>
                <button class="btn" type="submit" formaction="/ui/anomalies/unreview" data-min-select="1" data-needs="hasSource" title="Вернуть в непроверенные">
                    <?= ui_icon('x', 14) ?>Снять отметку
                </button>
            <?php endif; ?>
            <button class="btn danger solid" type="submit" formaction="/ui/anomalies/delete"
                    data-confirm="Будут удалены аномалии выбранных групп ({n}) и все графики их источников. Это необратимо."
                    data-confirm-title="Удалить аномалии?" data-confirm-ok="Удалить" data-danger>
                <?= ui_icon('trash', 14) ?>Удалить
            </button>
            <button class="btn icon" type="button" data-pick-none title="Снять выделение" aria-label="Снять выделение"><?= ui_icon('x') ?></button>
        </div>
    </form>

    <?php // Outside the actions form: the pager holds a form of its own. ?>
    <?= view('web/partials/pager', ['list' => $list, 'noun' => 'группа|группы|групп']) ?>
</div>

<?= $this->endSection() ?>
