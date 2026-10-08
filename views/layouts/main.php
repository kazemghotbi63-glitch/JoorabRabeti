<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

$isAdmin    = is_admin_logged_in();
$isCustomer = is_customer_logged_in();
$isLoggedIn = $isAdmin || $isCustomer;

$userName = $isAdmin
    ? ($_SESSION['admin_name'] ?? 'مدیر')
    : ($_SESSION['customer_name'] ?? 'کاربر');
?>
<?php
$cartCount = 0;
if (is_logged_in()) {
    try {
        $uid = (int)($_SESSION['admin_id'] ?? $_SESSION['customer_id'] ?? 0);
        $stmt = db()->prepare("
            SELECT COALESCE(SUM(ci.qty), 0)
            FROM cart_items ci
            JOIN carts c ON c.id = ci.cart_id
            WHERE c.user_id = ? AND c.status = 'active'
        ");
        $stmt->execute([$uid]);
        $cartCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <!-- ═══ SEO ═══ -->
    <title>
        <?= htmlspecialchars($pageTitle ?? 'رابطی | فروش عمده جوراب', ENT_QUOTES, 'UTF-8') ?>
    </title>
    <script>
        /* ⭐ جلوگیری از اسکرول خودکار مرورگر */
        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }

        /* اگه اسکرول ذخیره‌شده داریم، قبل از رندر مخفی کن */
        (function() {
            try {
                if (sessionStorage.getItem('__scroll_resume')) {
                    document.documentElement.classList.add('scroll-resume');
                }
            } catch (e) {}
        })();
    </script>

    <style>
        /* پنهان‌سازی صفحه تا اسکرول بازیابی شه — فقط چند میلی‌ثانیه */
        html.scroll-resume body {
            visibility: hidden !important;
        }
    </style>


    <meta name="description" content="<?= htmlspecialchars($pageDescription ?? 'فروشگاه تخصصی خرید عمده جوراب رابطی — تولید مستقیم، قیمت همکاری، موجودی واقعی و سفارش سریع', ENT_QUOTES, 'UTF-8') ?>">
    <meta name="author" content="RABETI">
    <meta name="theme-color" content="#004f53">
    <?php
    /* canonical بدون query string؛ صفحه‌ها می‌توانند $canonicalPath را خودشان تعیین کنند */
    if (!isset($canonicalPath)) {
        $canonicalPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $canonicalPath = rtrim((string)preg_replace('~(/index)?\.php$~', '', $canonicalPath), '/');
        $canonicalPath = $canonicalPath === '' ? '/' : $canonicalPath;
    }
    $canonicalUrl = SITE_URL . $canonicalPath;
    ?>
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
    <?php if (!empty($metaRobots)): ?>
        <meta name="robots" content="<?= htmlspecialchars($metaRobots, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <!-- ═══ Open Graph ═══ -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="رابطی">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle ?? 'رابطی | فروش عمده جوراب', ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDescription ?? 'فروشگاه تخصصی خرید عمده جوراب رابطی', ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image" content="https://jorabrabeti.ir/Image/logo3.jpg">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:alt" content="رابطی | فروش عمده جوراب">

    <!-- ═══ Twitter ═══ -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle ?? 'رابطی | فروش عمده جوراب', ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDescription ?? 'فروشگاه تخصصی خرید عمده جوراب رابطی', ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:image" content="https://jorabrabeti.ir/Image/logo3.jpg">
    <meta name="twitter:image:alt" content="رابطی | فروش عمده جوراب">

    <!-- ═══ Favicon & Icons ═══ -->
    <link rel="icon" type="image/jpeg" href="/Image/logo3.jpg">
    <link rel="shortcut icon" type="image/jpeg" href="/Image/logo3.jpg">
    <link rel="apple-touch-icon" href="/Image/logo3.jpg">
    <link rel="mask-icon" href="/Image/logo3.jpg" color="#004f53">

    <?php /* داده ساختاریافته — صفحه‌ها آرایه‌ای از اسکیماها را در $jsonLd می‌گذارند */ ?>
    <?php foreach ((array)($jsonLd ?? []) as $ld): ?>
        <script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php endforeach; ?>

    <link rel="stylesheet" href="/assets/css/app.css?v=49">
</head>

<body class="app-body">

    <!-- ══════════ نوار اعلان ══════════ -->

    <div class="r6-announce">
        ارسال رایگان برای سفارش‌های بالای ۱۰ میلیون تومان
    </div>

    <!-- ══════════ هدر ══════════ -->

    <header
        class="header"
        id="siteHeader">

        <div class="header-inner">

            <div class="header-right">

                <button
                    type="button"
                    class="hamburger"
                    id="menuBtn"
                    aria-label="باز کردن منو">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>

                <a
                    href="/"
                    class="brand r6-brand">
                    <span class="r6-logo">رابطی</span>
                    <span class="r6-logo-en">RABETI</span>
                </a>

            </div>

            <div class="header-left">

                <a href="/cart" class="header-action-btn" title="سبد خرید" aria-label="سبد خرید">
                    <svg
                        width="19"
                        height="19"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        viewBox="0 0 24 24">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M6 7V6a6 6 0 1112 0v1h2.2l1.4 12.6A2 2 0 0119.6 22H4.4a2 2 0 01-2-2.4L3.8 7H6zm2 0h8V6a4 4 0 10-8 0v1z" />
                    </svg>

                    <span class="badge" id="cartBadge" <?= $cartCount ? '' : 'style="display:none;"' ?>><?= $cartCount ?></span>
                </a>

                <div class="header-account">

                    <?php if ($isAdmin): ?>

                        <a
                            href="/admin"
                            class="header-account-btn">
                            پنل مدیریت
                        </a>

                        <a
                            href="/admin/logout"
                            class="header-account-logout">
                            خروج
                        </a>

                    <?php elseif ($isCustomer): ?>

                        <span class="header-account-name">
                            <?= htmlspecialchars(
                                $userName,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </span>

                        <a
                            href="/api/logout"
                            class="header-account-logout">
                            خروج
                        </a>

                    <?php else: ?>

                        <button
                            type="button"
                            class="r-btn r-btn-outline auth-trigger"
                            data-auth-open="login">
                            ورود / ثبت‌نام
                        </button>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </header>

    <!-- ══════════ کشوی موبایل ══════════ -->

    <div
        class="overlay"
        id="overlay"></div>

    <aside
        class="drawer r6-drawer"
        id="drawer">

        <div class="drawer-head">

            <button
                type="button"
                class="drawer-close"
                id="closeBtn"
                aria-label="بستن">
                ×
            </button>

            <a
                href="/"
                class="brand r6-brand">
                <span class="r6-logo">رابطی</span>
                <span class="r6-logo-en">RABETI</span>
            </a>

        </div>

        <?php if ($isLoggedIn): ?>

            <div class="drawer-user-box">

                <span>
                    <?= $isAdmin
                        ? 'حساب مدیریت'
                        : 'حساب شما' ?>
                </span>

                <strong>
                    <?= htmlspecialchars(
                        $userName,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </strong>

            </div>
            <?php if ($isCustomer): ?>
                <a href="/account-orders" class="r6-drawer-link">سفارش‌های من</a>
            <?php endif; ?>

        <?php endif; ?>

        <nav class="drawer-nav">

            <?php if ($isAdmin): ?>

                <a
                    href="/admin"
                    class="r6-drawer-link">
                    پنل مدیریت
                </a>

            <?php endif; ?>

            <a
                href="/products"
                class="r6-drawer-link">
                محصولات
            </a>

            <a
                href="/products"
                class="r6-drawer-link">
                پرفروش‌ها
            </a>

            <a
                href="/products"
                class="r6-drawer-link">
                جدیدها
            </a>

            <a
                href="/custom-order"
                class="r6-drawer-link">
                سفارش اختصاصی
            </a>


            <a
                href="/contact"
                class="r6-drawer-link">
                راهنمای سفارش
            </a>

            <a
                href="/about"
                class="r6-drawer-link">
                درباره رابطی
            </a>

            <?php if ($isLoggedIn): ?>

                <a
                    href="<?= $isAdmin
                                ? '/admin/logout'
                                : '/api/logout' ?>"
                    class="r6-drawer-link">
                    خروج از حساب
                </a>

            <?php endif; ?>

        </nav>

    </aside>

    <!-- ══════════ محتوا ══════════ -->

    <main class="container page-shell">
        <?= $content ?? '' ?>
    </main>

    <!-- ══════════ فوتر ══════════ -->

    <footer class="r6-footer">
        <div class="container">

            <div class="r6-footer-box">

                <div class="r6-footer-top">

                    <!-- برند -->
                    <div class="r6-footer-brand">
                        <div class="r6-footer-logo">
                            <span class="r6-logo">رابطی</span>
                            <span class="r6-logo-en">RABETI</span>
                        </div>
                        <p>
                            تولید و پخش عمده جوراب با قیمت همکاری،
                            موجودی واقعی و ارسال سریع به سراسر ایران.
                        </p>
                        <div class="r6-footer-trust">
                            <span>✓ تولید مستقیم</span>
                            <span>✓ قیمت همکاری</span>
                            <span>✓ ارسال سریع</span>
                        </div>
                    </div>

                    <!-- ستون‌های لینک -->
                    <div class="r6-footer-cols">

                        <div class="r6-footer-col">
                            <b>خرید</b>
                            <a href="/products">محصولات</a>
                            <a href="/custom-order">سفارش اختصاصی</a>
                        </div>

                        <div class="r6-footer-col">
                            <b>راهنما</b>
                            <a href="/contact">شرایط سفارش</a>
                            <a href="/contact">ارسال و پرداخت</a>
                        </div>

                        <div class="r6-footer-col">
                            <b>رابطی</b>
                            <a href="/about">درباره ما</a>
                            <!-- <a href="/contact">تماس با ما</a> -->
                        </div>

                    </div>

                </div>

                <!-- ═══ اطلاعات تماس — دکمه‌ای، بدون اکشن ═══ -->
                <div class="r6-footer-contact">

                    <div class="r6-footer-contact-item">
                        <span class="r6-footer-contact-icon">
                            <svg width="20" height="20" fill="none" stroke="currentColor"
                                stroke-width="1.6" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                            </svg>
                        </span>

                        <span class="r6-footer-contact-body">
                            <small>آدرس فروشگاه</small>
                            <span>بازار بزرگ تهران — بازار عباس‌آباد، سرای لطفی‌پور، پلاک ۲۲</span>
                        </span>
                    </div>

                    <div class="r6-footer-contact-item">
                        <span class="r6-footer-contact-icon">
                            <svg width="20" height="20" fill="none" stroke="currentColor"
                                stroke-width="1.6" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
                            </svg>
                        </span>

                        <span class="r6-footer-contact-body">
                            <small>موبایل / واتساپ</small>
                            <span dir="ltr" class="r6-footer-contact-num">۰۹۱۹ ۷۶۹ ۳۱۴۹</span>
                        </span>
                    </div>

                </div>

                <!-- کپی‌رایت -->
                <div class="r6-footer-bottom">
                    <span>&copy;طراحی و سئو <b><i>FIREWEB 🔥  </i></b></span>
                </div>

            </div>

        </div>
    </footer>

    <!-- ══════════ ابزارها ══════════ -->

    <!-- <button
        class="back-to-top"
        id="backToTop"
        aria-label="بازگشت به بالا">
        ↑
    </button>

    <div
        class="toast"
        id="toast"></div> -->

    <!-- ══════════ نوار پایین موبایل ══════════ -->
    <!-- 
    <nav
        class="bottom-nav"
        aria-label="ناوبری اصلی">

        <a href="/" class="bn-item">
            <span>خانه</span>
        </a>

        <a href="/products" class="bn-item">
            <span>دسته‌بندی</span>
        </a>

        <a href="/products" class="bn-item">
            <span>جستجو</span>
        </a>

        <a href="/custom-order" class="bn-item">
            <span>سفارش</span>
        </a>

        <?php if ($isAdmin): ?>

            <a
                href="/admin"
                class="bn-item">
                <span>پنل</span>
            </a>

        <?php elseif ($isCustomer): ?>

            <a
                href="/api/logout"
                class="bn-item">
                <span>خروج</span>
            </a>

        <?php else: ?>

            <button
                type="button"
                class="bn-item"
                data-auth-open="login">
                <span>حساب</span>
            </button>

        <?php endif; ?>

    </nav> -->
    <!-- ═══ Modal ورود / ثبت‌نام / فراموشی رمز ═══ -->
    <!-- ═══ Modal ورود / ثبت‌نام / فراموشی رمز ═══ -->
    <div class="auth-modal-overlay" id="authModalOverlay">
        <div class="auth-modal" role="dialog" aria-modal="true">
            <button class="auth-modal-close" id="authModalClose" aria-label="بستن">×</button>

            <!-- ═══ تب‌های اصلی — بیرون نماها، همیشه بالای مودال ═══ -->
            <div class="auth-tabs" id="authTabsBox">
                <button type="button" class="auth-tab active" data-tab="login">ورود</button>
                <button type="button" class="auth-tab" data-tab="register">ثبت‌نام</button>
                <span class="auth-tab-slider" id="authTabSlider"></span>
            </div>

            <!-- ═══ نما: ورود ═══ -->
            <div class="auth-view active" id="view-login">
                <form class="auth-pane active" id="pane-login" method="post">
                    <?= csrf_field() ?>
                    <h3 class="auth-pane-title">ورود به حساب</h3>

                    <div class="form-error" id="loginError" style="display:none;"></div>

                    <div class="form-field">
                        <label for="loginMobile">شماره موبایل</label>
                        <input type="tel" name="mobile" id="loginMobile" dir="ltr"
                            inputmode="numeric" autocomplete="tel"
                            placeholder="09121234567" required>
                    </div>
                    <div class="form-field">
                        <label for="loginPassword">رمز عبور</label>
                        <input type="password" name="password" id="loginPassword" dir="ltr"
                            autocomplete="current-password" required>
                    </div>

                    <button type="button" class="auth-forgot-link" id="forgotLink">
                        فراموشی رمز عبور؟
                    </button>

                    <button type="submit" class="btn btn-primary co-submit" id="loginBtn">ورود</button>
                </form>
            </div>

            <!-- ═══ نما: ثبت‌نام ═══ -->
            <div class="auth-view" id="view-register">

                <!-- نوع حساب: حقیقی / حقوقی -->
                <div class="auth-tabs auth-tabs-sm" style="margin:0 0 14px;">
                    <button type="button" class="auth-tab active" data-utype="real">شخص حقیقی</button>
                    <button type="button" class="auth-tab" data-utype="legal">شخص حقوقی</button>
                    <span class="auth-tab-slider" id="utypeSlider"></span>
                </div>

                <form class="auth-pane active" id="pane-register" method="post" novalidate>
                    <?= csrf_field() ?>
                    <h3 class="auth-pane-title">ثبت‌نام عمده‌فروشی</h3>

                    <div class="form-error" id="registerError" style="display:none;"></div>

                    <div class="form-field">
                        <label>نام و نام خانوادگی <i>*</i></label>
                        <input type="text" name="full_name" autocomplete="name" required>
                    </div>

                    <!-- فیلدهای حقیقی -->
                    <div class="utype-real">
                        <div class="form-field">
                            <label>کد ملی <i>*</i></label>
                            <input type="text" name="national_code" dir="ltr"
                                inputmode="numeric" maxlength="10" placeholder="کد ملی ۱۰ رقمی">
                        </div>
                        <div class="form-field">
                            <label>جنسیت <i>*</i></label>
                            <div style="display:flex;gap:14px;">
                                <label style="display:flex;align-items:center;gap:5px;font-weight:400;">
                                    <input type="radio" name="gender" value="male" checked> آقا
                                </label>
                                <label style="display:flex;align-items:center;gap:5px;font-weight:400;">
                                    <input type="radio" name="gender" value="female"> خانم
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- فیلدهای حقوقی -->
                    <div class="utype-legal" style="display:none;">

                        <div class="form-field">
                            <label>نام مغازه / شرکت</label>
                            <input type="text" name="company_name" autocomplete="organization">
                        </div>
                        <div class="form-field">
                            <label>شناسه اقتصادی <i>*</i></label>
                            <input type="text" name="economic_code" dir="ltr"
                                inputmode="numeric" maxlength="12"
                                placeholder="۱۰ تا ۱۲ رقم">
                        </div>
                    </div>

                    <div class="form-field">
                        <label>شماره موبایل <i>*</i></label>
                        <input type="tel" name="mobile" dir="ltr" inputmode="numeric"
                            autocomplete="tel" placeholder="09121234567" required>
                    </div>

                    <!-- <div class="form-field">
                        <label>ایمیل</label>
                        <input type="email" name="email" dir="ltr" autocomplete="email">
                    </div> -->

                    <div class="form-field">
                        <label>رمز عبور <i>*</i></label>
                        <input type="password" name="password" dir="ltr"
                            autocomplete="new-password" placeholder="حداقل ۸ کاراکتر" required>
                    </div>

                    <div class="form-field">
                        <label>تکرار رمز عبور <i>*</i></label>
                        <input type="password" name="password2" dir="ltr"
                            autocomplete="new-password" required>
                    </div>

                </form>

                <!-- دکمه ثبت — جدا از فرم اصلی، همیشه پایینِ دید -->
                <form method="post" id="registerSubmitForm" novalidate>
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary co-submit" id="registerBtn">
                        ایجاد حساب
                    </button>
                </form>

            </div>

            <!-- ═══ نما: فراموشی رمز ═══ -->
            <div class="auth-view" id="view-forgot">

                <!-- مرحله ۱: انتخاب روش -->
                <div id="forgot-step-1">
                    <h3 class="auth-pane-title">بازیابی رمز عبور</h3>
                    <p class="auth-pane-note">روش دریافت کد بازیابی را انتخاب کنید.</p>

                    <div class="form-error" id="forgotError" style="display:none;"></div>

                    <div class="forgot-channel-grid">
                        <button type="button" class="forgot-channel" data-channel="sms">
                            <span class="fc-icon">📱</span>
                            <b>ارسال به موبایل</b>
                            <small>کد پیامکی</small>
                        </button>
                        <!-- <button type="button" class="forgot-channel" data-channel="email">
                            <span class="fc-icon">📧</span>
                            <b>ارسال به ایمیل</b>
                            <small>کد ایمیلی</small>
                        </button> -->
                    </div>
                </div>

                <!-- مرحله ۲: ورود شماره/ایمیل -->
                <div id="forgot-step-2" style="display:none;">
                    <button type="button" class="auth-back" id="forgotBack1">→</button>
                    <h3 class="auth-pane-title" id="forgotTargetTitle">ارسال کد بازیابی</h3>

                    <div class="form-field">
                        <label id="forgotInputLabel">شماره موبایل</label>
                        <input type="text" id="forgotTarget" dir="ltr" required>
                    </div>

                    <button type="button" class="btn btn-primary co-submit" id="forgotSendBtn">
                        ارسال کد بازیابی
                    </button>
                </div>

                <!-- مرحله ۳: کد + رمز جدید -->
                <div id="forgot-step-3" style="display:none;">
                    <button type="button" class="auth-back" id="forgotBack2">→</button>
                    <h3 class="auth-pane-title">ثبت کد و رمز جدید</h3>

                    <div class="form-error" id="forgotError2" style="display:none;"></div>

                    <div class="form-field">
                        <label>کد ۶ رقمی ارسال‌شده</label>
                        <input type="text" id="forgotCode" dir="ltr" inputmode="numeric"
                            maxlength="6" placeholder="------" required>
                    </div>
                    <div class="form-field">
                        <label>رمز عبور جدید</label>
                        <input type="password" id="forgotNewPass" dir="ltr"
                            placeholder="حداقل ۸ کاراکتر" required>
                    </div>
                    <div class="form-field">
                        <label>تکرار رمز عبور جدید</label>
                        <input type="password" id="forgotNewPass2" dir="ltr" required>
                    </div>

                    <button type="button" class="btn btn-primary co-submit" id="forgotResetBtn">
                        تغییر رمز عبور
                    </button>
                </div>

                <!-- مرحله ۴: موفقیت -->
                <div id="forgot-step-4" style="display:none;">
                    <div class="payment-result payment-result-success">
                        <div class="payment-result-icon">✓</div>
                        <div>
                            <h2>رمز عبور تغییر کرد</h2>
                            <p>حالا با رمز جدید وارد شوید.</p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary co-submit" id="forgotDoneBtn">
                        رفتن به ورود
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- ══════════ دکمهٔ شناور سبد خرید ══════════ -->
    <a href="/cart" class="cart-fix" aria-label="سبد خرید">
        <svg width="22" height="22" fill="none" stroke="currentColor"
            stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
        </svg>
        <span class="badge" id="cartBadgeFloat" <?= $cartCount ? '' : 'style="display:none;"' ?>><?= $cartCount ?></span>
    </a>

    <!-- ══════════ ویجت ارتباط سریع ══════════ -->
    <ul class="social-fix" id="socialFix">

        <li>
            <a href="https://wa.me/989197693149"
                target="_blank" rel="noopener"
                class="social-fix-btn whatsapp"
                aria-label="واتساپ">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z" />
                </svg>
            </a>
        </li>

        <li>
            <a href="https://t.me/+989197693149"
                target="_blank" rel="noopener"
                class="social-fix-btn telegram"
                aria-label="تلگرام">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                    <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z" />
                </svg>
            </a>
        </li>

        <li>
            <a href="https://ble.ir/09197693149"
                target="_blank" rel="noopener"
                class="social-fix-btn bale"
                aria-label="بله">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                    <path d="M12 2C6.477 2 2 6.145 2 11.259c0 2.898 1.478 5.479 3.786 7.16V22l3.464-1.898c.87.24 1.79.37 2.75.37 5.523 0 10-4.146 10-9.259C22 6.145 17.523 2 12 2Zm4.794 7.137-3.878 6.156c-.229.363-.712.45-1.053.187l-2.847-2.204-2.027 1.558c-.302.232-.71.013-.71-.354V9.813c0-.41.474-.637.79-.376l4.086 3.372 2.213-3.513c.244-.387.83-.35 1.023.06l.003.008c.087.185.086.397-.007.573Z" />
                </svg>
            </a>
        </li>

    </ul>

    <script src="/assets/js/app.js?v=25"></script>
    <script>
        /* ⭐ بازیابی اسکرول — بدون هیچ پرشی */
        (function() {
            var y = 0;
            try {
                var v = sessionStorage.getItem('__scroll_resume');
                if (v) {
                    y = parseInt(v, 10) || 0;
                    sessionStorage.removeItem('__scroll_resume');
                }
            } catch (e) {}

            var root = document.documentElement;

            if (y <= 0) {
                root.classList.remove('scroll-resume');
                return;
            }

            var released = false;

            function tryRestore() {
                window.scrollTo(0, y);
            }

            function release() {
                if (released) return;
                released = true;
                tryRestore();
                root.classList.remove('scroll-resume');
            }

            /* چند بار در طول رندر تلاش کن تا layout ثابت شه */
            tryRestore();

            requestAnimationFrame(function() {
                tryRestore();
                requestAnimationFrame(function() {
                    tryRestore();
                });
            });

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', tryRestore);
            }

            if (document.readyState !== 'complete') {
                window.addEventListener('load', release);
            } else {
                release();
            }

            /* safety — حداکثر نیم ثانیه صفحه مخفی می‌مونه */
            setTimeout(release, 500);
        })();
    </script>

</body>

</html>