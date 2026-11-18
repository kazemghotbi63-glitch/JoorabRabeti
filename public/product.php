<?php
/* ════ RABETI-CUSTOM-ORDER | نسخه: v1 | 2026-10-22 | جدیدترین نسخه (LATEST) ════ */

/* Auto-detect bootstrap (local + host) */
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

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$moneyFa = static function ($amount, int $decimals = 0): string {
    $formatted = number_format((float)$amount, $decimals, '.', ',');
    $formatted = strtr($formatted, [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
        ',' => '٬',
        '.' => '٫',  // اعشار فارسی (اختیاری)
    ]);
    return $formatted . ' تومان';
};

// ⭐ تابع جدید — فقط عدد فارسی، بدون "تومان"
$numFa = static function ($value, int $decimals = 0): string {
    $formatted = number_format((float)$value, $decimals, '.', ',');
    return strtr($formatted, [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
        ',' => '٬',
        '.' => '٫',
    ]);
};

/* ═══════════════ خواندن محصول ═══════════════ */

$slug = trim($_GET['slug'] ?? '');

$stmt = db()->prepare("
    SELECT
        p.*,
        c.name AS cat_name,
        c.slug AS cat_slug,
        c.parent_id AS cat_parent_id,
        parent.name AS parent_name,
        parent.slug AS parent_slug,
        (SELECT spec_value FROM product_specs 
         WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,
        GREATEST(0, COALESCE(inv.qty_physical, 0) - COALESCE(inv.reserved, 0) - COALESCE(inv.unsellable, 0)) AS stock
    FROM products p
    JOIN categories c ON c.id = p.category_id
    LEFT JOIN categories parent ON parent.id = c.parent_id
    LEFT JOIN inventory inv ON inv.product_id = p.id
    WHERE p.slug = ?
      AND p.is_active = 1
    LIMIT 1
");

$stmt->execute([$slug]);
$product = $stmt->fetch();

if (!$product) {

    http_response_code(404);

    $pageTitle = 'محصول یافت نشد';
    $metaRobots = 'noindex';

    ob_start();
?>

    <div class="empty-state">
        <div class="empty-icon">🔍</div>
        <h2>این محصول پیدا نشد</h2>
        <a href="/products" class="btn btn-orange">مشاهده کاتالوگ</a>
    </div>

<?php
    $content = ob_get_clean();
    require ROOT_PATH . '/views/layouts/main.php';
    exit;
}

$productId = (int)$product['id'];
$stock     = (int)$product['stock'];
$moq       = max(1, (int)$product['base_moq']);
$unitLabel = unit_label($product['unit_code'] ?? 'جفتی');



/* ⭐ بررسی وجود محصول در سبد کاربر */
$cartItemQty = 0;
if (is_customer_logged_in()) {
    $uid = (int)$_SESSION['customer_id'];
    $stmt = db()->prepare("
        SELECT ci.qty FROM cart_items ci
        JOIN carts c ON c.id = ci.cart_id
        WHERE c.user_id = ? AND c.status = 'active' AND ci.product_id = ?
        LIMIT 1
    ");
    $stmt->execute([$uid, $productId]);
    $cartItemQty = (int)$stmt->fetchColumn();
}

/* ═══════════════ قیمت‌ها (wholesale فقط) ═══════════════ */
/* ═══════════════ قیمت‌ها — از PricingService v2 ═══════════════ */

require_once ROOT_PATH . '/app/services/PricingService.php';

$userGroup = current_user_group();

// قیمت پایه از products.base_price (با fallback legacy در PricingService)
$basePrice = PricingService::getBasePrice(db(), $productId);

// پله‌ها از product_tier_discounts (درصدی)
$tiersRaw = PricingService::getTiers(db(), $productId);

// ساختار پله‌ها برای نمایش در صفحه محصول
$tiers = [];
foreach ($tiersRaw as $t) {
    $tiers[] = [
        'min_qty'          => (int)$t['min_qty'],
        'discount_percent' => (float)$t['discount_percent'],
        'price'            => PricingService::roundPrice(
            (int)round($basePrice * (1 - $t['discount_percent'] / 100))
        ),
    ];
}

// قیمت برای گروه فعلی کاربر (اگر bronze/silver/gold باشد، پله‌ها اعمال نمی‌شوند)
$userPricing = PricingService::calculate(db(), $productId, $moq, $userGroup);

// آیا کاربر تخفیف سطح دارد؟
$hasGroupDiscount = in_array($userPricing['discount_source'], ['group'], true);
$groupDiscountPercent = $hasGroupDiscount ? $userPricing['discount_percent'] : 0;

// گروه فعلی (برای نمایش نام)
$userGroupData = PricingService::getGroup(db(), $userGroup);

/* ═══════════════ همه عکس‌ها ═══════════════ */

$stmt = db()->prepare("
    SELECT id, image_path, alt_text, is_main, sort_order
    FROM product_images
    WHERE product_id = ?
    ORDER BY is_main DESC, sort_order ASC, id ASC
");
$stmt->execute([$productId]);
$productImages = $stmt->fetchAll();

/* ═══════════════ مشخصات ═══════════════ */

$stmt = db()->prepare("
    SELECT spec_key, spec_value
    FROM product_specs
    WHERE product_id = ?
    ORDER BY sort_order, id
");
$stmt->execute([$productId]);
$specs = $stmt->fetchAll();

/* ═══════════════ محصولات مشابه (بدون تکرار) ═══════════════ */

$stmt = db()->prepare("
    SELECT
        p.id,
        p.name,
        p.slug,
        p.code,
        p.base_price AS price,
        (
            SELECT pi.image_path FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_main DESC, pi.sort_order ASC
            LIMIT 1
        ) AS img
    FROM products p
    WHERE p.category_id = ?
      AND p.id != ?
      AND p.is_active = 1
    ORDER BY p.id DESC
    LIMIT 4
");

$stmt->execute([(int)$product['category_id'], $productId]);
$related = $stmt->fetchAll();

/* ═══════════════ وضعیت موجودی ═══════════════ */

$inStock  = $stock >= $moq;
$lowStock = $inStock && $stock < ($moq * 2);

$pageTitle = $product['name'] . ' | جوراب رابطی';
$canonicalPath = '/product/' . rawurlencode($product['slug']);
$pageDescription = mb_substr(
    trim((string)($product['description'] ?? '')),
    0,
    155
);

/* ═══════════════ JSON-LD: Product + BreadcrumbList ═══════════════ */

$productUrl = SITE_URL . '/product/' . rawurlencode($product['slug']);

/* قیمت‌ها به تومان ذخیره شده‌اند؛ schema.org کد ISO می‌خواهد → ریال (×۱۰) */
/* قیمت‌ها به تومان ذخیره شده‌اند؛ schema.org کد ISO می‌خواهد → ریال (×۱۰) */
$irrPrices = [];
if ($basePrice > 0) {
    $irrPrices[] = $basePrice * 10;
}
foreach ($tiers as $t) {
    if ($t['price'] > 0) {
        $irrPrices[] = $t['price'] * 10;
    }
}
$irrPrices = array_values(array_unique(array_filter($irrPrices, static fn($p) => $p > 0)));

$productLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'Product',
    'name'     => $product['name'],
    'sku'      => (string)($product['code'] ?? ''),
    'url'      => $productUrl,
    'brand'    => ['@type' => 'Brand', 'name' => 'رابطی'],
    'category' => $product['cat_name'],
];

if ($pageDescription !== '') {
    $productLd['description'] = $pageDescription;
}

$ldImages = [];
foreach ($productImages as $img) {
    if (str_starts_with((string)$img['image_path'], '/')) {
        $ldImages[] = SITE_URL . $img['image_path'];
    }
}
if ($ldImages) {
    $productLd['image'] = $ldImages;
}

if ($irrPrices) {
    $productLd['offers'] = [
        '@type'            => count($irrPrices) > 1 ? 'AggregateOffer' : 'Offer',
        'priceCurrency'    => 'IRR',
        'availability'     => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'url'              => $productUrl,
        'eligibleQuantity' => ['@type' => 'QuantitativeValue', 'minValue' => $moq],
        'seller'           => ['@type' => 'Organization', 'name' => 'رابطی'],
    ];
    if (count($irrPrices) > 1) {
        $productLd['offers']['lowPrice']   = min($irrPrices);
        $productLd['offers']['highPrice']  = max($irrPrices);
        $productLd['offers']['offerCount'] = count($irrPrices);
    } else {
        $productLd['offers']['price'] = $irrPrices[0];
    }
}

$crumbs = [
    ['خانه', SITE_URL . '/'],
    ['محصولات', SITE_URL . '/products'],
];
if (!empty($product['parent_slug'])) {
    $crumbs[] = [$product['parent_name'], SITE_URL . '/products?cat=' . rawurlencode($product['parent_slug'])];
}
$crumbs[] = [$product['cat_name'], SITE_URL . '/products?cat=' . rawurlencode($product['cat_slug'])];
$crumbs[] = [$product['name'], $productUrl];

$jsonLd = [
    $productLd,
    [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => array_map(
            static fn($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1]],
            $crumbs,
            array_keys($crumbs)
        ),
    ],
];

ob_start();
?>
<link rel="stylesheet" href="/assets/css/product-premium.css?v=1">

<!-- ═══ Breadcrumb ═══ -->

<nav class="breadcrumb">

    <a href="/">خانه</a> ›

    <a href="/products">محصولات</a>

    <?php if (!empty($product['parent_slug'])): ?>
        ›
        <a href="/products?cat=<?= $h($product['parent_slug']) ?>">
            <?= $h($product['parent_name']) ?>
        </a>
    <?php endif; ?>

    ›

    <a href="/products?cat=<?= $h($product['cat_slug']) ?>">
        <?= $h($product['cat_name']) ?>
    </a>

    ›

    <span><?= $h($product['name']) ?></span>

</nav>


<!-- ═══ Product Detail ═══ -->

<!-- ═══ Product Detail ═══ -->
<section class="pd-wrap">

    <!-- ─── ستون راست: گالری + Trust ─── -->
    <div class="pd-gallery-col">

        <?php if (!empty($productImages)): ?>
            <div class="pdg-wrap">
                <!-- عکس اصلی -->
                <div class="pdg-main" id="pdgMain">
                    <div class="pdg-slides">
                        <?php foreach ($productImages as $i => $img): ?>
                            <div class="pdg-slide <?= $i === 0 ? 'is-active' : '' ?>" data-src="<?= $h($img['image_path']) ?>">
                                <img src="<?= $h($img['image_path']) ?>" alt="<?= $h($img['alt_text'] ?: $product['name']) ?>" <?= $i === 0 ? 'loading="eager"' : 'loading="lazy"' ?> draggable="false">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="pdg-shine"></div>

                    <?php if (count($productImages) > 1): ?>
                        <span class="pdg-counter" id="pdgCounter">۱ / <?= $moneyFa(count($productImages)) ?></span>
                    <?php endif; ?>

                    <?php if ($lowStock): ?>
                        <span class="pdg-stock-badge warn">موجودی محدود</span>
                    <?php elseif ($inStock): ?>
                        <span class="pdg-stock-badge ok">موجود</span>
                    <?php else: ?>
                        <span class="pdg-stock-badge out">ناموجود</span>
                    <?php endif; ?>

                    <div class="pdg-zoom-hint">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="7" />
                            <path d="M21 21l-4.35-4.35M11 8v6M8 11h6" />
                        </svg>
                        برای بزرگ‌نمایی کلیک کنید
                    </div>

                    <?php if (count($productImages) > 1): ?>
                        <button type="button" class="pdg-nav prev" aria-label="قبلی"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                <path d="M15 18l-6-6 6-6" />
                            </svg></button>
                        <button type="button" class="pdg-nav next" aria-label="بعدی"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                <path d="M9 18l6-6-6-6" />
                            </svg></button>
                    <?php endif; ?>
                </div>

                <!-- تامنیل‌ها -->
                <?php if (count($productImages) > 1): ?>
                    <div class="pdg-thumbs">
                        <?php foreach ($productImages as $i => $img): ?>
                            <button type="button" class="pdg-thumb <?= $i === 0 ? 'is-active' : '' ?>" aria-label="عکس <?= $moneyFa($i + 1) ?>">
                                <img src="<?= $h($img['image_path']) ?>" alt="" loading="lazy" draggable="false">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- نقطه‌ها (موبایل) -->
            <?php if (count($productImages) > 1): ?>
                <div class="pdg-dots">
                    <?php foreach ($productImages as $i => $img): ?>
                        <button type="button" class="pdg-dot <?= $i === 0 ? 'is-active' : '' ?>" aria-label="عکس <?= $moneyFa($i + 1) ?>"></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="pdg-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                    <rect x="3" y="3" width="18" height="18" rx="3" />
                    <circle cx="8.5" cy="8.5" r="1.5" />
                    <path d="M21 15l-5-5L5 21" />
                </svg>
                <span>عکس واقعی به‌زودی</span>
            </div>
        <?php endif; ?>

        <!-- ⭐ Trust Row (این باید بعد از دکمه‌ها باشد، ولی در صفحه فعلی اینجاست. ما در کد اصلی در جای درست قرارش دادیم، اینجا فقط برای رفرنس است) -->
        <!-- اگر در کد قبلی Trust را به داخل pd-info-col بردید، اینجا کاری نکنید. -->

    </div>

    <!-- ─── ستون چپ: اطلاعات ─── -->
    <div class="pd-info-col">

        <div class="pd-meta">
            <span class="pd-code">کد <?= $h($product['code']) ?></span>
            <span class="pd-meta-dot"></span>
            <?php if ($inStock): ?>
                <span class="pd-stock ok">● موجود در انبار (<?= $numFa($stock) ?> <?= $h($unitLabel) ?>)</span>
            <?php else: ?>
                <span class="pd-stock no">● ناموجود</span>
            <?php endif; ?>
        </div>
        <h1 class="pd-title"><?= $h($product['name']) ?></h1>

        <?php if (!empty($product['description'])): ?>
            <div class="pd-desc"><?= nl2br($h($product['description'])) ?></div>
        <?php endif; ?>

        <!-- ─── قیمت ─── -->
        <?php if ($basePrice !== null && $basePrice > 0): ?>
            <div class="pd-price-block">

                <?php if ($hasGroupDiscount && $userGroupData): ?>
                    <?php
                    // رنگ بج بر اساس کد ثابت گروه؛ مستقل از عنوان نمایشی گروه
                    $groupCode = strtolower((string)($userGroupData['code'] ?? $userGroup));
                    $badgeClass = match ($groupCode) {
                        'gold'   => 'pd-badge-gold',
                        'silver' => 'pd-badge-silver',
                        'bronze' => 'pd-badge-bronze',
                        default  => 'pd-badge-default',
                    };
                    ?>

                    <!-- بج تخفیف داینامیک -->
                    <div class="pd-price-badge <?= $badgeClass ?>">
                        <span class="pd-price-badge-pct"><?= $numFa($userPricing['discount_percent'], 0) ?>٪</span>
                        <span class="pd-price-badge-label">تخفیف سطح <?= $h($userGroupData['name']) ?></span>
                    </div>

                    <!-- قیمت اصلی -->
                    <div class="pd-price-main">
                        <div class="pd-price-value">
                            <b><?= $moneyFa($userPricing['unit_price']) ?></b>
                            <small>/ <?= $h($unitLabel) ?></small>
                        </div>
                    </div>

                    <!-- قیمت پایه و سود -->
                    <div class="pd-price-meta">
                        <span class="pd-price-base">قیمت پایه: <span class="pd-price-strike"><?= $moneyFa($basePrice) ?></span></span>
                        <span class="pd-price-save">✅ <b><?= $moneyFa($basePrice - $userPricing['unit_price']) ?></b> سود شما</span>
                    </div>
                <?php else: ?>
                    <div class="pd-price-main">
                        <div class="pd-price-value">
                            <b><?= $moneyFa($basePrice) ?></b>
                            <small>/ <?= $h($unitLabel) ?></small>
                        </div>
                    </div>
                    <div class="pd-price-meta">
                        <span class="pd-price-label">قیمت همکاری (عمده)</span>
                    </div>
                <?php endif; ?>
                <!-- پله‌ها -->
                <?php if ($tiers && !$hasGroupDiscount): ?>

                    <div class="pd-tier">


                        <?php foreach ($tiers as $t): ?>

                            <div class="pd-tier-note">

                                <span class="pd-tier-qty">
                                    بالای <?= $numFa($t['min_qty']) ?> <?= $h($unitLabel) ?>
                                </span>

                                <span class="pd-tier-discount">
                                    <?= $numFa($t['discount_percent'], 0) ?>٪ تخفیف
                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php elseif ($tiers && $hasGroupDiscount): ?>

                    <div class="pd-tier-note">
                        ✓ تخفیف سطح شما بالاتر از تخفیف پله‌ای است و خودکار اعمال شد.
                    </div>

                <?php endif; ?>
            </div>
        <?php elseif ($basePrice === 0): ?>
            <div class="pd-no-price">💬 قیمت به‌زودی — تماس بگیرید</div>
        <?php else: ?>
            <div class="pd-no-price">💬 استعلام قیمت</div>
        <?php endif; ?>

        <!-- ─── سفارش (تعداد + دکمه‌ها) ─── -->
        <!-- ─── سفارش ─── -->
        <?php if ($inStock && $basePrice !== null): ?>
            <div class="pd-order-block" id="pdOrderBlock" data-product-id="<?= $productId ?>">

                <!-- حالت اولیه: انتخاب تعداد -->
                <div id="pdAddState" style="<?= $cartItemQty > 0 ? 'display:none;' : '' ?>">
                    <div class="pd-qty-row">
                        <label class="pd-qty-label">تعداد:</label>
                        <div class="pd-stepper" data-min="<?= $moq ?>" data-max="<?= $stock ?>">
                            <button type="button" class="pd-step pd-minus" aria-label="کمتر">−</button>
                            <input type="text" inputmode="numeric" class="pd-qty-input" value="<?= $moq ?>">
                            <button type="button" class="pd-step pd-plus" aria-label="بیشتر">+</button>
                        </div>
                        <span class="pd-qty-hint">حداقل <?= number_format($moq) ?> جفت</span>
                    </div>
                </div>

                <!-- حالت بعد از افزودن: تعداد موجود در سبد -->
                <div id="pdCartState" style="<?= $cartItemQty > 0 ? '' : 'display:none;' ?>">
                    <div class="pd-qty-row">
                        <label class="pd-qty-label">در سبد شما:</label>
                        <div class="pd-stepper pd-stepper-cart" data-min="1" data-max="<?= $stock ?>" data-product-id="<?= $productId ?>">
                            <button type="button" class="pd-step pd-minus-cart" aria-label="کمتر">−</button>
                            <button type="button" class="pd-step pd-trash-btn" id="pdTrashBtn" style="display:none;" aria-label="حذف از سبد" title="حذف از سبد">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14M10 10v6m4-6v6" />
                                </svg>
                            </button>
                            <input type="text" inputmode="numeric" class="pd-qty-input pd-qty-input-cart" value="<?= $cartItemQty ?: 1 ?>" readonly>
                            <button type="button" class="pd-step pd-plus-cart" aria-label="بیشتر">+</button>
                        </div>
                    </div>
                </div>

                <!-- دکمه افزودن فقط تا قبل از ورود محصول به سبد نمایش داده می‌شود؛ سفارش فوری همیشه باقی می‌ماند -->
                <div class="pd-actions-row">
                    <button type="button" class="pd-btn pd-btn-cart" id="pdAddCartBtn"
                        data-product-id="<?= $productId ?>" data-action="cart"
                        style="<?= $cartItemQty > 0 ? 'display:none;' : '' ?>">
                        <svg ...>...</svg>
                        <span>افزودن به سبد</span>
                    </button>
                    <button type="button" class="pd-btn pd-btn-buy" data-product-id="<?= $productId ?>" data-action="buy">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <path d="M5 12h14M12 5l7 7-7 7" />
                        </svg>
                        <span>سفارش فوری</span>
                    </button>
                </div>

                <p class="pd-hint">
                    <b>سفارش فوری</b> = یک کلیک تا پرداخت — سبد رو دور می‌زنه و مستقیم می‌ره به تکمیل سفارش.
                </p>
            </div>

        <?php elseif (!$inStock): ?>
            <div class="pd-out-block">
                <p>این محصول فعلاً ناموجود است.</p>
                <a href="/custom-order" class="pd-btn pd-btn-outline">درخواست تولید اختصاصی ←</a>
            </div>
        <?php else: ?>
            <div class="pd-out-block">
                <p>قیمت این محصول به‌زودی اعلام می‌شود.</p>
                <a href="/custom-order" class="pd-btn pd-btn-outline">استعلام قیمت ←</a>
            </div>
        <?php endif; ?>

        <!-- ⭐ Trust Row (اینجا، بعد از دکمه‌ها) -->
        <div class="pd-trust">
            <div class="pd-trust-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 5h13v11H1zM14 8h4l3 3v5h-7" />
                    <circle cx="5.5" cy="17" r="2" />
                    <circle cx="16.5" cy="17" r="2" />
                </svg>
                <span>ارسال سریع</span>
            </div>
            <div class="pd-trust-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z" />
                    <path d="M9 12l2 2 4-4" />
                </svg>
                <span>ضمانت کیفیت</span>
            </div>
            <div class="pd-trust-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20.6 13.4L11 3.8A2 2 0 009.6 3.2H4a1 1 0 00-1 1v5.6c0 .5.2 1 .6 1.4l9.6 9.6a2 2 0 002.8 0l4.6-4.6a2 2 0 000-2.8z" />
                    <circle cx="7.5" cy="7" r="1" />
                </svg>
                <span>قیمت همکاری</span>
            </div>
        </div>

        <!-- سفارش اختصاصی -->
        <a href="/custom-order?ref=<?= $productId ?>" class="pd-custom-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M12 2l3 6 6 1-4.5 4.5L18 20l-6-3-6 3 1.5-6.5L3 9l6-1z" />
            </svg>
            <span>تولید با برند خودت؟ درخواست سفارش اختصاصی</span>
            <span class="pd-custom-arrow">←</span>
        </a>

    </div>

</section>
<!-- ═══ مشخصات فنی ═══ -->

<?php if ($specs): ?>

    <section class="pd-specs-section">

        <h2 class="pd-section-title">مشخصات فنی</h2>

        <div class="pd-specs-grid">

            <?php foreach ($specs as $s): ?>

                <div class="pd-spec-item">
                    <span class="pd-spec-key"><?= $h($s['spec_key']) ?></span>
                    <span class="pd-spec-val"><?= $h($s['spec_value']) ?></span>
                </div>

            <?php endforeach; ?>

        </div>

    </section>

<?php endif; ?>


<!-- ═══ محصولات مشابه ═══ -->

<?php if ($related): ?>

    <section class="pd-related">

        <h2 class="pd-section-title">محصولات مشابه</h2>

        <div class="pd-related-grid">

            <?php foreach ($related as $r): ?>

                <a href="/product/<?= $h($r['slug']) ?>" class="pd-related-card">

                    <div class="pd-related-img">
                        <?php if (!empty($r['img'])): ?>
                            <img src="<?= $h($r['img']) ?>" alt="<?= $h($r['name']) ?>" loading="lazy">
                        <?php endif; ?>
                    </div>

                    <div class="pd-related-info">

                        <span class="pd-related-code">کد <?= $h($r['code']) ?></span>

                        <h3><?= $h($r['name']) ?></h3>

                        <b>
                            <?= $r['price'] !== null
                                ? $moneyFa((float)$r['price']) . ''
                                : 'استعلام' ?>
                        </b>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    </section>

<?php endif; ?>


<!-- ═══ Lightbox گالری ═══ -->

<?php if (!empty($productImages) && count($productImages) > 1): ?>

    <div class="pdg-lb" id="pdgLb" role="dialog" aria-hidden="true">

        <button type="button" class="pdg-lb-close" aria-label="بستن">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6L6 18M6 6l12 12" />
            </svg>
        </button>

        <button type="button" class="pdg-lb-nav prev" aria-label="قبلی">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 18l-6-6 6-6" />
            </svg>
        </button>

        <button type="button" class="pdg-lb-nav next" aria-label="بعدی">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 18l6-6-6-6" />
            </svg>
        </button>

        <img class="pdg-lb-img" id="pdgLbImg" src="" alt="">

        <div class="pdg-lb-counter" id="pdgLbCounter">
            ۱ / <?= $moneyFa(count($productImages)) ?>
        </div>

    </div>

<?php elseif (!empty($productImages)): ?>

    <div class="pdg-lb" id="pdgLb" role="dialog" aria-hidden="true">

        <button type="button" class="pdg-lb-close" aria-label="بستن">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6L6 18M6 6l12 12" />
            </svg>
        </button>

        <img class="pdg-lb-img" id="pdgLbImg" src="" alt="">

    </div>

<?php endif; ?>


<!-- ═══ JS گالری ═══ -->

<?php if (!empty($productImages)): ?>
    <script src="/assets/js/product-gallery.js" defer></script>
<?php endif; ?>

<script>
    (function() {
        'use strict';

        var orderBlock = document.getElementById('pdOrderBlock');
        if (!orderBlock) return;

        var productId = orderBlock.dataset.productId;
        var addState = document.getElementById('pdAddState');
        var cartState = document.getElementById('pdCartState');
        var addButton = document.getElementById('pdAddCartBtn');
        var buyButton = orderBlock.querySelector('.pd-btn-buy');
        var cartStepper = orderBlock.querySelector('.pd-stepper-cart');
        var cartQtyInput = orderBlock.querySelector('.pd-qty-input-cart');
        var cartMinus = orderBlock.querySelector('.pd-minus-cart');
        var cartPlus = orderBlock.querySelector('.pd-plus-cart');
        var trashButton = document.getElementById('pdTrashBtn');
        var stockMax = cartStepper ? (parseInt(cartStepper.dataset.max, 10) || 999999) : 999999;

        function faToEn(value) {
            return String(value).replace(/[۰-۹]/g, function(d) {
                return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);
            }).replace(/[٠-٩]/g, function(d) {
                return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);
            });
        }

        function enToFa(value) {
            return String(value).replace(/\d/g, function(d) {
                return '۰۱۲۳۴۵۶۷۸۹' [d];
            });
        }

        function getQty() {
            var stepper = orderBlock.querySelector('#pdAddState .pd-stepper');
            if (!stepper) return 1;
            var input = stepper.querySelector('.pd-qty-input');
            var min = parseInt(stepper.dataset.min, 10) || 1;
            var value = parseInt(faToEn(input ? input.value : min).replace(/[^\d]/g, ''), 10) || min;
            return Math.max(min, Math.min(stockMax, value));
        }

        function currentCartQty() {
            return parseInt(faToEn(cartQtyInput ? cartQtyInput.value : '1'), 10) || 1;
        }

        function showCartState(qty) {
            qty = Math.max(1, Math.min(stockMax, parseInt(qty, 10) || 1));
            if (cartQtyInput) cartQtyInput.value = enToFa(qty);
            if (addState) addState.style.display = 'none';
            if (cartState) cartState.style.display = '';
            if (addButton) addButton.style.display = 'none';
            syncCartControls(qty);
        }

        function showAddState() {
            if (cartState) cartState.style.display = 'none';
            if (addState) addState.style.display = '';
            if (addButton) addButton.style.display = '';
        }

        function syncCartControls(qty) {
            if (cartMinus) cartMinus.style.display = qty <= 1 ? 'none' : '';
            if (trashButton) trashButton.style.display = qty <= 1 ? '' : 'none';
            if (cartPlus) {
                cartPlus.disabled = qty >= stockMax;
                cartPlus.style.opacity = qty >= stockMax ? '.45' : '';
            }
        }

        function authRequired() {
            if (typeof showToast === 'function') showToast('🔒 برای خرید، ابتدا وارد شوید یا ثبت‌نام کنید');
            if (typeof openAuthModal === 'function') {
                openAuthModal('register');
            } else {
                var modal = document.getElementById('authModalOverlay');
                if (modal) {
                    modal.classList.add('show');
                    document.body.style.overflow = 'hidden';
                }
            }
        }

        function postCart(action, qty, onSuccess, button) {
            /* مهمان: هیچ درخواستی به API ارسال نشود */
            if (window.RABETI_AUTH !== true) {
                authRequired();
                return;
            }

            var oldHtml = button ? button.innerHTML : '';
            if (button) {
                button.disabled = true;
                button.classList.add('is-loading');
            }

            var fd = new FormData();
            fd.append('action', action);
            fd.append('product_id', productId);
            if (qty !== null && qty !== undefined) fd.append('qty', qty);

            fetch('/api/cart', {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
                    }
                })
                .then(function(response) {
                    return response.text().then(function(body) {
                        return {
                            status: response.status,
                            body: body
                        };
                    });
                })
                .then(function(result) {
                    if (result.status === 401) {
                        authRequired();
                        return;
                    }
                    var data;
                    try {
                        data = JSON.parse(result.body);
                    } catch (error) {
                        throw new Error('پاسخ نامعتبر سرور');
                    }
                    if (!data.ok) {
                        if (typeof showToast === 'function') showToast('⚠️ ' + (data.error || 'خطا در بروزرسانی سبد'));
                        return;
                    }
                    if (typeof updateCartBadges === 'function' && data.count !== undefined) updateCartBadges(data.count);
                    if (typeof onSuccess === 'function') onSuccess(data);
                })
                .catch(function(error) {
                    console.error('CART_ERROR:', error);
                    if (typeof showToast === 'function') showToast('⚠️ خطا در ارتباط با سرور');
                })
                .finally(function() {
                    if (button) {
                        button.disabled = false;
                        button.classList.remove('is-loading');
                        if (button === addButton || button === buyButton) button.innerHTML = oldHtml;
                    }
                });
        }

        /* Stepper انتخاب تعداد پیش از افزودن */
        var initialStepper = orderBlock.querySelector('#pdAddState .pd-stepper');
        if (initialStepper) {
            var initialInput = initialStepper.querySelector('.pd-qty-input');
            var minQty = parseInt(initialStepper.dataset.min, 10) || 1;
            var maxQty = parseInt(initialStepper.dataset.max, 10) || stockMax;

            function writeInitialQty(value) {
                value = Math.max(minQty, Math.min(maxQty, parseInt(value, 10) || minQty));
                initialInput.value = enToFa(value);
            }
            initialStepper.querySelector('.pd-plus')?.addEventListener('click', function() {
                writeInitialQty(getQty() + 1);
            });
            initialStepper.querySelector('.pd-minus')?.addEventListener('click', function() {
                writeInitialQty(getQty() - 1);
            });
            initialInput.addEventListener('blur', function() {
                writeInitialQty(getQty());
            });
            initialInput.addEventListener('input', function() {
                var value = faToEn(initialInput.value).replace(/[^\d]/g, '');
                if (value) initialInput.value = enToFa(value);
            });
        }

        /* افزودن به سبد */
        if (addButton) addButton.addEventListener('click', function(event) {
            event.preventDefault();
            var qty = getQty();
            postCart('add', qty, function() {
                showCartState(qty);
                if (typeof showToast === 'function') showToast('✓ به سبد اضافه شد');
            }, addButton);
        });

        /* سفارش فوری مستقل می‌ماند و همیشه قابل مشاهده است */
        /* سفارش فوری: اگر محصول در سبد است، فقط ریدایرکت؛ وگرنه اضافه کن و برو */
        if (buyButton) buyButton.addEventListener('click', function(event) {
            event.preventDefault();
            if (window.RABETI_AUTH !== true) {
                authRequired();
                return;
            }

            // آیا محصول از قبل در سبد است؟
            var isInCart = (cartState && cartState.style.display !== 'none');

            // اگر در سبد است → فقط ریدایرکت
            if (isInCart) {
                if (typeof showToast === 'function') showToast('✓ در حال انتقال به تکمیل سفارش...');
                setTimeout(function() {
                    window.location.href = '/checkout-address';
                }, 300);
                return;
            }

            // اگر در سبد نیست → اضافه کن و برو
            var qty = getQty();
            var fd = new FormData();
            fd.append('action', 'add'); // ⭐ این خط قبلاً نبود!
            fd.append('product_id', productId);
            fd.append('qty', qty);

            buyButton.disabled = true;
            var label = buyButton.querySelector('span');
            var oldLabel = label ? label.textContent : '';
            if (label) label.textContent = 'صبر کنید...';

            fetch('/api/cart', {
                method: 'POST',
                body: fd,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
                }
            }).then(function(response) {
                if (response.status === 401) {
                    authRequired();
                    throw new Error('AUTH_REQUIRED');
                }
                return response.json();
            }).then(function(data) {
                if (!data.ok) throw new Error(data.error || 'خطا');
                if (typeof updateCartBadges === 'function' && data.count !== undefined) updateCartBadges(data.count);
                if (typeof showToast === 'function') showToast('✓ در حال انتقال به تکمیل سفارش...');
                setTimeout(function() {
                    window.location.href = '/checkout-address';
                }, 500);
            }).catch(function(error) {
                if (error.message !== 'AUTH_REQUIRED') {
                    console.error('BUY_NOW_ERROR:', error);
                    if (typeof showToast === 'function') showToast('⚠️ ' + (error.message || 'خطا در ارتباط با سرور'));
                }
            }).finally(function() {
                buyButton.disabled = false;
                if (label) label.textContent = oldLabel;
            });
        });

        /* افزایش تعداد در سبد */
        if (cartPlus) cartPlus.addEventListener('click', function() {
            var next = currentCartQty() + 1;
            if (next > stockMax) {
                if (typeof showToast === 'function') showToast('حداکثر موجودی قابل سفارش است');
                return;
            }
            postCart('update', next, function() {
                if (cartQtyInput) cartQtyInput.value = enToFa(next);
                syncCartControls(next);
            }, cartPlus);
        });

        /* کاهش تعداد در سبد */
        if (cartMinus) cartMinus.addEventListener('click', function() {
            var next = currentCartQty() - 1;
            if (next < 1) return;
            postCart('update', next, function() {
                if (cartQtyInput) cartQtyInput.value = enToFa(next);
                syncCartControls(next);
            }, cartMinus);
        });

        /* حذف کامل محصول از سبد */
        if (trashButton) trashButton.addEventListener('click', function() {
            postCart('remove', null, function() {
                /* پس از حذف، تعداد انتخاب اولیه به حداقل سفارش برگردد */
                var firstStepper = orderBlock.querySelector('#pdAddState .pd-stepper');
                if (firstStepper) {
                    var firstInput = firstStepper.querySelector('.pd-qty-input');
                    var firstMin = parseInt(firstStepper.dataset.min, 10) || 1;
                    if (firstInput) firstInput.value = enToFa(firstMin);
                }
                showAddState();
                if (typeof showToast === 'function') showToast('✓ محصول از سبد حذف شد');
            }, trashButton);
        });

    })();
    /* ═══════════ آپدیت همه‌ی Badgeها ═══════════ */
    function updateCartBadges(count) {
        if (count === undefined || count === null) return;

        var fa = function(n) {
            return String(n).replace(/\d/g, function(d) {
                return '۰۱۲۳۴۵۶۷۸۹' [d];
            });
        };

        var ids = ['cartBadge', 'cartBadgeFloat', 'cartCount', 'cartBadgeMobile'];

        ids.forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;

            el.textContent = fa(count);
            el.style.removeProperty('display');
            el.classList.add('has-count');
        });
    }
</script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
