<?php
/**
 * Owner - confirmation before entering a customer panel.
 *
 * @var array<string,mixed> $customer
 * @var array<string,mixed>|null $manager
 * @var bool $enabled
 * @var callable $e
 * @var callable $url
 * @var callable $csrf
 */
$uuid = (string) $customer['uuid'];
?>
<div class="o-page-head">
    <div>
        <h1>ورود اضطراری به پنل مشتری</h1>
        <p>مشتری: <strong><?= $e($customer['name']) ?></strong></p>
    </div>
    <div class="o-actions">
        <a class="o-btn" href="<?= $e($url('/owner/customers/' . $uuid)) ?>">بازگشت</a>
        <a class="o-btn" href="<?= $e($url('/owner/emergency')) ?>">سوابق دسترسی اضطراری</a>
    </div>
</div>

<?php if (!$enabled): ?>
    <div class="o-alert o-alert-error">دسترسی اضطراری در تنظیمات سامانه غیرفعال است. برای استفاده، آن را از صفحه تنظیمات فعال کنید.</div>
<?php endif; ?>

<div class="o-grid o-grid-2">
    <section class="o-card">
        <div class="o-card-head"><h2>ثبت ورود اضطراری</h2></div>

        <form method="post" action="<?= $e($url('/owner/customers/' . $uuid . '/emergency')) ?>">
            <?= $csrf() ?>

            <label class="o-field">
                <span>دلیل ورود (اجباری، حداقل ۵ کاراکتر) *</span>
                <textarea name="reason" id="emergency-reason" required minlength="5" maxlength="255"
                          placeholder="مثال: درخواست تلفنی مشتری برای بررسی مشکل ثبت دستگاه"></textarea>
                <small>این متن در گزارش رویدادها و سوابق دسترسی اضطراری ثبت می‌شود.</small>
            </label>

            <button type="submit" class="o-btn o-btn-warn" data-need-reason="emergency-reason" <?= !$enabled ? 'disabled' : '' ?>>
                ورود به پنل مشتری
            </button>
        </form>
    </section>

    <section class="o-card">
        <div class="o-card-head"><h2>چه اتفاقی می‌افتد؟</h2></div>
        <ul class="o-timeline">
            <li><span class="o-what"><strong>یک رکورد ورود ثبت می‌شود</strong><small>شناسه مالک، مشتری، مدیر اصلی، زمان، IP و دلیل.</small></span></li>
            <li><span class="o-what"><strong>شما پنل مشتری را می‌بینید</strong><small>دقیقاً همان دسترسی‌هایی که مدیر این مشتری دارد، محدود به امکانات فعال او.</small></span></li>
            <li><span class="o-what"><strong>همه اقدامات شما شمارش می‌شود</strong><small>تعداد عملیات نوشتاری داخل پنل مشتری در همان رکورد ذخیره می‌شود.</small></span></li>
            <li><span class="o-what"><strong>خروج، رکورد را می‌بندد</strong><small>با دکمه «خروج از دسترسی اضطراری» تاریخ پایان و مدت ثبت می‌شود.</small></span></li>
            <li><span class="o-what"><strong>رمز مدیر نمایش داده نمی‌شود</strong><small>فقط نام کاربری مدیر قابل مشاهده است.</small></span></li>
        </ul>

        <div class="o-kv o-mt">
            <div><span>مدیر اصلی مشتری</span><strong><?= $e($manager['name'] ?? 'ترکیبی ثبت نشده') ?></strong></div>
            <div><span>ایمیل مدیر</span><strong class="o-ltr"><?= $e($manager['email'] ?? '—') ?></strong></div>
        </div>
    </section>
</div>
