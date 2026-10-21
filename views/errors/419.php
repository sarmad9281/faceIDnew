<?php /** @var callable $e */ ?>
<section class="card card-auth">
    <div class="card-head">
        <span class="brand-mark">419</span>
        <h1>توکن امنیتی نامعتبر</h1>
    </div>
    <p class="muted">
        توکن امنیتی فرم (CSRF) نامعتبر یا منقضی شده است. لطفاً به صفحه قبل برگردید،
        صفحه را دوباره بارگذاری کنید و فرم را مجدداً ارسال کنید.
    </p>
    <a class="btn btn-primary" href="<?= $e(\App\Support\Url::to('/')) ?>">بازگشت به صفحه اصلی</a>
</section>
