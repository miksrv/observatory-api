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
 * @var int                   $limit
 * @var list<string>          $taskTypes
 */
$this->extend('web/layout');
$this->setVar('title', 'Кадры');
$this->setVar('active', 'frames');
$this->setVar('fill', true);

$filtered = $filterObject !== '' || $filterPipeline !== '' || $filterDateFrom !== '' || $filterDateTo !== '';
?>
<?= $this->section('content') ?>

<div class="page-head">
    <div>
        <h1>Кадры</h1>
        <p class="sub">Зарегистрированные FITS-кадры. Отметьте нужные, чтобы поставить задачу — она уйдёт тому pipeline, который хранит файл кадра.</p>
    </div>
</div>

<div class="panel" data-select-scope>
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
        <label class="field wide">
            <span class="label">С (UTC)</span>
            <input class="input" type="datetime-local" name="date_from" value="<?= esc($filterDateFrom, 'attr') ?>">
        </label>
        <label class="field wide">
            <span class="label">До (UTC)</span>
            <input class="input" type="datetime-local" name="date_to" value="<?= esc($filterDateTo, 'attr') ?>">
        </label>
        <label class="field narrow">
            <span class="label">Лимит</span>
            <input class="input mono" type="number" name="limit" value="<?= (int) $limit ?>" min="1" max="2000">
        </label>
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
                    <th>Файл</th>
                    <th>Объект</th>
                    <th>Наблюдение (UTC)</th>
                    <th>Фильтр</th>
                    <th class="num">Выдержка</th>
                    <th class="num">FOV°</th>
                    <th>QC</th>
                    <th class="num" title="Звёзд найдено QC / распознано и сохранено">Звёзды</th>
                    <th class="num">FWHM</th>
                    <th class="num">SNR</th>
                    <th class="num">Фон</th>
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
        <div class="panel-foot">
            <span>Показано <?= count($frames) ?><?= count($frames) >= $limit ? ' — достигнут лимит ' . $limit : '' ?></span>
            <span>Shift+клик выделяет диапазон</span>
        </div>

        <input type="hidden" name="scope_object" value="<?= esc($filterObject, 'attr') ?>">

        <div class="selbar" data-empty>
            <span class="count"><b data-sel-count>0</b>выбрано</span>
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
</div>

<?= $this->endSection() ?>
