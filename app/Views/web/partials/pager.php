<?php
/**
 * A list's foot: which rows are shown, the pages, and how many per page (App\Libraries\UiListQuery).
 * @var \App\Libraries\UiListQuery $list
 * @var string                     $noun  ui_plural() forms, "кадр|кадра|кадров"
 */
[$one, $few, $many] = explode('|', $noun);
?>
<div class="panel-foot pager">
    <span class="pager-range">
        <?php if ($list->total === 0): ?>
            Ничего не найдено
        <?php else: ?>
            <span class="mono"><?= ui_num($list->from()) ?>–<?= ui_num($list->to()) ?></span> из
            <span class="mono"><?= ui_num($list->total) ?></span> <?= ui_plural($list->total, $one, $few, $many) ?>
        <?php endif; ?>
    </span>

    <?php if ($list->pages > 1): ?>
        <nav class="pages" aria-label="Страницы">
            <?php if ($list->page > 1): ?>
                <a class="btn icon sm" href="<?= esc($list->pageUrl($list->page - 1), 'attr') ?>" rel="prev" title="Предыдущая" aria-label="Предыдущая страница"><?= ui_icon('back', 14) ?></a>
            <?php else: ?>
                <span class="btn icon sm" aria-disabled="true"><?= ui_icon('back', 14) ?></span>
            <?php endif; ?>
            <?php foreach ($list->window() as $p): ?>
                <?php if ($p === null): ?>
                    <span class="gap">…</span>
                <?php elseif ($p === $list->page): ?>
                    <span class="page-link current" aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a class="page-link" href="<?= esc($list->pageUrl($p), 'attr') ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($list->page < $list->pages): ?>
                <a class="btn icon sm" href="<?= esc($list->pageUrl($list->page + 1), 'attr') ?>" rel="next" title="Следующая" aria-label="Следующая страница"><?= ui_icon('chevron', 14) ?></a>
            <?php else: ?>
                <span class="btn icon sm" aria-disabled="true"><?= ui_icon('chevron', 14) ?></span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <form class="per-page" method="get">
        <?php foreach ($list->keptParams('per_page', 'page') as $name => $value): ?>
            <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($value, 'attr') ?>">
        <?php endforeach; ?>
        <label class="row">
            <span>По</span>
            <select class="input" name="per_page" data-autosubmit aria-label="Строк на странице">
                <?php foreach ($list->perPageOptions as $n): ?>
                    <option value="<?= $n ?>" <?= $n === $list->perPage ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><button class="btn sm" type="submit">OK</button></noscript>
    </form>
</div>
