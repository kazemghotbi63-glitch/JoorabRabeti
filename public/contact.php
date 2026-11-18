<?php
/* =========================================================  
   RABETI — CONTACT PAGE  
   Luxury B2B design | RTL | PHP  
========================================================= */

/* Bootstrap */
$__bp = [
    __DIR__ . '/../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../config/bootstrap.php',
];

foreach ($__bp as $__b) {
    if (is_file($__b)) {
        require_once $__b;
        break;
    }
}
unset($__bp, $__b);

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

/* Helpers */
$e = static fn($value) =>
htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$normalizeDigits = static function ($value) {
    return strtr(trim((string)$value), [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
    ]);
};

$errors = [];
$saved  = false;

$old = [
    'name'    => '',
    'company' => '',
    'mobile'  => '',
    'subject' => '',
    'message' => '',
];

/* Form processing */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $old['name']    = trim($_POST['name'] ?? '');
    $old['company'] = trim($_POST['company'] ?? '');
    $old['mobile']  = $normalizeDigits($_POST['mobile'] ?? '');
    $old['subject'] = trim($_POST['subject'] ?? '');
    $old['message'] = trim($_POST['message'] ?? '');

    if (mb_strlen($old['name']) < 3) {
        $errors['name'] = 'نام و نام خانوادگی را کامل وارد کنید.';
    }

    if (!preg_match('/^09\d{9}$/', $old['mobile'])) {
        $errors['mobile'] = 'شماره موبایل معتبر نیست.';
    }

    if (mb_strlen($old['message']) < 10) {
        $errors['message'] = 'پیام شما باید حداقل ۱۰ کاراکتر باشد.';
    }

    if (mb_strlen($old['subject']) > 150) {
        $errors['subject'] = 'موضوع پیام بیش از حد طولانی است.';
    }

    if (mb_strlen($old['company']) > 150) {
        $errors['company'] = 'نام فروشگاه/شرکت بیش از حد طولانی است.';
    }

    if (!$errors) {
        $message = $old['message'];
        if ($old['company'] !== '') {
            $message = '[فروشگاه/شرکت: ' . $old['company'] . "]\n" . $message;
        }

        $stmt = db()->prepare("  
            INSERT INTO contact_messages  
                (name, mobile, subject, message)  
            VALUES (?, ?, ?, ?)  
        ");

        $stmt->execute([
            $old['name'],
            $old['mobile'],
            $old['subject'] ?: null,
            $message
        ]);

        $saved = true;
    }
}

$pageTitle = 'ارتباط با رابطی | تماس با ما';

ob_start();
?>

<link rel="stylesheet" href="/assets/css/contact-about.css">

<div class="rbt-page-wrapper rbt-contact-page" dir="rtl">

    <!-- ================= HERO ================= -->
    <section class="rbt-hero">
        <div class="rbt-hero-pattern" aria-hidden="true"></div>
        <div class="rbt-hero-glow" aria-hidden="true"></div>

        <div class="rbt-hero-content">
            <div class="rbt-eyebrow">
                <span class="rbt-eyebrow-dot"></span>
                ارتباط مستقیم با تولیدی جوراب رابطی
            </div>

            <h1>
                شریک تجاری شما،
                <span>همیشه در دسترس.</span>
            </h1>

            <p>
                برای همکاری عمده، دریافت کاتالوگ و لیست قیمت تولیدی،
                مشاوره خرید یا پیگیری سفارش، با تیم رابطی در ارتباط باشید.
            </p>

            <div class="rbt-hero-bottom">
                <span class="rbt-hero-line"></span>
                <span>همراه شما از تولید تا فروش</span>
            </div>
        </div>

        <div class="rbt-hero-decoration" aria-hidden="true">
            <span class="rbt-decoration-circle circle-one"></span>
            <span class="rbt-decoration-circle circle-two"></span>
            <span class="rbt-decoration-word">R</span>
        </div>

        <div class="rbt-hero-index" aria-hidden="true">RABETI / CONTACT</div>
    </section>


    <!-- ================= B2B STRIP ================= -->
    <section class="rbt-b2b-strip" aria-label="مزیت‌های همکاری عمده">
        <div class="rbt-b2b-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M3 21V9l6 3V9l6 3V5l6 3v13H3Z" />
                <path d="M7 16h2m3 0h2m3 0h2" />
            </svg>
            <div><strong>قیمت مستقیم تولیدی</strong><small>بدون واسطه، از خط تولید</small></div>
        </div>
        <div class="rbt-b2b-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M20 7H4a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V8a1 1 0 0 0-1-1Z" />
                <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18" />
            </svg>
            <div><strong>سفارش عمده و سازمانی</strong><small>تیراژ بالا، بسته‌بندی اختصاصی</small></div>
        </div>
        <div class="rbt-b2b-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M3 7h11v10H3zM14 10h4l3 3v4h-7z" />
                <circle cx="7" cy="17" r="2" />
                <circle cx="17" cy="17" r="2" />
            </svg>
            <div><strong>ارسال سراسری</strong><small>به سراسر کشور با باربری معتبر</small></div>
        </div>
        <div class="rbt-b2b-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z" />
                <path d="M9 8h6M9 12h6" />
            </svg>
            <div><strong>صدور فاکتور رسمی</strong><small>مناسب فروشگاه‌ها و شرکت‌ها</small></div>
        </div>
    </section>


    <!-- ================= MAIN CONTACT ================= -->
    <section class="rbt-main">

        <!-- FORM -->
        <div class="rbt-form-panel">

            <?php if ($saved): ?>

                <div class="rbt-success">
                    <div class="rbt-success-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="m5 12 4 4L19 6" />
                            <circle cx="12" cy="12" r="10" />
                        </svg>
                    </div>

                    <span class="rbt-form-kicker">پیام شما ثبت شد</span>
                    <h2>از اعتمادتان سپاسگزاریم.</h2>
                    <p>
                        پیام شما با موفقیت دریافت شد.
                        کارشناسان فروش رابطی در اولین فرصت با شما تماس خواهند گرفت.
                    </p>

                    <a href="/" class="rbt-primary-btn">
                        بازگشت به فروشگاه
                        <span>←</span>
                    </a>
                </div>

            <?php else: ?>

                <div class="rbt-form-heading">
                    <div>
                        <span class="rbt-form-kicker">SEND US A MESSAGE</span>
                        <h2>درخواست همکاری یا پیامتان را بنویسید.</h2>
                        <p>اطلاعات زیر را وارد کنید تا در اولین فرصت با شما ارتباط بگیریم.</p>
                    </div>
                    <span class="rbt-form-number">01</span>
                </div>

                <?php if ($errors): ?>
                    <div class="rbt-alert" role="alert">
                        <span>!</span>
                        لطفاً اطلاعات مشخص‌شده را اصلاح کنید.
                    </div>
                <?php endif; ?>

                <form method="post" action="/contact" class="rbt-form">

                    <?= csrf_field() ?>

                    <div class="rbt-fields-two">

                        <div class="rbt-field">
                            <label for="rbt-name">نام و نام خانوادگی <span>*</span></label>
                            <input id="rbt-name" type="text" name="name" autocomplete="name"
                                value="<?= $e($old['name']) ?>"
                                placeholder="نام خود را وارد کنید"
                                class="<?= isset($errors['name']) ? 'is-invalid' : '' ?>" required>
                            <?php if (isset($errors['name'])): ?>
                                <small><?= $e($errors['name']) ?></small>
                            <?php endif; ?>
                        </div>

                        <div class="rbt-field">
                            <label for="rbt-mobile">شماره موبایل <span>*</span></label>
                            <input id="rbt-mobile" type="tel" name="mobile" inputmode="tel" dir="ltr"
                                autocomplete="tel"
                                value="<?= $e($old['mobile']) ?>"
                                placeholder="0912 123 4567"
                                class="<?= isset($errors['mobile']) ? 'is-invalid' : '' ?>" required>
                            <?php if (isset($errors['mobile'])): ?>
                                <small><?= $e($errors['mobile']) ?></small>
                            <?php endif; ?>
                        </div>

                    </div>

                    <div class="rbt-fields-two">

                        <div class="rbt-field">
                            <label for="rbt-company">نام فروشگاه / شرکت <span class="optional">اختیاری</span></label>
                            <input id="rbt-company" type="text" name="company" autocomplete="organization"
                                value="<?= $e($old['company']) ?>"
                                placeholder="مثلاً فروشگاه جوراب آریا"
                                class="<?= isset($errors['company']) ? 'is-invalid' : '' ?>">
                            <?php if (isset($errors['company'])): ?>
                                <small><?= $e($errors['company']) ?></small>
                            <?php endif; ?>
                        </div>

                        <div class="rbt-field">
                            <label for="rbt-subject">موضوع پیام <span class="optional">اختیاری</span></label>
                            <input id="rbt-subject" type="text" name="subject"
                                value="<?= $e($old['subject']) ?>"
                                placeholder="مثلاً همکاری و خرید عمده"
                                class="<?= isset($errors['subject']) ? 'is-invalid' : '' ?>">
                            <?php if (isset($errors['subject'])): ?>
                                <small><?= $e($errors['subject']) ?></small>
                            <?php endif; ?>
                        </div>

                    </div>

                    <div class="rbt-field">
                        <label for="rbt-message">متن پیام <span>*</span></label>
                        <textarea id="rbt-message" name="message" rows="5"
                            placeholder="چطور می‌توانیم به شما کمک کنیم؟"
                            class="<?= isset($errors['message']) ? 'is-invalid' : '' ?>"
                            required><?= $e($old['message']) ?></textarea>
                        <?php if (isset($errors['message'])): ?>
                            <small><?= $e($errors['message']) ?></small>
                        <?php endif; ?>
                    </div>

                    <div class="rbt-form-footer">
                        <span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                <rect x="5" y="10" width="14" height="11" rx="2" />
                                <path d="M8 10V7a4 4 0 0 1 8 0v3" />
                            </svg>
                            اطلاعات شما نزد رابطی محفوظ است.
                        </span>

                        <button type="submit" class="rbt-primary-btn">
                            <span>ارسال پیام</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M7 17 17 7M7 7h10v10" />
                            </svg>
                        </button>
                    </div>

                </form>

            <?php endif; ?>

        </div>


        <!-- QUICK CONTACT -->
        <aside class="rbt-side-panel">

            <div class="rbt-side-top">
                <span class="rbt-side-kicker">DIRECT CONTACT</span>
                <h3>راه‌های ارتباطی</h3>
                <p>اگر ترجیح می‌دهید مستقیم صحبت کنیم، از راه‌های زیر با ما در تماس باشید.</p>
            </div>

            <a href="tel:02155890915" class="rbt-quick-link">
                <span class="rbt-quick-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3 5.5A2.5 2.5 0 0 1 5.5 3h2A1.5 1.5 0 0 1 9 4.5v2a1.5 1.5 0 0 1-1.5 1.5H7a12 12 0 0 0 8 8v-.5a1.5 1.5 0 0 1 1.5-1.5h2a1.5 1.5 0 0 1 1.5 1.5v2A2.5 2.5 0 0 1 17.5 20h-1A14.5 14.5 0 0 1 3 6.5z" />
                    </svg>
                </span>
                <span class="rbt-quick-text">
                    <small>تلفن دفتر</small>
                    <strong dir="ltr">۰۲۱-۵۵۸۹۰۹۱۵</strong>
                </span>
                <span class="rbt-quick-arrow">←</span>
            </a>

            <a href="https://wa.me/989197693149" target="_blank" rel="noopener" class="rbt-quick-link">
                <span class="rbt-quick-icon whatsapp-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path d="M20 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20l1.2-4.2A8.5 8.5 0 1 1 20 11.5Z" />
                        <path d="M8 8.5c.5 2.5 2.5 4.5 5 5l1-1 2 1c-.5 1.5-1.5 2-3 1.5-3-1-5-3-6-6-.5-1.5 0-2.5 1.5-3l1 2-1.5.5Z" />
                    </svg>
                </span>
                <span class="rbt-quick-text">
                    <small>واتساپ فروشگاه</small>
                    <strong dir="ltr">۰۹۱۹۷۶۹۳۱۴۹</strong>
                </span>
                <span class="rbt-quick-arrow">←</span>
            </a>

            <div class="rbt-side-divider"></div>

            <div class="rbt-working">
                <div class="rbt-working-heading">
                    <span class="rbt-status-dot"></span>
                    <strong>ساعت پاسخگویی</strong>
                </div>
                <div class="rbt-working-row">
                    <span>شنبه تا پنجشنبه</span>
                    <strong>۹ تا ۱۷</strong>
                </div>
                <div class="rbt-working-row">
                    <span>جمعه</span>
                    <strong class="rbt-closed">تعطیل</strong>
                </div>
            </div>

            <div class="rbt-side-note">
                <span>R</span>
                <p>رابطی؛ ارتباطی مطمئن<br>از تولید تا بازار</p>
            </div>

        </aside>

    </section>


    <!-- ================= LOCATIONS ================= -->
    <section class="rbt-locations">

        <div class="rbt-section-heading">
            <div>
                <span class="rbt-form-kicker">OUR LOCATIONS</span>
                <h2>در کنار شما، در سه موقعیت</h2>
                <p>برای مراجعه حضوری، هر کدام از مراکز رابطی را که به شما نزدیک‌تر است انتخاب کنید.</p>
            </div>
            <span class="rbt-location-count">03 / LOCATIONS</span>
        </div>

        <div class="rbt-location-grid">

            <!-- FACTORY -->
            <article class="rbt-location-card">
                <div class="rbt-location-top">
                    <span class="rbt-location-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path d="M3 21V9l6 3V9l6 3V5l6 3v13H3Z" />
                            <path d="M7 16h2m3 0h2m3 0h2M7 21v-3h3v3" />
                        </svg>
                    </span>
                    <span class="rbt-location-number">01</span>
                </div>
                <span class="rbt-location-label">PRODUCTION</span>
                <h3>کارخانه</h3>
                <p class="rbt-location-address">بازار حاج قاسم، کوچه علیرضا زمان، پلاک ۱۸</p>
                <a href="tel:02155890915" class="rbt-location-phone" dir="ltr">۰۲۱-۵۵۸۹۰۹۱۵</a>
                <div class="rbt-map-actions">
                    <a href="https://nshn.ir/?lat=35.669357&lng=51.420973" target="_blank" rel="noopener">مسیریابی نشان <span>↗</span></a>
                    <a href="https://balad.ir/search?q=%D8%A8%D8%A7%D8%B2%D8%A7%D8%B1+%D8%AD%D8%A7%D8%AC+%D9%82%D8%A7%D8%B3%D9%85+%DA%A9%D9%88%DA%86%D9%87+%D8%B9%D9%84%DB%8C%D8%B1%D8%B6%D8%A7+%D8%B2%D9%85%D8%A7%D9%86+%D9%BE%D9%84%D8%A7%DA%A9+%DB%B1%DB%B8" target="_blank" rel="noopener">بلد <span>↗</span></a>
                </div>
            </article>

            <!-- SALES OFFICE -->
            <article class="rbt-location-card">
                <div class="rbt-location-top">
                    <span class="rbt-location-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path d="M3 21h18M5 21V7l7-4 7 4v14" />
                            <path d="M9 9h.01M15 9h.01M9 13h.01M15 13h.01M10 21v-4h4v4" />
                        </svg>
                    </span>
                    <span class="rbt-location-number">02</span>
                </div>
                <span class="rbt-location-label">SALES OFFICE</span>
                <h3>دفتر فروش</h3>
                <p class="rbt-location-address">بازار حاج قاسم، کوچه چراغ برق، کوچه حلم‌زاده، پلاک ۱۶، واحد ۱</p>
                <a href="https://wa.me/989120380974" target="_blank" rel="noopener" class="rbt-location-phone" dir="ltr">۰۹۱۲-۰۳۸-۰۹۷۴</a>
                <div class="rbt-map-actions">
                    <a href="https://nshn.ir/?lat=35.668965&lng=51.418391" target="_blank" rel="noopener">مسیریابی نشان <span>↗</span></a>
                    <a href="https://balad.ir/search?q=%D8%A8%D8%A7%D8%B2%D8%A7%D8%B1+%D8%AD%D8%A7%D8%AC+%D9%82%D8%A7%D8%B3%D9%85+%DA%A9%D9%88%DA%86%D9%87+%DA%86%D8%B1%D8%A7%D8%BA+%D8%A8%D8%B1%D9%82+%DA%A9%D9%88%DA%86%D9%87+%D8%AD%D9%84%D9%85+%D8%B2%D8%A7%D8%AF%D9%87+%D9%BE%D9%84%D8%A7%DA%A9+%DB%B1%DB%B6" target="_blank" rel="noopener">بلد <span>↗</span></a>
                </div>
            </article>

            <!-- STORE -->
            <article class="rbt-location-card">
                <div class="rbt-location-top">
                    <span class="rbt-location-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                            <path d="M3 10h18l-1.5-6h-15L3 10Z" />
                            <path d="M5 10v10h14V10M9 20v-6h6v6" />
                            <path d="M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0" />
                        </svg>
                    </span>
                    <span class="rbt-location-number">03</span>
                </div>
                <span class="rbt-location-label">RETAIL STORE</span>
                <h3>فروشگاه</h3>
                <p class="rbt-location-address">بازار بزرگ تهران، بازار عباس‌آباد، سرای لطفی‌پور، پلاک ۲۲</p>
                <a href="https://wa.me/989197693149" target="_blank" rel="noopener" class="rbt-location-phone" dir="ltr">۰۹۱۹-۷۶۹-۳۱۴۹</a>
                <div class="rbt-map-actions">
                    <a href="https://nshn.ir/?lat=35.670865&lng=51.419637" target="_blank" rel="noopener">مسیریابی نشان <span>↗</span></a>
                    <a href="https://balad.ir/search?q=%D8%A8%D8%A7%D8%B2%D8%A7%D8%B1+%D8%B9%D8%A8%D8%A7%D8%B3+%D8%A2%D8%A8%D8%A7%D8%AF+%D8%B3%D8%B1%D8%A7%DB%8C+%D9%84%D8%B7%D9%81%DB%8C+%D9%BE%D9%88%D8%B1+%D9%BE%D9%84%D8%A7%DA%A9+%DB%B2%DB%B2" target="_blank" rel="noopener">بلد <span>↗</span></a>
                </div>
            </article>

        </div>

    </section>


    <!-- ================= BOTTOM CTA ================= -->
    <section class="rbt-bottom-cta">
        <div>
            <span>RABETI / YOUR PARTNER</span>
            <h2>برای یک همکاری خوب، از همین‌جا شروع کنیم.</h2>
        </div>
        <a href="https://wa.me/989197693149" target="_blank" rel="noopener">
            گفتگو در واتساپ
            <span>↗</span>
        </a>
    </section>

</div>

<?php
$content = ob_get_clean();
$hideFooter = true;
require ROOT_PATH . '/views/layouts/main.php';
?>