<?= view('web/partials/header', ['title' => 'Кадры (frames)']) ?>

<h1 class="h4 mb-3">Кадры (frames)</h1>

<form method="get" action="/ui/frames" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label mb-0">Объект</label>
        <select name="object" class="form-select">
            <option value="">— все —</option>
            <?php foreach ($objects as $obj): ?>
                <option value="<?= esc($obj) ?>" <?= $obj === $filterObject ? 'selected' : '' ?>><?= esc($obj) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">С даты (obs_time ≥)</label>
        <input type="datetime-local" name="date_from" class="form-control" value="<?= esc($filterDateFrom) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">По дату (obs_time &lt;)</label>
        <input type="datetime-local" name="date_to" class="form-control" value="<?= esc($filterDateTo) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">Лимит</label>
        <input type="number" name="limit" class="form-control" style="width: 100px" value="<?= (int) $limit ?>" min="1" max="2000">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary">Фильтровать</button>
    </div>
    <div class="col-auto">
        <a href="/ui/frames" class="btn btn-outline-secondary">Сбросить</a>
    </div>
</form>

<form method="post" action="/ui/tasks">
    <div class="table-responsive border rounded mb-3 bg-white">
        <table class="table table-sm table-hover table-striped mb-0">
            <thead class="table-light sticky-top">
                <tr>
                    <th><input type="checkbox" id="checkAll"></th>
                    <th>Filename</th>
                    <th>Object</th>
                    <th>Obs time (UTC)</th>
                    <th>Filter</th>
                    <th>Exptime</th>
                    <th>FOV°</th>
                    <th>QC</th>
                    <th>Stars</th>
                    <th>FWHM</th>
                    <th>SNR</th>
                    <th>Background</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($frames)): ?>
                <tr><td colspan="12" class="text-center text-muted py-4">Ничего не найдено по заданному фильтру.</td></tr>
            <?php endif; ?>
            <?php foreach ($frames as $frame): ?>
                <?php
                $qcBadge = match ($frame['quality_flag'] ?? null) {
                    'OK'    => 'bg-success',
                    'BAD'   => 'bg-danger',
                    'BLUR', 'TRAIL', 'HIGH_BACKGROUND', 'LOW_STARS' => 'bg-warning text-dark',
                    default => 'bg-secondary',
                };
                ?>
                <tr>
                    <td><input type="checkbox" class="frame-checkbox" name="frame_ids[]" value="<?= esc($frame['id']) ?>"></td>
                    <td class="text-break small"><?= esc($frame['filename']) ?></td>
                    <td><?= esc($frame['object'] ?? '—') ?></td>
                    <td class="small"><?= esc(gmdate('Y-m-d H:i:s', strtotime($frame['obs_time']))) ?></td>
                    <td><?= esc($frame['filter'] ?? '—') ?></td>
                    <td><?= $frame['exptime'] !== null ? esc((string) $frame['exptime']) : '—' ?></td>
                    <td><?= esc((string) $frame['fov_deg']) ?></td>
                    <td><span class="badge <?= $qcBadge ?>"><?= esc($frame['quality_flag'] ?? '—') ?></span></td>
                    <td><?= $frame['qc_star_count'] !== null ? esc((string) $frame['qc_star_count']) : '—' ?> / <?= esc((string) $frame['recognized_star_count']) ?></td>
                    <td><?= $frame['qc_fwhm_median'] !== null ? esc((string) round((float) $frame['qc_fwhm_median'], 2)) : '—' ?></td>
                    <td><?= $frame['qc_snr_median'] !== null ? esc((string) round((float) $frame['qc_snr_median'], 1)) : '—' ?></td>
                    <td><?= $frame['qc_sky_background'] !== null ? esc((string) round((float) $frame['qc_sky_background'], 1)) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card shadow-sm">
        <div class="card-body d-flex flex-wrap gap-3 align-items-end">
            <div>
                <label class="form-label mb-0">Тип задачи</label>
                <select name="type" class="form-select">
                    <?php foreach ($taskTypes as $type): ?>
                        <option value="<?= esc($type) ?>"><?= esc($type) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">
                    DETECT_ANOMALIES — по frame_id уже зарегистрированных кадров.<br>
                    ANALYZE — по filename (повторный разбор пайплайном с самого начала).<br>
                    PREVIEW_CATALOG_MATCH — по filename, диагностический предпросмотр сопоставления
                    с каталогом без регистрации кадра (обычно для ещё не разобранных файлов; здесь —
                    просто повторный прогон по filename уже известного кадра).
                </div>
            </div>
            <div>
                <label class="form-label mb-0">scope_object (опционально, только для отображения)</label>
                <input type="text" name="scope_object" class="form-control" value="<?= esc($filterObject) ?>">
            </div>
            <div>
                <button type="submit" class="btn btn-success">
                    Создать задачу из выделенных (<span id="selCount">0</span>)
                </button>
            </div>
        </div>
    </div>
</form>

<script>
    (function () {
        const checkAll = document.getElementById('checkAll');
        const boxes = Array.from(document.querySelectorAll('.frame-checkbox'));
        const counter = document.getElementById('selCount');

        function updateCount() {
            counter.textContent = boxes.filter((b) => b.checked).length;
        }

        checkAll?.addEventListener('change', () => {
            boxes.forEach((b) => { b.checked = checkAll.checked; });
            updateCount();
        });
        boxes.forEach((b) => b.addEventListener('change', updateCount));
    })();
</script>

<?= view('web/partials/footer') ?>
