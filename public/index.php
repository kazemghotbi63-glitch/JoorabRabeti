<?php
/* ════ RABETI-FIX | نسخه: v5 | 2026-10-22 | جدیدترین نسخه (LATEST) ════ */

/* Auto-detect bootstrap (local + host) */
define('RABETI_THUMB_OFF', true);   // ⭐ عکس‌ها مستقیم لود می‌شن، thumb.php دور زده می‌شه
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
require_once ROOT_PATH . '/config/settings.php';


$h = static fn($v) => htmlspecialchars(
    (string)$v,
    ENT_QUOTES,
    'UTF-8'
);

$categories = db()->query("
    SELECT *
    FROM categories
    WHERE is_active = 1
    ORDER BY sort_order
")->fetchAll();

$heroBanners = db()->query("
    SELECT
        id,
        title,
        subtitle,
        image_path,
        mobile_image_path,
        link,
        sort_order
    FROM banners
    WHERE position = 'hero'
      AND is_active = 1
    ORDER BY sort_order ASC, id ASC
")->fetchAll();


/* ═══════════════════════════════════════════════
   دسته‌بندی‌ها — درختی (والد + زیرمجموعه)
   ═══════════════════════════════════════════════ */
$numFa = static function ($value, int $decimals = 0): string {
    $formatted = number_format((float)$value, $decimals, '.', ',');
    return strtr($formatted, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬', '.' => '٫']);
};
$moneyFa = static function ($amount, int $decimals = 0) use ($numFa): string {
    return $numFa($amount, $decimals) . ' تومان';
};

$parentCategories = db()->query("
    SELECT id, name, slug, icon, image_path, sort_order
    FROM categories
    WHERE is_active = 1
      AND parent_id IS NULL
    ORDER BY sort_order ASC, id ASC
")->fetchAll();

$allChildren = db()->query("
    SELECT
        c.id,
        c.parent_id,
        c.name,
        c.slug,
        c.icon,
        c.image_path,
        c.sort_order,
        (
            SELECT COUNT(*)
            FROM products p
            WHERE p.category_id = c.id
              AND p.is_active = 1
        ) AS product_count
    FROM categories c
    WHERE c.is_active = 1
      AND c.parent_id IS NOT NULL
    ORDER BY c.sort_order ASC, c.id ASC
")->fetchAll();

$childrenByParent = [];
foreach ($allChildren as $child) {
    $childrenByParent[(int)$child['parent_id']][] = $child;
}

$parentProductCounts = [];
foreach ($parentCategories as $parent) {
    $pid = (int)$parent['id'];

    $stmt = db()->prepare("
        SELECT COUNT(*) FROM products
        WHERE category_id = ? AND is_active = 1
    ");
    $stmt->execute([$pid]);
    $total = (int)$stmt->fetchColumn();

    foreach ($childrenByParent[$pid] ?? [] as $child) {
        $total += (int)$child['product_count'];
    }

    $parentProductCounts[$pid] = $total;
}

/* ═══════════════════════════════════════════════
   پرفروش‌ها — بر اساس تعداد واقعی فروش
   ══════════════════════════════════════════════ */
$bestSellers = db()->query("
    SELECT
        p.id,
        p.slug,
        p.code,
        p.name,
        p.base_moq,
        c.name AS cat_name,
        p.base_price AS price,
        (SELECT spec_value FROM product_specs 
         WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,
        GREATEST(0, COALESCE(inv.qty_physical, 0) - COALESCE(inv.reserved, 0) - COALESCE(inv.unsellable, 0)) AS qty_available,
        COALESCE(sold.total_sold, 0) AS total_sold,
        (
            SELECT image_path
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_main DESC, pi.sort_order ASC
            LIMIT 1
        ) AS img
    FROM products p

    JOIN categories c ON c.id = p.category_id

    LEFT JOIN inventory inv
        ON inv.product_id = p.id

    LEFT JOIN (
        SELECT
            oi.product_id,
            SUM(oi.qty) AS total_sold
        FROM order_items oi
        INNER JOIN orders o
            ON o.id = oi.order_id
        WHERE o.status IN (
            'delivered',
            'shipped',
            'shipped-pik',
            'shipped-post'
        )
        GROUP BY oi.product_id
    ) AS sold
        ON sold.product_id = p.id

    WHERE p.is_active = 1

    ORDER BY total_sold DESC, p.id DESC

    LIMIT 4
")->fetchAll();

/* ═══════════════════════════════════════════════
   تازه‌ها
══════════════════════════════════════════════ */
$newArrivals = db()->query("
    SELECT
        p.id,
        p.slug,
        p.code,
        p.name,
        p.base_moq,
        p.base_price AS price,
                (SELECT spec_value FROM product_specs 
         WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,


        GREATEST(0, COALESCE(inv.qty_physical, 0) - COALESCE(inv.reserved, 0) - COALESCE(inv.unsellable, 0)) AS qty_available,
        (
            SELECT image_path
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_main DESC, pi.sort_order ASC
            LIMIT 1
        ) AS img
    FROM products p

    LEFT JOIN inventory inv
        ON inv.product_id = p.id

    WHERE p.is_active = 1

    ORDER BY p.created_at DESC, p.id DESC

    LIMIT 4
")->fetchAll();

$pageTitle = 'رابطی — خرید عمده جوراب مستقیم از تولیدکننده';

/* ⭐ [P1] بندانگشتی‌ساز تصاویر — نیازمند thumb.php v2 در ریشه سایت
   عکس‌های تا ۱۵۰۰px فقط برای نمایش ۱۰۷–۳۷۰px سرو می‌شدند؛
   ~۵۳۸KB اتلاف شبکه + decode سنگین روی CPU موبایل (LCP 8.4s / TBT 1210ms).
   thumb.php v2 ضددخطاست: در هر شکست (GD نبودن، کش نشدن و…) فایل اصلی سرو
   می‌شود و عکس‌ها هرگز نمی‌شکنند. عیب‌یابی سریع: /thumb.php?debug=1 */
if (!function_exists('rabeti_thumb_url')) {
    /**
     * URL بندانگشتی؛ در هر شکست null برمی‌گردد (برای srcset).
     * گاردها: خالی، RABETI_THUMB_OFF، لینک خارجی/پسوند، نبود GD/WebP، نبود فایل.
     */
    function rabeti_thumb_url(?string $path, int $w, int $h = 0): ?string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return null;
        }

        /* کلید اضطراری: برای خاموش‌کردن کامل بندانگشتی، در ابتدای index.php
           بگذارید:  define('RABETI_THUMB_OFF', true);  */
        if (defined('RABETI_THUMB_OFF') && RABETI_THUMB_OFF) {
            return null;
        }

        /* لینک خارجی یا پسوند غیرتصویری → دست‌نخورده */
        if (
            preg_match('#^(https?:)?//#i', $path)
            || !preg_match('/\.(jpe?g|png|gif|webp)$/i', $path)
        ) {
            return null;
        }

        /* GD یا پشتیبانی WebP در این سرور نیست → همان مسیر اصلی (مثل قبل) */
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            return null;
        }

        /* فایل مبدأ روی دیسک نیست → همان مسیر اصلی (رف‌تری بی‌مصرف حذف می‌شود) */
        $abs = __DIR__ . '/' . ltrim(str_replace('\\', '/', $path), '/');
        if (!is_file($abs)) {
            return null;
        }

        return '/thumb.php?f=' . rawurlencode(ltrim($path, '/'))
            . '&w=' . $w
            . ($h > 0 ? '&h=' . $h : '');
    }

    function rabeti_thumb(string $path, int $w, int $h = 0): string
    {
        $u = rabeti_thumb_url($path, $w, $h);
        return $u !== null ? $u : trim((string)$path);
    }

    /**
     * ⭐ v5 — srcset با گزینه ۲x برای نمایشگرهای Retina و دسکتاپ‌های
     * با مقیاس ویندوز ۱۲۵٪/۱۵۰٪ (بدون آن، بندانگشتی ۳۴۰px نرم دیده می‌شود).
     * مرورگر فقط یکی از دو گزینه را دانلود می‌کند (DPR≤1 → نسخه 1x).
     * خروجی خام است؛ در call-site با $h() escape شود. خالی = attribute بی‌اثر.
     */
    function rabeti_srcset(string $path, int $w, int $h = 0): string
    {
        $u1 = rabeti_thumb_url($path, $w, $h);
        if ($u1 === null) {
            return '';
        }
        $u2 = rabeti_thumb_url($path, $w * 2, $h > 0 ? $h * 2 : 0);
        return $u1 . ' 1x' . ($u2 !== null ? ', ' . $u2 . ' 2x' : '');
    }
}

/* ⭐ CSSهای اختصاصی صفحه در <head> لود می‌شوند (به‌جای وسط body → رندر-بلاک) */
$pageCss = [
    '/assets/css/categories-premium.css?v=9',
    '/assets/css/home-theme.css',
];

$jsonLd = [
    [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => 'رابطی',
        'url'      => SITE_URL . '/',
        'logo'     => SITE_URL . '/Image/logo3.jpg',
    ],
    [
        '@context'        => 'https://schema.org',
        '@type'           => 'WebSite',
        'name'            => 'رابطی',
        'url'             => SITE_URL . '/',
        'inLanguage'      => 'fa-IR',
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => SITE_URL . '/products?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ],
];

/* گالری اینستاگرام */
$instagramPosts = [];
try {
    $instagramPosts = db()->query("
        SELECT id, image_path, caption, link
        FROM instagram_posts
        WHERE is_active = 1
        ORDER BY sort_order ASC, id DESC
        LIMIT 50
    ")->fetchAll();
} catch (Throwable $e) {
    $instagramPosts = [];
}

require_once ROOT_PATH . '/app/services/PricingService.php';
require_once ROOT_PATH . '/config/auth.php';

$userGroup = current_user_group();
$userGroupData = PricingService::getGroup(db(), $userGroup);
$userGroupName = $userGroupData['name'] ?? 'عادی';

// محاسبه قیمت و موجودی برای پرفروش‌ها
foreach ($bestSellers as &$p) {
    $stock = (int)($p['qty_available'] ?? 0);
    $moq = max(1, (int)($p['base_moq'] ?? 1));
    $pricing = PricingService::calculate(db(), (int)$p['id'], $moq, $userGroup);

    $p['_stock'] = $stock;
    $p['_moq'] = $moq;
    $p['_can_order'] = $stock >= $moq;
    $p['_has_discount'] = ($pricing['discount_source'] ?? '') === 'group';
    $p['_base_price'] = (float)($pricing['base_price'] ?? 0);
    $p['_final_price'] = (float)($pricing['unit_price'] ?? 0);
    $p['_discount_pct'] = (float)($pricing['discount_percent'] ?? 0);
    $p['_unit_label'] = unit_label($p['unit_code'] ?? 'جفتی');
}
unset($p);

ob_start();

?>


<!-- ═══ ۱. HERO ═══ -->
<section class="r6-hero" id="r6Hero">


    <?php if (!empty($heroBanners)): ?>

        <div class="r6-hero-slides">

            <?php foreach ($heroBanners as $index => $banner): ?>

                <article
                    class="r6-hero-slide <?= $index === 0 ? 'is-active' : '' ?>">

                    <?php if (!empty($banner['image_path'])): ?>

                        <picture class="r6-hero-picture">

                            <?php if (!empty($banner['mobile_image_path'])): ?>

                                <source
                                    media="(max-width: 760px)"
                                    srcset="<?= $h(rabeti_srcset($banner['mobile_image_path'], 760, 507)) ?>">

                            <?php endif; ?>

                            <img
                                class="r6-hero-img"
                                src="<?= $h(rabeti_thumb($banner['image_path'], 1440, 960)) ?>"
                                srcset="<?= $h(rabeti_srcset($banner['image_path'], 1440, 960)) ?>"
                                alt="<?= $h($banner['title'] ?? 'بنر رابطی') ?>"
                                <?= $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>

                        </picture>

                    <?php endif; ?>


                    <div class="r6-hero-fade"></div>


                    <div class="r6-hero-content">

                        <h1>
                            <?= $h(
                                $banner['title']
                                    ?? 'تولیدکننده و فروش عمده جوراب رابطی'
                            ) ?>
                        </h1>

                        <p>
                            <?= $h(
                                $banner['subtitle']
                                    ?? 'خرید عمده انواع جوراب مردانه، زنانه و بچگانه با قیمت مستقیم تولیدکننده و شرایط همکاری'
                            ) ?>
                        </p>
                        <!-- 
                        <div class="r6-hero-cta">

                            <a
                                href="/products"
                                class="r-btn r-btn-primary">

                                مشاهده محصولات

                                <span class="r6-arrow">
                                    ←
                                </span>

                            </a>


                            <a
                                href="/custom-order"
                                class="r-btn r-btn-outline">

                                سفارش اختصاصی

                            </a>

                        </div> -->

                    </div>

                </article>

            <?php endforeach; ?>

        </div>


        <?php if (count($heroBanners) > 1): ?>

            <button
                type="button"
                class="r6-hero-prev"
                aria-label="بنر قبلی">

                ‹

            </button>


            <button
                type="button"
                class="r6-hero-next"
                aria-label="بنر بعدی">

                ›

            </button>


            <div class="r6-hero-dots">

                <?php foreach ($heroBanners as $index => $banner): ?>

                    <button
                        type="button"
                        class="r6-hero-dot <?= $index === 0 ? 'is-active' : '' ?>"
                        data-slide="<?= $index ?>"
                        aria-label="بنر <?= $index + 1 ?>">
                    </button>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>


    <?php else: ?>


        <div class="r6-hero-empty">

            <h1>
                جوراب‌هایی که فروش می‌روند.
            </h1>

            <p>
                تأمین مستقیم از تولیدکننده؛
                قیمت همکاری، موجودی واقعی و سفارش سریع
            </p>

            <a
                href="/products"
                class="r-btn r-btn-primary">

                مشاهده محصولات

            </a>

        </div>


    <?php endif; ?>

</section>


<!-- ═══ ۲. دسته‌بندی پریمیوم ═══ -->
<!-- ⭐ CSS این بخش با $pageCss به <head> منتقل شد (حذف رندر-بلاک از بدنه صفحه) -->


<section class="r-cats-premium">

    <div class="rp-inner">

        <div class="rp-head">
            <span class="rp-eyebrow">انتخاب مطمئن برای تمام سلیقه‌ها</span>
            <h1 class="rp-title">کارخانه جوراب رابطی</h1>
            <h2 class="rp-subtitle">مستقیم، بدون واسطه</h2>
        </div>
        <div class="rp-cats-head">
            <h2 class="rp-cats-title">دسته‌بندی جوراب عمده</h2>
            <p></p>

        </div>

        <div class="rp-parents" role="tablist">

            <?php foreach ($parentCategories as $index => $parent): ?>

                <?php
                $pid = (int)$parent['id'];
                $isFirst = ($index === 0);
                $hasImage = !empty($parent['image_path']);
                $totalCount = $parentProductCounts[$pid] ?? 0;
                ?>

                <article
                    class="rp-parent <?= $isFirst ? 'is-active' : '' ?>"
                    data-parent-id="<?= $pid ?>"
                    role="tab"
                    aria-selected="<?= $isFirst ? 'true' : 'false' ?>">

                    <div class="rp-parent-img">
                        <?php if ($hasImage): ?>
                            <img src="<?= $h(rabeti_thumb($parent['image_path'], 240, 240)) ?>" srcset="<?= $h(rabeti_srcset($parent['image_path'], 240, 240)) ?>" alt="<?= $h($parent['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="rp-fallback"></div>
                        <?php endif; ?>
                    </div>

                    <div class="rp-parent-overlay"></div>

                    <div class="rp-parent-body">
                        <div>
                            <h3 class="rp-parent-title"><?= $h($parent['name']) ?></h3>
                            <span class="rp-parent-count"><?= number_format($totalCount) ?> محصول</span>
                        </div>
                        <span class="rp-parent-arrow">‹</span>
                    </div>

                </article>

            <?php endforeach; ?>

        </div>

        <div class="rp-panel-wrap">

            <?php foreach ($parentCategories as $index => $parent): ?>

                <?php
                $pid = (int)$parent['id'];
                $isFirst = ($index === 0);
                $children = $childrenByParent[$pid] ?? [];
                ?>

                <div class="rp-panel <?= $isFirst ? 'is-active' : '' ?>" data-parent-id="<?= $pid ?>">

                    <div class="rp-panel-head">

                        <div>
                            <div class="rp-panel-title">
                                <div>
                                    <span><?= $h($parent['name']) ?></span>
                                    <span class="rp-panel-sub">انواع جوراب <?= $h($parent['name']) ?> با کیفیت بالا</span>

                                </div>
                            </div>
                        </div>

                        <a href="/products?cat=<?= $h($parent['slug']) ?>" class="rp-panel-all">
                            مشاهده همه <span>←</span>
                        </a>

                    </div>


                    <?php if ($children): ?>

                        <div class="rp-subs">

                            <?php foreach ($children as $child): ?>

                                <?php $childHasImage = !empty($child['image_path']); ?>

                                <a href="/products?cat=<?= $h($child['slug']) ?>" class="rp-sub">

                                    <div class="rp-sub-img">
                                        <?php if ($childHasImage): ?>
                                            <img src="<?= $h(rabeti_thumb($child['image_path'], 240, 240)) ?>" srcset="<?= $h(rabeti_srcset($child['image_path'], 240, 240)) ?>" alt="<?= $h($child['name']) ?>" loading="lazy">
                                        <?php endif; ?>
                                    </div>

                                    <span class="rp-sub-name"><?= $h($child['name']) ?></span>
                                    <span class="rp-sub-count"><?= number_format((int)$child['product_count']) ?> محصول</span>
                                    <span class="rp-sub-arrow">‹</span>

                                </a>

                            <?php endforeach; ?>

                        </div>

                    <?php else: ?>

                        <div style="text-align:center;padding:24px;color:var(--rp-muted);font-size:.85rem;">
                            هنوز زیرمجموعه‌ای برای این دسته تعریف نشده.
                        </div>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </div>

    </div>

</section>

<script src="/assets/js/categories-premium.js" defer></script>

<!-- ═══ ۲.۵ سفارش اختصاصی ═══ -->

<section class="r6-custom-section">
    <span class="r6-custom-eyebrow">
        چرا خرید از رابطی؟
    </span>

    <h3>سفارش اختصاصی رابطی</h3>

    <p>
        تولید جوراب با طرح و نام اختصاصی برای مجموعه‌ها،
        فروشگاه‌ها و سازمان‌ها — از طراحی تا بسته‌بندی.
    </p>
    <div class="r6-custom-inner">

        <div class="r6-custom-body">



            <div class="r6-custom-features">

                <span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2a10 10 0 100 20c1.1 0 2-.9 2-2 0-.5-.2-.9-.5-1.3-.3-.3-.5-.8-.5-1.2 0-1.1.9-2 2-2h2.4c2.3 0 4.1-1.9 4.1-4.1C21.5 6.5 17.2 2 12 2z" />
                        <circle cx="6.5" cy="11.5" r="1.3" />
                        <circle cx="9.5" cy="7.5" r="1.3" />
                        <circle cx="14.5" cy="7.5" r="1.3" />
                        <circle cx="17.5" cy="11.5" r="1.3" />
                    </svg>
                    طراحی اختصاصی
                </span>

                <span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 9V3h12v6" />
                        <path d="M6 18H4a2 2 0 01-2-2v-4a2 2 0 012-2h16a2 2 0 012 2v4a2 2 0 01-2 2h-2" />
                        <rect x="6" y="14" width="12" height="8" rx="1" />
                        <path d="M10 18h4" />
                    </svg>
                    چاپ و بافت لوگو
                </span>

                <span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 16V8a2 2 0 00-1-1.7l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.7l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z" />
                        <path d="M3.3 7L12 12l8.7-5" />
                        <path d="M12 22V12" />
                    </svg>
                    بسته‌بندی اختصاصی
                </span>

                <span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 5h13v11H1z" />
                        <path d="M14 8h4l3 3v5h-7" />
                        <circle cx="5.5" cy="17" r="2" />
                        <circle cx="16.5" cy="17" r="2" />
                    </svg>
                    تحویل به‌موقع
                </span>

            </div>

            <a href="/custom-order" class="r6-custom-cta">
                ثبت سفارش اختصاصی <span>←</span>
            </a>

        </div>

        <div class="r6-custom-visual">
            <?php
            $customOrderImage = Setting::get('custom_order_image', '/Image/1.webp');
            ?>
            <img src="<?= $h(rabeti_thumb($customOrderImage, 680, 680)) ?>" srcset="<?= $h(rabeti_srcset($customOrderImage, 680, 680)) ?>" alt="سفارش اختصاصی رابطی" loading="lazy">
        </div>

    </div>

</section>

<!-- ═══ ۳. پرفروش‌ها ═══ -->
<section class="r-section">
    <div class="r-sec-head">
        <div>
            <h2>پرفروش‌های این هفته</h2>
            <p>مدل‌هایی که بیشتر از همه سفارش داده می‌شوند.</p>
        </div>
        <a href="/products" class="r-sec-link">مشاهده همه ←</a>
    </div>


    <section class="prod-grid">
        <?php foreach ($bestSellers as $p): ?>
            <?php
            $stock   = (int)($p['qty_available'] ?? 0);
            $moq     = max(1, (int)($p['base_moq'] ?? 1));
            $canOrder = $stock >= $moq;

            /* محاسبه قیمت با MOQ (پایه) برای نمایش اولیه */
            $userPricing      = PricingService::calculate(db(), (int)$p['id'], $moq, $userGroup);
            $hasGroupDiscount = ($userPricing['discount_source'] ?? '') === 'group';
            $baseUnitPrice    = (float)($userPricing['base_price'] ?? 0);
            $finalUnitPrice   = (float)($userPricing['unit_price'] ?? 0);
            $discountPercent  = (float)($userPricing['discount_percent'] ?? 0);
            $priceAvailable   = $finalUnitPrice > 0 || $baseUnitPrice > 0;
            $unitLabel        = unit_label($p['unit_code'] ?? 'جفتی');
            ?>

            <article class="prod-card card <?= $canOrder ? '' : 'is-out' ?>" data-product-id="<?= (int)$p['id'] ?>">

                <!-- ─── عکس محصول ─── -->
                <a href="/product/<?= $h($p['slug']) ?>" class="prod-img" aria-label="<?= $h($p['name']) ?>">
                    <?php if (!empty($p['img'])): ?>
                        <img src="<?= $h($p['img']) ?>" alt="<?= $h($p['name']) ?>" loading="lazy">
                    <?php else: ?>
                        <span aria-hidden="true">🧦</span>
                    <?php endif; ?>

                    <div class="in-cart-badge" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        <span>در سبد</span>
                    </div>
                </a>

                <!-- ─── اطلاعات محصول ─── -->
                <div class="prod-info">
                    <div class="prod-cat"><?= $h($p['cat_name']) ?></div>

                    <div class="prod-name">
                        <a href="/product/<?= $h($p['slug']) ?>"><?= $h($p['name']) ?></a>
                    </div>

                    <div class="prod-code">
                        کد <?= $h($p['code']) ?> | حداقل <?= $numFa($moq) ?> <?= $h($unitLabel) ?>
                    </div>

                    <!-- ─── قیمت (داینامیک) ─── -->
                    <div class="prod-price"
                        data-price-box
                        data-base-unit="<?= $baseUnitPrice ?>"
                        data-discount-percent="<?= $discountPercent ?>"
                        data-discount-source="<?= $h($userPricing['discount_source'] ?? '') ?>"
                        data-group-name="<?= $h($userGroupName) ?>"
                        data-decimals="0">
                        <?php if ($priceAvailable): ?>
                            <?php if ($hasGroupDiscount): ?>
                                <span class="prod-price-now" data-price-final><?= $moneyFa($finalUnitPrice) ?></span>
                                <small class="prod-price-base">
                                    قیمت پایه: <span data-price-base style="text-decoration: line-through;"><?= $moneyFa($baseUnitPrice) ?></span>
                                </small>
                                <div class="prod-price-badge">
                                    <?= $numFa($discountPercent, 0) ?>٪ تخفیف سطح <?= $h($userGroupName) ?>
                                </div>
                            <?php else: ?>
                                <span class="prod-price-now" data-price-final style="font-size:1.15rem;font-weight:900;color:#004f53;">
                                    <?= $moneyFa($finalUnitPrice) ?>
                                </span>
                                <small class="prod-price-base" style="display:block;margin-top:4px;font-size:0.7rem;color:#8b7355;">قیمت همکاری</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="prod-price-now" style="font-size:1.05rem;font-weight:800;color:#b8860b;">استعلام قیمت</span>
                            <small style="display:block;margin-top:4px;font-size:0.7rem;color:#8b7355;">برای قیمت تماس بگیرید</small>
                        <?php endif; ?>
                    </div>

                    <!-- ─── موجودی ─── -->
                    <div class="prod-stock <?= $stock > 0 ? 'in' : 'out' ?>">
                        <?php if ($stock > 0): ?>
                            ● موجود در انبار (<?= $numFa($stock) ?> <?= $h($unitLabel) ?>)
                        <?php else: ?>
                            ● ناموجود
                        <?php endif; ?>
                    </div>

                    <!-- ─── استپر + دکمه‌ها ─── -->
                    <?php if ($canOrder && $priceAvailable): ?>
                        <div class="stepper" data-min="<?= $moq ?>" data-max="<?= $stock ?>" data-product-id="<?= (int)$p['id'] ?>">
                            <button type="button" class="step plus" aria-label="افزایش تعداد">+</button>
                            <input type="text" inputmode="numeric" pattern="[0-9۰-۹]*" class="step-num step-input" value="<?= $numFa($moq) ?>" aria-label="تعداد سفارش">
                            <button type="button" class="step minus" aria-label="کاهش تعداد">−</button>
                        </div>

                        <div class="cart-btn-group" style="display:flex;align-items:center;gap:8px;width:100%;">
                            <button type="button" class="quick-add" style="flex-grow:1;height:38px;border-radius:8px;">
                                افزودن به سبد
                            </button>

                            <div class="product-cart-indicator" data-product-id="<?= (int)$p['id'] ?>" style="display:none;position:relative;background:#faf6ee;width:38px;height:38px;border-radius:8px;border:1px solid #ebe3d5;align-items:center;justify-content:center;flex-shrink:0;" aria-label="در سبد خرید">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#004f53" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="9" cy="20" r="1.5" />
                                    <circle cx="18" cy="20" r="1.5" />
                                    <path d="M3 3h2l2.4 12.4A2 2 0 009.4 17H18a2 2 0 002-1.6L21.5 8H7" />
                                </svg>
                                <span class="cart-indicator-badge" style="position:absolute;top:-6px;right:-6px;background:#c53d3d;color:#fff;font-size:0.65rem;font-weight:800;width:18px;height:18px;display:flex;align-items:center;justify-content:center;border-radius:50%;">۰</span>
                            </div>
                        </div>
                    <?php elseif ($stock > 0 && !$priceAvailable): ?>
                        <div class="field-hint" style="margin-top:8px;color:#b8860b;">برای این محصول با ما تماس بگیرید.</div>
                    <?php elseif ($stock === 0): ?>
                        <div class="field-hint" style="margin-top:8px;color:#c53d3d;">این محصول فعلاً موجود نیست.</div>
                    <?php else: ?>
                        <div class="field-hint" style="margin-top:6px;">موجودی کمتر از حداقل سفارش است.</div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>


    <!-- ═══ ۴. تازه‌ها ═══ -->

    <section class="r6-newwrap">

        <div class="r6-new-head">

            <div>

                <span class="r6-eyebrow" id="new">
                    کالکشن تازه
                </span>


                <h2>
                    تازه‌های رابطی
                </h2>


                <p>
                    رنگ‌ها و بافت‌های تازه برای ویترین فصل جدید.
                </p>


                <a
                    href="/products?sort=new"
                    class="r-sec-link">

                    دیدن همه تازه‌ها ←

                </a>

            </div>

        </div>


        <div class="r-grid">

            <?php foreach ($newArrivals as $i => $p): ?>

                <a
                    href="/product/<?= $h($p['slug']) ?>"
                    class="r6-card r6-card-new">


                    <div class="r6-card-img">

                        <?php if (!empty($p['img'])): ?>

                            <img
                                src="<?= $h(rabeti_thumb($p['img'], 340, 340)) ?>"
                                srcset="<?= $h(rabeti_srcset($p['img'], 340, 340)) ?>"
                                alt="<?= $h($p['name']) ?>"
                                loading="lazy">

                        <?php else: ?>

                            <span>
                                🧦
                            </span>

                        <?php endif; ?>

                    </div>


                    <div class="r6-card-body">

                        <span class="r6-new-num">
                            <?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?>
                        </span>


                        <h3>
                            <?= $h($p['name']) ?>
                        </h3>


                        <span class="r6-attr">

                            کد <?= $h($p['code']) ?>

                        </span>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    </section>

    <!-- ═══ ۶. گالری اینستاگرام ═══ -->

    <?php if (!empty($instagramPosts)): ?>

        <?php
        $igCount       = count($instagramPosts);
        $igUseCarousel = true;
        ?>

        <?php $pageCss[] = '/assets/css/instagram-gallery.css'; ?>

        <section class="r6-ig">
            <div class="r6-ig-inner">

                <div class="r6-ig-head">
                    <div class="r6-ig-head-l">
                        <span class="r6-ig-eyebrow">@tolidi_jorab_rabeti</span>
                        <h2>ما را در اینستاگرام دنبال کنید</h2>
                        <p>جدیدترین طرح‌ها و پشت صحنه‌ی کارگاه رابطی</p>
                    </div>
                    <a href="https://instagram.com/tolidi_jorab_rabeti" target="_blank" rel="noopener"
                        class="r6-ig-head-link">
                        مشاهده پیج <span>←</span>
                    </a>
                </div>

                <?php if ($igUseCarousel): ?>

                    <!-- ═══ Carousel ═══ -->
                    <div class="r6-ig-carousel" id="r6IgCarousel">
                        <div class="r6-ig-track" id="r6IgTrack">
                            <?php foreach ($instagramPosts as $post): ?>
                                <?php
                                $hasLink = !empty(trim((string)($post['link'] ?? '')));
                                $isVideo = (bool)preg_match('/\.(mp4|webm|mov)$/i', (string)$post['image_path']);
                                ?>
                                <a class="r6-ig-item"
                                    href="<?= $h($hasLink ? $post['link'] : $post['image_path']) ?>"
                                    <?= $hasLink ? 'target="_blank" rel="noopener"' : '' ?>
                                    data-has-link="<?= $hasLink ? '1' : '0' ?>"
                                    data-type="<?= $isVideo ? 'video' : 'image' ?>"
                                    data-src="<?= $h($post['image_path']) ?>"
                                    data-caption="<?= $h($post['caption'] ?? '') ?>">
                                    <?php if ($isVideo): ?>
                                        <video src="<?= $h($post['image_path']) ?>" muted playsinline preload="metadata"></video>
                                        <span class="r6-ig-video-icon"><svg viewBox="0 0 24 24" fill="currentColor">
                                                <polygon points="9 7 17 12 9 17 9 7" />
                                            </svg></span>
                                    <?php else: ?>
                                        <img src="<?= $h(rabeti_thumb($post['image_path'], 400, 400)) ?>" srcset="<?= $h(rabeti_srcset($post['image_path'], 400, 400)) ?>" alt="<?= $h($post['caption'] ?? 'رابطی') ?>" loading="lazy">
                                    <?php endif; ?>
                                    <div class="r6-ig-overlay">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="2" y="2" width="20" height="20" rx="5" />
                                            <circle cx="12" cy="12" r="4" />
                                            <circle cx="17.5" cy="6.5" r=".8" fill="currentColor" />
                                        </svg>
                                        <?php if (!empty($post['caption'])): ?><span><?= $h($post['caption']) ?></span><?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <div class="r6-ig-nav-row">
                            <button type="button" class="r6-ig-nav prev" id="r6IgPrev" aria-label="قبلی">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M9 18l6-6-6-6" />
                                </svg>
                            </button>
                            <div class="r6-ig-dots" id="r6IgDots"></div>
                            <button type="button" class="r6-ig-nav next" id="r6IgNext" aria-label="بعدی">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M15 18l-6-6 6-6" />
                                </svg>
                            </button>
                        </div>
                    </div>

                <?php else: ?>

                    <!-- ═══ Grid ساده ═══ -->
                    <div class="r6-ig-grid">
                        <?php foreach ($instagramPosts as $post): ?>
                            <?php
                            $hasLink = !empty(trim((string)($post['link'] ?? '')));
                            $isVideo = (bool)preg_match('/\.(mp4|webm|mov)$/i', (string)$post['image_path']);
                            ?>
                            <a class="r6-ig-item"
                                href="<?= $h($hasLink ? $post['link'] : $post['image_path']) ?>"
                                <?= $hasLink ? 'target="_blank" rel="noopener"' : '' ?>
                                data-has-link="<?= $hasLink ? '1' : '0' ?>"
                                data-type="<?= $isVideo ? 'video' : 'image' ?>"
                                data-src="<?= $h($post['image_path']) ?>"
                                data-caption="<?= $h($post['caption'] ?? '') ?>">
                                <?php if ($isVideo): ?>
                                    <video src="<?= $h($post['image_path']) ?>" muted playsinline preload="metadata"></video>
                                    <span class="r6-ig-video-icon"><svg viewBox="0 0 24 24" fill="currentColor">
                                            <polygon points="9 7 17 12 9 17 9 7" />
                                        </svg></span>
                                <?php else: ?>
                                    <img src="<?= $h(rabeti_thumb($post['image_path'], 400, 400)) ?>" srcset="<?= $h(rabeti_srcset($post['image_path'], 400, 400)) ?>" alt="<?= $h($post['caption'] ?? 'رابطی') ?>" loading="lazy">
                                <?php endif; ?>
                                <div class="r6-ig-overlay">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="2" y="2" width="20" height="20" rx="5" />
                                        <circle cx="12" cy="12" r="4" />
                                        <circle cx="17.5" cy="6.5" r=".8" fill="currentColor" />
                                    </svg>
                                    <?php if (!empty($post['caption'])): ?><span><?= $h($post['caption']) ?></span><?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>

            </div>
        </section>

        <!-- ═══ Lightbox ═══ -->
        <div class="r6-ig-lightbox" id="r6IgLightbox" aria-hidden="true" role="dialog">
            <button type="button" class="r6-ig-lb-close" aria-label="بستن">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
            <div class="r6-ig-lb-stage">
                <img id="r6IgLbImg" src="" alt="">
                <video id="r6IgLbVid" src="" controls playsinline style="display:none;"></video>
            </div>
            <div class="r6-ig-lb-caption" id="r6IgLbCaption"></div>
        </div>

        <!-- ⭐ اسکریپت گالری به فایل خارجی منتقل شد + defer (کاهش TBT) -->

    <?php endif; ?>


    <!-- ═══ HERO SLIDER JS ═══ -->
    <!-- ⭐ به فایل خارجی منتقل شد + defer (کاهش TBT) -->
    <!-- ═══ استایل بج «در سبد» (اگر در CSS اصلی نبود) ═══ -->
    <style>
        .prod-card .in-cart-badge {
            display: none;
        }

        .prod-card.is-in-cart .in-cart-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            position: absolute;
            top: 8px;
            right: 8px;
            background: #00666b;
            color: #fff;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: .7rem;
            font-weight: 700;
            z-index: 2;
        }

        .prod-card.is-in-cart .in-cart-badge svg {
            width: 12px;
            height: 12px;
        }

        .prod-card .prod-img {
            position: relative;
        }

        .prod-price-now {
            display: block;
            font-size: 1.15rem;
            font-weight: 900;
            color: #004f53;
            margin-bottom: 4px;
        }

        .prod-price-base {
            display: block;
            font-size: 0.7rem;
            color: #8b7355;
            margin-bottom: 6px;
        }

        .prod-price-badge {
            font-size: 0.72rem;
            color: #b8860b;
            font-weight: 700;
            background: #fdf8e9;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }
    </style>

    <!-- ═══ اسکریپت سبد + استپر + قیمت داینامیک ═══ -->
    <script>
        (function() {
            'use strict';

            function faToEn(s) {
                return String(s).replace(/[۰-۹]/g, function(d) {
                    return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);
                });
            }

            function enToFa(s) {
                return String(s).replace(/\d/g, function(d) {
                    return '۰۱۲۳۴۵۶۷۸۹' [d];
                });
            }

            function faMoney(num, decimals) {
                var n = Number(num) || 0;
                var fixed = n.toFixed(decimals || 0);
                var parts = fixed.split('.');
                parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '٬');
                var out = parts.join('٫');
                out = out.replace(/[0-9]/g, function(d) {
                    return '۰۱۲۳۴۵۶۷۸۹' [d];
                });
                return out + ' تومان';
            }

            /* ─────── استپر ایمن ─────── */
            document.querySelectorAll('.stepper').forEach(function(stepper) {
                var input = stepper.querySelector('.step-input');
                var plus = stepper.querySelector('.step.plus');
                var minus = stepper.querySelector('.step.minus');
                if (!input || !plus || !minus) return;

                var min = parseInt(stepper.dataset.min, 10) || 1;
                var max = parseInt(stepper.dataset.max, 10) || 999999;
                if (max < min) max = min;

                function readQty() {
                    var v = parseInt(faToEn(input.value), 10);
                    if (isNaN(v)) v = min;
                    return Math.max(min, Math.min(max, v));
                }

                function writeQty(v, trigger) {
                    v = Math.max(min, Math.min(max, v));
                    input.value = enToFa(v);
                    if (trigger) {
                        input.dispatchEvent(new Event('change', {
                            bubbles: true
                        }));
                    }
                }

                plus.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    writeQty(readQty() + 1, true);
                });
                minus.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    writeQty(readQty() - 1, true);
                });

                input.addEventListener('input', function() {
                    /* فقط عدد نگه‌دار */
                    var raw = faToEn(input.value).replace(/[^\d]/g, '');
                    input.value = enToFa(raw);
                });
                input.addEventListener('blur', function() {
                    writeQty(readQty(), true);
                });
                input.addEventListener('change', function() {
                    var card = stepper.closest('[data-product-id]');
                    if (!card) return;
                    updatePrice(card, readQty());
                });
            });

            /* ─────── آپدیت داینامیک قیمت بر اساس تعداد ─────── */
            var priceCache = {};
            var priceTimer = {};

            function updatePrice(card, qty) {
                var box = card.querySelector('[data-price-box]');
                if (!box) return;
                var pid = card.dataset.productId;

                var finalEl = box.querySelector('[data-price-final]');
                var baseEl = box.querySelector('[data-price-base]');
                var badgeEl = box.querySelector('.prod-price-badge');
                if (!finalEl) return;

                /* اگر قیمت برای این (pid, qty) کش شده باشد */
                var key = pid + ':' + qty;
                if (priceCache[key]) {
                    renderPrice(box, priceCache[key]);
                    return;
                }

                /* Debounce */
                clearTimeout(priceTimer[pid]);
                priceTimer[pid] = setTimeout(function() {
                    fetch('/api/product-price?id=' + encodeURIComponent(pid) + '&qty=' + encodeURIComponent(qty), {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            if (!data || !data.ok) return;
                            priceCache[key] = data;
                            renderPrice(box, data);
                        })
                        .catch(function(err) {
                            console.error('PRICE_ERROR:', err);
                        });
                }, 220);
            }

            function renderPrice(box, data) {
                var finalEl = box.querySelector('[data-price-final]');
                var baseEl = box.querySelector('[data-price-base]');
                var badgeEl = box.querySelector('.prod-price-badge');
                var decimals = parseInt(box.dataset.decimals, 10) || 0;

                if (!finalEl) return;
                if (data.unit_price !== undefined && Number(data.unit_price) > 0) {
                    finalEl.textContent = faMoney(data.unit_price, decimals);
                }
                if (baseEl && data.base_price !== undefined) {
                    baseEl.textContent = faMoney(data.base_price, decimals);
                }
                if (badgeEl && data.discount_percent !== undefined) {
                    badgeEl.textContent = enToFa(Math.round(data.discount_percent)) + '٪ تخفیف برای مشتری سطح ' + (data.group_name || '');
                }
            }

            /* ─────── همگام‌سازی آیکون‌های سبد ─────── */
            function syncCartIndicators() {
                if (window.RABETI_AUTH !== true) return;

                fetch('/api/cart', {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (!data || !data.ok) return;
                        var serverItems = data.items || {};
                        document.querySelectorAll('.product-cart-indicator').forEach(function(indicator) {
                            var pid = indicator.dataset.productId;
                            var qty = serverItems[pid] || 0;
                            var badge = indicator.querySelector('.cart-indicator-badge');
                            var card = indicator.closest('[data-product-id]');
                            if (qty > 0) {
                                indicator.style.display = 'flex';
                                if (badge) badge.textContent = enToFa(qty);
                                if (card) card.classList.add('is-in-cart');
                            } else {
                                indicator.style.display = 'none';
                                if (badge) badge.textContent = '۰';
                                if (card) card.classList.remove('is-in-cart');
                            }
                        });
                    })
                    .catch(function(err) {
                        console.error('SYNC_ERROR:', err);
                    });
            }

            /* ─────── افزودن به سبد ─────── */
            document.querySelectorAll('.quick-add').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    /* ⭐ مهمان → مودال ثبت‌نام */
                    if (window.RABETI_AUTH !== true) {
                        if (typeof showToast === 'function') showToast('🔒 برای افزودن به سبد، ابتدا وارد شوید یا ثبت‌نام کنید');
                        if (typeof openAuthModal === 'function') openAuthModal('register');
                        return;
                    }

                    var card = btn.closest('[data-product-id]');
                    if (!card) return;

                    var pid = card.dataset.productId;
                    var stepper = card.querySelector('.stepper');
                    var input = stepper ? stepper.querySelector('.step-input') : null;
                    var min = stepper ? (parseInt(stepper.dataset.min, 10) || 1) : 1;
                    var max = stepper ? (parseInt(stepper.dataset.max, 10) || 999999) : 999999;

                    /* اعتبارسنجی تعداد قبل از ارسال */
                    var qty = input ? parseInt(faToEn(input.value), 10) : min;
                    if (isNaN(qty)) qty = min;
                    qty = Math.max(min, Math.min(max, qty));

                    if (qty < min) {
                        if (typeof showToast === 'function') showToast('⚠️ حداقل سفارش ' + enToFa(min) + ' عدد است');
                        return;
                    }
                    if (qty > max) {
                        if (typeof showToast === 'function') showToast('⚠️ حداکثر موجودی ' + enToFa(max) + ' عدد است');
                        return;
                    }

                    var oldText = btn.textContent;
                    btn.disabled = true;
                    btn.textContent = 'صبر کنید...';

                    var fd = new FormData();
                    fd.append('product_id', pid);
                    fd.append('qty', qty);

                    fetch('/api/cart', {
                            method: 'POST',
                            body: fd,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
                            }
                        })
                        .then(function(r) {
                            return r.json().catch(function() {
                                return {
                                    ok: false,
                                    error: 'پاسخ نامعتبر از سرور'
                                };
                            });
                        })
                        .then(function(data) {
                            btn.disabled = false;
                            btn.textContent = oldText;
                            if (data && data.error === 'برای خرید ابتدا وارد شوید') {
                                if (typeof openAuthModal === 'function') openAuthModal('register');
                                return;
                            }
                            if (!data || !data.ok) {
                                var msg = (data && data.error) ? data.error : 'خطا در افزودن به سبد';
                                if (typeof showToast === 'function') showToast('⚠️ ' + msg);
                                return;
                            }

                            if (window.updateCartBadges) window.updateCartBadges(data.count);
                            if (typeof showToast === 'function') showToast('✓ ' + enToFa(qty) + ' عدد به سبد اضافه شد');

                            /* همگام‌سازی با سرور تا آیکون همین محصول هم آپدیت شود */
                            syncCartIndicators();
                        })
                        .catch(function(err) {
                            console.error('CART_ERROR:', err);
                            btn.disabled = false;
                            btn.textContent = oldText;
                            if (typeof showToast === 'function') showToast('⚠️ خطا در ارتباط با سرور');
                        });
                });
            });

            /* ─────── بارگذاری اولیه آیکون‌ها ─────── */
            if (window.RABETI_AUTH === true) {
                syncCartIndicators();
            }
        })();
    </script>

    <!-- ═══ HERO SLIDER JS (ایزوله و بدون تداخل) ═══ -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var hero = document.getElementById('r6Hero');
            if (!hero) return;

            var slides = Array.from(hero.querySelectorAll('.r6-hero-slide'));
            var dots = Array.from(hero.querySelectorAll('.r6-hero-dot'));
            var prevButton = hero.querySelector('.r6-hero-prev');
            var nextButton = hero.querySelector('.r6-hero-next');

            // اگر بنر بیشتر از یکی نبود، اسلایدر نیازی نیست
            if (slides.length <= 1) return;

            var current = 0;
            var timer = null;

            function showSlide(index) {
                current = (index + slides.length) % slides.length;

                slides.forEach(function(slide, i) {
                    var active = (i === current);
                    slide.classList.toggle('is-active', active);
                    slide.style.opacity = active ? '1' : '0';
                    slide.style.visibility = active ? 'visible' : 'hidden';
                    slide.style.pointerEvents = active ? 'auto' : 'none';
                    slide.style.zIndex = active ? '1' : '0';
                });

                dots.forEach(function(dot, i) {
                    dot.classList.toggle('is-active', i === current);
                });
            }

            function nextSlide() {
                showSlide(current + 1);
            }

            function prevSlide() {
                showSlide(current - 1);
            }

            function restartAutoPlay() {
                if (timer) clearInterval(timer);
                timer = setInterval(nextSlide, 5000);
            }

            // دکمه بعدی
            if (nextButton) {
                nextButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    nextSlide();
                    restartAutoPlay();
                });
            }

            // دکمه قبلی
            if (prevButton) {
                prevButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    prevSlide();
                    restartAutoPlay();
                });
            }

            // نقطه‌ها (Dots)
            dots.forEach(function(dot, index) {
                dot.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    showSlide(index);
                    restartAutoPlay();
                });
            });

            // توقف در هاور
            hero.addEventListener('mouseenter', function() {
                if (timer) clearInterval(timer);
            });
            hero.addEventListener('mouseleave', function() {
                restartAutoPlay();
            });

            // شروع
            showSlide(0);
            restartAutoPlay();
        });
    </script>
    <?php

    $content = ob_get_clean();

    require ROOT_PATH . '/views/layouts/main.php';

    ?>