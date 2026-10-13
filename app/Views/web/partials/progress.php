<?php
/**
 * A task's progress: completed (green) and failed (red) items out of the total.
 * @var array $task  completed_items, failed_items, total_items
 * @var bool  $large
 */
$_total  = (int) $task['total_items'];
$_done   = (int) $task['completed_items'];
$_failed = (int) $task['failed_items'];
$_pct    = static fn (int $n): string => $_total > 0 ? round($n / $_total * 100, 2) . '%' : '0';
?>
<div class="progress<?= ! empty($large) ? ' lg' : '' ?>" title="Готово <?= $_done ?>, ошибок <?= $_failed ?>, всего <?= $_total ?>">
    <div class="bar">
        <span class="ok" style="width: <?= $_pct($_done) ?>"></span>
        <span class="fail" style="width: <?= $_pct($_failed) ?>"></span>
    </div>
    <span class="label"><?= $_done + $_failed ?>/<?= $_total ?></span>
</div>
