<?php
/*  products.php Auto-detect bootstrap (local + host) */
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
require_once ROOT_PATH . '/app/services/PricingService.php';

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$catSlug     = trim($_GET['cat'] ?? '');
$searchQuery = trim((string)($_GET['q'] ?? ''));
$activeCat   = null;

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
        '.' => '٫',
    ]);
    return $formatted . ' تومان';
};

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

/* ============================================================
 * دسته فعال
 * ============================================================ */
if ($catSlug !== '') {
    $stmt = db()->prepare("SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$catSlug]);
    $activeCat = $stmt->fetch();

    if (!$activeCat) {
        http_response_code(404);
        $pageTitle = 'دسته یافت نشد';
        $metaRobots = 'noindex';
        ob_start();
?>
        <div class="empty-state">
            <h2>این دسته پیدا نشد</h2>
            <a href="/products" class="btn btn-orange">همه محصولات</a>
        </div>
<?php
        $content = ob_get_clean();
        require ROOT_PATH . '/views/layouts/main.php';
        exit;
    }
}

/* ============================================================
 * پیدا کردن همه IDهای مرتبط
 * ============================================================ */
$categoryIds   = [];
$isParent      = false;
$activeCatPath = [];

if ($activeCat) {
    $activeId = (int)$activeCat['id'];
    $stmt = db()->prepare("SELECT id, name, slug FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$activeId]);
    $children = $stmt->fetchAll();

    if ($children) {
        $isParent = true;
        $categoryIds[] = $activeId;
        foreach ($children as $ch) {
            $categoryIds[] = (int)$ch['id'];
        }
    } else {
        $categoryIds[] = $activeId;
        if (!empty($activeCat['parent_id'])) {
            $stmt = db()->prepare("SELECT id, name, slug FROM categories WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$activeCat['parent_id']]);
            $parentRow = $stmt->fetch();
            if ($parentRow) {
                $activeCatPath[] = $parentRow;
            }
        }
    }
    $activeCatPath[] = [
        'id'   => $activeId,
        'name' => $activeCat['name'],
        'slug' => $activeCat['slug'],
    ];
}

/* ============================================================
 * محصولات
 * ============================================================ */
$sql = "
    SELECT p.id, p.code, p.name, p.slug, p.base_moq, p.base_price AS price,
        (SELECT spec_value FROM product_specs WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1) AS unit_code,
        GREATEST(0, COALESCE(inv.qty_physical, 0) - COALESCE(inv.reserved, 0) - COALESCE(inv.unsellable, 0)) AS qty_available,
        c.name AS cat_name,
        (SELECT image_path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_main DESC, pi.sort_order ASC LIMIT 1) AS img
    FROM products p
    JOIN categories c ON c.id = p.category_id
    LEFT JOIN inventory inv ON inv.product_id = p.id
    WHERE p.is_active = 1
";

$params = [];
if ($categoryIds) {
    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
    $sql .= " AND p.category_id IN ($placeholders) ";
    foreach ($categoryIds as $cid) {
        $params[] = $cid;
    }
}
if ($searchQuery !== '') {
    $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR c.name LIKE ?) ";
    $like = '%' . $searchQuery . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql .= " ORDER BY p.id DESC LIMIT 60";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

/* ============================================================
 * عنوان صفحه
 * ============================================================ */
$pageTitle = ($activeCat ? $activeCat['name'] . ' — ' : '') . 'کاتالوگ محصولات | رابطی';
$pageDescription = 'خرید عمده جوراب مردانه، زنانه، بچگانه و اسپرت رابطی با موجودی واقعی و قیمت همکاری.';
$canonicalPath = $activeCat ? '/products?cat=' . rawurlencode($activeCat['slug']) : '/products';
if ($searchQuery !== '') {
    $metaRobots = 'noindex, follow';
}

/* ============================================================
 * گروه کاربر (یکبار برای همه محصولات)
 * ============================================================ */
$userGroup = current_user_group();
$userGroupData = PricingService::getGroup(db(), $userGroup);
$userGroupName = $userGroupData['name'] ?? 'عادی';

ob_start();
?>

<!-- ═══ سربرگ ═══ -->
<section class="page-head">
    <h1 class="section-title"><?= $activeCat ? $h($activeCat['name']) : 'همه محصولات' ?></h1>
    <p class="section-sub"><?= $numFa(count($products)) ?> مدل — قیمت همکاری، مستقیم از تولیدی</p>
</section>

<!-- ═══ Breadcrumb ═══ -->
<nav class="breadcrumb" aria-label="مسیر صفحه">
    <a href="/">خانه</a> ›
    <a href="/products">محصولات</a>
    <?php foreach ($activeCatPath as $pathItem): ?>
        ›
        <?php if ($pathItem['slug'] === ($activeCat['slug'] ?? '')): ?>
            <span aria-current="page"><?= $h($pathItem['name']) ?></span>
        <?php else: ?>
            <a href="/products?cat=<?= $h($pathItem['slug']) ?>" data-scroll-preserve><?= $h($pathItem['name']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

<!-- ═══ زیرمجموعه‌ها ═══ -->
<?php if ($isParent): ?>
    <?php
    $stmt = db()->prepare("
        SELECT c.id, c.name, c.slug, c.image_path,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS product_count
        FROM categories c
        WHERE c.parent_id = ? AND c.is_active = 1
        ORDER BY c.sort_order ASC, c.id ASC
    ");
    $stmt->execute([(int)$activeCat['id']]);
    $subCats = $stmt->fetchAll();
    ?>
    <?php if ($subCats): ?>
        <div class="prod-subcats-row" style="display:flex;gap:10px;overflow-x:auto;padding:4px 2px 16px;margin:0 0 18px;scrollbar-width:thin;">
            <?php foreach ($subCats as $sub): ?>
                <a href="/products?cat=<?= $h($sub['slug']) ?>" data-scroll-preserve class="prod-subcat-chip" style="flex:0 0 auto;display:inline-flex;align-items:center;gap:8px;padding:8px 16px;background:#fff;border:1px solid rgba(42, 95, 120, 0.14);border-radius:999px;color:var(--teal-800, #1f4a5e);text-decoration:none;font-size:.82rem;font-weight:600;white-space:nowrap;transition:all .25s ease;">
                    <?php if (!empty($sub['image_path'])): ?>
                        <img src="<?= $h($sub['image_path']) ?>" alt="" style="width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                    <?php endif; ?>
                    <span><?= $h($sub['name']) ?></span>
                    <span style="background:rgba(0,102,107,.08);color:var(--teal-700, #00666b);padding:1px 8px;border-radius:999px;font-size:.7rem;"><?= $numFa((int)$sub['product_count']) ?> محصول</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ═══ سرچ پریمیوم ═══ -->
<link rel="stylesheet" href="/assets/css/search-premium.css?v=1">
<?php
/* رفع N+1: یک کوئری برای شمارش همه چیپ‌ها */
$chipCats = db()->query("
    SELECT c.id, c.name, c.slug,
        (SELECT COUNT(*) FROM products p
            INNER JOIN categories cc ON cc.id = p.category_id
            WHERE p.is_active = 1 AND (cc.id = c.id OR cc.parent_id = c.id)
        ) AS product_count
    FROM categories c
    WHERE c.is_active = 1 AND c.parent_id IS NULL
    ORDER BY c.sort_order ASC, c.id ASC
")->fetchAll();
?>
<div class="rp-search-wrap">
    <form method="get" action="/products" class="rp-search" id="rpSearchForm" role="search">
        <?php if ($activeCat): ?>
            <input type="hidden" name="cat" value="<?= $h($activeCat['slug']) ?>">
        <?php endif; ?>
        <div class="rp-search-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="7" />
                <path d="M21 21l-4.35-4.35" />
            </svg>
        </div>
        <input type="text" name="q" class="rp-search-input" placeholder="دنبال چی می‌گردی؟ مثلاً مچی مردانه..." value="<?= $h($searchQuery) ?>" autocomplete="off" id="rpSearchInput" aria-label="جستجوی محصول">
        <button type="button" class="rp-search-clear <?= $searchQuery !== '' ? 'is-visible' : '' ?>" id="rpSearchClear" aria-label="پاک کردن جستجو">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6L6 18M6 6l12 12" />
            </svg>
        </button>
        <button type="submit" class="rp-search-submit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7" />
                <path d="M21 21l-4.35-4.35" />
            </svg>
            <span>جستجو</span>
        </button>
    </form>
    <?php if ($chipCats): ?>
        <div class="rp-search-cats">
            <a href="/products<?= $searchQuery !== '' ? '?q=' . urlencode($searchQuery) : '' ?>" data-scroll-preserve class="rp-chip <?= !$activeCat ? 'is-active' : '' ?>">همه</a>
            <?php foreach ($chipCats as $chip): ?>
                <?php
                $isActive = $activeCat && (int)$activeCat['id'] === (int)$chip['id'];
                $chipUrl = '/products?cat=' . urlencode($chip['slug']);
                if ($searchQuery !== '') {
                    $chipUrl .= '&q=' . urlencode($searchQuery);
                }
                ?>
                <a href="<?= $h($chipUrl) ?>" data-scroll-preserve class="rp-chip <?= $isActive ? 'is-active' : '' ?>" <?= $isActive ? 'aria-current="page"' : '' ?>><?= $h($chip['name']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    (function() {
        'use strict';
        var input = document.getElementById('rpSearchInput');
        var clear = document.getElementById('rpSearchClear');
        var form = document.getElementById('rpSearchForm');
        var search = form ? form.querySelector('.rp-search') : null;
        if (!input || !search) return;
        input.addEventListener('focus', function() {
            search.classList.add('is-focused');
        });
        input.addEventListener('blur', function() {
            search.classList.remove('is-focused');
        });

        function toggleClear() {
            if (!clear) return;
            clear.classList.toggle('is-visible', input.value.trim().length > 0);
        }
        input.addEventListener('input', toggleClear);
        toggleClear();
        if (clear) {
            clear.addEventListener('click', function() {
                input.value = '';
                toggleClear();
                input.focus();
            });
        }
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                input.value = '';
                toggleClear();
            }
        });
    })();
</script>

<!-- ═══ گرید محصولات ═══ -->
<?php if ($products): ?>
    <?php if ($searchQuery !== '' || $activeCat): ?>
        <div class="rp-search-info">
            <div class="rp-search-info-text">
                <?php if ($searchQuery !== '' && $activeCat): ?>
                    نتیجه‌ی جستجوی «<b><?= $h($searchQuery) ?></b>» در دسته‌ی <b><?= $h($activeCat['name']) ?></b> — <b><?= $numFa(count($products)) ?></b> مورد یافت شد
                <?php elseif ($searchQuery !== ''): ?>
                    نتیجه‌ی جستجوی «<b><?= $h($searchQuery) ?></b>» در همه محصولات — <b><?= $numFa(count($products)) ?></b> مورد یافت شد
                <?php elseif ($activeCat): ?>
                    نمایش <b><?= $numFa(count($products)) ?></b> محصول در دسته‌ی <b><?= $h($activeCat['name']) ?></b>
                <?php else: ?>
                    نمایش <b><?= $numFa(count($products)) ?></b> محصول
                <?php endif; ?>
            </div>
            <?php if ($searchQuery !== '' || $activeCat): ?>
                <a href="/products" class="rp-search-info-clear" data-scroll-preserve>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 6L6 18M6 6l12 12" />
                    </svg>
                    پاک کردن فیلترها
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="prod-grid">
        <?php foreach ($products as $p): ?>
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
<?php else: ?>
    <?php if ($searchQuery !== ''): ?>
        <div class="rp-search-empty">
            <div class="rp-search-empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M21 21l-4.35-4.35" />
                    <path d="M8.5 11h5M11 8.5v5" />
                </svg>
            </div>
            <h3>چیزی پیدا نشد</h3>
            <p>برای «<b><?= $h($searchQuery) ?></b>» محصولی پیدا نکردیم.<br>امتحان کن با کلمه‌ی دیگه یا یه دسته رو انتخاب کن.</p>
            <a href="/products" class="btn btn-orange">مشاهده همه محصولات</a>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon" aria-hidden="true">📦</div>
            <h2>فعلاً محصولی در این دسته نیست</h2>
            <a href="/products" class="btn btn-orange">مشاهده همه محصولات</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

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
                badgeEl.textContent = enToFa(Math.round(data.discount_percent)) + '٪ تخفیف سطح ' + (data.group_name || '');
            }
        }

        /* ─────── همگام‌سازی آیکون‌های سبد ─────── */
        function syncCartIndicators() {
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
        syncCartIndicators();

    })();
</script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
