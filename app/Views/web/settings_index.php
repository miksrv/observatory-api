<?php
/**
 * @var list<array>                $settings  config-type rows (SettingModel::getConfigurable())
 * @var array<string, list<array>> $groups    the same rows by name prefix (QC_, STAR_, …)
 */
$this->extend('web/layout');
$this->setVar('title', 'Настройки');
$this->setVar('active', 'settings');

$isBool = static fn (?string $v): bool => in_array(strtolower((string) $v), ['true', 'false'], true);
?>
<?= $this->section('content') ?>

<div class="page-head">
    <div>
        <h1>Настройки pipeline</h1>
        <p class="sub">Параметры, которые pipeline читает из базы. После сохранения каждый активный pipeline
            получает задачу RESTART и перезапускается с новыми значениями. Внутренние параметры скрыты.</p>
    </div>
    <?php // Saving is the bar that appears on the first change (app.js); without a script, this. ?>
    <noscript>
        <div class="actions">
            <button class="btn primary" type="submit" form="settings-form"><?= ui_icon('save') ?>Сохранить</button>
        </div>
    </noscript>
</div>

<?php if ($settings === []): ?>
    <div class="panel"><div class="empty"><?= ui_icon('settings') ?><strong>Настраиваемых параметров нет</strong><span>Они появляются после <code>php spark migrate</code>.</span></div></div>
<?php else: ?>
    <nav class="settings-nav" aria-label="Группы">
        <?php foreach ($groups as $prefix => $rows): ?>
            <a href="#group-<?= esc(strtolower($prefix), 'attr') ?>"><?= esc($prefix) ?> <span class="dim"><?= count($rows) ?></span></a>
        <?php endforeach; ?>
    </nav>

    <form method="post" action="/ui/settings" id="settings-form" data-settings-form>
        <?= csrf_field() ?>
        <?php foreach ($groups as $prefix => $rows): ?>
            <section class="panel section" id="group-<?= esc(strtolower($prefix), 'attr') ?>">
                <div class="panel-head">
                    <div class="title"><h3 class="mono"><?= esc($prefix) ?></h3></div>
                    <span class="hint"><?= count($rows) ?> <?= ui_plural(count($rows), 'параметр', 'параметра', 'параметров') ?></span>
                </div>
                <?php foreach ($rows as $setting): ?>
                    <?php
                    $val   = (string) ($setting['value'] ?? '');
                    $name  = 'setting_' . $setting['id'];
                    $field = 'f-' . $setting['id'];
                    ?>
                    <div class="setting">
                        <label for="<?= $field ?>">
                            <div class="param"><?= esc($setting['param']) ?></div>
                            <?php if (! empty($setting['description'])): ?><div class="desc"><?= esc($setting['description']) ?></div><?php endif; ?>
                        </label>
                        <div>
                            <?php if ($isBool($val)): ?>
                                <label class="switch">
                                    <!-- Unchecked submits the hidden "false"; checked, the checkbox's "true" comes later and wins. -->
                                    <input type="hidden" name="<?= esc($name, 'attr') ?>" value="false">
                                    <input type="checkbox" id="<?= $field ?>" name="<?= esc($name, 'attr') ?>" value="true"
                                           data-original="<?= esc(strtolower($val), 'attr') ?>" data-on="true" data-off="false"
                                           <?= strtolower($val) === 'true' ? 'checked' : '' ?>>
                                    <span class="track"></span>
                                    <span class="state"><?= esc(strtolower($val)) ?></span>
                                </label>
                            <?php else: ?>
                                <input class="input mono" type="text" id="<?= $field ?>" name="<?= esc($name, 'attr') ?>"
                                       value="<?= esc($val, 'attr') ?>" data-original="<?= esc($val, 'attr') ?>" spellcheck="false">
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <div class="selbar" data-empty data-dirty-bar>
            <span class="count"><b data-dirty-count>0</b>изменено</span>
            <span class="hint">Сохранение перезапустит pipeline.</span>
            <button class="btn" type="reset">Отменить</button>
            <button class="btn primary" type="submit"><?= ui_icon('save', 14) ?>Сохранить и перезапустить</button>
        </div>
    </form>
<?php endif; ?>

<?= $this->endSection() ?>
