<?php
/**
 * Shared pagination control.
 *
 * @var array{page:int,pages:int,total:int,per_page:int} $listing
 * @var callable $e
 * @var callable $url
 * @var array<string,mixed> $query current query string values (filters)
 */
$page   = (int) $listing['page'];
$pages  = (int) $listing['pages'];
$total  = (int) $listing['total'];
$per    = (int) $listing['per_page'];
$query  = $query ?? [];

// Build a query string preserving the active filters.
$buildUrl = static function (array $overrides) use ($query, $url): string {
    $params = array_merge($query, $overrides);
    $params = array_filter($params, static function ($value): bool {
        return $value !== '' && $value !== null && $value !== 0;
    });

    $path = (string) ($params['_path'] ?? '/');
    unset($params['_path']);

    return $url($path) . ($params === [] ? '' : '?' . http_build_query($params));
};

$window = 2;
$from   = max(1, $page - $window);
$to     = min($pages, $page + $window);
?>
<?php if ($total > 0): ?>
    <nav class="o-pagination" aria-label="صفحه‌بندی">
        <a class="o-page <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= $e($buildUrl(['page' => max(1, $page - 1)])) ?>">قبلی</a>

        <?php if ($from > 1): ?>
            <a class="o-page" href="<?= $e($buildUrl(['page' => 1])) ?>">1</a>
            <?php if ($from > 2): ?><span class="o-page is-disabled">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $from; $i <= $to; $i++): ?>
            <a class="o-page <?= $i === $page ? 'is-active' : '' ?>" href="<?= $e($buildUrl(['page' => $i])) ?>"><?= $e($i) ?></a>
        <?php endfor; ?>

        <?php if ($to < $pages): ?>
            <?php if ($to < $pages - 1): ?><span class="o-page is-disabled">…</span><?php endif; ?>
            <a class="o-page" href="<?= $e($buildUrl(['page' => $pages])) ?>"><?= $e($pages) ?></a>
        <?php endif; ?>

        <a class="o-page <?= $page >= $pages ? 'is-disabled' : '' ?>" href="<?= $e($buildUrl(['page' => min($pages, $page + 1)])) ?>">بعدی</a>

        <span class="o-pagination-info">
            صفحه <?= $e($page) ?> از <?= $e($pages) ?> — مجموع <?= $e($total) ?> رکورد (<?= $e($per) ?> در هر صفحه)
        </span>
    </nav>
<?php endif; ?>
