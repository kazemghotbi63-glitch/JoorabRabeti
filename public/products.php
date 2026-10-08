<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$catSlug     = trim($_GET['cat'] ?? '');
$searchQuery = trim((string)($_GET['q'] ?? ''));
$activeCat   = null;

/* ============================================================
 * دسته فعال
 * ============================================================ */
if ($catSlug !== '') {

    $stmt = db()->prepare("
        SELECT *
        FROM categories
        WHERE slug = ?
          AND is_active = 1
        LIMIT 1
    ");

    $stmt->execute([$catSlug]);
    $activeCat = $stmt->fetch();

    if (!$activeCat) {

        http_response_code(404);

        $pageTitle = 'دسته یافت نشد';

        ob_start();
?>

        <div class="empty-state">
            <h2>این دسته پیدا نشد</h2>
            <a href="/products" class="btn btn-orange">
                همه محصولات
            </a>
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

    $stmt = db()->prepare("
        SELECT id, name, slug
        FROM categories
        WHERE parent_id = ?
          AND is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");
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
            $stmt = db()->prepare("
                SELECT id, name, slug
                FROM categories
                WHERE id = ?
                LIMIT 1
            ");
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
    SELECT
        p.id,
        p.code,
        p.name,
        p.slug,
        p.base_moq,

        (
            SELECT pp.price
            FROM product_prices pp
            WHERE pp.product_id = p.id
              AND pp.customer_group = 'wholesale'
              AND pp.min_qty = 1
            ORDER BY pp.id DESC
            LIMIT 1
        ) AS price,

        COALESCE(inv.qty_available, 0) AS qty_available,

        c.name AS cat_name,

        (
            SELECT image_path
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_main DESC, pi.sort_order ASC
            LIMIT 1
        ) AS img

    FROM products p

    JOIN categories c
        ON c.id = p.category_id

    LEFT JOIN inventory inv
        ON inv.product_id = p.id

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
    $sql .= "
        AND (
            p.name LIKE ?
            OR p.code LIKE ?
            OR c.name LIKE ?
        )
    ";
    $like = '%' . $searchQuery . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= "
    ORDER BY p.id DESC
    LIMIT 60
";

$stmt = db()->prepare($sql);
$stmt->execute($params);

$products = $stmt->fetchAll();

/* ============================================================
 * عنوان صفحه
 * ============================================================ */

$pageTitle =
    ($activeCat ? $activeCat['name'] . ' — ' : '')
    . 'کاتالوگ محصولات | رابطی';

$pageDescription = 'خرید عمده جوراب مردانه، زنانه، بچگانه و اسپرت رابطی با موجودی واقعی و قیمت همکاری.';

ob_start();
?>

<!-- ═══ سربرگ ═══ -->

<section class="page-head">

    <h1 class="section-title">
        <?= $activeCat
            ? $h($activeCat['name'])
            : 'همه محصولات'
        ?>
    </h1>

    <p class="section-sub">
        <?= count($products) ?> مدل
        — قیمت همکاری، مستقیم از تولیدی
    </p>

</section>


<!-- ═══ Breadcrumb ═══ -->

<nav class="breadcrumb">

    <a href="/">خانه</a> ›

    <a href="/products">محصولات</a>

    <?php foreach ($activeCatPath as $pathItem): ?>

        ›

        <?php if ($pathItem['slug'] === ($activeCat['slug'] ?? '')): ?>
            <span><?= $h($pathItem['name']) ?></span>
        <?php else: ?>
            <a href="/products?cat=<?= $h($pathItem['slug']) ?>"
                data-scroll-preserve>
                <?= $h($pathItem['name']) ?>
            </a>
        <?php endif; ?>

    <?php endforeach; ?>

</nav>


<!-- ═══ زیرمجموعه‌ها ═══ -->

<?php if ($isParent): ?>

    <?php
    $stmt = db()->prepare("
        SELECT
            c.id,
            c.name,
            c.slug,
            c.image_path,
            (
                SELECT COUNT(*) FROM products p
                WHERE p.category_id = c.id AND p.is_active = 1
            ) AS product_count
        FROM categories c
        WHERE c.parent_id = ?
          AND c.is_active = 1
        ORDER BY c.sort_order ASC, c.id ASC
    ");
    $stmt->execute([(int)$activeCat['id']]);
    $subCats = $stmt->fetchAll();
    ?>

    <?php if ($subCats): ?>

        <div class="prod-subcats-row"
            style="display:flex;gap:10px;overflow-x:auto;padding:4px 2px 16px;margin:0 0 18px;scrollbar-width:thin;">

            <?php foreach ($subCats as $sub): ?>

                <a href="/products?cat=<?= $h($sub['slug']) ?>"
                    data-scroll-preserve
                    class="prod-subcat-chip"
                    style="flex:0 0 auto;display:inline-flex;align-items:center;gap:8px;padding:8px 16px;background:#fff;border:1px solid rgba(42, 95, 120, 0.14);border-radius:999px;color:var(--teal-800, #1f4a5e);text-decoration:none;font-size:.82rem;font-weight:600;white-space:nowrap;transition:all .25s ease;">

                    <?php if (!empty($sub['image_path'])): ?>
                        <img src="<?= $h($sub['image_path']) ?>"
                            alt=""
                            style="width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                    <?php endif; ?>

                    <span><?= $h($sub['name']) ?></span>

                    <span style="background:rgba(0,102,107,.08);color:var(--teal-700, #00666b);padding:1px 8px;border-radius:999px;font-size:.7rem;">
                        <?= number_format((int)$sub['product_count']) ?>
                    </span>

                </a>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

<?php endif; ?>


<!-- ═══ سرچ پریمیوم ═══ -->

<link rel="stylesheet" href="/assets/css/search-premium.css">

<?php
$chipCats = db()->query("
    SELECT id, name, slug
    FROM categories
    WHERE is_active = 1
      AND parent_id IS NULL
    ORDER BY sort_order ASC, id ASC
")->fetchAll();
?>

<div class="rp-search-wrap">

    <form method="get" action="/products" class="rp-search" id="rpSearchForm">

        <?php if ($activeCat): ?>
            <input type="hidden" name="cat" value="<?= $h($activeCat['slug']) ?>">
        <?php endif; ?>

        <div class="rp-search-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="7" />
                <path d="M21 21l-4.35-4.35" />
            </svg>
        </div>

        <input
            type="text"
            name="q"
            class="rp-search-input"
            placeholder="دنبال چی می‌گردی؟ مثلاً مچی مردانه..."
            value="<?= $h($searchQuery) ?>"
            autocomplete="off"
            id="rpSearchInput">

        <button type="button"
            class="rp-search-clear <?= $searchQuery !== '' ? 'is-visible' : '' ?>"
            id="rpSearchClear"
            aria-label="پاک کردن">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 6L6 18M6 6l12 12" />
            </svg>
        </button>

        <button type="submit" class="rp-search-submit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="7" />
                <path d="M21 21l-4.35-4.35" />
            </svg>
            <span>جستجو</span>
        </button>

    </form>

    <?php if ($chipCats): ?>

        <div class="rp-search-cats">

            <a href="/products<?= $searchQuery !== '' ? '?q=' . urlencode($searchQuery) : '' ?>"
                data-scroll-preserve
                class="rp-chip <?= !$activeCat ? 'is-active' : '' ?>">
                همه
            </a>

            <?php foreach ($chipCats as $chip): ?>

                <?php
                $isActive = $activeCat && (int)$activeCat['id'] === (int)$chip['id'];

                $stmt = db()->prepare("
                    SELECT COUNT(*)
                    FROM products p
                    INNER JOIN categories c ON c.id = p.category_id
                    WHERE p.is_active = 1
                      AND (c.id = ? OR c.parent_id = ?)
                ");
                $stmt->execute([$chip['id'], $chip['id']]);
                $chipCount = (int)$stmt->fetchColumn();

                $chipUrl = '/products?cat=' . urlencode($chip['slug']);
                if ($searchQuery !== '') {
                    $chipUrl .= '&q=' . urlencode($searchQuery);
                }
                ?>

                <a href="<?= $h($chipUrl) ?>"
                    data-scroll-preserve
                    class="rp-chip <?= $isActive ? 'is-active' : '' ?>">
                    <?= $h($chip['name']) ?>
                </a>

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
                    نتیجه‌ی جستجوی «<b><?= $h($searchQuery) ?></b>»
                    در دسته‌ی <b><?= $h($activeCat['name']) ?></b>
                    — <b><?= number_format(count($products)) ?></b> مورد یافت شد
                <?php elseif ($searchQuery !== ''): ?>
                    نتیجه‌ی جستجوی «<b><?= $h($searchQuery) ?></b>»
                    در همه محصولات
                    — <b><?= number_format(count($products)) ?></b> مورد یافت شد
                <?php elseif ($activeCat): ?>
                    نمایش <b><?= number_format(count($products)) ?></b> محصول
                    در دسته‌ی <b><?= $h($activeCat['name']) ?></b>
                <?php else: ?>
                    نمایش <b><?= number_format(count($products)) ?></b> محصول
                <?php endif; ?>

            </div>

            <?php if ($searchQuery !== '' || $activeCat): ?>
                <a href="/products" class="rp-search-info-clear" data-scroll-preserve>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6L6 18M6 6l12 12" />
                    </svg>
                    پاک کردن فیلترها
                </a>
            <?php endif; ?>

        </div>

    <?php endif; ?>

    <section class="prod-grid">

        <?php foreach ($products as $index => $p): ?>

            <?php
            $stock = (int)($p['qty_available'] ?? 0);
            $moq = max(1, (int)($p['base_moq'] ?? 1));
            $canOrder = $stock >= $moq;
            ?>

            <article class="prod-card card" data-product-id="<?= (int)$p['id'] ?>">

                <a href="/product/<?= $h($p['slug']) ?>" class="prod-img">

                    <?php if (!empty($p['img'])): ?>
                        <img src="<?= $h($p['img']) ?>"
                            alt="<?= $h($p['name']) ?>"
                            loading="lazy">
                    <?php else: ?>
                        <span>🧦</span>
                    <?php endif; ?>

                </a>

                <div class="prod-info">

                    <div class="prod-cat">
                        <?= $h($p['cat_name']) ?>
                    </div>

                    <div class="prod-name">
                        <a href="/product/<?= $h($p['slug']) ?>">
                            <?= $h($p['name']) ?>
                        </a>
                    </div>

                    <div class="prod-code">
                        کد <?= $h($p['code']) ?>
                        |
                        حداقل <?= number_format($moq) ?> جفت
                    </div>

                    <div class="prod-price">

                        <?php if ($p['price'] !== null): ?>
                            <?= number_format((float)$p['price']) ?>
                        <?php else: ?>
                            استعلام
                        <?php endif; ?>

                        تومان

                        <small>قیمت همکاری</small>

                    </div>

                    <div class="prod-stock <?= $stock > 0 ? 'in' : 'out' ?>">
                        <?php if ($stock > 0): ?>
                            ● موجود در انبار
                            (<?= number_format($stock) ?> جفت)
                        <?php else: ?>
                            ● ناموجود
                        <?php endif; ?>
                    </div>

                    <?php if ($stock > 0 && $p['price'] !== null): ?>

                        <div class="stepper" data-min="<?= $moq ?>" data-max="<?= $stock ?>">
                            <button type="button" class="step plus" aria-label="افزایش تعداد">+</button>
                            <input type="text" inputmode="numeric" class="step-num step-input"
                                value="<?= $moq ?>" aria-label="تعداد سفارش">
                            <button type="button" class="step minus" aria-label="کاهش تعداد">−</button>
                        </div>

                        <button type="button" class="quick-add" <?= !$canOrder ? 'disabled' : '' ?>>
                            افزودن سریع
                        </button>

                        <?php if (!$canOrder): ?>
                            <div class="field-hint" style="margin-top:6px;">
                                موجودی کمتر از حداقل سفارش است.
                            </div>
                        <?php endif; ?>

                    <?php elseif ($stock > 0): ?>

                        <div class="field-hint" style="margin-top:8px;">
                            قیمت این محصول نیاز به استعلام دارد.
                        </div>

                    <?php endif; ?>

                </div>

            </article>

        <?php endforeach; ?>

    </section>

<?php else: ?>

    <?php if ($searchQuery !== ''): ?>

        <div class="rp-search-empty">

            <div class="rp-search-empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7" />
                    <path d="M21 21l-4.35-4.35" />
                    <path d="M8.5 11h5M11 8.5v5" />
                </svg>
            </div>

            <h3>چیزی پیدا نشد</h3>

            <p>
                برای «<b><?= $h($searchQuery) ?></b>» محصولی پیدا نکردیم.<br>
                امتحان کن با کلمه‌ی دیگه یا یه دسته رو انتخاب کن.
            </p>

            <a href="/products" class="btn btn-orange">
                مشاهده همه محصولات
            </a>

        </div>

    <?php else: ?>

        <div class="empty-state">

            <div class="empty-icon">📦</div>

            <h2>فعلاً محصولی در این دسته نیست</h2>

            <a href="/products" class="btn btn-orange">
                مشاهده همه محصولات
            </a>

        </div>

    <?php endif; ?>

<?php endif; ?>


<!-- ═══ ذخیره موقعیت اسکرول قبل از ناوبری ═══ -->

<script>
    (function() {
        'use strict';

        document.addEventListener('click', function(e) {
            var t = e.target;
            if (!(t instanceof Element)) return;

            var link = t.closest('a[data-scroll-preserve]');
            if (!link) return;

            try {
                sessionStorage.setItem(
                    '__scroll_resume',
                    String(window.pageYOffset || document.documentElement.scrollTop || 0)
                );
            } catch (err) {}
        }, true);
    })();
</script>


<?php

$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
