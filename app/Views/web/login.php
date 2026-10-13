<?php
/**
 * Sign-in (Web\AuthController). Standalone — no rail, nothing about the observatory shown.
 *
 * @var bool        $configured  ui.username / ui.password are set in .env
 * @var string      $next        where to go after signing in
 * @var string      $username    the name just submitted, kept on a failed try
 * @var string|null $error
 * @var int|null    $left        tries left before the lock
 * @var int|null    $locked      seconds until this address may try again
 * @var string|null $notice      flash (signed out, session expired)
 */
$error  = $error ?? null;
$left   = $left ?? null;
$locked = $locked ?? null;
$notice = $notice ?? null;
?>
<!doctype html>
<html lang="ru">
<head>
    <?= view('web/partials/head', ['title' => 'Вход']) ?>
</head>
<body>
<div class="login-page">
    <form class="login-card" method="post" action="/ui/login" autocomplete="on">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= esc($next, 'attr') ?>">

        <div class="login-brand">
            <?= view('web/partials/brand_mark') ?>
            <div>
                <h1>Observatory</h1>
                <p>Консоль управления pipeline</p>
            </div>
        </div>

        <?php if (! $configured): ?>
            <div class="notice warn" role="alert">
                <?= ui_icon('lock') ?>
                <div class="body">
                    Вход отключён: в <code>.env</code> не заданы <code>ui.username</code> и
                    <code>ui.password</code>.
                </div>
            </div>
        <?php elseif ($locked): ?>
            <div class="notice warn" role="alert">
                <?= ui_icon('lock') ?>
                <div class="body">
                    Слишком много неудачных попыток. Вход с этого адреса заблокирован ещё на
                    <?= max(1, (int) ceil($locked / 60)) ?> мин.
                </div>
            </div>
        <?php elseif ($error): ?>
            <div class="notice error" role="alert">
                <?= ui_icon('alert') ?>
                <div class="body">
                    <?= esc($error) ?>
                    <?php if ($left !== null && $left <= 3): ?>
                        Осталось <?= (int) $left ?> <?= ui_plural((int) $left, 'попытка', 'попытки', 'попыток') ?> до блокировки.
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($notice): ?>
            <div class="notice info" role="status">
                <?= ui_icon('info') ?>
                <div class="body"><?= esc($notice) ?></div>
            </div>
        <?php endif; ?>

        <label class="field">
            <span class="label">Логин</span>
            <input class="input" type="text" name="username" value="<?= esc($username, 'attr') ?>"
                   autocomplete="username" autocapitalize="none" spellcheck="false" required
                   <?= $username === '' ? 'autofocus' : '' ?> <?= $configured ? '' : 'disabled' ?>>
        </label>

        <label class="field">
            <span class="label">Пароль</span>
            <span class="pass-wrap">
                <input class="input" type="password" name="password" id="password" autocomplete="current-password" required
                       <?= $username !== '' ? 'autofocus' : '' ?> <?= $configured ? '' : 'disabled' ?>>
                <button type="button" class="btn icon" data-reveal="#password" aria-pressed="false" title="Показать пароль" aria-label="Показать пароль">
                    <?= ui_icon('eye') ?>
                </button>
            </span>
        </label>

        <button type="submit" class="btn primary" <?= $configured && ! $locked ? '' : 'disabled' ?>>Войти</button>

        <p class="login-foot">Сессия завершается после <?= (int) config(\Config\Ui::class)->idleMinutes ?> мин бездействия</p>
    </form>
</div>
<script src="<?= ui_asset('ui/app.js') ?>"></script>
</body>
</html>
