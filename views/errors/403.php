<?php /** @var callable $e */ ?>
<section class="card card-auth">
    <div class="card-head">
        <span class="brand-mark">403</span>
        <h1>دسترسی غیرمجاز</h1>
    </div>
    <p class="muted"><?= $e($message ?? 'شما مجوز دسترسی به این بخش را ندارید.') ?></p>
    <?php // '/' is the role dispatcher (DashboardController::home): the owner
          // lands on /owner, managers on /dashboard, devices on /device. A
          // hard-coded /dashboard would loop a customer-less owner back to 403. ?>
    <a class="btn btn-primary" href="<?= $e(\App\Support\Url::to('/')) ?>">بازگشت به صفحه اصلی پنل</a>
</section>
