<?php
/**
 * <head> contents shared by the layout and the sign-in page.
 * @var string $title
 */
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?= esc($title) ?> · Observatory</title>
<script>
    // The theme before the first paint — same key and values as public/assets/ui/app.js.
    try {
        var t = localStorage.getItem('obs.theme');
        document.documentElement.dataset.theme = t === 'day' || t === 'night' || t === 'red'
            ? t
            : (matchMedia('(prefers-color-scheme: dark)').matches ? 'night' : 'day');
    } catch (e) {
        document.documentElement.dataset.theme = 'day';
    }
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= ui_asset('ui/app.css') ?>">
<link rel="icon" href="/favicon.ico">
