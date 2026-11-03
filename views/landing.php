<?php
/**
 * SARMAD Attendance - public landing page.
 *
 * Standalone by design: it must not inherit the authenticated panel layout,
 * expose tenant data, or depend on a logged-in session. Contact channels
 * arrive from LandingController so no personal phone number or messenger
 * handle is hard-coded in the template.
 *
 * @var callable $e
 * @var callable $url
 * @var callable $asset
 * @var string $title
 * @var array<string,string> $contact
 */
$title = isset($title) && is_string($title) && $title !== ''
    ? $title
    : 'سرمد | سامانه حضور و غیاب هوشمند با تشخیص چهره';

$contact = array_merge([
    'phoneDisplay' => '',
    'phoneHref' => '#consult',
    'whatsappUrl' => '',
    'whatsappHref' => '#consult',
    'telegramUrl' => '',
    'telegramHref' => '#consult',
], isset($contact) && is_array($contact) ? $contact : []);

$hasWhatsapp = (string) $contact['whatsappUrl'] !== '';
$hasTelegram = (string) $contact['telegramUrl'] !== '';
$phoneDisplay = (string) $contact['phoneDisplay'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?></title>
    <meta name="description" content="سامانه حضور و غیاب هوشمند سرمد با تشخیص چهره؛ بدون خرید دستگاه اختصاصی، با هر گوشی یا تبلت، آنلاین و آفلاین، همراه با گزارش‌گیری و اعطای نمایندگی رسمی.">
    <meta name="theme-color" content="#040713">
    <meta name="color-scheme" content="dark">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:title" content="<?= $e($title) ?>">
    <meta property="og:description" content="نسل نوین حضور و غیاب با هوش مصنوعی و تشخیص چهره؛ کیوسک هوشمند اختصاصی مجموعه شما روی گوشی یا تبلت.">
    <meta property="og:image" content="<?= $e($asset('/icons/icon-512.png')) ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= $e($asset('/icons/icon-192.png')) ?>">
    <link rel="apple-touch-icon" href="<?= $e($asset('/icons/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= $e($asset('/css/landing.css')) ?>">
</head>
<body class="landing-body">
<a class="skip-link" href="#main">پرش به محتوای اصلی</a>
<div class="bg-grid" aria-hidden="true"></div>
<div class="bg-orb orb-cyan" aria-hidden="true"></div>
<div class="bg-orb orb-violet" aria-hidden="true"></div>

<header class="site-header">
    <div class="wrap nav-shell">
        <a class="brand" href="<?= $e($url('/')) ?>" aria-label="سامانه حضور و غیاب هوشمند سرمد">
            <span class="brand-mark"><img src="<?= $e($asset('/icons/icon-192.png')) ?>" alt=""></span>
            <span class="brand-copy">
                <strong>سرمد</strong>
                <small>سامانه حضور و غیاب هوشمند</small>
            </span>
        </a>

        <nav class="main-nav" aria-label="ناوبری اصلی">
            <a href="#features">ویژگی‌ها</a>
            <a href="#advantages">مزایا</a>
            <a href="#agency">اخذ نمایندگی</a>
            <a href="#contact">تماس</a>
        </nav>

        <div class="nav-actions">
            <a class="btn btn-ghost" href="<?= $e($url('/login')) ?>">ورود به پنل کاربری</a>
            <a class="btn btn-primary" href="<?= $e($url('/device')) ?>">کیوسک دستگاه</a>
        </div>
    </div>
</header>

<main id="main">
    <!-- ==============================================================
         Hero + 3D biometric hologram
         ============================================================== -->
    <section class="hero wrap" id="top" aria-labelledby="hero-title">
        <div class="hero-copy">
            <span class="eyebrow">تشخیص چهره بیومتریک، بدون دستگاه اختصاصی</span>
            <h1 id="hero-title">نسل نوین حضور و غیاب با <span class="gradient-text">هوش مصنوعی و تشخیص چهره</span></h1>
            <p class="hero-lead">بدون نیاز به دستگاه‌های گران‌قیمت؛ با هر گوشی یا تبلت، کیوسک هوشمند تشخیص چهره اختصاصی مجموعه خود را راه‌اندازی کنید.</p>

            <div class="hero-actions">
                <a class="btn btn-primary btn-large" href="<?= $e($url('/login')) ?>">
                    ورود به پنل کاربری
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"></path><path d="m12 19-7-7 7-7"></path></svg>
                </a>
                <a class="btn btn-ghost btn-large" href="<?= $e($url('/device')) ?>">
                    مشاهده کیوسک دستگاه
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2"></rect><path d="M12 18h.01"></path></svg>
                </a>
            </div>

            <ul class="hero-proof" aria-label="مزیت‌های کلیدی">
                <li>راه‌اندازی سریع روی گوشی یا تبلت</li>
                <li>ثبت آفلاین و هم‌گام‌سازی خودکار</li>
                <li>گزارش، حقوق و خروجی اکسل</li>
            </ul>
        </div>

        <div class="hero-visual" id="hero-visual" aria-label="نمایش گرافیکی اسکن سه‌بعدی چهره">
            <div class="holo-scene" id="holo-scene">
                <div class="holo-card" aria-hidden="true">
                    <div class="holo-head">
                        <span class="pulse-dot"></span>
                        <span>اسکن بیومتریک زنده</span>
                        <span class="live-badge">LIVE</span>
                    </div>

                    <div class="scan-stage">
                        <span class="corner corner-top-right"></span>
                        <span class="corner corner-top-left"></span>
                        <span class="corner corner-bottom-right"></span>
                        <span class="corner corner-bottom-left"></span>

                        <svg class="face-svg" viewBox="0 0 240 280" role="img" aria-label="مش‌بندی نورانی چهره">
                            <path class="face-outline" d="M120 18 C164 18 190 52 190 108 C190 176 160 236 120 260 C80 236 50 176 50 108 C50 52 76 18 120 18 Z"></path>

                            <path class="mesh-line" d="M120 18 V260"></path>
                            <path class="mesh-line" d="M76 36 C62 98 70 190 94 242"></path>
                            <path class="mesh-line" d="M164 36 C178 98 170 190 146 242"></path>
                            <path class="mesh-line" d="M52 92 H188"></path>
                            <path class="mesh-line" d="M55 140 H185"></path>
                            <path class="mesh-line" d="M72 198 H168"></path>
                            <path class="mesh-line" d="M76 48 L164 232"></path>
                            <path class="mesh-line" d="M164 48 L76 232"></path>
                            <path class="mesh-line" d="M50 108 C86 76 154 76 190 108"></path>
                            <path class="mesh-line" d="M62 176 C94 150 146 150 178 176"></path>

                            <path class="face-feature" d="M74 104 Q92 91 110 104"></path>
                            <path class="face-feature" d="M130 104 Q148 91 166 104"></path>
                            <path class="face-feature" d="M120 112 L112 158 Q120 166 133 160"></path>
                            <path class="face-feature" d="M93 200 Q120 217 147 200"></path>
                            <path class="face-feature" d="M70 82 Q91 72 112 81"></path>
                            <path class="face-feature" d="M128 81 Q149 72 170 82"></path>

                            <g>
                                <circle class="mesh-node" cx="120" cy="18" r="4"></circle>
                                <circle class="mesh-node" cx="76" cy="36" r="3.5"></circle>
                                <circle class="mesh-node" cx="164" cy="36" r="3.5"></circle>
                                <circle class="mesh-node" cx="52" cy="92" r="3.5"></circle>
                                <circle class="mesh-node" cx="188" cy="92" r="3.5"></circle>
                                <circle class="mesh-node" cx="92" cy="104" r="4"></circle>
                                <circle class="mesh-node" cx="148" cy="104" r="4"></circle>
                                <circle class="mesh-node" cx="120" cy="140" r="4"></circle>
                                <circle class="mesh-node" cx="72" cy="198" r="3.5"></circle>
                                <circle class="mesh-node" cx="168" cy="198" r="3.5"></circle>
                                <circle class="mesh-node" cx="120" cy="260" r="4"></circle>
                                <circle class="mesh-node" cx="94" cy="242" r="3"></circle>
                                <circle class="mesh-node" cx="146" cy="242" r="3"></circle>
                            </g>
                        </svg>

                        <span class="scan-line"></span>
                        <span class="holo-chip chip-eyes">نقاط چهره شناسایی شد</span>
                        <span class="holo-chip chip-depth">تحلیل زنده مش بیومتریک</span>
                        <span class="holo-chip chip-match">تطابق هویت تأیید شد</span>
                    </div>

                    <div class="confidence">
                        <span>اطمینان شناسایی</span>
                        <span class="meter"><i></i></span>
                        <b>۹۹.۸٪</b>
                    </div>

                    <div class="holo-result">
                        <span class="check">✓</span>
                        <div>
                            <strong>ورود با موفقیت ثبت شد</strong>
                            <small>امروز • شعبه مرکزی • ثبت خودکار</small>
                        </div>
                        <time>0.3s</time>
                    </div>
                </div>

                <div class="float-card float-offline" aria-hidden="true">
                    حالت آفلاین فعال
                    <small>ثبت ادامه دارد؛ هم‌گام‌سازی خودکار</small>
                </div>
                <div class="float-card float-telegram" aria-hidden="true">
                    اعلان تلگرام ارسال شد
                    <small>گزارش لحظه‌ای ورود پرسنل</small>
                </div>
            </div>
        </div>
    </section>

    <!-- ==============================================================
         Key counters
         ============================================================== -->
    <section class="wrap stats-grid" id="stats" aria-label="شاخص‌های کلیدی سرمد">
        <article class="stat-card">
            <div class="stat-value"><span class="counter" data-target="0.3" data-decimals="1">۰.۳</span><small>ثانیه</small></div>
            <p>سرعت اسکن فوق‌سریع چهره</p>
        </article>
        <article class="stat-card">
            <div class="stat-value"><span class="counter" data-target="99.8" data-decimals="1">۹۹.۸</span><small>٪</small></div>
            <p>دقت شناسایی بیومتریک</p>
        </article>
        <article class="stat-card">
            <div class="stat-value"><span class="counter" data-target="100" data-decimals="0">۱۰۰</span><small>٪</small></div>
            <p>ابری و طراحی‌شده برای کارکرد بدون قطعی</p>
        </article>
        <article class="stat-card">
            <div class="stat-value"><span class="counter" data-target="0" data-decimals="0">۰</span><small>تومان</small></div>
            <p>هزینه خرید سخت‌افزار جانبی اختصاصی</p>
        </article>
    </section>

    <!-- ==============================================================
         Features
         ============================================================== -->
    <section class="section wrap" id="features" aria-labelledby="features-title">
        <div class="section-head">
            <span class="eyebrow">قابلیت‌های سامانه</span>
            <h2 id="features-title">یک کیوسک هوشمند، یک پنل کامل مدیریت پرسنل</h2>
            <p>از لحظه نگاه‌کردن کارمند به دوربین تا گزارش نهایی حقوق، همه مراحل در یک سامانه یکپارچه و امن انجام می‌شود.</p>
        </div>

        <div class="features-grid">
            <article class="feature-card">
                <div class="feature-icon" aria-hidden="true">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"></rect><path d="M12 18h.01"></path><path d="M9 7h6"></path></svg>
                </div>
                <h3>کیوسک هوشمند آفلاین و آنلاین</h3>
                <p>گوشی یا تبلت موجود مجموعه به دستگاه حضور و غیاب تبدیل می‌شود؛ در زمان قطعی اینترنت ثبت‌ها در صف امن می‌مانند و پس از اتصال، خودکار هم‌گام می‌شوند.</p>
            </article>

            <article class="feature-card">
                <div class="feature-icon" aria-hidden="true">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"></circle><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="8" r="3"></circle><path d="M6 9v6"></path><path d="M18 11a9 9 0 0 1-9 4"></path></svg>
                </div>
                <h3>مدیریت شعب و شیفت‌های کاری چندگانه</h3>
                <p>برای هر شعبه، دستگاه و گروه پرسنلی شیفت مستقل تعریف کنید و ورود، خروج، تأخیر، مرخصی و اضافه‌کار را دقیق و یکپارچه ببینید.</p>
            </article>

            <article class="feature-card">
                <div class="feature-icon" aria-hidden="true">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h5"></path></svg>
                </div>
                <h3>محاسبه کارکرد و حقوق با خروجی اکسل</h3>
                <p>کارکرد واقعی پرسنل به گزارش‌های مدیریتی و محاسبات حقوق و دستمزد تبدیل می‌شود و خروجی آماده اکسل، خطای دستی را از بین می‌برد.</p>
            </article>

            <article class="feature-card">
                <div class="feature-icon" aria-hidden="true">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4z"></path><path d="M22 2 11 13"></path></svg>
                </div>
                <h3>ربات تلگرام اطلاع‌رسانی لحظه‌ای</h3>
                <p>ورود و خروج پرسنل و گزارش‌های روزانه می‌تواند در لحظه برای مدیر ارسال شود تا بدون بازکردن پنل، از وضعیت مجموعه مطلع باشد.</p>
            </article>

            <article class="feature-card">
                <div class="feature-icon" aria-hidden="true">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path></svg>
                </div>
                <h3>امنیت حداکثری و غیرقابل دور زدن</h3>
                <p>توکن اختصاصی هر دستگاه، کنترل سطح دسترسی، جداسازی داده مشتریان و ثبت رویدادها، مسیرهای رایج ثبت غیرواقعی و دسترسی غیرمجاز را می‌بندد.</p>
            </article>
        </div>
    </section>

    <!-- ==============================================================
         Benefits / workflow
         ============================================================== -->
    <section class="section wrap" id="advantages" aria-labelledby="advantages-title">
        <div class="section-head align-start">
            <span class="eyebrow">مزایا و مسیر راه‌اندازی</span>
            <h2 id="advantages-title">از ثبت چهره تا گزارش حقوق، در سه گام</h2>
            <p>سرمد برای مجموعه‌هایی طراحی شده است که می‌خواهند بدون سرمایه‌گذاری سخت‌افزاری، سریع و دقیق شروع کنند.</p>
        </div>

        <div class="steps-grid">
            <article class="step-card" data-step="۰۱">
                <h3>ثبت پرسنل و چهره</h3>
                <p>مشخصات پرسنل، شعبه و شیفت در پنل ثبت می‌شود و نمونه چهره هر فرد برای شناسایی امن آماده می‌گردد.</p>
            </article>
            <article class="step-card" data-step="۰۲">
                <h3>اسکن با گوشی یا تبلت</h3>
                <p>دستگاه موجود مجموعه فعال می‌شود و پرسنل فقط با یک نگاه، ورود و خروج خود را آنلاین یا آفلاین ثبت می‌کنند.</p>
            </article>
            <article class="step-card" data-step="۰۳">
                <h3>گزارش و تصمیم دقیق</h3>
                <p>مدیر کارکرد، تأخیر، اضافه‌کار و غیبت را لحظه‌ای می‌بیند و در پایان دوره، خروجی اکسل و محاسبات حقوق آماده است.</p>
            </article>
        </div>
    </section>

    <!-- ==============================================================
         Official representation / franchise
         ============================================================== -->
    <section class="agency-panel wrap" id="agency" aria-labelledby="agency-title">
        <div class="agency-copy">
            <span class="eyebrow">فرصت همکاری رسمی</span>
            <h2 id="agency-title">اعطای نمایندگی رسمی و عاملیت فروش سرمد</h2>
            <p>از شرکت‌های نرم‌افزاری، متخصصان فناوری اطلاعات، مجریان سیستم‌های سازمانی و مجموعه‌های فعال در حوزه کلینیک و خدمات دعوت می‌کنیم برای اخذ نمایندگی فروش و پشتیبانی سرمد در سراسر کشور اقدام کنند.</p>

            <ul class="benefit-list" aria-label="مزایای نمایندگی">
                <li>سود مستمر ماهیانه از فروش، تمدید اشتراک و خدمات مشتریان</li>
                <li>پنل اختصاصی مدیریت مشتریان، دستگاه‌ها و پیگیری فرصت‌های فروش</li>
                <li>پشتیبانی جامع فنی ۲۴ ساعته، آموزش محصول و همراهی در راه‌اندازی مشتری</li>
            </ul>

            <div class="hero-actions agency-cta">
                <a class="btn btn-primary btn-large" href="#consult">ثبت درخواست نمایندگی</a>
                <a class="btn btn-ghost btn-large" href="#contact">گفت‌وگو با تیم سرمد</a>
            </div>
        </div>

        <aside class="conditions-card" aria-labelledby="conditions-title">
            <h3 id="conditions-title">شرایط همکاری</h3>
            <p>شرایط نهایی هر محدوده پس از بررسی ظرفیت بازار و توان اجرایی متقاضی در قرارداد رسمی مشخص می‌شود.</p>
            <ul class="condition-list">
                <li>توان معرفی، فروش و پشتیبانی محلی در محدوده فعالیت</li>
                <li>آشنایی با نصب نرم‌افزار، شبکه و آموزش کاربران یا برخورداری از تیم فنی</li>
                <li>تعهد به پاسخ‌گویی، محرمانگی داده‌ها و استانداردهای برند سرمد</li>
                <li>تکمیل ارزیابی، امضای قرارداد رسمی و گذراندن آموزش فعال‌سازی نمایندگی</li>
                <li>امکان بررسی انحصار محدوده پس از ارزیابی ظرفیت بازار و عملکرد همکاری</li>
            </ul>
        </aside>
    </section>

    <!-- ==============================================================
         Direct contact channels + consultation form
         ============================================================== -->
    <section class="section wrap" id="contact" aria-labelledby="contact-title">
        <div class="section-head">
            <span class="eyebrow">پل‌های ارتباطی مستقیم</span>
            <h2 id="contact-title">برای خرید، دمو یا اخذ نمایندگی در تماس باشید</h2>
            <p>سریع‌ترین مسیر را انتخاب کنید؛ یا فرم مشاوره را تکمیل کنید تا پیام شما آماده ارسال شود.</p>
        </div>

        <div class="contact-grid">
            <div class="channel-stack" aria-label="مسیرهای تماس">
                <a class="channel-card channel-phone" href="<?= $e($contact['phoneHref']) ?>">
                    <span class="channel-icon" aria-hidden="true">
                        <svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.08 4.18 2 2 0 0 1 4.06 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                    </span>
                    <span class="channel-copy">
                        <strong>تماس تلفنی مستقیم</strong>
                        <span>برای مشاوره فوری خرید و راه‌اندازی</span>
                        <?php if ($phoneDisplay !== ''): ?>
                            <span class="channel-value" dir="ltr"><?= $e($phoneDisplay) ?></span>
                        <?php else: ?>
                            <span class="channel-value">درخواست تماس از فرم مشاوره</span>
                        <?php endif; ?>
                    </span>
                    <span class="channel-arrow" aria-hidden="true">←</span>
                </a>

                <a class="channel-card channel-whatsapp" href="<?= $e($contact['whatsappHref']) ?>"<?= $hasWhatsapp ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <span class="channel-icon" aria-hidden="true">
                        <svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><path d="M8.5 10.5c.5 2.5 2.5 4.5 5 5l1.5-1.5 2 1c-.5 1.5-1.5 2-3 1.5-3.5-1-6.5-4-7.5-7.5C6 8 6.5 7 8 6.5l1 2z"></path></svg>
                    </span>
                    <span class="channel-copy">
                        <strong>ارتباط مستقیم در واتس‌اپ</strong>
                        <span>ارسال سریع درخواست دمو، خرید یا نمایندگی</span>
                        <span class="channel-value"><?= $hasWhatsapp ? 'شروع گفت‌وگوی واتس‌اپ' : 'ثبت درخواست گفت‌وگو' ?></span>
                    </span>
                    <span class="channel-arrow" aria-hidden="true">←</span>
                </a>

                <a class="channel-card channel-telegram" href="<?= $e($contact['telegramHref']) ?>"<?= $hasTelegram ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <span class="channel-icon" aria-hidden="true">
                        <svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4z"></path><path d="M22 2 11 13"></path></svg>
                    </span>
                    <span class="channel-copy">
                        <strong>ارتباط در تلگرام</strong>
                        <span>پیام مستقیم برای مشاوره و همکاری فروش</span>
                        <span class="channel-value"><?= $hasTelegram ? 'شروع گفت‌وگوی تلگرام' : 'ثبت درخواست پیام' ?></span>
                    </span>
                    <span class="channel-arrow" aria-hidden="true">←</span>
                </a>
            </div>

            <article class="consult-card" id="consult" aria-labelledby="consult-title">
                <h3 id="consult-title">فرم درخواست مشاوره و نمایندگی</h3>
                <p>مشخصات خود را وارد کنید؛ پیام درخواست برای ارسال از سریع‌ترین مسیر ارتباطی آماده می‌شود.</p>

                <form class="consult-form" id="consult-form" action="#consult" method="get"
                      data-whatsapp="<?= $e($contact['whatsappUrl']) ?>"
                      data-telegram="<?= $e($contact['telegramUrl']) ?>">
                    <div class="field">
                        <label for="consult-name">نام و نام خانوادگی *</label>
                        <input id="consult-name" name="name" type="text" autocomplete="name" placeholder="مثلاً علی رضایی" required>
                    </div>

                    <div class="field">
                        <label for="consult-mobile">شماره موبایل *</label>
                        <input id="consult-mobile" name="mobile" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="09xxxxxxxxx" required>
                    </div>

                    <div class="field">
                        <label for="consult-company">نام مجموعه یا شهر</label>
                        <input id="consult-company" name="company" type="text" autocomplete="organization" placeholder="نام کسب‌وکار یا شهر فعالیت">
                    </div>

                    <div class="field">
                        <label for="consult-subject">موضوع درخواست</label>
                        <select id="consult-subject" name="subject">
                            <option value="مشاوره خرید سامانه">مشاوره خرید سامانه</option>
                            <option value="اخذ نمایندگی رسمی">اخذ نمایندگی رسمی</option>
                            <option value="درخواست دمو">درخواست دمو</option>
                            <option value="پشتیبانی و راه‌اندازی">پشتیبانی و راه‌اندازی</option>
                        </select>
                    </div>

                    <div class="field field-full">
                        <label for="consult-message">توضیحات</label>
                        <textarea id="consult-message" name="message" placeholder="درباره تعداد پرسنل، شعب، شهر فعالیت یا نیاز مجموعه بنویسید…"></textarea>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn-primary btn-large" type="submit">ارسال درخواست</button>
                        <p class="form-note" id="form-note" role="status" aria-live="polite">پس از تکمیل فرم، پیام شما برای ارسال آماده می‌شود.</p>
                        <p class="privacy-note">این فرم داده‌ای را روی سرور ذخیره نمی‌کند؛ پیام در مرورگر شما آماده و از مسیر ارتباطی انتخابی ارسال می‌شود.</p>
                    </div>
                </form>
            </article>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="wrap footer-shell">
        <a class="brand" href="<?= $e($url('/')) ?>" aria-label="بازگشت به صفحه اصلی سرمد">
            <span class="brand-mark"><img src="<?= $e($asset('/icons/icon-192.png')) ?>" alt=""></span>
            <span class="brand-copy">
                <strong>سرمد</strong>
                <small>حضور و غیاب هوشمند با تشخیص چهره</small>
            </span>
        </a>
        <p>بدون دستگاه اختصاصی؛ سریع، ابری، آفلاین‌پذیر و آماده توسعه در سراسر کشور.</p>
        <nav class="footer-links" aria-label="پیوندهای فوتر">
            <a href="#features">ویژگی‌ها</a>
            <a href="#agency">نمایندگی</a>
            <a href="<?= $e($url('/login')) ?>">ورود به پنل</a>
            <a href="<?= $e($url('/device')) ?>">کیوسک دستگاه</a>
        </nav>
        <p class="copyright">© <span dir="ltr"><?= $e(date('Y')) ?></span> سامانه حضور و غیاب هوشمند سرمد؛ همه حقوق محفوظ است.</p>
    </div>
</footer>

<script src="<?= $e($asset('/js/landing.js')) ?>" defer></script>
</body>
</html>
