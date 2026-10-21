<?php
/**
 * @var string $label
 * @var string|int $value
 * @var callable $e
 * @var string|null $hint
 */
?>
<div class="stat">
    <span class="stat-value"><?= $e($value) ?></span>
    <span class="stat-label"><?= $e($label) ?></span>
    <?php if (!empty($hint)): ?>
        <span class="stat-hint"><?= $e($hint) ?></span>
    <?php endif; ?>
</div>
