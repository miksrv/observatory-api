<?php
/**
 * @var list<array>           $frames
 * @var list<string>          $objects
 * @var array<string, string> $pipelines id => name
 * @var string                $pipelineNone
 * @var string                $filterPipeline
 * @var string                $filterObject
 * @var string                $filterDateFrom
 * @var string                $filterDateTo
 * @var string                $filterQc
 * @var list<string>          $qcFlags  distinct non-OK quality flags present
 * @var string                $qcNotOk  `qc` value for «Не OK»
 * @var \App\Libraries\UiListQuery $list
 * @var list<string>          $taskTypes
 */
$this->extend('web/layout');
$this->setVar('title', 'Кадры');
$this->setVar('active', 'frames');
$this->setVar('fill', true);

$filtered = $filterObject !== '' || $filterPipeline !== '' || $filterDateFrom !== '' || $filterDateTo !== '' || $filterQc !== '';
// The filter as the task form re-sends it in «all frames matching the filter» mode.
$matchFilter = ['object' => $filterObject, 'pipeline' => $filterPipeline, 'date_from' => $filterDateFrom, 'date_to' => $filterDateTo, 'qc' => $filterQc];
?>
<?= $this->section('content') ?>

<div class="page-head">
    <div>
        <h1>Кадры</h1>
        <p class="sub">Зарегистрированные FITS-кадры. Отметьте нужные, чтобы поставить задачу — она уйдёт тому pipeline, который хранит файл кадра.</p>
    </div>
</div>

<div class="panel" data-select-scope data-match-total="<?= (int) $list->total ?>">
    <form class="filters" method="get" action="/ui/frames">
        <label class="field">
            <span class="label">Объект</span>
            <select class="input" name="object" data-autosubmit>
                <option value="">Все объекты</option>
                <?php foreach ($objects as $obj): ?>
                    <option value="<?= esc($obj, 'attr') ?>" <?= $obj === $filterObject ? 'selected' : '' ?>><?= esc($obj) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="label">Pipeline</span>
            <select class="input" name="pipeline" data-autosubmit>
                <option value="">Все</option>
                <?php foreach ($pipelines as $name): ?>
                    <option value="<?= esc($name, 'attr') ?>" <?= $name === $filterPipeline ? 'selected' : '' ?>><?= esc($name) ?></option>
                <?php endforeach; ?>
                <option value="<?= esc($pipelineNone, 'attr') ?>" <?= $filterPipeline === $pipelineNone ? 'selected' : '' ?>>(не указан)</option>
            </select>
        </label>
        <label class="field">
            <span class="label">QC</span>
            <select class="input" name="qc" data-autosubmit>
                <option value="">Все</option>
                <option value="OK" <?= $filterQc === 'OK' ? 'selected' : '' ?>>OK</option>
                <option value="<?= esc($qcNotOk, 'attr') ?>" <?= $filterQc === $qcNotOk ? 'selected' : '' ?>>Не OK</option>
                <?php foreach ($qcFlags as $flag): ?>
                    <option value="<?= esc($flag, 'attr') ?>" <?= $flag === $filterQc ? 'selected' : '' ?>><?= esc($flag) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field wide">
            <span class="label">С (UTC)</span>
            <input class="input" type="datetime-local" name="date_from" value="<?= esc($filterDateFrom, 'attr') ?>">
        </label>
        <label class="field wide">
            <span class="label">До (UTC)</span>
            <input class="input" type="datetime-local" name="date_to" value="<?= esc($filterDateTo, 'attr') ?>">
        </label>
        <?php foreach ($list->keptParams('object', 'pipeline', 'date_from', 'date_to', 'qc', 'page', 'limit') as $name => $value): ?>
            <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
        <?php endforeach; ?>
        <div class="actions">
            <?php if ($filtered): ?><a class="btn ghost" href="/ui/frames"><?= ui_icon('x', 14) ?>Сбросить</a><?php endif; ?>
            <button class="btn" type="submit"><?= ui_icon('search', 14) ?>Показать</button>
        </div>
    </form>

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
                    <?= ui_th($list, 'stars', 'Звёзды', 'num', 'Звёзд найдено QC / распознано и сохранено; сортировка — по найденным') ?>
                    <?= ui_th($list, 'fwhm', 'FWHM', 'num') ?>
                    <?= ui_th($list, 'snr', 'SNR', 'num') ?>
                    <?= ui_th($list, 'background', 'Фон', 'num') ?>
                    <th>Pipeline</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($frames)): ?>
                    <tr><td colspan="13" class="empty-cell"><div class="empty"><?= ui_icon('frames') ?><strong>Кадров не найдено</strong><span>Измените или сбросьте фильтр.</span></div></td></tr>
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
                        <?= $type === 'DELETE_FRAME' ? 'data-confirm="Файлы {n} кадр(ов) уйдут в FITS_REJECTED, а сами кадры со всеми наблюдениями и аномалиями будут удалены из базы." data-confirm-title="Удалить кадры?" data-confirm-ok="Удалить" data-danger' : '' ?>><?= esc($type) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="hint" id="type-hint"></span>
            <button class="btn primary" type="submit" data-min-select="1"><?= ui_icon('play', 14) ?>Создать задачу</button>
            <button class="btn icon" type="button" data-pick-none title="Снять выделение" aria-label="Снять выделение"><?= ui_icon('x') ?></button>
        </div>
    </form>

    <?php // Outside the task form: the pager holds a form of its own. ?>
    <?= view('web/partials/pager', ['list' => $list, 'noun' => 'кадр|кадра|кадров']) ?>
</div>

<?= $this->endSection() ?>
