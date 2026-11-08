<?php
/**
 * @var list<array>           $frames
 * @var list<string>          $objects
 * @var array<string, string> $pipelines id => name
 * @var string                $pipelineNone
 * @var array<string, string> $filters  the applied list filter, FramesController::FILTERS => value
 * @var bool                  $filtersOpen the side panel starts open (?filters=1)
 * @var string                $filterSearch   part of the file name
 * @var string                $filterPipeline
 * @var string                $filterObject
 * @var string                $filterDateFrom
 * @var string                $filterDateTo
 * @var string                $filterQc
 * @var string                $filterSourceId frames carrying this source's anomalies
 * @var list<string>          $qcFlags  distinct non-OK quality flags present
 * @var string                $qcNotOk  `qc` value for «Не OK»
 * @var string                $processingProblems `processing` value for «С проблемами»
 * @var string                $processingUnknown  `processing` value for «Нет данных»
 * @var \App\Libraries\UiListQuery $list
 * @var list<string>          $taskTypes
 */
$this->extend('web/layout');
$this->setVar('title', 'Кадры');
$this->setVar('active', 'frames');
$this->setVar('fill', true);
$this->setVar('bleed', true);

$processingLabels = [
    $processingProblems => 'С проблемами',
    'OK'                => 'OK',
    'DEGRADED'          => 'Частично',
    'FAILED'            => 'Сбой',
    'SKIPPED'           => 'Пропущен (QC)',
    $processingUnknown  => 'Нет данных',
];

// Every filter but «Объект» lives in the side panel; an applied one shows as a badge after «Объект».
$badgeText = static function (string $name, string $value) use ($processingLabels, $qcNotOk, $pipelineNone): string {
    return match ($name) {
        'search'     => 'Файл: ' . $value,
        'processing' => 'Обработка: ' . ($processingLabels[$value] ?? $value),
        'qc'         => 'QC: ' . ($value === $qcNotOk ? 'Не OK' : $value),
        'pipeline'   => 'Pipeline: ' . ($value === $pipelineNone ? '(не указан)' : $value),
        'date_from'  => 'С ' . str_replace('T', ' ', $value),
        'date_to'    => 'До ' . str_replace('T', ' ', $value),
        'source_id'  => 'source_id: ' . $value,
        default      => $name . ': ' . $value,
    };
};
$panelFilters = array_filter(
    array_diff_key($filters, ['object' => true]),
    static fn (string $value): bool => $value !== ''
);
$filtered = $panelFilters !== [] || $filterObject !== '';

// URLs over the current query: the list's own params (sort, per_page, …) stay; `page` restarts.
$frameUrl = static fn (array $params): string => '/ui/frames' . ($params === [] ? '' : '?' . http_build_query($params));
$openUrl  = $frameUrl($list->keptParams('filters') + ['filters' => '1']);
$closeUrl = $frameUrl($list->keptParams('filters'));

// The filter as the task form re-sends it in «all frames matching the filter» mode.
$matchFilter = $filters;
?>
<?= $this->section('content') ?>

<div class="panel" data-select-scope data-match-total="<?= (int) $list->total ?>" data-filter-scope>
    <div class="filters quickbar">
        <form class="quick" method="get" action="/ui/frames">
            <label class="field">
                <span class="label">Объект</span>
                <select class="input" name="object" data-autosubmit>
                    <option value="">Все объекты</option>
                    <?php foreach ($objects as $obj): ?>
                        <option value="<?= esc($obj, 'attr') ?>" <?= $obj === $filterObject ? 'selected' : '' ?>><?= esc($obj) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php foreach ($list->keptParams('object', 'page', 'filters') as $name => $value): ?>
                <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
            <?php endforeach; ?>
            <noscript><button class="btn" type="submit">OK</button></noscript>
        </form>

        <?php if ($panelFilters !== []): ?>
            <ul class="applied" aria-label="Применённые фильтры">
                <?php foreach ($panelFilters as $name => $value): ?>
                    <li class="filter-badge">
                        <a class="open" href="<?= esc($openUrl, 'attr') ?>" data-filters-open data-focus="<?= esc($name, 'attr') ?>" title="Изменить фильтр"><?= esc($badgeText($name, $value)) ?></a>
                        <a class="remove" href="<?= esc($frameUrl($list->keptParams($name, 'page', 'filters')), 'attr') ?>" title="Убрать фильтр" aria-label="Убрать фильтр «<?= esc($badgeText($name, $value), 'attr') ?>»"><?= ui_icon('x', 12) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="actions">
            <?php if ($filtered): ?><a class="btn ghost" href="/ui/frames"><?= ui_icon('x', 14) ?>Сбросить</a><?php endif; ?>
            <a class="btn<?= $panelFilters !== [] ? ' active' : '' ?>" href="<?= esc($filtersOpen ? $closeUrl : $openUrl, 'attr') ?>" data-filters-open aria-controls="frames-filters" aria-expanded="<?= $filtersOpen ? 'true' : 'false' ?>">
                <?= ui_icon('settings', 14) ?>Фильтры<?php if ($panelFilters !== []): ?><span class="count"><?= count($panelFilters) ?></span><?php endif; ?>
            </a>
        </div>
    </div>

    <div class="filter-host">
        <aside class="filter-drawer" id="frames-filters" aria-label="Фильтры" <?= $filtersOpen ? 'data-open' : '' ?>>
            <form class="drawer-form" method="get" action="/ui/frames">
                <div class="drawer-head">
                    <strong>Фильтры</strong>
                    <a class="btn icon sm" href="<?= esc($closeUrl, 'attr') ?>" data-filters-close title="Закрыть" aria-label="Закрыть фильтры"><?= ui_icon('x', 14) ?></a>
                </div>
                <div class="drawer-body">
                    <label class="field">
                        <span class="label">Имя файла содержит</span>
                        <span class="search"><?= ui_icon('search', 14) ?><input class="input" type="search" name="search" value="<?= esc($filterSearch, 'attr') ?>" placeholder="M51_, 2026-10-01 …" data-filter="search"></span>
                    </label>
                    <label class="field">
                        <span class="label">Обработка</span>
                        <select class="input" name="processing" data-filter="processing">
                            <option value="">Все</option>
                            <?php foreach ($processingLabels as $value => $label): ?>
                                <option value="<?= esc($value, 'attr') ?>" <?= $value === $filters['processing'] ? 'selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="help">«С проблемами» — кадры, проанализированные не полностью (каталог не ответил, нет нуль-пункта, астрометрия не удалась): их стоит перезапустить задачей «Анализ кадра».</span>
                    </label>
                    <label class="field">
                        <span class="label">QC</span>
                        <select class="input" name="qc" data-filter="qc">
                            <option value="">Все</option>
                            <option value="OK" <?= $filterQc === 'OK' ? 'selected' : '' ?>>OK</option>
                            <option value="<?= esc($qcNotOk, 'attr') ?>" <?= $filterQc === $qcNotOk ? 'selected' : '' ?>>Не OK</option>
                            <?php foreach ($qcFlags as $flag): ?>
                                <option value="<?= esc($flag, 'attr') ?>" <?= $flag === $filterQc ? 'selected' : '' ?>><?= esc($flag) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span class="label">Pipeline</span>
                        <select class="input" name="pipeline" data-filter="pipeline">
                            <option value="">Все</option>
                            <?php foreach ($pipelines as $name): ?>
                                <option value="<?= esc($name, 'attr') ?>" <?= $name === $filterPipeline ? 'selected' : '' ?>><?= esc($name) ?></option>
                            <?php endforeach; ?>
                            <option value="<?= esc($pipelineNone, 'attr') ?>" <?= $filterPipeline === $pipelineNone ? 'selected' : '' ?>>(не указан)</option>
                        </select>
                    </label>
                    <div class="field-pair">
                        <label class="field">
                            <span class="label">С (UTC)</span>
                            <input class="input" type="datetime-local" name="date_from" value="<?= esc($filterDateFrom, 'attr') ?>" data-filter="date_from">
                        </label>
                        <label class="field">
                            <span class="label">До (UTC)</span>
                            <input class="input" type="datetime-local" name="date_to" value="<?= esc($filterDateTo, 'attr') ?>" data-filter="date_to">
                        </label>
                    </div>
                    <label class="field">
                        <span class="label" title="Только кадры, на которых у этого источника найдены аномалии">source_id (аномалии)</span>
                        <input class="input mono" type="text" name="source_id" value="<?= esc($filterSourceId, 'attr') ?>" data-filter="source_id">
                    </label>
                    <?php // «Объект» is the bar's own filter; sort and page size carry over, the page restarts. ?>
                    <?php foreach ($list->keptParams('search', 'processing', 'qc', 'pipeline', 'date_from', 'date_to', 'source_id', 'page', 'filters') as $name => $value): ?>
                        <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
                    <?php endforeach; ?>
                </div>
                <div class="drawer-foot">
                    <?php // JS only empties the fields (applied with «Применить»); without it, the link drops them at once. ?>
                    <a class="btn ghost" href="<?= esc($frameUrl($list->keptParams('search', 'processing', 'qc', 'pipeline', 'date_from', 'date_to', 'source_id', 'page', 'filters')), 'attr') ?>" data-filters-clear>Очистить</a>
                    <button class="btn primary" type="submit"><?= ui_icon('check', 14) ?>Применить</button>
                </div>
            </form>
        </aside>
        <a class="filter-scrim" href="<?= esc($closeUrl, 'attr') ?>" data-filters-close tabindex="-1" aria-hidden="true"></a>

    <form method="post" action="/ui/tasks" id="frames-form">
        <?= csrf_field() ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th class="check-col"><input class="check" type="checkbox" data-pick-all aria-label="Выбрать все"></th>
                    <?= ui_th($list, 'filename', 'Файл') ?>
                    <?= ui_th($list, 'object', 'Объект') ?>
                    <?= ui_th($list, 'obs_time', 'Наблюдение (UTC)') ?>
                    <?= ui_th($list, 'filter', 'Фильтр') ?>
                    <?= ui_th($list, 'exptime', 'Выдержка', 'num') ?>
                    <?= ui_th($list, 'fov', 'FOV°', 'num') ?>
                    <?= ui_th($list, 'qc', 'QC') ?>
                    <?= ui_th($list, 'processing', 'Обработка', '', 'Что дал анализ после QC: OK, частично (каталог не ответил, нет нуль-пункта …), сбой (astap не решил), пропущен (QC отклонил)') ?>
                    <?= ui_th($list, 'stars', 'Звёзды', 'num', 'Звёзд найдено QC / распознано и сохранено; сортировка — по найденным') ?>
                    <?= ui_th($list, 'fwhm', 'FWHM', 'num') ?>
                    <?= ui_th($list, 'snr', 'SNR', 'num') ?>
                    <?= ui_th($list, 'background', 'Фон', 'num') ?>
                    <th>Pipeline</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($frames)): ?>
                    <tr><td colspan="14" class="empty-cell"><div class="empty"><?= ui_icon('frames') ?><strong>Кадров не найдено</strong><span>Измените или сбросьте фильтр.</span></div></td></tr>
                <?php endif; ?>
                <?php foreach ($frames as $frame): ?>
                    <tr>
                        <td class="check-col"><input class="check js-pick" type="checkbox" name="frame_ids[]" value="<?= esc($frame['id'], 'attr') ?>" aria-label="Выбрать кадр"></td>
                        <td>
                            <span class="primary file mono small" title="<?= esc($frame['filename'], 'attr') ?>"><?= esc($frame['filename']) ?></span>
                            <span class="sub id"><?= esc($frame['id']) ?></span>
                        </td>
                        <td class="nowrap"><?= esc($frame['object'] ?? '—') ?></td>
                        <td class="mono small nowrap"><?= ui_time($frame['obs_time'], 'Y-m-d H:i:s') ?></td>
                        <td><?= $frame['filter'] !== null ? ui_chip($frame['filter'], 'outline') : '<span class="dim">—</span>' ?></td>
                        <td class="num"><?= $frame['exptime'] !== null ? ui_num($frame['exptime'], 0) . ' с' : '—' ?></td>
                        <td class="num"><?= ui_num($frame['fov_deg'], 2) ?></td>
                        <td><?= ui_qc($frame['quality_flag'] ?? null) ?></td>
                        <td class="processing"><?= ui_processing($frame['processing_status'] ?? null, $frame['processing_issues'] ?? null) ?></td>
                        <td class="num"><?= $frame['qc_star_count'] !== null ? ui_num($frame['qc_star_count']) : '—' ?> <span class="dim">/ <?= ui_num($frame['recognized_star_count']) ?></span></td>
                        <td class="num"><?= ui_num($frame['qc_fwhm_median'], 2) ?></td>
                        <td class="num"><?= ui_num($frame['qc_snr_median'], 1) ?></td>
                        <td class="num"><?= ui_num($frame['qc_sky_background'], 1) ?></td>
                        <td class="mono small nowrap"><?= $frame['pipeline_id'] !== null ? esc($pipelines[$frame['pipeline_id']] ?? $frame['pipeline_id']) : '<span class="dim">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <input type="hidden" name="scope_object" value="<?= esc($filterObject, 'attr') ?>">
        <?php // Enabled by app.js only while «all frames matching the filter» is chosen. ?>
        <fieldset class="match-fields" data-match-fields disabled hidden>
            <input type="hidden" name="all_matching" value="1">
            <?php foreach ($matchFilter as $name => $value): ?>
                <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
            <?php endforeach; ?>
        </fieldset>

        <div class="selbar" data-empty>
            <span class="count"><b data-sel-count>0</b>выбрано</span>
            <button class="btn" type="button" data-pick-matching hidden>Выбрать все <?= ui_num($list->total) ?> <?= ui_plural($list->total, 'кадр', 'кадра', 'кадров') ?> по фильтру</button>
            <button class="btn" type="button" data-pick-page hidden>Только эта страница</button>
            <span class="sep"></span>
            <select class="input" name="type" data-type-hint="#type-hint" aria-label="Тип задачи">
                <?php foreach ($taskTypes as $type): ?>
                    <option value="<?= esc($type, 'attr') ?>" data-hint="<?= esc(ui_task_type_hint($type), 'attr') ?>"
                        <?= $type === 'DELETE_FRAME' ? 'data-confirm="Файлы {n} кадр(ов) уйдут в FITS_REJECTED, а сами кадры со всеми наблюдениями и аномалиями будут удалены из базы." data-confirm-title="Удалить кадры?" data-confirm-ok="Удалить" data-danger' : '' ?>><?= esc(ui_task_type($type)) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="hint" id="type-hint"></span>
            <button class="btn primary" type="submit" data-min-select="1"><?= ui_icon('play', 14) ?>Создать задачу</button>
            <button class="btn icon" type="button" data-pick-none title="Снять выделение" aria-label="Снять выделение"><?= ui_icon('x') ?></button>
        </div>
    </form>

    </div>

    <?php // Outside the task form: the pager holds a form of its own. ?>
    <?= view('web/partials/pager', ['list' => $list, 'noun' => 'кадр|кадра|кадров']) ?>
</div>

<?= $this->endSection() ?>
