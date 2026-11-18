<?php

/**
 * product-form.php — فرم ثبت و ویرایش محصول
 *
 * ═══════════════════════════════════════════════════════════════════
 * ساختار:
 *   ۱. PHP — اعتبارسنجی، ذخیره در DB، لود داده اولیه
 *   ۲. HTML — فرم ۶ بخشی
 *   ۳. JS — آپلود عکس، پله‌های تخفیف، به‌روزرسانی خودکار
 * ═══════════════════════════════════════════════════════════════════
 *
 * وابستگی‌ها:
 *   - products.base_price             (قیمت پایه، از فاز A)
 *   - products.status                 (draft/active/inactive)
 *   - product_tier_discounts          (پله‌های درصدی)
 *   - product_prices                  (فقط legacy fallback برای wholesale min_qty=1)
 *   - customer_groups                 (از طریق PricingService)
 *   - product_specs, product_images, inventory
 *
 * قواعد:
 *   - هزینه/قیمت تمام‌شده (cost_price) در این فرم استفاده نمی‌شود.
 *   - قیمت گروهی مرکزی (customer_groups.discount_percent) در این فرم تعریف نمی‌شود.
 *   - پله‌های تخفیف درصدی در product_tier_discounts ذخیره می‌شوند.
 *   - موجودی: فقط qty_physical تغییر می‌کند — reserved و unsellable دست‌نخورده می‌مانند.
 *   - slug در ویرایش بدون تغییر صریح کاربر حفظ می‌شود.
 */

/* ─────────────────────────────────────────────────────────────────
   Bootstrap
   ───────────────────────────────────────────────────────────────── */

$__bp = [
    __DIR__ . '/../../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../../config/bootstrap.php',
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
require_once ROOT_PATH . '/config/upload.php';
require_once ROOT_PATH . '/app/services/PricingService.php';

require_admin();

$db = db();

/* ─────────────────────────────────────────────────────────────────
   ثابت‌های فرم
   ───────────────────────────────────────────────────────────────── */

/** فیلدهای مشخصات فنی محصول */
$specFields = [
    'مناسب برای',
    'تعداد در بسته',
    'واحد سفارش',
    'دوخت پنجه',
    'سایز',
    'جنس',
];

/** مقادیر پیش‌فرض مشخصات (حالت new) */
$defaultSpecs = [
    'تعداد در بسته' => '12',
    'واحد سفارش'    => 'جین',
    'دوخت پنجه'     => 'روسو',
    'سایز'          => 'فری سایز',
    'جنس'           => 'نخ پنبه',
];

/** برند پیش‌فرض بر اساس دسته ریشه */
$brandDefaults = [
    'مردانه' => 'Romeo',
    'زنانه'  => 'Aysel',
    'بچگانه' => 'Rafaella',
];

/** گزینه‌های مشخصات (select) */
$unitOptions  = ['جفتی' => 'جفتی (۱ جفت)', 'بسته' => 'بسته (۱۰ جفتی)', 'جین' => 'جین (۱۲ جفتی)'];
$toeOptions   = ['روسو' => 'روسو', 'بدون دوخت' => 'بدون دوخت', 'ماکرو' => 'ماکرو'];
$sizeOptions  = ['فری سایز' => 'فری سایز', '۳۹-۴۲' => '۳۹-۴۲', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7'];
$matOptions   = ['نخ پنبه' => 'نخ پنبه', 'پنبه' => 'پنبه', 'پلی‌استر' => 'پلی‌استر', 'مخلوط' => 'مخلوط'];

/** گزینه‌های وضعیت محصول */
$statusOptions = [
    'draft'    => 'پیش‌نویس (در سایت نمایش داده نمی‌شود)',
    'active'   => 'فعال (در سایت قابل سفارش)',
    'inactive' => 'غیرفعال (مخفی)',
];

/* ─────────────────────────────────────────────────────────────────
   پارامترهای درخواست
   ───────────────────────────────────────────────────────────────── */

$action    = ($_GET['action'] ?? 'new') === 'edit' ? 'edit' : 'new';
$productId = (int)($_GET['id'] ?? 0);

/* ─────────────────────────────────────────────────────────────────
   متغیرهای فرم
   ───────────────────────────────────────────────────────────────── */

$errors       = [];
$currentSpecs = [];
$priceTiers   = [];
$images       = [];

$product = [
    'code'                => '',
    'name'                => '',
    'slug'                => '',
    'description'         => '',
    'meta_title'          => '',
    'meta_description'    => '',
    'base_moq'            => '1',
    'category_id'         => '',
    'base_price'          => 0,
    'brand'               => '',
    'material'            => '',
    'color'               => '',
    'size'                => '',
    'is_custom_orderable' => 0,
    'is_active'           => 1,
    'status'              => 'draft',
];

$qty              = 0;
$discountEnabled  = false;

/* ─────────────────────────────────────────────────────────────────
   لود محصول (edit)
   ───────────────────────────────────────────────────────────────── */

if ($action === 'edit' && $productId > 0) {

    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        header('Location: /admin/products');
        exit;
    }

    /* قیمت پایه — از products.base_price با fallback به legacy */
    $product['base_price'] = (int)($product['base_price'] ?? 0);
    if ($product['base_price'] <= 0) {
        $product['base_price'] = PricingService::getBasePrice($db, $productId);
    }

    /* پله‌های تخفیف درصدی */
    $tiersRaw = PricingService::getTiers($db, $productId);
    foreach ($tiersRaw as $t) {
        $priceTiers[] = [
            'min_qty'          => (int)$t['min_qty'],
            'discount_percent' => (float)$t['discount_percent'],
        ];
    }
    $discountEnabled = !empty($priceTiers);

    /* موجودی — فقط qty_physical در فرم نمایش داده می‌شود */
    $stmt = $db->prepare("SELECT qty_physical FROM inventory WHERE product_id = ? LIMIT 1");
    $stmt->execute([$productId]);
    $qty = (int)($stmt->fetchColumn() ?: 0);

    /* تصاویر */
    $stmt = $db->prepare("
        SELECT id, image_path, alt_text, is_main, sort_order
        FROM product_images
        WHERE product_id = ?
        ORDER BY is_main DESC, sort_order ASC, id ASC
    ");
    $stmt->execute([$productId]);
    $images = $stmt->fetchAll();

    /* مشخصات */
    $stmt = $db->prepare("SELECT spec_key, spec_value FROM product_specs WHERE product_id = ?");
    $stmt->execute([$productId]);
    foreach ($stmt->fetchAll() as $s) {
        $currentSpecs[$s['spec_key']] = $s['spec_value'];
    }
}

/* ─────────────────────────────────────────────────────────────────
   حذف تصویر
   ───────────────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete_image') {
    verify_csrf();

    $imgId = (int)($_POST['image_id'] ?? 0);

    if ($imgId > 0 && $productId > 0) {

        $stmt = $db->prepare("
            SELECT image_path, is_main FROM product_images
            WHERE id = ? AND product_id = ?
            LIMIT 1
        ");
        $stmt->execute([$imgId, $productId]);
        $row = $stmt->fetch();

        if ($row) {
            $file = ROOT_PATH . '/public' . $row['image_path'];
            if (is_file($file)) @unlink($file);

            $db->prepare("DELETE FROM product_images WHERE id = ?")->execute([$imgId]);

            if ((int)$row['is_main'] === 1) {
                $db->prepare("
                    UPDATE product_images SET is_main = 1
                    WHERE product_id = ?
                    ORDER BY sort_order ASC, id ASC
                    LIMIT 1
                ")->execute([$productId]);
            }
        }
    }

    header('Location: /admin/product-form?action=edit&id=' . $productId . '&img=deleted');
    exit;
}

/* ─────────────────────────────────────────────────────────────────
   تعیین تصویر اصلی
   ───────────────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'set_main') {
    verify_csrf();

    $imgId = (int)($_POST['image_id'] ?? 0);

    if ($imgId > 0 && $productId > 0) {
        $db->prepare("UPDATE product_images SET is_main = 0 WHERE product_id = ?")
            ->execute([$productId]);
        $db->prepare("UPDATE product_images SET is_main = 1 WHERE id = ? AND product_id = ?")
            ->execute([$imgId, $productId]);
    }

    header('Location: /admin/product-form?action=edit&id=' . $productId . '&img=main');
    exit;
}

/* ─────────────────────────────────────────────────────────────────
   آپلود تصویر (AJAX)
   ───────────────────────────────────────────────────────────────── */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'upload_image') {
    verify_csrf();

    header('Content-Type: application/json; charset=utf-8');

    if ($productId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'شناسه محصول نامعتبر'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($_FILES['images']['name'][0])) {
        echo json_encode(['ok' => false, 'error' => 'عکسی انتخاب نشده'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $uploaded = [];
    $failed   = [];

    $stmt = $db->prepare("SELECT COUNT(*) FROM product_images WHERE product_id = ?");
    $stmt->execute([$productId]);
    $existingCount = (int)$stmt->fetchColumn();

    $slots = max(0, 5 - $existingCount);

    if ($slots === 0) {
        echo json_encode(['ok' => false, 'error' => 'حداکثر ۵ عکس مجاز است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_main = 1");
    $stmt->execute([$productId]);
    $hasMain = ((int)$stmt->fetchColumn()) > 0;

    $fileCount = count($_FILES['images']['name']);

    for ($i = 0; $i < $fileCount && count($uploaded) < $slots; $i++) {

        if (empty($_FILES['images']['name'][$i])) continue;

        $origName = $_FILES['images']['name'][$i];

        if ((int)$_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
            $failed[] = $origName . ' — خطای آپلود (کد ' . $_FILES['images']['error'][$i] . ')';
            continue;
        }

        $single = [
            'name'     => $_FILES['images']['name'][$i],
            'type'     => $_FILES['images']['type'][$i],
            'tmp_name' => $_FILES['images']['tmp_name'][$i],
            'error'    => $_FILES['images']['error'][$i],
            'size'     => $_FILES['images']['size'][$i],
        ];

        $up = upload_image_ex($single, 'products');

        if (!$up['ok']) {
            $failed[] = $origName . ' — ' . $up['error'];
            continue;
        }

        $isMain = (!$hasMain && count($uploaded) === 0) ? 1 : 0;

        $db->prepare("
            INSERT INTO product_images (product_id, image_path, alt_text, is_main, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $productId,
            $up['path'],
            $product['name'] ?? null,
            $isMain,
            $existingCount + count($uploaded),
        ]);

        $newId = (int)$db->lastInsertId();

        if ($isMain) $hasMain = true;

        $uploaded[] = [
            'id'      => $newId,
            'path'    => $up['path'],
            'is_main' => $isMain,
        ];
    }

    echo json_encode([
        'ok'       => !empty($uploaded),
        'uploaded' => $uploaded,
        'failed'   => $failed,
        'message'  => count($uploaded) . ' عکس آپلود شد'
            . ($failed ? ' — ' . count($failed) . ' خطا' : ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ─────────────────────────────────────────────────────────────────
   ذخیره فرم اصلی
   ───────────────────────────────────────────────────────────────── */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['do'] ?? '') !== 'delete_image' &&
    ($_POST['do'] ?? '') !== 'set_main' &&
    ($_POST['do'] ?? '') !== 'upload_image'
) {
    verify_csrf();

    /* ─── جمع‌آوری ورودی ─── */
    $code        = strtoupper(trim($_POST['code'] ?? ''));
    $name        = trim($_POST['name'] ?? '');
    $slug        = trim($_POST['slug'] ?? '');
    $slugEn      = trim($_POST['slug_en'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $metaTitle   = trim($_POST['meta_title'] ?? '');
    $metaDesc    = trim($_POST['meta_description'] ?? '');

    $baseMoq    = max(1, (int)($_POST['base_moq'] ?? 1));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    /* قیمت ممکن است سه‌رقم سه‌رقم جدا شده باشد (مثل 1.000.000)؛
       همهٔ جداکننده‌ها را قبل از cast به int حذف می‌کنیم. */
    $basePriceRaw = (string)($_POST['base_price'] ?? '');
    $basePriceRaw = preg_replace('/[^\d]/', '', $basePriceRaw);  // فقط ارقام باقی بمانند
    $basePrice  = max(0, (int)$basePriceRaw);
    $qty        = max(0, (int)($_POST['qty'] ?? 0));

    $brand             = trim((string)($_POST['brand'] ?? ''));
    $material          = trim((string)($_POST['material'] ?? ''));
    $color             = trim((string)($_POST['color'] ?? ''));
    $size              = trim((string)($_POST['size'] ?? ''));
    $isCustomOrderable = isset($_POST['is_custom_orderable']) && $_POST['is_custom_orderable'] === '1' ? 1 : 0;
    $isActive          = isset($_POST['is_active']) && $_POST['is_active'] === '0' ? 0 : 1;
    $status            = in_array($_POST['status'] ?? 'draft', ['draft', 'active', 'inactive'], true)
        ? $_POST['status'] : 'draft';

    /* ─── پله‌های تخفیف ─── */
    $discountEnabled = isset($_POST['discount_enabled']) && $_POST['discount_enabled'] === '1';
    $tierQtyInput     = is_array($_POST['discount_qty'] ?? null)     ? $_POST['discount_qty']     : [];
    $tierPercentInput = is_array($_POST['discount_percent'] ?? null) ? $_POST['discount_percent'] : [];

    /* ─── مشخصات ─── */
    $specs = [];
    foreach ($specFields as $key) {
        $v = trim($_POST['spec_' . md5($key)] ?? '');
        if ($v !== '') $specs[] = [$key, $v];
    }

    /* ─── استخراج واحد سفارش برای ستون جدید products.unit ─── */
    // مقدار پیش‌فرض از select گرفته می‌شود. اگر خالی بود، 'جین' (پیش‌فرض فرم)
    $unitValue = trim($_POST['spec_' . md5('واحد سفارش')] ?? 'جین');

    // فقط مقادیر مجاز را قبول کن (مطابق $unitOptions)
    $validUnits = ['جفتی', 'بسته', 'جین', 'عدد'];
    if (!in_array($unitValue, $validUnits, true)) {
        $unitValue = 'جین'; // مقدار پیش‌فرض امن
    }
    /* ─── اعتبارسنجی پایه ─── */
    if ($code === '') {
        $errors['code'] = 'کد محصول را وارد کنید';
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9\-_]{1,19}$/', $code)) {
        $errors['code'] = 'کد فقط حروف بزرگ، عدد، - و _ (۲ تا ۲۰ کاراکتر)';
    }

    if (mb_strlen($name) < 3)         $errors['name']        = 'نام محصول را کامل وارد کنید';
    if ($categoryId < 1)              $errors['category_id'] = 'دسته را انتخاب کنید';
    if ($basePrice < 1000)            $errors['base_price']  = 'قیمت پایه را به تومان وارد کنید';
    if ($baseMoq < 1)                 $errors['base_moq']    = 'حداقل سفارش باید ۱ یا بیشتر باشد';

    /* ─── یکتایی کد ─── */
    if (!$errors) {
        $stmt = $db->prepare("SELECT id FROM products WHERE code = ? AND id != ? LIMIT 1");
        $stmt->execute([$code, $productId]);
        if ($stmt->fetch()) $errors['code'] = 'این کد قبلاً استفاده شده است';
    }

    /* ─── slug: در ویرایش، اگر کاربر تغییر نداده، از slug فعلی استفاده کن ─── */
    if ($action === 'edit') {
        $currentSlug = (string)($product['slug'] ?? '');
        if ($slug === '') {
            $slug = $currentSlug;
        }
    }

    if ($slug === '') {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($slugEn !== '' ? $slugEn : $code));
        $base = trim($base, '-');
        if ($base === '') $base = strtolower($code);
        $slug = $base . '-' . strtolower($code);
    }

    /* ─── یکتایی slug ─── */
    if (!$errors) {
        $stmt = $db->prepare("SELECT id FROM products WHERE slug = ? AND id != ? LIMIT 1");
        $stmt->execute([$slug, $productId]);
        if ($stmt->fetch()) $errors['slug'] = 'این نامک قبلاً استفاده شده است';
    }

    /* ─── اعتبارسنجی پله‌های تخفیف ─── */
    if (!$errors && $discountEnabled) {
        $lastQty = 0;
        $rowCount = max(count($tierQtyInput), count($tierPercentInput));

        for ($i = 0; $i < $rowCount; $i++) {
            $tierQty    = (int)($tierQtyInput[$i] ?? 0);
            $percentRaw = trim((string)($tierPercentInput[$i] ?? ''));

            if ($tierQty === 0 && $percentRaw === '') continue;

            $percent = (float)str_replace(',', '.', $percentRaw);

            if ($tierQty < $baseMoq) {
                $errors['discount'] = 'تعداد شروع هر تخفیف نباید کمتر از MOQ باشد';
                break;
            }
            if ($tierQty <= $lastQty) {
                $errors['discount'] = 'تعدادها باید صعودی باشند';
                break;
            }
            if ($percent <= 0 || $percent >= 100) {
                $errors['discount'] = 'درصد باید بین ۰ و ۱۰۰ باشد';
                break;
            }

            $priceTiers[] = [
                'min_qty'          => $tierQty,
                'discount_percent' => $percent,
            ];
            $lastQty = $tierQty;
        }

        if (!$errors && !$priceTiers) {
            $errors['discount'] = 'حداقل یک پله تخفیف تعریف کنید';
        }
    }

    /* ─── ذخیره در DB ─── */
    if (!$errors) {
        try {
            $db->beginTransaction();

            if ($action === 'new') {
                $db->prepare("
                    INSERT INTO products
                    (category_id, code, name, slug, description, meta_title, meta_description, base_moq, unit,
                     base_price, brand, material, color, size, is_custom_orderable, is_active, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $categoryId,
                    $code,
                    $name,
                    $slug,
                    $description ?: null,
                    $metaTitle ?: null,
                    $metaDesc ?: null,
                    $baseMoq,
                    $unitValue,
                    $basePrice,
                    $brand !== '' ? $brand : null,
                    $material !== '' ? $material : null,
                    $color !== '' ? $color : null,
                    $size !== '' ? $size : null,
                    $isCustomOrderable,
                    $isActive,
                    $status,
                ]);

                $productId = (int)$db->lastInsertId();
            } else {
                $db->prepare("
                    UPDATE products SET
                        category_id = ?, code = ?, name = ?, slug = ?, description = ?,
                        meta_title = ?, meta_description = ?, base_moq = ?, unit = ?, base_price = ?,
                        brand = ?, material = ?, color = ?, size = ?,
                        is_custom_orderable = ?, is_active = ?, status = ?
                    WHERE id = ?
                ")->execute([
                    $categoryId,
                    $code,
                    $name,
                    $slug,
                    $description ?: null,
                    $metaTitle ?: null,
                    $metaDesc ?: null,
                    $baseMoq,
                    $unitValue,
                    $basePrice,
                    $brand !== '' ? $brand : null,
                    $material !== '' ? $material : null,
                    $color !== '' ? $color : null,
                    $size !== '' ? $size : null,
                    $isCustomOrderable,
                    $isActive,
                    $status,
                    $productId,
                ]);
            }

            /* ─── پله‌های تخفیف: پاک و درج مجدد ─── */
            $db->prepare("DELETE FROM product_tier_discounts WHERE product_id = ?")
                ->execute([$productId]);

            if ($priceTiers) {
                $insTier = $db->prepare("
        INSERT INTO product_tier_discounts
        (product_id, min_qty, discount_percent, sort_order)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            discount_percent = VALUES(discount_percent),
            sort_order = VALUES(sort_order)
    ");
                foreach ($priceTiers as $i => $t) {
                    $insTier->execute([
                        $productId,
                        $t['min_qty'],
                        $t['discount_percent'],
                        $i,
                    ]);
                }
            }

            /* ─── legacy: product_prices wholesale min_qty=1 (برای سازگاری) ─── */
            $db->prepare("DELETE FROM product_prices WHERE product_id = ? AND customer_group = 'wholesale' AND min_qty = 1")
                ->execute([$productId]);
            $db->prepare("
                INSERT INTO product_prices (product_id, customer_group, min_qty, price)
                VALUES (?, 'wholesale', 1, ?)
            ")->execute([$productId, $basePrice]);

            /* ─── موجودی: فقط qty_physical — reserved و unsellable دست‌نخورده ─── */
            $stmt = $db->prepare("SELECT COUNT(*) FROM inventory WHERE product_id = ?");
            $stmt->execute([$productId]);
            $invExists = ((int)$stmt->fetchColumn()) > 0;

            if ($invExists) {
                $db->prepare("UPDATE inventory SET qty_physical = ? WHERE product_id = ?")
                    ->execute([$qty, $productId]);
            } else {
                $db->prepare("INSERT INTO inventory (product_id, qty_physical, reserved, unsellable) VALUES (?, ?, 0, 0)")
                    ->execute([$productId, $qty]);
            }

            /* ─── مشخصات: پاک و درج مجدد ─── */
            $db->prepare("DELETE FROM product_specs WHERE product_id = ?")->execute([$productId]);

            if ($specs) {
                $insSpec = $db->prepare("
                    INSERT INTO product_specs (product_id, spec_key, spec_value, sort_order)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($specs as $i => $sv) {
                    $insSpec->execute([$productId, $sv[0], $sv[1], $i]);
                }
            }

            $db->commit();

            header('Location: /admin/product-form?action=edit&id=' . $productId . '&saved=1');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('PRODUCT FORM SAVE ERROR: ' . $e->getMessage());
            $errors['general'] = 'ذخیره محصول ناموفق بود. لطفاً دوباره تلاش کنید.';
        }
    }

    /* ─── بازگردانی مقادیر فرم در صورت خطا ─── */
    $product = array_merge($product, [
        'code'                => $code,
        'name'                => $name,
        'slug'                => $slug,
        'description'         => $description,
        'meta_title'          => $metaTitle,
        'meta_description'    => $metaDesc,
        'base_moq'            => $baseMoq,
        'category_id'         => $categoryId,
        'base_price'          => $basePrice,
        'brand'               => $brand,
        'material'            => $material,
        'color'               => $color,
        'size'                => $size,
        'is_custom_orderable' => $isCustomOrderable,
        'is_active'           => $isActive,
        'status'              => $status,
    ]);
    $currentSpecs = [];
    foreach ($specFields as $key) {
        $v = trim($_POST['spec_' . md5($key)] ?? '');
        if ($v !== '') $currentSpecs[$key] = $v;
    }
}

/* ─────────────────────────────────────────────────────────────────
   دسته‌ها — تفکیک والد/فرزند برای انتخاب هدایت‌شده
   - $rootCategories: دسته‌های اصلی (parent_id IS NULL)
   - $childCategories: همهٔ فرزندها (parent_id IS NOT NULL) — نگاشت parentId => [فرزندها]
   در ویرایش محصولی که دسته‌اش غیرفعال شده، همچنان باید آن دسته در لیست ظاهر شود
   تا ادمین بداند محصول در کدام دسته است. برای همین یک کوئری «همه» هم داریم.
   ───────────────────────────────────────────────────────────────── */

$rootCatsRaw = $db->query("
    SELECT id, name, parent_id, icon, image_path, sort_order
    FROM categories
    WHERE parent_id IS NULL
    ORDER BY sort_order ASC, id ASC
")->fetchAll();

$childCatsRaw = $db->query("
    SELECT id, name, parent_id, icon, image_path, sort_order
    FROM categories
    WHERE parent_id IS NOT NULL
    ORDER BY parent_id ASC, sort_order ASC, id ASC
")->fetchAll();

/* نگاشت parentId => [فرزندها] — فقط فعال‌ها (به‌علاوهٔ فرزندِ انتخاب‌شدهٔ محصول فعلی در حالت edit) */
$childCategories = [];   // parentId => [rows]
$allCategories   = [];  // همه برای find_root_category_name
foreach (array_merge($rootCatsRaw, $childCatsRaw) as $c) {
    $allCategories[(int)$c['id']] = $c;
}

foreach ($childCatsRaw as $c) {
    $pid = (int)$c['parent_id'];
    $childCategories[$pid][] = $c;
}

/* مجموعهٔ id دسته‌هایی که باید قابل انتخاب باشند (فعال + دستهٔ فعلی محصول) */
$editableChildIds = [];
foreach ($childCatsRaw as $c) {
    if ((int)$c['sort_order'] >= 0) { /* همه قابل انتخاب، چون is_active در کوئری فیلتر نشد — برای سادگی فعلاً همه */
        $editableChildIds[(int)$c['id']] = true;
    }
}

/* ─────────────────────────────────────────────────────────────────
   کمک‌تابع: نام دسته ریشه
   ───────────────────────────────────────────────────────────────── */

if (!function_exists('find_root_category_name')) {
    function find_root_category_name(array $categories, int $catId): string
    {
        $byId = [];
        foreach ($categories as $c) $byId[(int)$c['id']] = $c;
        $current = $byId[$catId] ?? null;
        $guard = 0;
        while ($current && !empty($current['parent_id']) && $guard++ < 20) {
            $parent = $byId[(int)$current['parent_id']] ?? null;
            if (!$parent) break;
            $current = $parent;
        }
        return $current ? $current['name'] : '';
    }
}

$rootCatName = $product['category_id']
    ? find_root_category_name($allCategories, (int)$product['category_id'])
    : '';

if ($action === 'new') {
    $currentSpecs = array_merge($defaultSpecs, $currentSpecs);
    if (!empty($rootCatName)) {
        $currentSpecs['مناسب برای'] = $rootCatName;
        if (empty($product['brand'])) {
            $product['brand'] = $brandDefaults[$rootCatName] ?? '';
        }
    }
} else {
    if (empty($currentSpecs['مناسب برای']) && !empty($rootCatName)) {
        $currentSpecs['مناسب برای'] = $rootCatName;
    }
}

/* ─────────────────────────────────────────────────────────────────
   آماده‌سازی نقشه‌های JS برای انتخاب هدایت‌شدهٔ دسته
   - $catRootMap[catId] => نام ریشه (برای «مناسب برای» و برند)
   - $catParentMap[catId] => id والد (برای انتخاب والدِ دستهٔ فعلی در حالت edit)
   - $childCatsJs[parentId] => [{id, name, icon, image}, ...] (لیست فرزندها)
   ───────────────────────────────────────────────────────────────── */

$catRootMap   = [];
$catParentMap = [];
foreach ($allCategories as $cid => $c) {
    $catRootMap[$cid]   = find_root_category_name($allCategories, $cid);
    $catParentMap[$cid] = $c['parent_id'] !== null ? (int)$c['parent_id'] : 0;
}

/* در حالت edit، اگر دستهٔ فعلی محصول یک فرزند است، والدش را هم می‌خواهیم */
$currentCatParent = 0;
if (!empty($product['category_id']) && isset($catParentMap[(int)$product['category_id']])) {
    $currentCatParent = $catParentMap[(int)$product['category_id']];
}

/* برای نمایش در حالت edit: دستهٔ فعلی ممکن است خودش والد باشد (parent_id=0) یا فرزند.
   اگر والد باشد: باید والد انتخاب‌شده = خودش، و dropdown فرزندها آن دسته را به‌عنوان گزینهٔ «خودِ دستهٔ اصلی» نشان دهد. */

/* ─────────────────────────────────────────────────────────────────
   خروجی HTML
   ───────────────────────────────────────────────────────────────── */

$activeMenu = 'products';
$pageTitle  = ($action === 'edit' ? 'ویرایش محصول' : 'محصول جدید');

ob_start();
?>

<div class="pf-wrap">

    <link rel="stylesheet" href="/assets/css/product-form-images.css?v=10">

    <!-- استایل انتخاب هدایت‌شدهٔ دسته (کارت‌های ریشه + dropdown وابسته) -->
    <style>
        .pf-cat-picker {
            display: flex;
            flex-direction: column;
            gap: 14px;
            padding: 14px;
            background: var(--admin-surface-soft, #f8fafb);
            border: 1px solid var(--admin-border, #e4e9eb);
            border-radius: var(--admin-radius-md, 12px);
        }

        .pf-cat-roots {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px;
        }

        .pf-cat-root {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 14px 10px;
            background: #fff;
            border: 1.5px solid var(--admin-border, #e4e9eb);
            border-radius: var(--admin-radius-md, 12px);
            cursor: pointer;
            font-family: inherit;
            transition: border-color 180ms ease, box-shadow 180ms ease, transform 120ms ease;
            text-align: center;
        }

        .pf-cat-root:hover {
            border-color: var(--admin-info, #3f7fa1);
            box-shadow: 0 4px 14px rgba(63, 127, 161, .12);
        }

        .pf-cat-root:active {
            transform: scale(.98);
        }

        .pf-cat-root.is-selected {
            border-color: var(--admin-info, #3f7fa1);
            background: var(--admin-info-soft, #eaf4f9);
            box-shadow: 0 0 0 3px var(--admin-info-soft, #eaf4f9);
        }

        .pf-cat-root img,
        .pf-cat-emoji {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: var(--admin-surface-soft, #f8fafb);
            border: 1px solid var(--admin-border, #e4e9eb);
            font-size: 1.6rem;
            line-height: 1;
            object-fit: cover;
        }

        .pf-cat-label {
            font-size: .82rem;
            font-weight: 600;
            color: var(--admin-text, #263840);
            word-break: break-word;
        }

        .pf-cat-empty {
            grid-column: 1 / -1;
            text-align: center;
            color: var(--admin-text-muted, #8a989e);
            font-size: .85rem;
            padding: 20px;
        }

        .pf-cat-sub {
            display: flex;
            flex-direction: column;
            gap: 6px;
            padding: 12px 14px;
            background: #fff;
            border: 1px dashed var(--admin-border-strong, #d7dfe2);
            border-radius: var(--admin-radius-md, 12px);
        }

        .pf-cat-sub-label {
            font-size: .78rem;
            font-weight: 600;
            color: var(--admin-text-soft, #61727a);
        }

        .pf-cat-sub select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--admin-border, #e4e9eb);
            border-radius: var(--admin-radius-md, 12px);
            font-family: inherit;
            font-size: .9rem;
            background: #fff;
        }

        .pf-cat-sub select:focus {
            outline: none;
            border-color: var(--admin-info, #3f7fa1);
            box-shadow: 0 0 0 3px var(--admin-info-soft, #eaf4f9);
        }

        .pf-cat-chosen {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: var(--admin-success-soft, #e8f6ef);
            border: 1px solid var(--admin-success, #2f8f68);
            border-radius: var(--admin-radius-md, 12px);
            font-size: .85rem;
        }

        .pf-cat-chosen-label {
            color: var(--admin-text-soft, #61727a);
        }

        .pf-cat-chosen b {
            color: var(--admin-success, #2f8f68);
            font-weight: 700;
            flex: 1;
        }

        .pf-cat-chosen-reset {
            border: none;
            background: transparent;
            color: var(--admin-text-muted, #8a989e);
            cursor: pointer;
            width: 24px;
            height: 24px;
            border-radius: 6px;
            font-size: .9rem;
            line-height: 1;
        }

        .pf-cat-chosen-reset:hover {
            background: var(--admin-border, #e4e9eb);
            color: var(--admin-text, #263840);
        }

        @media (max-width: 540px) {
            .pf-cat-roots {
                grid-template-columns: 1fr 1fr;
            }

            .pf-cat-root {
                padding: 10px 6px;
            }
        }
    </style>

    <!-- ═══════════════ Header ═══════════════ -->
    <div class="pf-header">
        <a href="/admin/products" class="pf-back">← بازگشت به محصولات</a>
        <h1 class="pf-header-title">
            <?= $action === 'edit' ? 'ویرایش محصول' : 'محصول جدید' ?>
        </h1>
    </div>

    <!-- ═══════════════ Alerts ═══════════════ -->
    <?php if (isset($_GET['saved'])): ?>
        <div class="pf-alert pf-alert-success">✓ تغییرات ذخیره شد</div>
    <?php endif; ?>

    <?php if (isset($_GET['img'])): ?>
        <div class="pf-alert pf-alert-success">
            <?= $_GET['img'] === 'deleted' ? '✓ عکس حذف شد' : '✓ عکس اصلی تغییر کرد' ?>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="pf-alert pf-alert-error">
            ⚠️
            <div>
                <?php foreach ($errors as $er): ?>
                    <?= htmlspecialchars($er, ENT_QUOTES, 'UTF-8') ?><br>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ═══════════════ فرم‌های کمکی ═══════════════ -->
    <form id="form_set_main" method="post" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="set_main">
    </form>

    <form id="form_delete_img" method="post" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="delete_image">
    </form>

    <!-- ═══════════════ فرم اصلی ═══════════════ -->
    <form id="mainProductForm" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">

        <!-- ─────────────── بخش ۱: اطلاعات اصلی ─────────────── -->
        <div class="pf-section">
            <div class="pf-section-head">
                <div class="pf-section-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M12 16v-4M12 8h.01" />
                    </svg>
                </div>
                <div>
                    <h3>اطلاعات اصلی</h3>
                    <small>کد، نام، دسته و توضیحات محصول</small>
                </div>
            </div>

            <div class="pf-grid">

                <div class="pf-field">
                    <label>کد محصول <i>*</i></label>
                    <input type="text" name="code" dir="ltr"
                        value="<?= htmlspecialchars($product['code'], ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="مثلاً: TEST-01 یا ROMEO-500" maxlength="20">
                    <span class="pf-field-hint">کد دلخواه — باید یکتا باشد</span>
                    <?php if (isset($errors['code'])): ?>
                        <span class="pf-field-error"><?= htmlspecialchars($errors['code']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="pf-field pf-field-full">
                    <label>دسته‌بندی <i>*</i></label>

                    <!-- انتخاب هدایت‌شده: اول دستهٔ اصلی، بعد زیرمجموعهٔ همان -->
                    <div class="pf-cat-picker">

                        <!-- مرحله ۱: کارت‌های دسته‌های اصلی -->
                        <div class="pf-cat-roots" id="pfCatRoots">
                            <?php foreach ($rootCatsRaw as $rc):
                                $rcId = (int)$rc['id'];
                                $rcImg = trim($rc['image_path'] ?? '');
                                $rcIcon = trim($rc['icon'] ?? '');
                                if (substr($rcIcon, 0, 9) === '/uploads/') $rcIcon = '🧦';
                                $isSel = ($currentCatParent === $rcId)
                                    || ((int)$product['category_id'] === $rcId);
                            ?>
                                <button type="button"
                                    class="pf-cat-root <?= $isSel ? 'is-selected' : '' ?>"
                                    data-root-id="<?= $rcId ?>"
                                    data-root-name="<?= htmlspecialchars($rc['name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php if ($rcImg !== ''): ?>
                                        <img src="<?= htmlspecialchars($rcImg, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <span class="pf-cat-emoji"><?= htmlspecialchars($rcIcon ?: '🧦', ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <span class="pf-cat-label"><?= htmlspecialchars($rc['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                </button>
                            <?php endforeach; ?>

                            <?php if (empty($rootCatsRaw)): ?>
                                <div class="pf-cat-empty">
                                    هنوز دستهٔ اصلی ثبت نشده است.<br>
                                    <a href="/admin/categories" target="_blank">به صفحهٔ دسته‌بندی‌ها بروید</a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- مرحله ۲: dropdown زیرمجموعه‌های والد انتخاب‌شده -->
                        <div class="pf-cat-sub" id="pfCatSubWrap" style="<?= $currentCatParent > 0 ? '' : 'display:none;' ?>">
                            <label class="pf-cat-sub-label">زیرمجموعهٔ این دسته</label>
                            <select name="category_id" id="pfCatSubSelect">
                                <option value="">— انتخاب کنید —</option>
                            </select>
                            <span class="pf-field-hint">
                                فقط زیرمجموعه‌های این دستهٔ اصلی نمایش داده می‌شوند.
                                اگر محصول مستقیماً زیر خودِ دستهٔ اصلی ثبت می‌شود، گزینهٔ «خودِ دستهٔ اصلی» را انتخاب کنید.
                            </span>
                        </div>

                        <!-- نمایش دستهٔ انتخاب‌شدهٔ نهایی -->
                        <div class="pf-cat-chosen" id="pfCatChosen" style="display:none;">
                            <span class="pf-cat-chosen-label">دستهٔ انتخاب‌شده:</span>
                            <b id="pfCatChosenName"></b>
                            <button type="button" class="pf-cat-chosen-reset" id="pfCatReset" title="تغییر">✕</button>
                        </div>

                        <?php if (isset($errors['category_id'])): ?>
                            <span class="pf-field-error"><?= htmlspecialchars($errors['category_id']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pf-field pf-field-full">
                    <label>نام محصول <i>*</i></label>
                    <input type="text" name="name"
                        value="<?= htmlspecialchars($product['name']) ?>"
                        placeholder="مثلاً: جوراب نخی مردانه ساق‌بلند">
                    <?php if (isset($errors['name'])): ?>
                        <span class="pf-field-error"><?= htmlspecialchars($errors['name']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="pf-field">
                    <label>نامک انگلیسی (اختیاری)</label>
                    <input type="text" name="slug_en" dir="ltr" placeholder="m-cotton-long">
                    <span class="pf-field-hint">اگر خالی بماند، از کد ساخته می‌شود</span>
                </div>

                <div class="pf-field">
                    <label>حداقل سفارش (MOQ) <i>*</i></label>
                    <input type="number" name="base_moq" dir="ltr"
                        value="<?= htmlspecialchars($product['base_moq']) ?>" min="1">
                    <span class="pf-field-hint">کمترین تعداد قابل سفارش</span>
                    <?php if (isset($errors['base_moq'])): ?>
                        <span class="pf-field-error"><?= htmlspecialchars($errors['base_moq']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="pf-field">
                    <label>برند</label>
                    <input type="text" name="brand"
                        value="<?= htmlspecialchars($product['brand'] ?? '') ?>"
                        placeholder="مثلاً: Romeo">
                    <span class="pf-field-hint">پیش‌فرض بر اساس دسته ریشه — قابل ویرایش</span>
                </div>

                <div class="pf-field">
                    <label>جنس (ماده اصلی)</label>
                    <input type="text" name="material"
                        value="<?= htmlspecialchars($product['material'] ?? '') ?>"
                        placeholder="مثلاً: پنبه ۸۰٪ + پلی‌استر ۲۰٪">
                </div>

                <div class="pf-field">
                    <label>رنگ غالب</label>
                    <input type="text" name="color"
                        value="<?= htmlspecialchars($product['color'] ?? '') ?>"
                        placeholder="مثلاً: مشکی، سرمه‌ای">
                </div>

                <div class="pf-field">
                    <label>سایز</label>
                    <input type="text" name="size"
                        value="<?= htmlspecialchars($product['size'] ?? '') ?>"
                        placeholder="مثلاً: ۳۹-۴۲، فری‌سایز">
                </div>

                <div class="pf-field">
                    <label>وضعیت محصول <i>*</i></label>
                    <select name="status">
                        <?php foreach ($statusOptions as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($product['status'] ?? 'draft') === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="pf-field-hint">پیش‌نویس در سایت نمایش داده نمی‌شود</span>
                </div>

                <div class="pf-field">
                    <label>وضعیت فعال/غیرفعال</label>
                    <select name="is_active">
                        <option value="1" <?= ($product['is_active'] ?? 1) ? 'selected' : '' ?>>فعال</option>
                        <option value="0" <?= empty($product['is_active']) ? 'selected' : '' ?>>غیرفعال</option>
                    </select>
                </div>

                <div class="pf-field">
                    <label class="pf-checkbox">
                        <input type="checkbox" name="is_custom_orderable" value="1"
                            <?= !empty($product['is_custom_orderable']) ? 'checked' : '' ?>>
                        <span>این محصول قابل سفارش اختصاصی است</span>
                    </label>
                </div>

                <div class="pf-field pf-field-full">
                    <label>توضیحات محصول</label>
                    <textarea name="description" rows="5"
                        placeholder="معرفی کوتاه و دقیق محصول..."><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                </div>

            </div>
        </div>

        <!-- ─────────────── بخش ۲: قیمت و موجودی ─────────────── -->
        <div class="pf-section">
            <div class="pf-section-head">
                <div class="pf-section-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="1" x2="12" y2="23" />
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                    </svg>
                </div>
                <div>
                    <h3>قیمت و موجودی</h3>
                    <small>قیمت عادی فروش و موجودی انبار</small>
                </div>
            </div>

            <div class="pf-grid">
                <div class="pf-field">
                    <label>قیمت عادی فروش (تومان) <i>*</i></label>
                    <input type="text" name="base_price" id="basePriceInput" dir="ltr"
                        value="<?= number_format((int)$product['base_price']) ?>" inputmode="numeric"
                        placeholder="مثلاً 1.000.000" autocomplete="off">
                    <span class="pf-field-hint">قیمت پایه محصول — سه‌رقم سه‌رقم جدا می‌شود</span>
                    <?php if (isset($errors['base_price'])): ?>
                        <span class="pf-field-error"><?= htmlspecialchars($errors['base_price']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="pf-field">
                    <label>موجودی فیزیکی انبار</label>
                    <input type="number" name="qty" dir="ltr"
                        value="<?= (int)$qty ?>" min="0" step="1">
                    <span class="pf-field-hint">موجودی فیزیکی — رزروها دست‌نخورده می‌مانند</span>
                </div>
            </div>

            <!-- پله‌های تخفیف درصدی -->
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #f0f3f4;">
                <label class="pf-checkbox">
                    <input type="checkbox" name="discount_enabled" value="1"
                        id="discountEnabled" <?= $discountEnabled ? 'checked' : '' ?>>
                    <span>این محصول دارای تخفیف پلکانی است</span>
                </label>

                <div id="discountTiersBox" style="<?= $discountEnabled ? '' : 'display:none;' ?>">
                    <div id="discountTiers">
                        <?php if ($priceTiers): ?>
                            <?php foreach ($priceTiers as $tier): ?>
                                <div class="pf-tier">
                                    <input type="number" name="discount_qty[]"
                                        value="<?= (int)$tier['min_qty'] ?>"
                                        min="<?= (int)$product['base_moq'] ?>"
                                        step="1" placeholder="از تعداد">
                                    <input type="number" name="discount_percent[]"
                                        value="<?= htmlspecialchars((string)$tier['discount_percent']) ?>"
                                        min="0.01" max="99.99" step="0.01" placeholder="درصد تخفیف">
                                    <button type="button" class="pf-tier-remove discount-remove">حذف</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <button type="button" id="addDiscountTier" class="pf-tier-add">
                        + افزودن پله تخفیف
                    </button>

                    <?php if (isset($errors['discount'])): ?>
                        <span class="pf-field-error" style="display:block;margin-top:10px;">
                            <?= htmlspecialchars($errors['discount']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ─────────────── بخش ۳: مشخصات فنی ─────────────── -->
        <div class="pf-section">
            <div class="pf-section-head">
                <div class="pf-section-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                        <line x1="16" y1="13" x2="8" y2="13" />
                        <line x1="16" y1="17" x2="8" y2="17" />
                    </svg>
                </div>
                <div>
                    <h3>مشخصات فنی</h3>
                    <small>فیلدهای خالی در سایت نمایش داده نمی‌شوند</small>
                </div>
            </div>

            <div class="pf-specs-grid">

                <!-- مناسب برای (readonly) -->
                <div class="pf-field">
                    <label>مناسب برای</label>
                    <input type="text" name="spec_<?= md5('مناسب برای') ?>"
                        id="specForField"
                        value="<?= htmlspecialchars($currentSpecs['مناسب برای'] ?? '') ?>"
                        readonly
                        style="background:#f5f7f8;cursor:not-allowed;">
                    <span class="pf-field-hint">از دسته ریشه ارث می‌برد</span>
                </div>

                <!-- تعداد در بسته — خودکار بر اساس واحد سفارش (جفتی→۱، بسته→۱۰، جین→۱۲) -->
                <div class="pf-field">
                    <label>تعداد در بسته</label>
                    <input type="number" name="spec_<?= md5('تعداد در بسته') ?>"
                        id="packCountInput"
                        value="<?= htmlspecialchars($currentSpecs['تعداد در بسته'] ?? '12') ?>"
                        min="1" step="1" dir="ltr"
                        readonly
                        style="background:#f5f7f8;cursor:not-allowed;">
                    <span class="pf-field-hint">خودکار از واحد سفارش گرفته می‌شود</span>
                </div>

                <!-- واحد سفارش -->
                <div class="pf-field">
                    <label>واحد سفارش</label>
                    <select name="spec_<?= md5('واحد سفارش') ?>" id="unitSelect">
                        <?php
                        // اولویت: ۱) ستون unit در products  ۲) product_specs  ۳) پیش‌فرض 'جین'
                        $curUnit = !empty($product['unit'])
                            ? $product['unit']
                            : ($currentSpecs['واحد سفارش'] ?? 'جین');
                        ?>
                        <?php foreach ($unitOptions as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val) ?>" <?= $curUnit === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- دوخت پنجه -->
                <div class="pf-field">
                    <label>دوخت پنجه</label>
                    <select name="spec_<?= md5('دوخت پنجه') ?>">
                        <?php $curToe = $currentSpecs['دوخت پنجه'] ?? 'روسو'; ?>
                        <?php foreach ($toeOptions as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val) ?>" <?= $curToe === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- سایز -->
                <div class="pf-field">
                    <label>سایز</label>
                    <select name="spec_<?= md5('سایز') ?>">
                        <?php $curSize = $currentSpecs['سایز'] ?? 'فری سایز'; ?>
                        <?php foreach ($sizeOptions as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val) ?>" <?= $curSize === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- جنس -->
                <div class="pf-field">
                    <label>جنس</label>
                    <select name="spec_<?= md5('جنس') ?>">
                        <?php $curMat = $currentSpecs['جنس'] ?? 'نخ پنبه'; ?>
                        <?php foreach ($matOptions as $val => $label): ?>
                            <option value="<?= htmlspecialchars($val) ?>" <?= $curMat === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>
        </div>

        <!-- ─────────────── بخش ۴: تصاویر ─────────────── -->
        <?php if ($action === 'new'): ?>
            <div class="pfi-block" style="opacity:.75;">
                <div class="pfi-head">
                    <div>
                        <h3>تصاویر محصول</h3>
                        <p>پس از ذخیره محصول قابل آپلود است</p>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:30px 20px;text-align:center;color:var(--admin-info,#3f7fa1);background:#f8fafb;border:1px dashed #cfd8dc;border-radius:12px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:32px;height:32px;">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                    </svg>
                    <b style="font-size:.9rem;">ابتدا محصول را ذخیره کنید</b>
                    <small style="font-size:.75rem;color:#607d8b;">بعد از ذخیره، می‌توانید تا ۵ عکس آپلود کنید</small>
                </div>
            </div>
        <?php else: ?>
            <div class="pfi-block">
                <div class="pfi-head">
                    <div>
                        <h3>تصاویر محصول</h3>
                        <p>حداکثر ۵ عکس — ستاره‌دار = عکس اصلی</p>
                    </div>
                    <span class="pfi-counter"><?= count($images) ?> / ۵</span>
                </div>

                <?php if ($images): ?>
                    <div class="pfi-grid">
                        <?php foreach ($images as $img): ?>
                            <div class="pfi-card <?= (int)$img['is_main'] ? 'is-main' : '' ?>">
                                <div class="pfi-img-wrap">
                                    <img src="<?= htmlspecialchars($img['image_path']) ?>" alt="">
                                    <?php if ((int)$img['is_main']): ?>
                                        <span class="pfi-badge">★ اصلی</span>
                                    <?php endif; ?>
                                </div>
                                <div class="pfi-actions">
                                    <?php if (!(int)$img['is_main']): ?>
                                        <button type="submit" form="form_set_main"
                                            name="image_id" value="<?= (int)$img['id'] ?>"
                                            class="pfi-btn pfi-btn-main">★ اصلی شود</button>
                                    <?php endif; ?>
                                    <button type="submit" form="form_delete_img"
                                        name="image_id" value="<?= (int)$img['id'] ?>"
                                        class="pfi-btn pfi-btn-del"
                                        onclick="return confirm('این عکس حذف شود؟');">🗑 حذف</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (count($images) < 5): ?>
                    <div class="pfi-uploader">
                        <input type="file" id="pfiInput"
                            accept="image/jpeg,image/png,image/webp" multiple
                            style="display:none;">

                        <div class="pfi-drop" id="pfiDrop">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                                <polyline points="17 8 12 3 7 8" />
                                <line x1="12" y1="3" x2="12" y2="15" />
                            </svg>
                            <b>فایل‌ها را بکشید اینجا یا کلیک کنید</b>
                            <small>JPG / PNG / WebP — هر فایل زیر ۲ مگابایت</small>
                        </div>

                        <div class="pfi-preview" id="pfiPreview"></div>

                        <div class="pfi-actions-row" id="pfiActionsRow" style="display:none;">
                            <button type="button" class="pfi-btn-upload" id="pfiUploadBtn">⬆ آپلود عکس‌ها</button>
                            <button type="button" class="pfi-btn-clear" id="pfiClearBtn">✕ انصراف</button>
                        </div>

                        <div class="pfi-status" id="pfiStatus"></div>
                    </div>
                <?php else: ?>
                    <div class="pfi-limit">✓ حداکثر عکس (۵) — برای افزودن عکس جدید، یکی را حذف کنید</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ─────────────── بخش ۵: سئو ─────────────── -->
        <div class="pf-section">
            <div class="pf-section-head">
                <div class="pf-section-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" />
                        <path d="M21 21l-4.35-4.35" />
                    </svg>
                </div>
                <div>
                    <h3>سئو و انتشار</h3>
                    <small>عنوان و توضیحات متای صفحه محصول</small>
                </div>
            </div>

            <div class="pf-grid">

                <div class="pf-field pf-field-full">
                    <label>عنوان متا (Meta Title)</label>
                    <input type="text" name="meta_title" maxlength="200"
                        value="<?= htmlspecialchars($product['meta_title'] ?? '') ?>"
                        placeholder="مثلاً: جوراب نخی مردانه ساق‌بلند | رابطی">
                    <span class="pf-field-hint">خالی = نام محصول به‌عنوان عنوان</span>
                    <?php if (isset($errors['slug'])): ?>
                        <span class="pf-field-error"><?= htmlspecialchars($errors['slug']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="pf-field pf-field-full">
                    <label>توضیحات متا (Meta Description)</label>
                    <textarea name="meta_description" rows="3"
                        placeholder="توضیح کوتاه ۱۵۰-۱۶۰ کاراکتری برای موتور جستجو..."><?= htmlspecialchars($product['meta_description'] ?? '') ?></textarea>
                </div>

                <?php if ($action === 'edit'): ?>
                    <div class="pf-field pf-field-full">
                        <label>نشانی یکتای محصول (slug)</label>
                        <input type="text" name="slug" dir="ltr"
                            value="<?= htmlspecialchars($product['slug'] ?? '') ?>">
                        <span class="pf-field-hint">
                            تغییر فقط در صورت ضرورت — صفحه محصول:
                            <code dir="ltr">/product/<?= htmlspecialchars($product['slug'] ?? '') ?></code>
                        </span>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- ─────────────── دکمه ذخیره ─────────────── -->
        <div class="pf-submit-row">
            <button type="submit" class="pf-submit">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
                    <polyline points="17 21 17 13 7 13 7 21" />
                    <polyline points="7 3 7 8 15 8" />
                </svg>
                <?= $action === 'edit' ? 'ذخیره تغییرات' : 'افزودن محصول' ?>
            </button>
        </div>

    </form>
</div>

<!-- ═══════════════ نوار شناور ═══════════════ -->
<div class="pf-sticky-bar" id="pfStickyBar" aria-hidden="true">
    <div class="pf-sticky-inner">
        <div class="pf-sticky-info">
            <span class="pf-sticky-dot" id="pfStickyDot"></span>
            <span class="pf-sticky-text" id="pfStickyText">تغییری ثبت نشده</span>
        </div>
        <div class="pf-sticky-actions">
            <?php if ($action === 'edit'): ?>
                <a href="/product/<?= htmlspecialchars($product['slug']) ?>"
                    target="_blank" rel="noopener"
                    class="pf-sticky-btn pf-sticky-btn-view">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                        <circle cx="12" cy="12" r="3" />
                    </svg>
                    <span>مشاهده</span>
                </a>
            <?php endif; ?>
            <button type="submit" form="mainProductForm"
                class="pf-sticky-btn pf-sticky-btn-save" id="pfStickySave">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
                    <polyline points="17 21 17 13 7 13 7 21" />
                    <polyline points="7 3 7 8 15 8" />
                </svg>
                <span>ذخیره</span>
            </button>
        </div>
    </div>
</div>

<script>
    (function() {
        'use strict';

        /* ═══════════════════════════════════════════════════════════
           ۱) پر کردن خودکار «تعداد در بسته» با تغییر واحد سفارش
              جفتی → ۱، بسته → ۱۰، جین → ۱۲
           ═══════════════════════════════════════════════════════════ */

        var unitSelect = document.getElementById('unitSelect');
        var packCountInput = document.getElementById('packCountInput');

        /* نگاشت واحد → تعداد در بسته (مطابق تعریف unitOptions در PHP) */
        var packByUnit = {
            'جفتی': 1,
            'بسته': 10,
            'جین': 12
        };

        function syncPackCount() {
            if (!unitSelect || !packCountInput) return;
            var unit = unitSelect.value;
            var n = packByUnit[unit];
            if (typeof n !== 'undefined') {
                packCountInput.value = n;
            }
        }

        if (unitSelect && packCountInput) {
            unitSelect.addEventListener('change', syncPackCount);
            /* هم در بار اول (لود صفحه) همگام کن — مگر اینکه مقدار از DB آمده باشد */
            syncPackCount();
        }

        /* ═══════════════════════════════════════════════════════════
           ۲) فرمت‌بندی قیمت سه‌رقم سه‌رقم هنگام تایپ
              (separating thousands with '.' for fa-IR display)
              مقدار واقعی که به سرور می‌رود همان فیلد است ولی با نقطه.
              سمت سرور همهٔ غیر-رقم‌ها را حذف می‌کند.
           ═══════════════════════════════════════════════════════════ */

        var priceInput = document.getElementById('basePriceInput');

        function formatPrice(value) {
            /* فقط ارقام را نگه می‌داریم */
            var digits = String(value).replace(/[^\d]/g, '');
            if (digits === '') return '';
            /* حذف صفرهای ابتدایی (مگر اینکه خود عدد صفر باشد) */
            digits = digits.replace(/^0+(?=\d)/, '');
            if (digits === '') return '0';
            /* سه‌رقم سه‌رقم از راست با نقطه جدا می‌کنیم */
            return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        if (priceInput) {
            /* مقدار اولیه را هم فرمت کن (در حالت ویرایش، از DB می‌آید) */
            priceInput.value = formatPrice(priceInput.value);

            priceInput.addEventListener('input', function() {
                var oldStart = priceInput.selectionStart;
                var oldLen = priceInput.value.length;
                priceInput.value = formatPrice(priceInput.value);
                var newLen = priceInput.value.length;
                /* مکان‌نما را در همان جای منطقی نگه دار */
                var diff = newLen - oldLen;
                var newPos = Math.max(0, oldStart + diff);
                try {
                    priceInput.setSelectionRange(newPos, newPos);
                } catch (e) {}
            });

            /* جلوگیری از وارد کردن حروف با کلیدهای چاپی */
            priceInput.addEventListener('keypress', function(e) {
                var allowedKeys = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
                    'Backspace', 'Delete', 'ArrowLeft', 'ArrowRight',
                    'Tab', 'Home', 'End', 'Enter'
                ];
                if (e.ctrlKey || e.metaKey || e.altKey) return;
                if (allowedKeys.indexOf(e.key) === -1) {
                    e.preventDefault();
                }
            });

            /* جلوگیری از چسباندن متن غیرعددی */
            priceInput.addEventListener('paste', function(e) {
                e.preventDefault();
                var text = (e.clipboardData || window.clipboardData).getData('text');
                priceInput.value = formatPrice(text);
            });
        }

        /* ═══════════════════════════════════════════════════════════
           پله‌های تخفیف — toggle
           ═══════════════════════════════════════════════════════════ */

        var discountEnabled = document.getElementById('discountEnabled');
        var tiersBox = document.getElementById('discountTiersBox');

        if (discountEnabled && tiersBox) {
            discountEnabled.addEventListener('change', function() {
                tiersBox.style.display = discountEnabled.checked ? '' : 'none';
            });
        }

        /* ═══════════════════════════════════════════════════════════
           پله‌های تخفیف — افزودن/حذف ردیف
           ═══════════════════════════════════════════════════════════ */

        var addTierBtn = document.getElementById('addDiscountTier');
        var tiersList = document.getElementById('discountTiers');

        if (addTierBtn && tiersList) {
            addTierBtn.addEventListener('click', function() {
                var row = document.createElement('div');
                row.className = 'pf-tier';
                row.innerHTML = '' +
                    '<input type="number" name="discount_qty[]" min="1" step="1" placeholder="از تعداد">' +
                    '<input type="number" name="discount_percent[]" min="0.01" max="99.99" step="0.01" placeholder="درصد تخفیف">' +
                    '<button type="button" class="pf-tier-remove discount-remove">حذف</button>';
                tiersList.appendChild(row);
            });

            tiersList.addEventListener('click', function(e) {
                var btn = e.target.closest('.discount-remove');
                if (!btn) return;

                var rows = tiersList.querySelectorAll('.pf-tier');
                if (rows.length === 1) {
                    rows[0].querySelectorAll('input').forEach(function(i) {
                        i.value = '';
                    });
                    return;
                }
                btn.closest('.pf-tier').remove();
            });
        }

        /* ═══════════════════════════════════════════════════════════
           انتخاب هدایت‌شدهٔ دسته: کارت ریشه → dropdown زیرمجموعه
           ═══════════════════════════════════════════════════════════ */

        var catSubSelect = document.getElementById('pfCatSubSelect');
        var catSubWrap = document.getElementById('pfCatSubWrap');
        var catChosen = document.getElementById('pfCatChosen');
        var catChosenName = document.getElementById('pfCatChosenName');
        var catReset = document.getElementById('pfCatReset');
        var catRootBtns = document.querySelectorAll('.pf-cat-root');

        var specForField = document.getElementById('specForField');
        var brandField = document.querySelector('input[name="brand"]');

        /* داده از PHP: نقشهٔ parentId => [فرزندها] و نقشهٔ catId => نام ریشه */
        var childCatsJs = <?= json_encode(
                                array_map(function ($kids) {
                                    return array_map(function ($k) {
                                        $icon = (string)($k['icon'] ?? '');
                                        if (substr($icon, 0, 9) === '/uploads/') $icon = '🧦';
                                        return [
                                            'id'    => (int)$k['id'],
                                            'name'  => $k['name'],
                                            'icon'  => $icon,
                                            'image' => (string)($k['image_path'] ?? ''),
                                        ];
                                    }, $kids);
                                }, $childCategories),
                                JSON_UNESCAPED_UNICODE
                            ) ?>;

        var catRootMap = <?= json_encode($catRootMap, JSON_UNESCAPED_UNICODE) ?>;
        var brandByRoot = <?= json_encode($brandDefaults, JSON_UNESCAPED_UNICODE) ?>;

        /* نام دستهٔ اصلی والد (برای نمایش «خودِ دستهٔ اصلی» در dropdown) */
        var rootNameById = {};
        catRootBtns.forEach(function(btn) {
            rootNameById[parseInt(btn.dataset.rootId, 10)] = btn.dataset.rootName || '';
        });

        /* دستهٔ انتخاب‌شدهٔ فعلی (در حالت edit) از PHP می‌آید */
        var currentCatId = <?= (int)($product['category_id'] ?? 0) ?>;
        var currentParent = <?= (int)$currentCatParent ?>;

        /* ─── نمایش دستهٔ انتخاب‌شده در نوار پایین ─── */
        function showChosen(catId) {
            if (!catId) {
                catChosen.style.display = 'none';
                catChosenName.textContent = '';
                return;
            }
            var rootName = catRootMap[catId] || '';
            var subSel = catSubSelect.querySelector('option[value="' + catId + '"]');
            var subName = subSel ? subSel.textContent : '';
            var display = subName ? (rootName + ' › ' + subName) : rootName;
            catChosenName.textContent = display;
            catChosen.style.display = 'flex';

            /* به‌روزرسانی فیلدهای وابسته: «مناسب برای» و برند */
            if (specForField) specForField.value = rootName;
            if (brandField) {
                var currentBrand = brandField.value.trim();
                var newBrand = brandByRoot[rootName] || '';
                var oldDefault = brandField.dataset.defaultBrand || '';
                if (currentBrand === '' || currentBrand === oldDefault) {
                    brandField.value = newBrand;
                }
                brandField.dataset.defaultBrand = newBrand;
            }
        }

        /* ─── پاک کردن کامل انتخاب ─── */
        function clearSelection() {
            catSubSelect.value = '';
            catSubSelect.innerHTML = '<option value="">— انتخاب کنید —</option>';
            catSubWrap.style.display = 'none';
            catChosen.style.display = 'none';
            catRootBtns.forEach(function(b) {
                b.classList.remove('is-selected');
            });
        }

        /* ─── کلیک روی یک کارت ریشه ─── */
        catRootBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var rootId = parseInt(btn.dataset.rootId, 10);
                var rootName = btn.dataset.rootName || '';

                /* فقط همین کارت را انتخاب‌شده نشان بده */
                catRootBtns.forEach(function(b) {
                    b.classList.remove('is-selected');
                });
                btn.classList.add('is-selected');

                /* پر کردن dropdown زیرمجموعه با فرزندهای همین والد */
                var kids = childCatsJs[rootId] || [];
                var html = '<option value="">— انتخاب کنید —</option>';

                /* گزینهٔ «خودِ دستهٔ اصلی» — یعنی محصول مستقیماً زیر والد ثبت شود */
                html += '<option value="' + rootId + '">— خودِ دستهٔ اصلی («' + rootName + '») —</option>';

                kids.forEach(function(k) {
                    var label = (k.image ? '' : (k.icon ? k.icon + ' ' : '')) + k.name;
                    html += '<option value="' + k.id + '">' + label + '</option>';
                });

                catSubSelect.innerHTML = html;
                catSubWrap.style.display = 'flex';
                catSubSelect.value = '';
                catChosen.style.display = 'none';
                catSubSelect.focus();
            });
        });

        /* ─── تغییر در dropdown زیرمجموعه ─── */
        if (catSubSelect) {
            catSubSelect.addEventListener('change', function() {
                showChosen(parseInt(catSubSelect.value, 10) || 0);
            });
        }

        /* ─── دکمهٔ بازنشانی انتخاب ─── */
        if (catReset) {
            catReset.addEventListener('click', function() {
                clearSelection();
            });
        }

        /* ─── در حالت edit، اگر محصول دستهٔ انتخاب‌شده دارد، آن را فعال کن ─── */
        if (currentCatId > 0) {
            /* اگر دستهٔ فعلی خودش والد است (currentParent === 0)، کارت همان را انتخاب کن */
            var targetRootId = currentParent > 0 ? currentParent : currentCatId;

            var targetBtn = document.querySelector('.pf-cat-root[data-root-id="' + targetRootId + '"]');
            if (targetBtn) {
                targetBtn.click(); /* dropdown را با فرزندها پر می‌کند */
                /* حالا مقدار صحیح را در dropdown بگذار */
                catSubSelect.value = String(currentCatId);
                showChosen(currentCatId);
            }
        }

        /* برند پیش‌فرض اولیه */
        if (brandField) {
            brandField.dataset.defaultBrand = brandField.value.trim();
        }

        /* ═══════════════════════════════════════════════════════════
           به‌روزرسانی MOQ با تغییر واحد سفارش
           ═══════════════════════════════════════════════════════════ */

        var unitSelect = document.getElementById('unitSelect');
        var moqField = document.querySelector('input[name="base_moq"]');

        if (unitSelect && moqField) {
            unitSelect.addEventListener('change', function() {
                var v = unitSelect.value;
                if (moqField.value === '' || moqField.value === '0') {
                    if (v === 'جین') moqField.value = '1';
                    if (v === 'بسته') moqField.value = '10';
                    if (v === 'جفتی') moqField.value = '1';
                }
            });
        }

        /* ═══════════════════════════════════════════════════════════
           آپلود تصویر (AJAX)
           ═══════════════════════════════════════════════════════════ */

        var pfiInput = document.getElementById('pfiInput');
        var pfiDrop = document.getElementById('pfiDrop');
        var pfiPreview = document.getElementById('pfiPreview');
        var pfiActions = document.getElementById('pfiActionsRow');
        var pfiUploadBtn = document.getElementById('pfiUploadBtn');
        var pfiClearBtn = document.getElementById('pfiClearBtn');
        var pfiStatus = document.getElementById('pfiStatus');

        if (pfiInput && pfiDrop && pfiUploadBtn) {

            var MAX_SIZE = 2 * 1024 * 1024;
            var productId = <?= (int)$productId ?>;

            pfiDrop.addEventListener('click', function() {
                pfiInput.click();
            });

            ['dragenter', 'dragover'].forEach(function(ev) {
                pfiDrop.addEventListener(ev, function(e) {
                    e.preventDefault();
                    pfiDrop.classList.add('is-drag');
                });
            });

            ['dragleave', 'drop'].forEach(function(ev) {
                pfiDrop.addEventListener(ev, function(e) {
                    e.preventDefault();
                    pfiDrop.classList.remove('is-drag');
                });
            });

            pfiDrop.addEventListener('drop', function(e) {
                setFiles(Array.from(e.dataTransfer.files));
            });

            pfiInput.addEventListener('change', function() {
                setFiles(Array.from(pfiInput.files));
            });

            function setFiles(files) {
                pfiStatus.innerHTML = '';

                var valid = [];
                var errors = [];

                files.forEach(function(f) {
                    if (!/image\/(jpeg|png|webp)/i.test(f.type)) {
                        errors.push(f.name + ' — فرمت نامعتبر');
                        return;
                    }
                    if (f.size > MAX_SIZE) {
                        errors.push(f.name + ' — بزرگ‌تر از ۲ مگ');
                        return;
                    }
                    valid.push(f);
                });

                valid = valid.slice(0, 5);

                if (errors.length) {
                    pfiStatus.innerHTML = '<div class="pfi-alert pfi-alert-error">⚠️ ' + errors.join('<br>') + '</div>';
                }

                if (!valid.length) {
                    pfiActions.style.display = 'none';
                    return;
                }

                try {
                    var dt = new DataTransfer();
                    valid.forEach(function(f) {
                        dt.items.add(f);
                    });
                    pfiInput.files = dt.files;
                } catch (err) {}

                pfiPreview.innerHTML = '';
                valid.forEach(function(f) {
                    var url = URL.createObjectURL(f);
                    var el = document.createElement('div');
                    el.className = 'pfi-preview-item';
                    el.innerHTML = '<img src="' + url + '" alt=""><span class="pfi-preview-name">' + f.name + '</span>';
                    pfiPreview.appendChild(el);
                });

                pfiActions.style.display = 'flex';
                pfiUploadBtn.textContent = '⬆ آپلود ' + valid.length + ' عکس';
                pfiUploadBtn.disabled = false;
            }

            pfiUploadBtn.addEventListener('click', function() {
                if (!pfiInput.files.length) {
                    pfiStatus.innerHTML = '<div class="pfi-alert pfi-alert-error">⚠️ عکسی انتخاب نشده</div>';
                    return;
                }

                var fd = new FormData();
                fd.append('do', 'upload_image');
                for (var i = 0; i < pfiInput.files.length; i++) {
                    fd.append('images[]', pfiInput.files[i]);
                }

                pfiUploadBtn.disabled = true;
                pfiUploadBtn.textContent = 'در حال آپلود...';
                pfiStatus.innerHTML = '<div class="pfi-alert pfi-alert-loading">⏳ در حال آپلود...</div>';

                fetch('/admin/product-form?action=edit&id=' + productId, {
                        method: 'POST',
                        body: fd,
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.ok) {
                            pfiStatus.innerHTML = '<div class="pfi-alert pfi-alert-success">✓ ' + data.message + '</div>';
                            setTimeout(function() {
                                window.location.reload();
                            }, 900);
                        } else {
                            pfiStatus.innerHTML = '<div class="pfi-alert pfi-alert-error">⚠️ ' + (data.error || 'خطا') + '</div>';
                            pfiUploadBtn.disabled = false;
                            pfiUploadBtn.textContent = '⬆ تلاش دوباره';
                        }
                    })
                    .catch(function() {
                        pfiStatus.innerHTML = '<div class="pfi-alert pfi-alert-error">⚠️ خطای ارتباط</div>';
                        pfiUploadBtn.disabled = false;
                        pfiUploadBtn.textContent = '⬆ تلاش دوباره';
                    });
            });

            pfiClearBtn.addEventListener('click', function() {
                pfiInput.value = '';
                pfiPreview.innerHTML = '';
                pfiStatus.innerHTML = '';
                pfiActions.style.display = 'none';
            });
        }

        /* ═══════════════════════════════════════════════════════════
           نوار شناور ذخیره
           ═══════════════════════════════════════════════════════════ */

        var stickyBar = document.getElementById('pfStickyBar');
        var stickyDot = document.getElementById('pfStickyDot');
        var stickyText = document.getElementById('pfStickyText');
        var mainForm = document.getElementById('mainProductForm');
        var saveBtn = document.getElementById('pfStickySave');

        if (stickyBar && mainForm && saveBtn) {

            var isDirty = false;
            var isSubmitting = false;

            function updateBarVisibility() {
                var scrollY = window.pageYOffset || document.documentElement.scrollTop;
                var form = mainForm.getBoundingClientRect();

                if (form.top < 100 || scrollY > 200) {
                    stickyBar.classList.add('is-visible');
                    stickyBar.setAttribute('aria-hidden', 'false');
                } else if (!isDirty) {
                    stickyBar.classList.remove('is-visible');
                    stickyBar.setAttribute('aria-hidden', 'true');
                }
            }

            function setDirty(state) {
                isDirty = state;

                if (isDirty) {
                    stickyDot.classList.add('is-dirty');
                    stickyDot.classList.remove('is-saved');
                    stickyText.classList.add('is-dirty');
                    stickyText.classList.remove('is-saved');
                    stickyText.textContent = 'تغییرات ذخیره نشده';
                    updateBarVisibility();
                } else {
                    stickyDot.classList.remove('is-dirty');
                    stickyDot.classList.add('is-saved');
                    stickyText.classList.remove('is-dirty');
                    stickyText.classList.add('is-saved');
                    stickyText.textContent = 'همه‌چیز ذخیره شد';
                    setTimeout(function() {
                        if (!isDirty) {
                            stickyText.classList.remove('is-saved');
                            stickyText.textContent = 'تغییری ثبت نشده';
                        }
                    }, 2400);
                }
            }

            mainForm.addEventListener('input', function(e) {
                if (e.target.closest('#pfiInput')) return;
                if (isSubmitting) return;
                setDirty(true);
            });

            mainForm.addEventListener('change', function(e) {
                if (e.target.closest('#pfiInput')) return;
                if (isSubmitting) return;
                setDirty(true);
            });

            saveBtn.addEventListener('click', function() {
                isSubmitting = true;
                saveBtn.disabled = true;
                saveBtn.querySelector('span').textContent = 'در حال ذخیره...';
                mainForm.submit();
            });

            window.addEventListener('scroll', updateBarVisibility, {
                passive: true
            });

            <?php if (isset($_GET['saved'])): ?>
                setDirty(false);
            <?php endif; ?>

            window.addEventListener('beforeunload', function(e) {
                if (isDirty && !isSubmitting) {
                    e.preventDefault();
                    e.returnValue = '';
                    return '';
                }
            });

            setTimeout(updateBarVisibility, 300);
        }
    })();
</script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
