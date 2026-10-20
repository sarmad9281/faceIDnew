<?php /** @var callable $e */ ?>
<section class="card card-auth">
    <div class="card-head">
        <span class="brand-mark">500</span>
        <h1>خطای سرور</h1>
    </div>
    <p class="muted"><?= $e($message ?? 'خطای غیرمنتظره‌ای رخ داد.') ?></p>
    <a class="btn btn-primary" href="<?= $e(\App\Support\Url::to('/')) ?>">بازگشت</a>
</section>
