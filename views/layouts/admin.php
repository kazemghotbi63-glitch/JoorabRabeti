<?php

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

require_admin();

/*
|--------------------------------------------------------------------------
| Admin shared counters — هر خطای دیتابیس به‌جای صفحهٔ سفید، صفر برمی‌گرداند
|--------------------------------------------------------------------------
*/

$adminCount = function (string $sql): int {
    try {
        return (int) db()->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
};

$countCustom = $adminCount("SELECT COUNT(*) FROM custom_order_requests WHERE status = 'new'");

$countMessages = $adminCount("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");

$countPendingCustomers = $adminCount("SELECT COUNT(*) FROM companies WHERE status = 'pending'");

// ═══ اعداد تفکیک‌شده برای هر دپارتمان (جدید) ═══
$countFinancePending   = $adminCount("SELECT COUNT(*) FROM orders WHERE status = 'pending_review'");
$countWarehousePending = $adminCount("SELECT COUNT(*) FROM orders WHERE status = 'processing'");
$countShippingPending  = $adminCount("SELECT COUNT(*) FROM orders WHERE status = 'packed'");

unset($adminCount);

$adminPageTitle = $pageTitle ?? 'پنل مدیریت';
$activeMenu     = $activeMenu ?? '';
/*
|--------------------------------------------------------------------------
| SVG Icons
|--------------------------------------------------------------------------
*/

if (!function_exists('admin_icon')) {
    function admin_icon(string $name): string
    {
        $icons = [

            'dashboard' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                    <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                    <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                    <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
                </svg>
            ',

            'orders' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M7 3h10a2 2 0 0 1 2 2v16H5V5a2 2 0 0 1 2-2Z"></path>
                    <path d="M9 3v4h6V3"></path>
                    <path d="M8 11h8"></path>
                    <path d="M8 15h8"></path>
                    <path d="M8 19h5"></path>
                </svg>
            ',

            'payment' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                    <path d="M3 10h18"></path>
                    <path d="M7 15h4"></path>
                </svg>
            ',

            'invoice' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"></path>
                    <path d="M9 8h6"></path>
                    <path d="M9 12h6"></path>
                    <path d="M9 16h4"></path>
                </svg>
            ',

            'products' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"></path>
                    <path d="m4 7.5 8 4.5 8-4.5"></path>
                    <path d="M12 12v9"></path>
                </svg>
            ',

            'categories' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H10l2 2h6.5A1.5 1.5 0 0 1 20 7.5v11a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13Z"></path>
                </svg>
            ',

            'inventory' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 7h16"></path>
                    <path d="M6 7V5h12v2"></path>
                    <path d="M5 7h14l-1 13H6L5 7Z"></path>
                    <path d="M9 11v5"></path>
                    <path d="M15 11v5"></path>
                </svg>
            ',

            'customers' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="9" cy="8" r="3"></circle>
                    <path d="M3.5 20c.5-3.5 2.3-5.5 5.5-5.5s5 2 5.5 5.5"></path>
                    <path d="M16 5.5a3 3 0 0 1 0 5.8"></path>
                    <path d="M17 14.5c2.2.6 3.4 2.2 3.8 4.5"></path>
                </svg>
            ',

            'custom' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H12l-4.5 4v-4h-1A2.5 2.5 0 0 1 4 13.5v-8Z"></path>
                    <path d="M8 8h8"></path>
                    <path d="M8 11h5"></path>
                </svg>
            ',

            'message' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H12l-4.5 4v-4h-1A2.5 2.5 0 0 1 4 13.5v-8Z"></path>
                    <path d="M8 8h8"></path>
                    <path d="M8 11h5"></path>
                </svg>
            ',

            'banner' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                    <circle cx="8" cy="9" r="1.5"></circle>
                    <path d="m4.5 18 5-5 3 3 2.5-2.5 4.5 4.5"></path>
                </svg>
            ',

            'logout' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M10 5H5v14h5"></path>
                    <path d="M14 8l4 4-4 4"></path>
                    <path d="M8 12h10"></path>
                </svg>
            ',

            'external' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M14 5h5v5"></path>
                    <path d="m19 5-8 8"></path>
                    <path d="M19 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h4"></path>
                </svg>
            ',

            'settings' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"></path>
                </svg>
            ',

            'chevron' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M6 9l6 6 6-6"></path>
                </svg>
            ',
        ];

        return $icons[$name] ?? '';
    }
}

/*
|--------------------------------------------------------------------------
| Menu structure
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Menu structure — 8 collapsible sections (single-open accordion)
|--------------------------------------------------------------------------
*/
$menuGroups = [

    /* ── لینک ثابت بالای منو ── */
    [
        'type'  => 'link',
        'items' => [
            ['key' => 'dashboard', 'label' => 'داشبورد', 'url' => '/admin', 'icon' => 'dashboard'],
        ],
    ],

    /* ── ۱. سفارش‌ها و ارسال ── */
    [
        'type'  => 'section',
        'key'   => 'sec-orders',
        'label' => 'سفارش‌ها و ارسال',
        'icon'  => 'orders',
        'items' => [
            ['key' => 'orders',    'label' => 'سفارش‌ها (انبار)', 'url' => '/admin/orders',       'icon' => 'orders',    'badge' => $countWarehousePending],
            ['key' => 'shipping',  'label' => 'پنل ارسال',       'url' => '/admin/shipping',     'icon' => 'inventory', 'badge' => $countShippingPending],
            ['key' => 'shipments', 'label' => 'گزارش ارسال',     'url' => '/admin/shipments',    'icon' => 'invoice'],
            ['key' => 'custom',    'label' => 'سفارش اختصاصی',  'url' => '/admin/custom-orders', 'icon' => 'custom',     'badge' => $countCustom],
        ],
    ],
    /* ── ۲. مالی ── */
    [
        'type'  => 'section',
        'key'   => 'sec-finance',
        'label' => 'مالی',
        'icon'  => 'payment',
        'items' => [
            ['key' => 'payments',    'label' => 'پرداخت‌ها',         'url' => '/admin/payments',      'icon' => 'payment', 'badge' => $countFinancePending],
            ['key' => 'receivables', 'label' => 'مطالبات و سررسید', 'url' => '/admin/receivables',   'icon' => 'invoice'],
            ['key' => 'reports',     'label' => 'دفتر مالی',         'url' => '/admin/report-ledger', 'icon' => 'invoice'],
        ],
    ],

    /* ── ۳. کاتالوگ و انبار ── */
    [
        'type'  => 'section',
        'key'   => 'sec-catalog',
        'label' => 'کاتالوگ و انبار',
        'icon'  => 'products',
        'items' => [
            ['key' => 'products',               'label' => 'محصولات',          'url' => '/admin/products',           'icon' => 'products'],
            ['key' => 'categories',             'label' => 'دسته‌بندی‌ها',     'url' => '/admin/categories',         'icon' => 'categories'],
            ['key' => 'brand-settings',         'label' => 'تنظیمات برند',     'url' => '/admin/brand-settings',     'icon' => 'banner'],
            ['key' => 'inventory-movements',     'label' => 'سود موجودی',        'url' => '/admin/inventory-movements', 'icon' => 'inventory'],
            ['key' => 'inventory-stock-in',      'label' => 'ورود کالا (رسید)', 'url' => '/admin/stock-in',            'icon' => 'inventory'],
            ['key' => 'inventory-stock-adjust',  'label' => 'اصلاح موجودی',     'url' => '/admin/stock-adjust',       'icon' => 'inventory'],
            ['key' => 'reports-inv',             'label' => 'موجودی و فروش',    'url' => '/admin/report-inventory',   'icon' => 'inventory'],
        ],
    ],

    /* ── ۴. مشتریان و ارتباطات ── */
    [
        'type'  => 'section',
        'key'   => 'sec-customers',
        'label' => 'مشتریان و ارتباطات',
        'icon'  => 'customers',
        'items' => [
            ['key' => 'customers', 'label' => 'مشتریان', 'url' => '/admin/customers', 'icon' => 'customers', 'badge' => $countPendingCustomers],
            ['key' => 'messages',  'label' => 'پیام‌ها',  'url' => '/admin/messages',   'icon' => 'message',   'badge' => $countMessages],
        ],
    ],

    /* ── ۵. بازاریابی و تبلیغات ── */
    [
        'type'  => 'section',
        'key'   => 'sec-marketing',
        'label' => 'بازاریابی و تبلیغات',
        'icon'  => 'banner',
        'items' => [
            ['key' => 'banners',   'label' => 'بنرها',            'url' => '/admin/banners',   'icon' => 'banner'],
            ['key' => 'instagram', 'label' => 'گالری اینستاگرام', 'url' => '/admin/instagram', 'icon' => 'banner'],
        ],
    ],

    /* ── ۶. گزارش‌گیری ── */
    [
        'type'  => 'section',
        'key'   => 'sec-reports',
        'label' => 'گزارش‌گیری',
        'icon'  => 'invoice',
        'items' => [
            ['key' => 'reports-discount', 'label' => 'گزارش تخفیف‌ها', 'url' => '/admin/report-discounts', 'icon' => 'invoice'],
            ['key' => 'reports-ship',     'label' => 'گزارش ارسال',    'url' => '/admin/report-shipping',   'icon' => 'inventory'],
        ],
    ],

    /* ── ۷. تنظیمات سیستم ── */
    [
        'type'  => 'section',
        'key'   => 'sec-system',
        'label' => 'تنظیمات سیستم',
        'icon'  => 'settings',
        'items' => [
            ['key' => 'system-users',      'label' => 'کاربران ادمین',     'url' => '/admin/users',             'icon' => 'customers'],
            ['key' => 'shipping-settings', 'label' => 'ارسال و زمان‌بندی', 'url' => '/admin/shipping-settings', 'icon' => 'inventory'],
            ['key' => 'price-update',      'label' => 'تغییر گروهی قیمت',  'url' => '/admin/price-update',      'icon' => 'products'],
            ['key' => 'system-audit',       'label' => 'رویدادها',          'url' => '/admin/audit',             'icon' => 'invoice'],
            ['key' => 'system-backup',      'label' => 'پشتیبان‌گیری',      'url' => '/admin/backup',            'icon' => 'banner'],
            ['key' => 'profile',            'label' => 'پروفایل مدیر',     'url' => '/admin/profile',           'icon' => 'customers'],
        ],
    ],
];

?>
<!doctype html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <title>
        <?= htmlspecialchars($adminPageTitle, ENT_QUOTES, 'UTF-8') ?>
        | پنل مدیریت
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="/assets/css/admin.css?v=1012">

    <?php if (!empty($pageStyles)): ?>
        <!-- استایل‌های اختصاصی صفحه -->
        <?= $pageStyles ?>
    <?php endif; ?>

</head>

<body class="admin-body">

    <div class="admin-shell">

        <!-- =========================================================
         SIDEBAR
    ========================================================== -->

        <aside class="admin-sidebar" id="adminSidebar">

            <div class="admin-sidebar-head">

                <a href="/admin" class="admin-brand">

                    <span class="admin-brand-mark">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>

                    <span class="admin-brand-copy">
                        <strong>رابطی</strong>
                        <small>پنل مدیریت</small>
                    </span>

                </a>

            </div>


            <div class="admin-sidebar-scroll">

                <nav class="admin-nav" aria-label="منوی مدیریت">

                    <?php
                    // مسیر درخواست جاری (بدون query string) برای تشخیص آیتم فعال بر اساس URL
                    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
                    ?>

                    <?php foreach ($menuGroups as $group): ?>

                        <?php if (($group['type'] ?? 'section') === 'link'): ?>

                            <?php foreach ($group['items'] as $item):
                                $isActive = ($activeMenu === $item['key']) || ($requestPath === $item['url']);
                                $badge    = isset($item['badge']) ? (int)$item['badge'] : 0;
                            ?>

                                <a
                                    href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"
                                    class="admin-nav-link admin-nav-link--single<?= $isActive ? ' is-active' : '' ?>"
                                    <?= $isActive ? 'aria-current="page"' : '' ?>>

                                    <span class="admin-nav-icon">
                                        <?= admin_icon($item['icon']) ?>
                                    </span>

                                    <span class="admin-nav-text">
                                        <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>

                                    <?php if ($badge > 0): ?>
                                        <span class="admin-nav-badge">
                                            <?= $badge > 99 ? '99+' : $badge ?>
                                        </span>
                                    <?php endif; ?>

                                </a>

                            <?php endforeach; ?>

                        <?php continue;
                        endif; ?>

                        <?php
                        // بخش تاشو: آیا آیتم فعالی در این بخش وجود دارد؟
                        $sectionHasActive = false;
                        $sectionBadge     = 0;
                        foreach ($group['items'] as $item) {
                            if (($activeMenu === $item['key']) || ($requestPath === $item['url'])) {
                                $sectionHasActive = true;
                            }
                            if (isset($item['badge'])) {
                                $sectionBadge += (int)$item['badge'];
                            }
                        }
                        $sectionId = htmlspecialchars($group['key'], ENT_QUOTES, 'UTF-8');
                        ?>

                        <div class="admin-nav-section<?= $sectionHasActive ? ' is-open' : '' ?>" data-section>

                            <button
                                type="button"
                                class="admin-nav-section-head"
                                aria-expanded="<?= $sectionHasActive ? 'true' : 'false' ?>"
                                aria-controls="<?= $sectionId ?>"
                                data-section-toggle>

                                <span class="admin-nav-icon">
                                    <?= admin_icon($group['icon']) ?>
                                </span>

                                <span class="admin-nav-section-title">
                                    <?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?>
                                </span>

                                <?php if ($sectionBadge > 0): ?>
                                    <span class="admin-nav-section-badge">
                                        <?= $sectionBadge > 99 ? '99+' : $sectionBadge ?>
                                    </span>
                                <?php endif; ?>

                                <span class="admin-nav-chevron" aria-hidden="true">
                                    <?= admin_icon('chevron') ?>
                                </span>

                            </button>

                            <div class="admin-nav-section-body" id="<?= $sectionId ?>">
                                <div class="admin-nav-section-inner">

                                    <?php foreach ($group['items'] as $item):
                                        $isActive = ($activeMenu === $item['key']) || ($requestPath === $item['url']);
                                        $badge    = isset($item['badge']) ? (int)$item['badge'] : 0;
                                    ?>

                                        <a
                                            href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"
                                            class="admin-nav-link<?= $isActive ? ' is-active' : '' ?>"
                                            <?= $isActive ? 'aria-current="page"' : '' ?>>

                                            <span class="admin-nav-icon">
                                                <?= admin_icon($item['icon']) ?>
                                            </span>

                                            <span class="admin-nav-text">
                                                <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>

                                            <?php if ($badge > 0): ?>
                                                <span class="admin-nav-badge">
                                                    <?= $badge > 99 ? '99+' : $badge ?>
                                                </span>
                                            <?php endif; ?>

                                        </a>

                                    <?php endforeach; ?>

                                </div>
                            </div>

                        </div>

                    <?php endforeach; ?>

                </nav>

            </div>


            <div class="admin-sidebar-footer">

                <a href="/" class="admin-sidebar-footer-link" target="_blank" rel="noopener">

                    <span class="admin-nav-icon">
                        <?= admin_icon('external') ?>
                    </span>

                    <span>مشاهده سایت</span>

                </a>

                <a href="/admin/logout?t=<?= urlencode(logout_token()) ?>" class="admin-sidebar-footer-link admin-sidebar-logout">

                    <span class="admin-nav-icon">
                        <?= admin_icon('logout') ?>
                    </span>

                    <span>خروج از پنل</span>

                </a>

            </div>

        </aside>


        <!-- =========================================================
         MOBILE OVERLAY
    ========================================================== -->

        <div class="admin-overlay" id="adminOverlay"></div>


        <!-- =========================================================
         MAIN
    ========================================================== -->

        <div class="admin-main">

            <!-- TOPBAR -->

            <header class="admin-topbar">

                <div class="admin-topbar-right">

                    <button
                        type="button"
                        class="admin-burger"
                        id="adminBurger"
                        aria-label="باز کردن منو"
                        aria-controls="adminSidebar"
                        aria-expanded="false">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>

                    <div class="admin-topbar-title">

                        <div class="admin-topbar-brand">
                            پنل مدیریت
                        </div>

                        <span class="admin-topbar-separator">/</span>

                        <div class="admin-topbar-current">
                            <?= htmlspecialchars($adminPageTitle, ENT_QUOTES, 'UTF-8') ?>
                        </div>

                    </div>

                </div>


                <div class="admin-topbar-left">

                    <a href="/" class="admin-view-site" target="_blank" rel="noopener">
                        <?= admin_icon('external') ?>
                        <span>مشاهده سایت</span>
                    </a>

                    <div class="admin-user">

                        <div class="admin-user-avatar">
                            <?= htmlspecialchars(mb_substr((string)admin_name(), 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                        </div>

                        <div class="admin-user-info">

                            <strong>
                                <?= htmlspecialchars((string)admin_name(), ENT_QUOTES, 'UTF-8') ?>
                            </strong>

                            <span>مدیر سیستم</span>

                        </div>

                    </div>

                </div>

            </header>


            <!-- PAGE CONTENT -->

            <main class="admin-content">

                <?= $content ?? '' ?>

            </main>

        </div>

    </div>


    <script src="/assets/js/jalali.js?v=1012"></script>
    <script src="/assets/js/admin.js?v=1012"></script>

</body>

</html>