<?php /** @var callable $e */ ?>
<section class="card card-auth">
    <div class="card-head">
        <span class="brand-mark">429</span>
        <h1>تعداد درخواست بیش از حد مجاز</h1>
    </div>
    <p class="muted">لطفاً <?= (int) ($retry ?? 60) ?> ثانیه دیگر دوباره تلاش کنید.</p>
    <a class="btn btn-primary" href="<?= $e(\App\Support\Url::to('/')) ?>">بازگشت</a>
</section>
