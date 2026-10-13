<?php
/**
 * The web UI's frame: the console rail (navigation, pipelines' heartbeats, theme, account), the
 * page, and the dialogs app.js drives. Pages extend it and set, with $this->setVar():
 *
 * @var string   $title        <title> and nothing else — each page renders its own heading
 * @var string   $active       nav key of the current section
 * @var int|null $autorefresh  reload the page every N seconds while it's visible and idle
 * @var bool     $fill         a list page exactly one window high: its last panel's table takes
 *                             the room left under the head and scrolls inside
 */
use App\Libraries\UiAuth;
use App\Libraries\UiShell;

$active      = $active ?? '';
$autorefresh = $autorefresh ?? null;
$fill        = ! empty($fill);
$shell       = UiShell::data();
$user        = (string) (session(UiAuth::SESSION_KEY)['user'] ?? '');

$nav = [
    'Наблюдения' => [
        'overview'  => ['/ui', 'Обзор', 'overview', null],
        'frames'    => ['/ui/frames', 'Кадры', 'frames', null],
        'sources'   => ['/ui/sources', 'Источники', 'sources', null],
        'anomalies' => ['/ui/anomalies', 'Аномалии', 'anomalies', null],
        'charts'    => ['/ui/charts', 'Графики', 'charts', null],
    ],
    'Управление' => [
        'tasks'    => ['/ui/tasks', 'Задачи', 'tasks', $shell['running'] + $shell['pending']],
        'settings' => ['/ui/settings', 'Настройки', 'settings', null],
    ],
];

$flashes = array_filter([
    'success' => session()->getFlashdata('success'),
    'error'   => session()->getFlashdata('error'),
]);
?>
<!doctype html>
<html lang="ru">
<head>
    <?= view('web/partials/head', ['title' => $title ?? 'Observatory']) ?>
</head>
<body<?= $autorefresh ? ' data-autorefresh="' . (int) $autorefresh . '"' : '' ?>>
<div class="shell" data-shell>
    <aside class="rail" aria-label="Навигация">
        <div class="rail-brand">
            <a class="brand" href="/ui">
                <?= view('web/partials/brand_mark') ?>
                <span class="brand-text">
                    <span class="brand-name">Observatory</span>
                    <span class="brand-sub">pipeline console</span>
                </span>
            </a>
            <button type="button" class="btn icon sm rail-toggle" data-rail-toggle title="Свернуть панель" aria-label="Свернуть панель">
                <?= ui_icon('panel') ?>
            </button>
        </div>

        <nav class="rail-nav">
            <?php foreach ($nav as $group => $links): ?>
                <div class="rail-group"><?= esc($group) ?></div>
                <?php foreach ($links as $key => [$href, $label, $icon, $count]): ?>
                    <a class="rail-link<?= $key === $active ? ' active' : '' ?>" href="<?= $href ?>"
                       title="<?= esc($label, 'attr') ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
                        <?= ui_icon($icon, 18) ?>
                        <span class="label"><?= esc($label) ?></span>
                        <?php if ($count): ?>
                            <span class="rail-count<?= $shell['running'] > 0 ? ' live' : '' ?>"
                                  title="Выполняется: <?= $shell['running'] ?>, в очереди: <?= $shell['pending'] ?>"><?= (int) $count ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="rail-foot">
            <div class="rail-pipes">
                <?php foreach ($shell['pipelines'] as $p): ?>
                    <?php [$tone, $label] = ui_pipeline_status($p['status']); ?>
                    <div class="rail-pipe" title="<?= esc($p['name'] . ' — ' . $label . ', ' . ui_ago($p['last_seen_at']), 'attr') ?>">
                        <span class="led <?= $tone ?>"></span>
                        <span class="name mono"><?= esc($p['name']) ?></span>
                        <span class="ago"><?= esc(ui_ago($p['last_seen_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="theme-switch" role="group" aria-label="Тема">
                <button type="button" data-theme-set="day" title="Дневная тема"><?= ui_icon('sun', 14) ?><span class="label">День</span></button>
                <button type="button" data-theme-set="night" title="Ночная тема"><?= ui_icon('moon', 14) ?><span class="label">Ночь</span></button>
                <button type="button" data-theme-set="red" title="Красный режим — сохраняет темновую адаптацию глаз"><?= ui_icon('eye', 14) ?><span class="label">Красный</span></button>
            </div>

            <div class="rail-user">
                <span class="avatar"><?= esc(mb_substr($user, 0, 1)) ?></span>
                <span class="who"><?= esc($user) ?><small>оператор</small></span>
                <form method="post" action="/ui/logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn icon sm" title="Выйти" aria-label="Выйти"><?= ui_icon('logout') ?></button>
                </form>
            </div>

            <?php if ($shell['version'] !== null): ?>
                <div class="rail-version" title="Версия observatory-api">
                    <span class="label">observatory-api</span> <span class="mono">v<?= esc($shell['version']) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </aside>
    <div class="scrim" data-drawer-close></div>

    <div class="main">
        <header class="topbar">
            <button type="button" class="btn icon" data-drawer-open aria-label="Меню"><?= ui_icon('menu', 20) ?></button>
            <a class="brand" href="/ui"><?= view('web/partials/brand_mark') ?><span class="brand-name">Observatory</span></a>
            <span class="leds">
                <?php foreach ($shell['pipelines'] as $p): ?>
                    <span class="led <?= ui_pipeline_status($p['status'])[0] ?>" title="<?= esc($p['name'], 'attr') ?>"></span>
                <?php endforeach; ?>
            </span>
        </header>

        <main class="page<?= $fill ? ' fill' : '' ?>">
            <?php foreach ($flashes as $type => $message): ?>
                <div class="notice <?= $type ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
                    <?= ui_icon($type === 'error' ? 'alert' : 'check') ?>
                    <div class="body"><?= esc($message) ?></div>
                    <button type="button" class="btn icon" data-dismiss aria-label="Закрыть"><?= ui_icon('x', 14) ?></button>
                </div>
            <?php endforeach; ?>

            <?= $this->renderSection('content') ?>
        </main>
    </div>
</div>

<dialog id="confirm-dialog" class="modal">
    <form method="dialog">
        <div class="modal-body">
            <span class="modal-icon"><?= ui_icon('alert', 18) ?></span>
            <div>
                <h2 data-confirm-title>Подтвердите действие</h2>
                <p data-confirm-text></p>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" value="cancel" data-confirm-cancel>Отмена</button>
            <button class="btn primary" value="ok" data-confirm-ok>Продолжить</button>
        </div>
    </form>
</dialog>

<dialog id="lightbox" class="lightbox">
    <div class="lightbox-inner">
        <img alt="">
        <div class="lightbox-bar">
            <span class="grow mono" data-lightbox-caption></span>
            <a class="btn icon sm" data-lightbox-open target="_blank" rel="noopener" title="Открыть в новой вкладке"><?= ui_icon('external') ?></a>
            <button type="button" class="btn icon sm" data-lightbox-close aria-label="Закрыть"><?= ui_icon('x') ?></button>
        </div>
    </div>
</dialog>

<script src="<?= ui_asset('ui/app.js') ?>"></script>
</body>
</html>
