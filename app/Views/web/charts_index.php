<?php
/**
 * @var list<array>  $charts  source_charts rows joined with their source / task item
 * @var list<string> $styles
 * @var string       $filterSourceId
 * @var string       $filterStyle
 * @var string       $filterReview  '' (all), 'new' or 'done'
 * @var \App\Libraries\UiListQuery $list
 */
$this->extend('web/layout');
$this->setVar('title', 'Графики');
$this->setVar('active', 'charts');

$filtered = $filterSourceId !== '' || $filterStyle !== '';
$reviewTab = static fn (?string $value): string => ui_query(['review' => $value, 'page' => null]);
$styleTone = static fn (string $style): string => match ($style) {
    'track', 'track_gif'             => 'blue',
    'stamp_strip', 'stamp_strip_gif' => 'violet',
    'before_after'                   => 'cyan',
    default                          => 'amber',
};
?>
<?= $this->section('content') ?>

<div class="page-head">
    <div>
        <h1>Графики</h1>
        <p class="sub">Карты-трекеры, стрипы и диагностические превью. Посмотрели и ничего интересного — отметьте
            карточки и нажмите «Проверено» с заметкой: аномалии источников уйдут из непроверенных, затем графики можно удалить.
            Две и больше карточки одного объекта (например, кометы, которую SkyBot распознал как разные источники) можно объединить.</p>
    </div>
</div>

<!-- The merge checkboxes sit inside the cards, whose own delete forms can't nest a second form:
     they join this one through their form="" attribute instead. -->
<form method="post" id="merge-form" action="/ui/sources/merge" hidden>
    <?= csrf_field() ?>
</form>

<div class="panel" data-select-scope>
    <nav class="tabs" aria-label="Проверка">
        <a href="<?= esc($reviewTab(null), 'attr') ?>" class="<?= $filterReview === '' ? 'active' : '' ?>">Все</a>
        <a href="<?= esc($reviewTab('new'), 'attr') ?>" class="<?= $filterReview === 'new' ? 'active' : '' ?>">Не проверенные</a>
        <a href="<?= esc($reviewTab('done'), 'attr') ?>" class="<?= $filterReview === 'done' ? 'active' : '' ?>">Проверенные</a>
    </nav>
    <form class="filters" method="get" action="/ui/charts">
        <label class="field wide">
            <span class="label">source_id</span>
            <input class="input mono" type="text" name="source_id" value="<?= esc($filterSourceId, 'attr') ?>" placeholder="точный id">
        </label>
        <label class="field">
            <span class="label">Стиль</span>
            <select class="input" name="style" data-autosubmit>
                <option value="">Все стили</option>
                <?php foreach ($styles as $s): ?>
                    <option value="<?= esc($s, 'attr') ?>" <?= $s === $filterStyle ? 'selected' : '' ?>><?= esc($s) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="field auto">
            <span class="label">Сортировка</span>
            <span class="gallery-sort">
                <?php foreach (['updated' => 'Обновлён', 'epochs' => 'Эпохи', 'designation' => 'Обозначение', 'style' => 'Стиль'] as $key => $label): ?>
                    <a class="btn sm<?= $list->isSorted($key) ? ' active' : '' ?>" href="<?= esc($list->sortUrl($key), 'attr') ?>"
                       <?= $list->isSorted($key) ? 'aria-current="true"' : '' ?>>
                        <?= esc($label) ?><?= $list->isSorted($key) ? ui_icon($list->dir === 'asc' ? 'sort-asc' : 'sort-desc', 12) : '' ?>
                    </a>
                <?php endforeach; ?>
            </span>
        </div>
        <?php foreach ($list->keptParams('source_id', 'style', 'page') as $name => $value): ?>
            <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
        <?php endforeach; ?>
        <div class="actions">
            <?php if ($filtered): ?><a class="btn ghost" href="/ui/charts"><?= ui_icon('x', 14) ?>Сбросить</a><?php endif; ?>
            <button class="btn" type="submit"><?= ui_icon('search', 14) ?>Показать</button>
        </div>
    </form>

    <?php if (empty($charts) && $list->total === 0): ?>
        <div class="empty"><?= ui_icon('charts') ?><strong>Графиков не найдено</strong><span>Графики строит задача GENERATE_CHARTS — её можно поставить со страниц источников и аномалий.</span></div>
    <?php else: ?>
        <div class="gallery">
            <?php foreach ($charts as $chart): ?>
                <?php
                $chartId  = $chart['source_id'] ?? $chart['task_item_id'];
                // A source_id holds one chart per style (see SourceChartModel's class docblock), so
                // the image URL pins this card's style; ?v= busts the browser cache on a re-render.
                $imageUrl = '/ui/charts/' . rawurlencode($chartId) . '/image?'
                    . ($chart['source_id'] ? 'style=' . rawurlencode($chart['style']) . '&' : '')
                    . 'v=' . (ui_ts($chart['updated_at']) ?? 0);
                $title = $chart['source_id']
                    ? ($chart['catalog_id'] ?: 'Без обозначения')
                    : ($chart['item_filename'] ?? 'Предпросмотр каталога');
                ?>
                <div class="panel shot">
                    <?php if ($chart['source_id']): ?>
                        <label class="shot-pick" title="Выбрать">
                            <input class="check js-pick" type="checkbox" form="merge-form" name="source_ids[]" value="<?= esc($chart['source_id'], 'attr') ?>" aria-label="Выбрать">
                        </label>
                    <?php endif; ?>
                    <span class="shot-style"><?= ui_chip($chart['style'], $styleTone($chart['style'])) ?></span>
                    <?php if ($chart['source_id'] && (int) $chart['anomaly_count'] > 0): ?>
                        <span class="shot-review"><?= (int) $chart['open_count'] === 0
                            ? ui_chip('проверено', 'green', 'Все аномалии источника отмечены проверенными')
                            : ui_chip((int) $chart['open_count'] . ' не проверено', 'amber', 'Аномалий источника без отметки «проверено»') ?></span>
                    <?php endif; ?>
                    <a class="shot-img" href="<?= esc($imageUrl, 'attr') ?>" data-lightbox data-caption="<?= esc($title . ' · ' . $chart['style'], 'attr') ?>">
                        <img src="<?= esc($imageUrl, 'attr') ?>" loading="lazy" alt="<?= esc($chart['style'], 'attr') ?>">
                    </a>
                    <div class="shot-body">
                        <div class="shot-title" title="<?= esc($title, 'attr') ?>"><?= esc($title) ?></div>
                        <?php if ($chart['source_id']): ?>
                            <div class="shot-meta">
                                <span><?= esc($chart['catalog_name'] ?? 'без каталога') ?><?= $chart['object_type'] ? ' · ' . esc($chart['object_type']) : '' ?></span>
                                <span><?= (int) $chart['frame_count'] ?> <?= ui_plural((int) $chart['frame_count'], 'эпоха', 'эпохи', 'эпох') ?></span>
                            </div>
                            <div class="shot-meta">
                                <span class="id mono"><?= esc($chart['source_id']) ?></span>
                            </div>
                            <?php if (! empty($chart['src_ra']) && ! empty($chart['src_dec'])): ?>
                                <div class="shot-meta">
                                    <a class="coords" href="<?= esc(ui_aladin($chart['src_ra'], $chart['src_dec'], 0.1), 'attr') ?>" target="_blank" rel="noopener" title="Открыть в Aladin Lite">
                                        <?= ui_icon('crosshair', 13) ?> <?= ui_num($chart['src_ra'], 5) ?>°, <?= ui_num($chart['src_dec'], 5) ?>°
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="shot-meta"><span>PREVIEW_CATALOG_MATCH</span></div>
                            <div class="shot-meta"><span class="id mono"><?= esc($chart['task_item_id']) ?></span></div>
                        <?php endif; ?>
                        <div class="shot-meta"><span><?= ui_icon('clock', 13) ?> <?= ui_when($chart['updated_at']) ?></span></div>
                    </div>
                    <div class="shot-foot">
                        <?php if ($chart['source_id']): ?>
                            <a class="btn sm grow" href="/ui/anomalies?source_id=<?= esc($chart['source_id'], 'url') ?>"><?= ui_icon('anomalies', 14) ?>Аномалии</a>
                        <?php elseif ($chart['task_id']): ?>
                            <a class="btn sm grow" href="/ui/tasks/<?= esc($chart['task_id'], 'url') ?>"><?= ui_icon('tasks', 14) ?>К задаче</a>
                        <?php else: ?>
                            <span class="grow"></span>
                        <?php endif; ?>
                        <form class="inline" method="post" action="/ui/charts/<?= esc($chart['id'], 'url') ?>/delete"
                              data-confirm="Запись и файл изображения будут удалены. Перестроить график можно задачей GENERATE_CHARTS." data-confirm-title="Удалить график?" data-confirm-ok="Удалить" data-danger>
                            <?= csrf_field() ?>
                            <button type="submit" class="btn icon sm danger" title="Удалить график" aria-label="Удалить график"><?= ui_icon('trash', 15) ?></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="selbar" data-empty>
            <span class="count"><b data-sel-count>0</b>выбрано</span>
            <button class="btn" type="button" data-pick-every>Выбрать все</button>
            <span class="sep"></span>
            <input class="input" type="text" name="note" form="merge-form" maxlength="1000" placeholder="Заметка: что это на кадре" aria-label="Заметка к проверке">
            <button class="btn primary" type="submit" form="merge-form" formaction="/ui/charts/review" data-min-select="1"
                    title="Посмотрел, ничего интересного: все текущие аномалии источников уйдут из непроверенных и из счётчиков">
                <?= ui_icon('check', 14) ?>Проверено
            </button>
            <button class="btn" type="submit" form="merge-form" formaction="/ui/charts/delete" data-min-select="1"
                    data-confirm="Будут удалены все графики выбранных источников ({n}). Аномалии и отметки «проверено» останутся."
                    data-confirm-title="Удалить графики?" data-confirm-ok="Удалить" data-danger>
                <?= ui_icon('trash', 14) ?>Удалить графики
            </button>
            <span class="sep"></span>
            <button class="btn" type="submit" form="merge-form" data-min-select="2"
                    data-confirm="{n} источник(ов) станут одним новым. Их графики и аномалии будут удалены — потом поставьте DETECT_ANOMALIES для затронутых кадров и GENERATE_CHARTS для нового источника."
                    data-confirm-title="Объединить источники?" data-confirm-ok="Объединить" data-danger>
                <?= ui_icon('merge', 14) ?>Объединить
            </button>
            <button class="btn icon" type="button" data-pick-none title="Снять выделение" aria-label="Снять выделение"><?= ui_icon('x') ?></button>
        </div>
    <?php endif; ?>

    <?= view('web/partials/pager', ['list' => $list, 'noun' => 'график|графика|графиков']) ?>
</div>

<?= $this->endSection() ?>
