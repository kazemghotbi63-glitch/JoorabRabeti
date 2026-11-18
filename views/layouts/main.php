<?php
/* ════ RABETI-LAYOUT | v5 ════ */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once ROOT_PATH . '/config/settings.php';
require_once ROOT_PATH . '/app/services/PricingService.php';

$isAdmin    = is_admin_logged_in();
$isCustomer = is_customer_logged_in();
$isLoggedIn = $isAdmin || $isCustomer;

$siteLogo       = Setting::get('site_logo', '/Image/logo3.jpg');
$siteFavicon    = Setting::get('site_favicon', '/Image/logo3.jpg');
$ogDefaultImage = Setting::get('og_default_image', '/Image/logo3.jpg');
$siteUrl        = 'https://joorabrabeti.ir';

$userName = $isAdmin
    ? ($_SESSION['admin_name'] ?? 'مدیر')
    : ($_SESSION['customer_name'] ?? 'کاربر');

/* ─── گروه مشتری — فقط یک بار محاسبه می‌شود ─── */
$groupName  = null;
$groupClass = null;

if ($isCustomer && class_exists('PricingService')) {
    $groupData = PricingService::getGroup(db(), current_user_group());
    if ($groupData) {
        $groupName  = $groupData['name'];
        $groupClass = 'is-' . strtolower((string)current_user_group()); // is-gold / is-silver / is-bronze  
    }
}

/* ─── تعداد سبد خرید ─── */
$cartCount = 0;
if ($isLoggedIn) {
    try {
        $uid  = (int)($_SESSION['customer_id'] ?? 0);
        $stmt = db()->prepare("  
            SELECT COALESCE(SUM(ci.qty), 0)  
            FROM cart_items ci  
            JOIN carts c ON c.id = ci.cart_id  
            WHERE c.user_id = ? AND c.status = 'active'  
        ");
        $stmt->execute([$uid]);
        $cartCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('cart count: ' . $e->getMessage());
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
    <title>جوراب رابطی | تولیدکننده و فروش عمده جوراب</title>

    <meta name="description" content="جوراب رابطی، تولیدکننده و عرضه‌کننده انواع جوراب مردانه، زنانه و بچگانه. خرید عمده جوراب با قیمت تولیدی، تخفیف همکاری و سفارش مستقیم از کارخانه." />
    <meta name="author" content="RABETI">
    <meta name="theme-color" content="#004f53">
    <link rel="canonical" href="https://joorabrabeti.ir<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8') ?>">

    <!-- ═══ Open Graph ═══ -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="رابطی">
    <meta property="og:title" content="جوراب رابطی | تولیدکننده و فروش عمده جوراب" />
    <meta property="og:description" content="تولید و عرضه انواع جوراب مردانه، زنانه و بچگانه با قیمت همکاری و سفارش مستقیم از کارخانه." />
    <meta property="og:locale" content="fa_IR">
    <meta property="og:url" content="https://joorabrabeti.ir<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/', ENT_QUOTES, 'UTF-8') ?>">

    <meta property="og:image" content="<?= htmlspecialchars($siteUrl . $ogDefaultImage, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:image:alt" content="<?= htmlspecialchars($pageTitle ?? 'رابطی | فروش عمده جوراب', ENT_QUOTES, 'UTF-8') ?>">

    <!-- Twitter -->
    <meta name="twitter:image" content="<?= htmlspecialchars($siteUrl . $ogDefaultImage, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:image:alt" content="<?= htmlspecialchars($pageTitle ?? 'رابطی | فروش عمده جوراب', ENT_QUOTES, 'UTF-8') ?>">

    <!-- ═══ Favicon & Icons ═══ -->
    <link rel="icon" href="<?= htmlspecialchars($siteFavicon, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars($siteFavicon, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($siteFavicon, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="mask-icon" href="<?= htmlspecialchars($siteFavicon, ENT_QUOTES, 'UTF-8') ?>" color="#004f53">


    <link rel="stylesheet" href="/assets/css/app.css?v=10014">

    <?php
    // لود CSS اختصاصی صفحه تماس و درباره ما (فقط در همین دو صفحه)
    $__currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    if (in_array($__currentPage, ['contact.php', 'about.php'], true)): ?>
        <link rel="stylesheet" href="/assets/css/contact-about.css?v=2">
    <?php endif; ?>

    <?php foreach (($pageCss ?? []) as $pc): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($pc, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>

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
                        <a href="/admin" class="header-account-btn">پنل مدیریت</a>
                        <a href="/admin/logout?t=<?= urlencode(logout_token()) ?>" class="header-account-logout">خروج</a>
                    <?php elseif ($isCustomer): ?>
                        <?php
                        // ⭐ دریافت اطلاعات گروه مشتری برای نمایش در هدر
                        $headerUserGroupCode = strtolower((string)current_user_group());
                        $headerGroupColor = '#8b7355';

                        if (class_exists('PricingService')) {
                            $groupData = PricingService::getGroup(db(), current_user_group());
                            if ($groupData) {
                                $headerGroupName = $groupData['name'];
                                if ($headerUserGroupCode === 'gold') $headerGroupColor = '#d4a017';
                                elseif ($headerUserGroupCode === 'silver') $headerGroupColor = '#9ca3af';
                                elseif ($headerUserGroupCode === 'bronze') $headerGroupColor = '#b87333';
                            }
                        }
                        ?>
                        <div class="header-user-vip" style="display: flex; align-items: center; gap: 8px;">
                            <span class="header-account-name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>


                        </div>
                        <a href="/api/logout?t=<?= urlencode(logout_token()) ?>" class="header-account-logout">خروج</a>
                    <?php else: ?>
                        <button type="button" class="r-btn r-btn-outline auth-trigger" data-auth-open="login">
                            ورود / ثبت‌نام
                        </button>
                    <?php endif; ?>
                </div>

            </div>

        </div>

    </header>


    <!-- ══════════ کشوی موبایل رابطی — Cream & Gold ══════════ -->

    <div class="overlay" id="overlay"></div>

    <aside class="drawer r6-drawer" id="drawer"
        aria-label="منوی اصلی"
        aria-hidden="true">

        <!-- هدر منو: مشترک بین مدیر، مشتری و مهمان -->
        <div class="drawer-user-box">

            <?php if ($isLoggedIn): ?>

                <!-- هدر مدیر یا مشتری -->
                <div class="r6-user-info">

                    <strong class="r6-user-name">

                        <span><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>

                        <?php if ($isAdmin): ?>
                            <span class="r6-role-badge r6-admin-badge">
                                مدیر
                            </span>
                        <?php else: ?>
                            <?php if ($isCustomer && !empty($groupName)): ?>
                                <?php
                                $safeGroupClass = preg_replace(
                                    '/[^a-zA-Z0-9_-]/',
                                    '',
                                    (string)($groupClass ?? '')
                                );
                                ?>
                                <span class="r6-group-badge  <?= htmlspecialchars($safeGroupClass, ENT_QUOTES, 'UTF-8') ?>"> مشتری سطح
                                    <?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>

                    </strong>

                </div>

            <?php else: ?>

                <!-- هدر اختصاصی مهمان -->
                <a href="/" class="r6-drawer-brand" aria-label="صفحه اصلی رابطی">

                    <span class="r6-brand-name">رابطی</span>
                    <span class="r6-brand-en">RABETI</span>

                </a>

            <?php endif; ?>

            <!-- دکمه بستن: کوچک و سمت چپ — برای همه کاربران (مهمان و لاگین) -->
            <button type="button"
                class="drawer-close"
                id="closeBtn"
                aria-label="بستن منو">

                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>

            </button>

        </div>


        <!-- ناوبری منو -->
        <nav class="drawer-nav" aria-label="دسترسی‌های اصلی">

            <?php if ($isAdmin): ?>

                <a href="/admin" class="r6-drawer-link">
                    <span class="r6-link-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="7" rx="1" />
                            <rect x="14" y="3" width="7" height="7" rx="1" />
                            <rect x="3" y="14" width="7" height="7" rx="1" />
                            <rect x="14" y="14" width="7" height="7" rx="1" />
                        </svg>
                    </span>
                    <span class="r6-link-text">پنل مدیریت</span>
                </a>

            <?php elseif ($isCustomer): ?>

                <a href="/account-orders" class="r6-drawer-link">
                    <span class="r6-link-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M6 7V6a6 6 0 0112 0v1h2.2l1.4 12.6A2 2 0 0119.6 22H4.4a2 2 0 01-2-2.4L3.8 7H6z" />
                        </svg>
                    </span>
                    <span class="r6-link-text">سفارش‌های من</span>
                </a>

            <?php endif; ?>


            <!-- محصولات -->
            <a href="/products" class="r6-drawer-link">
                <span class="r6-link-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 2 3 7v10l9 5 9-5V7l-9-5Z" />
                        <path d="M12 22V12M3 7l9 5 9-5" />
                    </svg>
                </span>
                <span class="r6-link-text">محصولات</span>
            </a>


            <!-- پرفروش‌ها -->
            <a href="/products?sort=best" class="r6-drawer-link">
                <span class="r6-link-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="m12 3 2.7 5.6 6.1.8-4.5 4.3 1.1 6.1-5.4-2.9-5.4 2.9 1.1-6.1-4.5-4.3 6.1-.8L12 3z" />
                    </svg>
                </span>
                <span class="r6-link-text">پرفروش‌ها</span>
            </a>


            <!-- جدیدها -->
            <a href="/#new" class="r6-drawer-link">
                <span class="r6-link-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 3v18M3 12h18" />
                    </svg>
                </span>
                <span class="r6-link-text">جدیدها</span>
            </a>


            <!-- سفارش اختصاصی -->
            <a href="/custom-order" class="r6-drawer-link">
                <span class="r6-link-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                    </svg>
                </span>
                <span class="r6-link-text">سفارش اختصاصی</span>
            </a>


            <!-- تماس با ما -->
            <a href="/contact" class="r6-drawer-link">
                <span class="r6-link-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z" />
                    </svg>
                </span>
                <span class="r6-link-text">تماس با ما</span>
            </a>


            <!-- درباره رابطی -->
            <a href="/about" class="r6-drawer-link">
                <span class="r6-link-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M12 8h.01M11 12h1v5h1" />
                    </svg>
                </span>
                <span class="r6-link-text">درباره رابطی</span>
            </a>

        </nav>


        <!-- فوتر ثابت منو -->
        <div class="drawer-foot">

            <?php if ($isLoggedIn): ?>

                <a href="<?= $isAdmin
                                ? '/admin/logout?t=' . urlencode(logout_token())
                                : '/api/logout?t=' . urlencode(logout_token()) ?>"
                    class="r6-logout-link">

                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
                    </svg>

                    خروج از حساب

                </a>

            <?php else: ?>

                <button type="button"
                    class="r6-logout-link r6-login-link auth-trigger"
                    data-auth-open="login">

                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3" />
                    </svg>

                    ورود / ثبت‌نام

                </button>

            <?php endif; ?>

        </div>

    </aside>



    <!-- ══════════ محتوا ══════════ -->

    <main class="container page-shell">
        <?= $content ?? '' ?>
    </main>


    <div class="footer-line"></div>

    <!-- ══════════ فوتر ══════════ -->
    <?php if (empty($hideFooter)): ?>
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
                                <a href="/contact">ارتباط با ما</a>

                            </div>

                        </div>

                    </div>
                    <!-- ═══ نسخه فشرده و لاکچری فوتر ═══ -->
                    <div class="r6-footer-contact" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; margin-top: 20px;">

                        <!-- کارخانه -->
                        <div style="background: #fff; border-radius: 14px; padding: 16px 18px; border: 1px solid #eee; box-shadow: 0 2px 12px rgba(0,0,0,0.04);">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                <span style="width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, #e8f5e9, #c8e6c9); color: #2e7d32; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2 20h20M4 20V8l6 4V8l6 4V4h4v16" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 20v-4h2v4M15 20v-4h2v4" />
                                    </svg>
                                </span>
                                <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1a1a1a;">کارخانه</h4>
                            </div>
                            <p style="margin: 0 0 6px; font-size: 13px; line-height: 1.6; color: #555;">
                                بازار حاج قاسم، کوچه علیرضا زمان، پلاک ۱۸
                            </p>
                            <a href="tel:02155890915" style="font-size: 13.5px; color: #2e7d32; text-decoration: none; font-weight: 600; direction: ltr; display: inline-block;">
                                ۰۲۱-۵۵۸۹۰۹۱۵
                            </a>
                        </div>

                        <!-- دفتر فروش -->
                        <div style="background: #fff; border-radius: 14px; padding: 16px 18px; border: 1px solid #eee; box-shadow: 0 2px 12px rgba(0,0,0,0.04);">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                <span style="width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, #e3f2fd, #bbdefb); color: #1565c0; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01" />
                                    </svg>
                                </span>
                                <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1a1a1a;">دفتر فروش</h4>
                            </div>
                            <p style="margin: 0 0 6px; font-size: 13px; line-height: 1.6; color: #555;">
                                بازار حاج قاسم، کوچه چراغ برق، کوچه حلم‌زاده، پلاک ۱۶، واحد ۱
                            </p>
                            <a href="tel:09120380974" style="font-size: 13.5px; color: #1565c0; text-decoration: none; font-weight: 600; direction: ltr; display: inline-block;">
                                ۰۹۱۲-۰۳۸-۰۹۷۴
                            </a>
                        </div>

                        <!-- فروشگاه -->
                        <div style="background: #fff; border-radius: 14px; padding: 16px 18px; border: 1px solid #eee; box-shadow: 0 2px 12px rgba(0,0,0,0.04);">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                <span style="width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, #fff3e0, #ffe0b2); color: #e65100; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 22V12h6v10" />
                                    </svg>
                                </span>
                                <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #1a1a1a;">فروشگاه</h4>
                            </div>
                            <p style="margin: 0 0 6px; font-size: 13px; line-height: 1.6; color: #555;">
                                بازار بزرگ تهران — بازار عباس‌آباد، سرای لطفی‌پور، پلاک ۲۲
                            </p>
                            <a href="tel:09197693149" style="font-size: 13.5px; color: #e65100; text-decoration: none; font-weight: 600; direction: ltr; display: inline-block;">
                                ۰۹۱۹-۷۶۹-۳۱۴۹
                            </a>
                        </div>

                    </div>

                    <!-- کپی‌رایت -->
                    <div class="r6-footer-bottom">
                        <span>&copy;طراحی و سئو <b><i> Thurnder_Web ⚡ </i></b></span>
                    </div>

                </div>

            </div>
        </footer>
    <?php endif; ?>

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
                    <h3 class="auth-pane-title">ورود به حساب</h3>

                    <div class="form-error" id="loginError" style="display:none;"></div>

                    <div class="form-field">
                        <label for="loginMobile">کدملی / شماره موبایل (EN)</label>
                        <input type="tel" name="mobile" id="loginMobile" dir="ltr"
                            inputmode="numeric" autocomplete="tel"
                            placeholder="09121234567" required>
                    </div>
                    <div class="form-field">
                        <label for="loginPassword">رمز عبور (EN)</label>
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
                            autocomplete="new-password" placeholder="حداقل ۴ کاراکتر" required>
                    </div>

                    <div class="form-field">
                        <label>تکرار رمز عبور <i>*</i></label>
                        <input type="password" name="password2" dir="ltr"
                            autocomplete="new-password" required>
                    </div>

                </form>

                <!-- دکمه ثبت — جدا از فرم اصلی، همیشه پایینِ دید -->
                <form method="post" id="registerSubmitForm" novalidate>
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
                            placeholder="حداقل ۴ کاراکتر" required>
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


    <nav class="mobile-bottom-nav" aria-label="دسترسی سریع">

        <a href="/" class="mbn-item <?= (basename($_SERVER['PHP_SELF']) === 'index.php' ? 'is-active' : '') ?>">
            <span class="mbn-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1V9.5z" />
                </svg>
            </span>
            <span class="mbn-label">خانه</span>
        </a>


        <a href="/cart" class="mbn-item <?= (basename($_SERVER['PHP_SELF']) === 'cart.php' ? 'is-active' : '') ?>">
            <span class="mbn-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="9" cy="20" r="1.5" />
                    <circle cx="18" cy="20" r="1.5" />
                    <path d="M3 3h2l2.7 12.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 8H6" />
                </svg>
                <span class="badge" id="cartBadgeBottom" <?= $cartCount ? '' : 'style="display:none;"' ?>><?= $cartCount ?></span>
            </span>
            <span class="mbn-label">سبد خرید</span>
        </a>


        <a href="/products" class="mbn-item <?= (basename($_SERVER['PHP_SELF']) === 'products.php' ? 'is-active' : '') ?>">
            <span class="mbn-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7" rx="1" />
                    <rect x="14" y="3" width="7" height="7" rx="1" />
                    <rect x="3" y="14" width="7" height="7" rx="1" />
                    <rect x="14" y="14" width="7" height="7" rx="1" />
                </svg>
            </span>
            <span class="mbn-label">دسته‌بندی</span>
        </a>


        <a href="/account-orders" class="mbn-item <?= (basename($_SERVER['PHP_SELF']) === 'account-orders.php' ? 'is-active' : '') ?>">
            <span class="mbn-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="8" r="4" />
                    <path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" />
                </svg>
            </span>
            <span class="mbn-label"> سفارشات من</span>
        </a>

        <!-- دکمه کانال‌های ارتباطی -->
        <a href="javascript:void(0)"
            class="mbn-item mbn-item-contact"
            id="socialToggle"
            role="button"
            aria-expanded="false"
            aria-label="کانال‌های ارتباطی"
            onclick="toggleSocialPopup(event)">
            <span class="mbn-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z" />
                </svg>
            </span>
            <span class="mbn-label">کانال‌های ارتباطی</span>
        </a>

    </nav>

    <!-- منوی شبکه‌های اجتماعی (بالای نوار) -->
    <div class="social-popup" id="socialPopup" aria-hidden="true" inert>
        <a href="https://wa.me/989197693149" target="_blank" rel="noopener" class="social-popup-btn whatsapp" aria-label="واتساپ">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z" />
            </svg>
        </a>

        <a href="https://t.me/+989197693149" target="_blank" rel="noopener" class="social-popup-btn telegram" aria-label="تلگرام">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
                <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z" />
            </svg>
        </a>

        <a href="https://ble.ir/09197693149" target="_blank" rel="noopener" class="social-popup-btn bale" aria-label="بله">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
                <path d="M12 2C6.477 2 2 6.145 2 11.259c0 2.898 1.478 5.479 3.786 7.16V22l3.464-1.898c.87.24 1.79.37 2.75.37 5.523 0 10-4.146 10-9.259C22 6.145 17.523 2 12 2Zm4.794 7.137-3.878 6.156c-.229.363-.712.45-1.053.187l-2.847-2.204-2.027 1.558c-.302.232-.71.013-.71-.354V9.813c0-.41.474-.637.79-.376l4.086 3.372 2.213-3.513c.244-.387.83-.35 1.023.06l.003.008c.087.185.086.397-.007.573Z" />
            </svg>
        </a>
    </div>

    <script>
        /* ⭐ FIX: باز شدن کانال‌های ارتباطی در همه صفحات (از جمله صفحه اصلی) */
        (function() {
            function toggleSocialPopup(e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }

                const popup = document.getElementById('socialPopup');
                const toggle = document.getElementById('socialToggle');

                if (!popup || !toggle) return;

                const isOpen = popup.classList.contains('is-open') || popup.getAttribute('aria-hidden') === 'false';

                if (isOpen) {
                    popup.classList.remove('is-open');
                    popup.setAttribute('aria-hidden', 'true');
                    popup.setAttribute('inert', '');
                    toggle.setAttribute('aria-expanded', 'false');
                } else {
                    popup.classList.add('is-open');
                    popup.setAttribute('aria-hidden', 'false');
                    popup.removeAttribute('inert');
                    toggle.setAttribute('aria-expanded', 'true');
                }
            }

            // در دسترس قرار دادن برای onclick
            window.toggleSocialPopup = toggleSocialPopup;

            // کلیک روی خود پاپ‌آپ یا بیرون آن برای بستن
            document.addEventListener('click', function(e) {
                const popup = document.getElementById('socialPopup');
                const toggle = document.getElementById('socialToggle');

                if (!popup || !toggle) return;

                if (popup.classList.contains('is-open') &&
                    !popup.contains(e.target) &&
                    !toggle.contains(e.target)) {
                    toggleSocialPopup();
                }
            });

            // پشتیبانی از دکمه Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const popup = document.getElementById('socialPopup');
                    if (popup && popup.classList.contains('is-open')) {
                        toggleSocialPopup();
                    }
                }
            });
        })();
    </script>

    <script src="/assets/js/jalali.js" defer></script>

    <script>
        window.RABETI_AUTH = <?= $isLoggedIn ? 'true' : 'false' ?>;
    </script>

    <script src="/assets/js/app.js?v=1008" defer></script>

</body>

</html>