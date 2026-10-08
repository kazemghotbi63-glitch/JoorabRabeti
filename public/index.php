<?php

require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';

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
        pr.price,
        inv.qty_available,
        COALESCE(sold.total_sold, 0) AS total_sold,
        (
            SELECT image_path
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_main DESC, pi.sort_order ASC
            LIMIT 1
        ) AS img
    FROM products p

    LEFT JOIN product_prices pr
        ON pr.product_id = p.id
       AND pr.customer_group = 'wholesale'
       AND pr.min_qty = 1

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
        pr.price,
        inv.qty_available,
        (
            SELECT image_path
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_main DESC, pi.sort_order ASC
            LIMIT 1
        ) AS img
    FROM products p

    LEFT JOIN product_prices pr
        ON pr.product_id = p.id
       AND pr.customer_group = 'wholesale'
       AND pr.min_qty = 1

    LEFT JOIN inventory inv
        ON inv.product_id = p.id

    WHERE p.is_active = 1

    ORDER BY p.created_at DESC, p.id DESC

    LIMIT 4
")->fetchAll();

$pageTitle = 'رابطی — خرید عمده جوراب مستقیم از تولیدکننده';

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
                                    srcset="<?= $h($banner['mobile_image_path']) ?>">

                            <?php endif; ?>

                            <img
                                class="r6-hero-img"
                                src="<?= $h($banner['image_path']) ?>"
                                alt="<?= $h($banner['title'] ?? 'بنر رابطی') ?>">

                        </picture>

                    <?php endif; ?>


                    <div class="r6-hero-fade"></div>


                    <div class="r6-hero-content">

                        <h1>
                            <?= $h(
                                $banner['title']
                                    ?? 'جوراب‌هایی که فروش می‌روند.'
                            ) ?>
                        </h1>


                        <p>
                            <?= $h(
                                $banner['subtitle']
                                    ?? 'تأمین مستقیم از تولیدکننده؛ قیمت همکاری، موجودی واقعی و سفارش سریع'
                            ) ?>
                        </p>


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

                        </div>

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

<link rel="stylesheet" href="/assets/css/categories-premium.css">
<link rel="stylesheet" href="/assets/css/home-theme.css">

<section class="r-cats-premium">

    <div class="rp-inner">

        <div class="rp-head">
            <span class="rp-eyebrow">انتخاب مطمئن برای تمام سلیقه‌ها</span>
            <h2>دسته‌بندی جوراب عمده</h2>
            <p>تولیدکننده تخصصی جوراب — مستقیم از کارگاه رابطی</p>
        </div>

        <div class="rp-parents">

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
                            <img src="<?= $h($parent['image_path']) ?>" alt="<?= $h($parent['name']) ?>" loading="lazy">
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
                                            <img src="<?= $h($child['image_path']) ?>" alt="<?= $h($child['name']) ?>" loading="lazy">
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

    <div class="r6-custom-inner">

        <div class="r6-custom-body">

            <span class="r6-custom-eyebrow">
                چرا خرید از رابطی؟
            </span>

            <h3>سفارش اختصاصی رابطی</h3>

            <p>
                تولید جوراب با طرح و نام اختصاصی برای مجموعه‌ها،
                فروشگاه‌ها و سازمان‌ها — از طراحی تا بسته‌بندی.
            </p>

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
            <img src="/Image/1.webp" alt="سفارش اختصاصی رابطی">
        </div>

    </div>

</section>


<!-- ═══ ۳. پرفروش‌ها ═══ -->

<section class="r-section">

    <div class="r-sec-head">

        <div>

            <h2>
                پرفروش‌های این هفته
            </h2>

            <p>
                مدل‌هایی که بیشتر از همه سفارش داده می‌شوند.
            </p>

        </div>


        <a
            href="/products"
            class="r-sec-link">

            مشاهده همه ←

        </a>

    </div>


    <div class="r-grid">

        <?php foreach ($bestSellers as $i => $p): ?>

            <?php
            $stock = (int)(
                $p['qty_available'] ?? 0
            );
            ?>


            <article
                class="r6-card"
                data-product-id="<?= (int)$p['id'] ?>">


                <a
                    href="/product/<?= $h($p['slug']) ?>"
                    class="r6-card-img">


                    <?php if (!empty($p['img'])): ?>

                        <img
                            src="<?= $h($p['img']) ?>"
                            alt="<?= $h($p['name']) ?>"
                            loading="lazy">

                    <?php else: ?>

                        <span>
                            🧦
                        </span>

                    <?php endif; ?>


                </a>


                <div class="r6-card-body">


                    <h3>

                        <a
                            href="/product/<?= $h($p['slug']) ?>">

                            <?= $h($p['name']) ?>

                        </a>

                    </h3>


                    <span class="r6-attr">

                        کد <?= $h($p['code']) ?>

                        |

                        حداقل
                        <?= (int)$p['base_moq'] ?>
                        جفت

                    </span>


                    <div class="r6-price">

                        <b>

                            <?= $p['price'] !== null
                                ? number_format((float)$p['price'])
                                : 'استعلام' ?>

                            تومان

                        </b>


                        <small>
                            قیمت همکاری
                        </small>

                    </div>


                    <span
                        class="r6-stock <?= $stock > 0 ? 'in' : 'out' ?>">

                        <?= $stock > 0
                            ? '● موجود در انبار'
                            : '● ناموجود' ?>

                    </span>


                    <?php if ($stock > 0): ?>


                        <div
                            class="r6-stepper"
                            data-min="<?= max(1, (int)$p['base_moq']) ?>">


                            <button
                                type="button"
                                class="r6-step plus">

                                +

                            </button>


                            <input
                                type="text"
                                inputmode="numeric"
                                class="r6-num step-input"
                                value="<?= max(1, (int)$p['base_moq']) ?>">


                            <button
                                type="button"
                                class="r6-step minus">

                                −

                            </button>

                        </div>


                        <button
                            type="button"
                            class="r6-add"
                            onclick="showToast('✓ اضافه شد — سبد در فاز بعد فعال می‌شود')">

                            افزودن سریع

                        </button>


                    <?php endif; ?>


                </div>

            </article>

        <?php endforeach; ?>

    </div>

</section>


<!-- ═══ ۴. تازه‌ها ═══ -->

<section class="r6-newwrap">

    <div class="r6-new-head">

        <div>

            <span class="r6-eyebrow">
                کالکشن تازه
            </span>


            <h2>
                تازه‌های رابطی
            </h2>


            <p>
                رنگ‌ها و بافت‌های تازه برای ویترین فصل جدید.
            </p>


            <a
                href="/products"
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
                            src="<?= $h($p['img']) ?>"
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

    <link rel="stylesheet" href="/assets/css/instagram-gallery.css">

    <section class="r6-ig">
        <div class="r6-ig-inner">

            <div class="r6-ig-head">
                <div class="r6-ig-head-l">
                    <span class="r6-ig-eyebrow">@rabati.socks</span>
                    <h2>ما را در اینستاگرام دنبال کنید</h2>
                    <p>جدیدترین طرح‌ها و پشت صحنه‌ی کارگاه رابطی</p>
                </div>
                <a href="https://instagram.com/" target="_blank" rel="noopener"
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
                                href="<?= $hasLink ? $h($post['link']) : 'javascript:void(0);' ?>"
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
                                    <img src="<?= $h($post['image_path']) ?>" alt="<?= $h($post['caption'] ?? 'رابطی') ?>" loading="lazy">
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
                            href="<?= $hasLink ? $h($post['link']) : 'javascript:void(0);' ?>"
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
                                <img src="<?= $h($post['image_path']) ?>" alt="<?= $h($post['caption'] ?? 'رابطی') ?>" loading="lazy">
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

    <script>
        (function() {
            'use strict';

            if (window.__r6IgInit) return;
            window.__r6IgInit = true;

            /* ═══════════ Lightbox ═══════════ */
            var lb = document.getElementById('r6IgLightbox');
            var lbImg = document.getElementById('r6IgLbImg');
            var lbVid = document.getElementById('r6IgLbVid');
            var lbCap = document.getElementById('r6IgLbCaption');

            function openLb(type, src, caption) {
                if (type === 'video') {
                    lbImg.style.display = 'none';
                    lbImg.removeAttribute('src');
                    lbVid.style.display = 'block';
                    lbVid.src = src;
                    try {
                        lbVid.play();
                    } catch (e) {}
                } else {
                    lbVid.style.display = 'none';
                    try {
                        lbVid.pause();
                    } catch (e) {}
                    lbVid.removeAttribute('src');
                    lbImg.style.display = 'block';
                    lbImg.src = src;
                }
                lbCap.textContent = caption || '';
                lb.classList.add('is-open');
                lb.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function closeLb() {
                lb.classList.remove('is-open');
                lb.setAttribute('aria-hidden', 'true');
                lbImg.removeAttribute('src');
                try {
                    lbVid.pause();
                } catch (e) {}
                lbVid.removeAttribute('src');
                document.body.style.overflow = '';
            }

            document.addEventListener('click', function(e) {
                var el = e.target.closest('.r6-ig-item');
                if (!el) return;
                if (el.getAttribute('data-has-link') === '1') return;
                e.preventDefault();
                e.stopPropagation();
                openLb(el.dataset.type || 'image', el.dataset.src, el.dataset.caption || '');
            }, true);

            lb.addEventListener('click', function(e) {
                if (e.target === lb ||
                    e.target.classList.contains('r6-ig-lb-close') ||
                    e.target.closest('.r6-ig-lb-close') ||
                    e.target.classList.contains('r6-ig-lb-stage')) {
                    closeLb();
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && lb.classList.contains('is-open')) closeLb();
            });

            /* ═══════════ Carousel ═══════════ */
            var track = document.getElementById('r6IgTrack');
            var dotsEl = document.getElementById('r6IgDots');
            var btnPrev = document.getElementById('r6IgPrev');
            var btnNext = document.getElementById('r6IgNext');

            if (!track || !dotsEl) return;

            var currentPage = 0;

            function getPageCount() {
                var item = track.querySelector('.r6-ig-item');
                if (!item) return 1;

                var style = window.getComputedStyle(track);
                var gap = parseFloat(style.gap) || 16;
                var itemW = item.offsetWidth + gap;
                var colCount = Math.ceil(track.querySelectorAll('.r6-ig-item').length / 2);
                var visibleCols = Math.max(1, Math.floor((track.clientWidth + gap) / itemW));
                return Math.max(1, Math.ceil(colCount / visibleCols));
            }

            function renderDots() {
                var pages = getPageCount();
                dotsEl.innerHTML = '';
                if (pages <= 1) {
                    dotsEl.style.display = 'none';
                    return;
                }
                dotsEl.style.display = 'flex';
                for (var i = 0; i < pages; i++) {
                    var dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'r6-ig-dot' + (i === currentPage ? ' is-active' : '');
                    dot.setAttribute('aria-label', 'صفحه ' + (i + 1));
                    dot.dataset.page = i;
                    dotsEl.appendChild(dot);
                }
            }

            function goToPage(page) {
                var pages = getPageCount();
                page = Math.max(0, Math.min(pages - 1, page));
                currentPage = page;

                /* در RTL، scrollLeft منفی می‌شود */
                var target = -page * track.clientWidth;
                try {
                    track.scrollTo({
                        left: target,
                        behavior: 'smooth'
                    });
                } catch (err) {
                    track.scrollLeft = target;
                }

                updateUI();
            }

            function updateUI() {
                var pages = getPageCount();

                if (btnPrev) btnPrev.disabled = currentPage <= 0;
                if (btnNext) btnNext.disabled = currentPage >= pages - 1;

                dotsEl.querySelectorAll('.r6-ig-dot').forEach(function(d, i) {
                    d.classList.toggle('is-active', i === currentPage);
                });
            }

            /* کلیک دات‌ها */
            dotsEl.addEventListener('click', function(e) {
                var dot = e.target.closest('.r6-ig-dot');
                if (!dot) return;
                goToPage(parseInt(dot.dataset.page, 10) || 0);
            });

            /* کلیک فلش‌ها */
            if (btnPrev) btnPrev.addEventListener('click', function() {
                goToPage(currentPage - 1);
            });
            if (btnNext) btnNext.addEventListener('click', function() {
                goToPage(currentPage + 1);
            });

            /* بروزرسانی دات‌ها روی اسکرول دستی */
            var scrollTimer = null;
            track.addEventListener('scroll', function() {
                clearTimeout(scrollTimer);
                scrollTimer = setTimeout(function() {
                    var w = track.clientWidth;
                    if (!w) return;
                    var p = Math.round(Math.abs(track.scrollLeft) / w);
                    if (p !== currentPage) {
                        currentPage = p;
                        updateUI();
                    }
                }, 80);
            });

            /* Resize */
            var resizeTimer = null;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    renderDots();
                    goToPage(0);
                }, 150);
            });

            /* شروع */
            renderDots();
            updateUI();

            console.log('[IG] carousel ready, items:', document.querySelectorAll('.r6-ig-item').length,
                '| pages:', getPageCount());
        })();
    </script>

<?php endif; ?>


<!-- ═══ HERO SLIDER JS ═══ -->

<script>
    (function() {

        function initHero() {

            const hero =
                document.getElementById('r6Hero');

            if (!hero) {
                return;
            }


            const slides =
                Array.from(
                    hero.querySelectorAll(
                        '.r6-hero-slide'
                    )
                );


            const dots =
                Array.from(
                    hero.querySelectorAll(
                        '.r6-hero-dot'
                    )
                );


            const prevButton =
                hero.querySelector(
                    '.r6-hero-prev'
                );


            const nextButton =
                hero.querySelector(
                    '.r6-hero-next'
                );


            if (slides.length <= 1) {
                return;
            }


            let current = 0;
            let timer = null;


            function showSlide(index) {

                current =
                    (index + slides.length) %
                    slides.length;


                slides.forEach(
                    (slide, i) => {

                        const active =
                            i === current;


                        slide.classList.toggle(
                            'is-active',
                            active
                        );


                        slide.style.opacity =
                            active ? '1' : '0';


                        slide.style.visibility =
                            active ?
                            'visible' :
                            'hidden';


                        slide.style.pointerEvents =
                            active ?
                            'auto' :
                            'none';


                        slide.style.zIndex =
                            active ? '1' : '0';

                    }
                );


                dots.forEach(
                    (dot, i) => {

                        dot.classList.toggle(
                            'is-active',
                            i === current
                        );

                    }
                );

            }


            function nextSlide() {
                showSlide(current + 1);
            }


            function prevSlide() {
                showSlide(current - 1);
            }


            function restartAutoPlay() {

                clearInterval(timer);


                timer =
                    setInterval(
                        nextSlide,
                        5000
                    );

            }


            nextButton?.addEventListener(
                'click',
                function(e) {

                    e.preventDefault();

                    nextSlide();

                    restartAutoPlay();

                }
            );


            prevButton?.addEventListener(
                'click',
                function(e) {

                    e.preventDefault();

                    prevSlide();

                    restartAutoPlay();

                }
            );


            dots.forEach(
                (dot, index) => {

                    dot.addEventListener(
                        'click',
                        function(e) {

                            e.preventDefault();

                            showSlide(index);

                            restartAutoPlay();

                        }
                    );

                }
            );


            hero.addEventListener(
                'mouseenter',
                function() {

                    clearInterval(timer);

                }
            );


            hero.addEventListener(
                'mouseleave',
                function() {

                    restartAutoPlay();

                }
            );


            showSlide(0);

            restartAutoPlay();


            console.log(
                'R6 Hero Slider فعال شد:',
                slides.length,
                'بنر'
            );

        }


        if (
            document.readyState ===
            'loading'
        ) {

            document.addEventListener(
                'DOMContentLoaded',
                initHero
            );

        } else {

            initHero();

        }

    })();
</script>


<?php

$content = ob_get_clean();

require ROOT_PATH . '/views/layouts/main.php';

?>