<?php
/**
 * Who a task is for and who took it: the target (`tasks.pipeline_id`, "любой" when untargeted)
 * and, once claimed, the claiming pipeline (`tasks.claimed_by`), shown by name.
 * @var array                 $task
 * @var array<string, string> $names id => pipeline name (PipelineModel::getAllNames())
 */
$_target  = $task['pipeline_id'] ?? null;
$_claimed = $task['claimed_by'] ?? null;
?>
<span class="mono small"><?php if ($_target !== null): ?><?= esc($names[$_target] ?? $_target) ?><?php else: ?><span class="dim">любой</span><?php endif; ?><?php if ($_claimed !== null && $_claimed !== $_target): ?> <span class="dim">→ <?= esc($names[$_claimed] ?? $_claimed) ?></span><?php endif; ?></span>
