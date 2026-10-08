<?php

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

require_admin();

/*
|--------------------------------------------------------------------------
| Admin shared counters
|--------------------------------------------------------------------------
*/

$countCustom = (int) db()->query("
    SELECT COUNT(*)
    FROM custom_order_requests
    WHERE status = 'new'
")->fetchColumn();

$countMessages = (int) db()->query("
    SELECT COUNT(*)
    FROM contact_messages
    WHERE status = 'new'
")->fetchColumn();

$countPendingCustomers = (int) db()->query("
    SELECT COUNT(*)
    FROM companies
    WHERE status = 'pending'
")->fetchColumn();

$countPendingOrders = (int) db()->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status IN ('pending', 'processing')
")->fetchColumn();

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
        ];

        return $icons[$name] ?? '';
    }
}

/*
|--------------------------------------------------------------------------
| Menu structure
|--------------------------------------------------------------------------
*/

$menuGroups = [

    [
        'label' => null,
        'items' => [
            [
                'key'   => 'dashboard',
                'label' => 'داشبورد',
                'url'   => '/admin',
                'icon'  => 'dashboard',
            ],
        ],
    ],
    [
        'label' => 'تنظیمات فروش',
        'items' => [
            ['key' => 'shipping-settings', 'label' => 'ارسال و زمان‌بندی', 'url' => '/admin/shipping-settings', 'icon' => 'inventory'],
            ['key' => 'price-update',      'label' => 'تغییر گروهی قیمت',  'url' => '/admin/price-update',      'icon' => 'products'],
        ],
    ],

    [
        'label' => 'فروش',
        'items' => [
            [
                'key'   => 'orders',
                'label' => 'سفارش‌ها',
                'url'   => '/admin/orders',
                'icon'  => 'orders',
                'badge' => $countPendingOrders,
            ],
            [
                'key'   => 'payments',
                'label' => 'پرداخت‌ها',
                'url'   => '/admin/payments',
                'icon'  => 'payment',
            ],
        ],
    ],

    [
        'label' => 'کاتالوگ',
        'items' => [
            [
                'key'   => 'products',
                'label' => 'محصولات',
                'url'   => '/admin/products',
                'icon'  => 'products',
            ],
            [
                'key'   => 'categories',
                'label' => 'دسته‌بندی‌ها',
                'url'   => '/admin/categories',
                'icon'  => 'categories',
            ],
        ],
    ],

    [
        'label' => 'مشتریان',
        'items' => [
            [
                'key'   => 'customers',
                'label' => 'مشتریان',
                'url'   => '/admin/customers',
                'icon'  => 'customers',
                'badge' => $countPendingCustomers,
            ],
        ],
    ],

    [
        'label' => 'ارتباطات',
        'items' => [
            [
                'key'   => 'custom',
                'label' => 'سفارش اختصاصی',
                'url'   => '/admin/custom-orders',
                'icon'  => 'custom',
                'badge' => $countCustom,
            ],
            [
                'key'   => 'messages',
                'label' => 'پیام‌ها',
                'url'   => '/admin/messages',
                'icon'  => 'message',
                'badge' => $countMessages,
            ],
        ],
    ],
    [
        'label' => 'گزارشات',
        'items' => [
            ['key' => 'reports',          'label' => 'دفتر مالی',        'url' => '/admin/report-ledger',    'icon' => 'invoice'],
            ['key' => 'reports-inv',      'label' => 'موجودی و فروش',    'url' => '/admin/report-inventory', 'icon' => 'inventory'],
            ['key' => 'reports-discount', 'label' => 'گزارش تخفیف‌ها',   'url' => '/admin/report-discounts', 'icon' => 'invoice'],
            ['key' => 'reports-ship',     'label' => 'گزارش ارسال',      'url' => '/admin/report-shipping',  'icon' => 'invoice'],
            ['key' => 'price-update',     'label' => 'تغییر گروهی قیمت', 'url' => '/admin/price-update',     'icon' => 'products'],
        ],
    ],

    [
        'label' => 'بازاریابی',
        'items' => [
            [
                'key'   => 'banners',
                'label' => 'بنرها',
                'url'   => '/admin/banners',
                'icon'  => 'banner',
            ],
             [
                'key'   => 'instagram',
                'label' => 'گالری اینستاگرام',
                'url'   => '/admin/instagram',
                'icon'  => 'banner',
            ],
        ],
    ],
];

?>
<!doctype html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        <?= htmlspecialchars($adminPageTitle, ENT_QUOTES, 'UTF-8') ?>
        | پنل مدیریت
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="/assets/css/app.css?v=30">
    <link rel="stylesheet" href="/assets/css/admin.css?v=30">
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

                    <?php foreach ($menuGroups as $group): ?>

                        <?php if (!empty($group['label'])): ?>
                            <div class="admin-nav-group-label">
                                <?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <div class="admin-nav-group">

                            <?php foreach ($group['items'] as $item): ?>

                                <?php
                                $isActive = $activeMenu === $item['key'];
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

                <a href="/admin/logout" class="admin-sidebar-footer-link admin-sidebar-logout">

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


    <script src="/assets/js/admin.js?v=4"></script>

</body>

</html>