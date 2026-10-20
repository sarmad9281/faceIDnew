<?php
/**
 * @var callable $e
 * @var callable $url
 * @var array $customers
 * @var string $search
 */
?>
<section class="page-head">
    <div>
        <h1>مشتریان</h1>
        <p class="muted">هر مشتری یک محدوده (Tenant) کاملاً مستقل است.</p>
    </div>
    <a class="btn btn-primary" href="<?= $e($url('/owner/customers/create')) ?>">افزودن مشتری</a>
</section>

<section class="card">
    <form method="get" action="<?= $e($url('/owner/customers')) ?>" class="inline-form">
        <input type="text" name="q" value="<?= $e($search) ?>" placeholder="جستجو بر اساس نام یا slug" maxlength="80">
        <button class="btn btn-ghost" type="submit">جستجو</button>
    </form>

    <?php if ($customers === []): ?>
        <p class="muted">موردی یافت نشد.</p>
    <?php else: ?>
        <table class="table">
            <thead>
            <tr><th>#</th><th>نام</th><th>UUID</th><th>وضعیت</th><th>سقف کارمند</th><th>عملیات</th></tr>
            </thead>
            <tbody>
            <?php foreach ($customers as $customer): ?>
                <tr>
                    <td><?= (int) $customer['id'] ?></td>
                    <td>
                        <?= $e($customer['name']) ?>
                        <small class="muted"><?= $e($customer['slug']) ?></small>
                    </td>
                    <td class="ltr small"><?= $e($customer['uuid']) ?></td>
                    <td><span class="badge badge-<?= $e($customer['status'] === 'active' ? 'ok' : 'muted') ?>"><?= $e($customer['status']) ?></span></td>
                    <td><?= (int) $customer['max_employees'] ?></td>
                    <td>
                        <a class="btn btn-ghost" href="<?= $e($url('/owner/customers/show?customer=' . urlencode((string) $customer['uuid']))) ?>">جزئیات</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
