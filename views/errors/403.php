<?php /** @var callable $e */ ?>
<section class="card card-auth">
    <div class="card-head">
        <span class="brand-mark">403</span>
        <h1>دسترسی غیرمجاز</h1>
    </div>
    <p class="muted"><?= $e($message ?? 'شما مجوز دسترسی به این بخش را ندارید.') ?></p>
    <a class="btn btn-primary" href="<?= $e(\App\Support\Url::to('/dashboard')) ?>">بازگشت به داشبورد</a>
</section>
