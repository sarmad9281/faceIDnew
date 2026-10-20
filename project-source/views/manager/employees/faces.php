<?php
/**
 * Manager - face enrolment wizard (stage 5).
 *
 * THREE samples, one at a time, each gated by real local quality checks
 * (face present / exactly one face / big enough / not blurry / inside bounds).
 * The browser computes the 128-d descriptor with the SHIPPED model and posts
 * THAT (plus, when enabled, one small enrolment snapshot). No recognition runs
 * on the server and no video frame is uploaded.
 *
 * The device only ever sees the descriptors of its own customer (the sync
 * endpoint is scoped by the device token), and an employee or a manager can
 * never read a raw embedding back from the UI.
 *
 * Liveness detection is NOT implemented - by project decision.
 *
 * @var array<string,mixed>  $employee
 * @var array<string,mixed>  $faceState
 * @var array<string,mixed>  $faceConfig
 * @var array<int,array<string,mixed>> $samples
 * @var array<string,mixed>|null $customer
 * @var string|null $error
 * @var string|null $success
 * @var callable $e
 * @var callable $url
 * @var callable $asset
 * @var callable $csrf
 */

use App\Models\Employee;
use App\Models\FaceEmbedding;

$id        = (int) $employee['id'];
$fullName  = Employee::fullName($employee);
$photo     = trim((string) ($employee['photo_path'] ?? ''));
$count     = (int) ($faceState['count'] ?? 0);
$required  = (int) ($faceState['required'] ?? FaceEmbedding::SAMPLES);
$model     = (string) ($faceState['model_version'] ?? '');
$complete  = $count >= $required;

$byIndex = [];

foreach ($samples as $sample) {
    $byIndex[(int) $sample['sample_index']] = $sample;
}
?>
<div class="page-head">
    <div>
        <h1>ثبت چهره پرسنل</h1>
        <p class="muted small">
            برای هر کارمند دقیقاً سه نمونه چهره ثبت می‌شود. پردازش چهره فقط داخل مرورگر همین دستگاه انجام می‌شود
            و تصویر یا ویدیو به سرور ارسال نمی‌شود.
        </p>
    </div>
    <div class="row-actions">
        <a class="btn btn-ghost" href="<?= $e($url('/manager/employees/' . $id)) ?>">بازگشت به پرونده</a>
    </div>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= $e($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= $e($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <div class="person">
            <?php if ($photo !== ''): ?>
                <img class="avatar avatar-lg" src="<?= $e(\App\Support\Url::upload($photo)) ?>" alt="">
            <?php else: ?>
                <span class="avatar avatar-lg"><?= $e(mb_substr($fullName, 0, 1)) ?></span>
            <?php endif; ?>
            <div>
                <strong><?= $e($fullName) ?></strong>
                <div class="muted small"><?= $e((string) ($employee['employee_code'] ?? '')) ?></div>
            </div>
        </div>
        <div class="row-actions">
            <?php if ($complete): ?>
                <span class="badge badge-ok">کامل — <?= $e((string) $required) ?> از <?= $e((string) $required) ?> نمونه</span>
            <?php else: ?>
                <span class="badge badge-warn"><?= $e((string) $count) ?> از <?= $e((string) $required) ?> نمونه</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="fw-summary">
        <div class="fw-summary-cell">
            <span class="muted small">وضعیت ثبت حضور با چهره</span>
            <strong><?= $complete ? 'فعال — سه نمونه کامل است' : 'غیرفعال — تا تکمیل سه نمونه، حضور ثبت نمی‌شود' ?></strong>
        </div>
        <div class="fw-summary-cell">
            <span class="muted small">موتور تشخیص چهره</span>
            <strong class="ltr"><?= $e((string) $faceConfig['model_version']) ?></strong>
        </div>
        <div class="fw-summary-cell">
            <span class="muted small">آستانه تطبیق (متریک <?= $e((string) $faceConfig['metric']) ?>)</span>
            <strong class="ltr"><?= $e((string) $faceConfig['match_threshold']) ?></strong>
        </div>
        <div class="fw-summary-cell">
            <span class="muted small">Liveness</span>
            <strong>پیاده‌سازی نشده</strong>
        </div>
    </div>

    <?php if ($model !== '' && $count > 0): ?>
        <p class="muted small">
            نسخه مدل نمونه‌های فعلی: <span class="ltr"><?= $e($model) ?></span>
        </p>
    <?php endif; ?>
</div>

<div class="card"
     id="face-wizard"
     data-employee="<?= $e((string) $id) ?>"
     data-endpoint="<?= $e($url('/manager/employees/' . $id . '/faces/embedding')) ?>"
     data-csrf="<?= $e(\App\Core\Csrf::token()) ?>"
     data-samples="<?= $e(json_encode(array_values($samples), JSON_UNESCAPED_UNICODE)) ?>"
     data-config="<?= $e(json_encode($faceConfig, JSON_UNESCAPED_UNICODE)) ?>"
     data-required="<?= $e((string) $required) ?>"
     data-model="<?= $e((string) $faceConfig['model_version']) ?>">

    <div class="card-head">
        <h2>نمونه‌های چهره</h2>
        <span class="badge badge-muted" id="fw-status">آماده</span>
    </div>

    <div class="face-grid fw-slots">
        <?php for ($index = 1; $index <= $required; $index++): ?>
            <?php $sample = $byIndex[$index] ?? null; ?>
            <div class="face-slot <?= $sample !== null ? 'is-filled' : '' ?>" data-slot="<?= $e((string) $index) ?>">
                <div class="fw-slot-index">نمونه <?= $e((string) $index) ?></div>
                <?php if ($sample !== null): ?>
                    <span class="badge badge-ok">ثبت شده</span>
                    <div class="face-meta">
                        <span>کیفیت: <span class="ltr"><?= $e($sample['quality'] === null ? '—' : number_format((float) $sample['quality'], 2)) ?></span></span>
                        <span class="ltr"><?= $e((string) $sample['model_version']) ?></span>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm btn-block"
                            data-capture="<?= $e((string) $index) ?>">
                        ثبت مجدد این نمونه
                    </button>
                <?php else: ?>
                    <span class="badge badge-muted">خالی</span>
                    <div class="face-meta">
                        <span>کیفیت: —</span>
                        <span>بردار ۱۲۸ بُعدی</span>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm btn-block"
                            data-capture="<?= $e((string) $index) ?>">
                        ثبت این نمونه
                    </button>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>

    <p class="muted small">
        کیفیت هر نمونه پیش از ذخیره بررسی می‌شود: حضور چهره، تنها یک چهره در کادر، اندازه کافی، وضوح تصویر و
        قرارگیری در محدوده. اگر شرطی برقرار نباشد هیچ داده‌ای ذخیره نمی‌شود.
    </p>

    <?php if ($complete): ?>
        <div class="alert alert-success">
            ثبت چهره این کارمند کامل است و دستگاه‌ها می‌توانند برای ثبت ورود و خروج از آن استفاده کنند.
        </div>
    <?php else: ?>
        <div class="alert alert-warn">
            تا زمانی که سه نمونه کامل نشود، این کارمند با چهره شناسایی نمی‌شود و هیچ حضوری برای او ثبت نخواهد شد.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $e($url('/manager/employees/' . $id . '/faces/reset')) ?>"
          data-confirm="همه نمونه‌های چهره این کارمند باطل شود؟ پس از باطل‌سازی باید سه نمونه جدید ثبت شود.">
        <?= $csrf() ?>
        <button type="submit" class="btn btn-danger btn-sm">باطل‌کردن ثبت چهره و ثبت سه نمونه جدید</button>
    </form>
</div>

<!-- ------------------------------------------------------------------
     Capture overlay: the camera never leaves this page and the preview is
     stopped/released as soon as the overlay closes.
     ------------------------------------------------------------------ -->
<div class="fw-overlay" id="fw-overlay" hidden>
    <div class="fw-panel">
        <div class="fw-panel-head">
            <strong id="fw-title">ثبت نمونه ۱</strong>
            <button type="button" class="btn btn-ghost btn-sm" id="fw-close">بستن</button>
        </div>

        <div class="fw-stage">
            <video id="fw-video" playsinline muted autoplay></video>
            <div class="fw-oval" id="fw-oval">
                <span class="fw-box" id="fw-box" aria-hidden="true"></span>
                <span class="fw-oval-label" id="fw-oval-label">چهره خود را داخل محدوده قرار دهید</span>
            </div>
            <div class="fw-overlay-state" id="fw-state" data-state="loading">
                <span class="fw-spinner" aria-hidden="true"></span>
                <span id="fw-state-text">در حال بارگذاری موتور تشخیص چهره…</span>
            </div>
            <div class="fw-flash" id="fw-flash" aria-hidden="true"></div>
        </div>

        <div class="fw-metrics" id="fw-metrics" hidden>
            <span>اندازه چهره: <b class="ltr" id="fw-m-ratio">—</b></span>
            <span>وضوح: <b class="ltr" id="fw-m-blur">—</b></span>
            <span>روشنایی: <b class="ltr" id="fw-m-bright">—</b></span>
            <span>مرکزیت: <b class="ltr" id="fw-m-center">—</b></span>
            <span>چرخش: <b class="ltr" id="fw-m-yaw">—</b></span>
        </div>

        <div class="fw-actions">
            <button type="button" class="btn btn-primary btn-block" id="fw-capture" disabled>ثبت نمونه</button>
            <p class="muted small" id="fw-note">
                تصویر فقط داخل همین مرورگر پردازش می‌شود؛ برای ثبت حضور هیچ تصویری به سرور ارسال نمی‌شود.
            </p>
        </div>
    </div>
</div>

<script src="<?= $e($asset('vendor/tfjs-1.7.4.min.js')) ?>" defer></script>
<script src="<?= $e($asset('vendor/face-api-0.22.2.min.js')) ?>" defer></script>
<script src="<?= $e($asset('js/face/face-engine.js')) ?>" defer></script>
<script src="<?= $e($asset('js/enroll.js')) ?>" defer></script>
