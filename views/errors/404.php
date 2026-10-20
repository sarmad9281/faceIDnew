<?php /** @var callable $e */ ?>
<section class="card card-auth">
    <div class="card-head">
        <span class="brand-mark">404</span>
        <h1>صفحه یافت نشد</h1>
    </div>
    <p class="muted">آدرس درخواستی در این سامانه وجود ندارد.</p>
    <a class="btn btn-primary" href="<?= $e(\App\Support\Url::to('/')) ?>">بازگشت</a>
</section>
