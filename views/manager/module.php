<?php
/**
 * Manager - structure page of a module whose engine arrives in a later stage.
 *
 * @var array{title:string,feature:string,state:array<string,mixed>|null,items:array<int,string>} $module
 * @var callable $e
 * @var callable $url
 */
$enabled = !empty($module['state']['enabled']);
?>
<div class="page-head">
    <div>
        <h1><?= $e($module['title']) ?></h1>
        <p class="muted small">
            ساختار و کنترل دسترسی این ماژول آماده است؛ خود موتور آن در مرحله بعدی پروژه ساخته می‌شود.
            تا آن زمان هیچ عدد و گزارشی در این صفحه ساخته نمی‌شود.
        </p>
    </div>
    <a class="btn btn-ghost" href="<?= $e($url('/dashboard')) ?>">داشبورد</a>
</div>

<section class="card">
    <div class="card-head"><h2>وضعیت امکان «<?= $e($module['feature']) ?>»</h2></div>
    <div class="list-row">
        <span>وضعیت در دیتابیس</span>
        <span class="badge <?= $enabled ? 'badge-ok' : 'badge-muted' ?>"><?= $enabled ? 'فعال' : 'غیرفعال' ?></span>
    </div>
    <p class="muted small" style="margin-top:12px">
        اگر این امکان برای کسب‌وکار شما غیرفعال شود، همین آدرس توسط میان‌افزار سمت سرور با خطای ۴۰۳ رد می‌شود
        و تلاش دسترسی در رویدادها ثبت می‌گردد.
    </p>
</section>

<section class="card">
    <div class="card-head"><h2>اجزای مرحله بعد</h2></div>
    <div class="list">
        <?php foreach ($module['items'] as $item): ?>
            <div class="list-row"><span><?= $e($item) ?></span><span class="badge badge-muted">در دست ساخت</span></div>
        <?php endforeach; ?>
    </div>
</section>
