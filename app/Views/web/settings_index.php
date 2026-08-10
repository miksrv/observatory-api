<?= view('web/partials/header', ['title' => 'Observatory — Настройки']) ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Настройки pipeline</h1>
    <span class="text-muted small">Только параметры с type&nbsp;=&nbsp;<code>config</code>. Внутренние параметры скрыты.</span>
</div>

<form method="post" action="/ui/settings">

    <div class="card shadow-sm mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 25%">Параметр</th>
                            <th style="width: 30%">Значение</th>
                            <th>Описание</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $currentGroup = '';
                        foreach ($settings as $setting):
                            $parts = explode('_', $setting['param'], 2);
                            $prefix = $parts[0];
                            if ($prefix !== $currentGroup):
                                $currentGroup = $prefix;
                        ?>
                            <tr class="table-secondary">
                                <td colspan="3" class="fw-semibold small text-uppercase py-1 px-3">
                                    <?= esc($currentGroup) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td>
                                <code><?= esc($setting['param']) ?></code>
                            </td>
                            <td>
                                <?php
                                $val = $setting['value'] ?? '';
                                $fieldName = 'setting_' . $setting['id'];
                                // Boolean-like values get a select
                                if (in_array(strtolower($val), ['true', 'false'], true)):
                                ?>
                                    <select name="<?= esc($fieldName) ?>" class="form-select form-select-sm">
                                        <option value="true" <?= $val === 'true' ? 'selected' : '' ?>>true</option>
                                        <option value="false" <?= $val === 'false' ? 'selected' : '' ?>>false</option>
                                    </select>
                                <?php else: ?>
                                    <input type="text"
                                           name="<?= esc($fieldName) ?>"
                                           value="<?= esc($val) ?>"
                                           class="form-control form-control-sm"
                                    >
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= esc($setting['description'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 align-items-center">
        <button type="submit" class="btn btn-primary">
            💾 Сохранить и перезагрузить pipeline
        </button>
        <span class="text-muted small">После сохранения будет создана задача RESTART — pipeline подхватит новые настройки.</span>
    </div>
</form>

<?= view('web/partials/footer') ?>

