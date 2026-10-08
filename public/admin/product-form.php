<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/config/upload.php';

require_admin();

$db = db();

/* ═══════════════ فیلدهای مشخصات ═══════════════ */
$specFields = [
    'نوع جوراب',
    'جنس',
    'طرح',
    'مناسب برای',
    'تعداد در بسته',
    'رنگ',
    'دوخت پنجه',
    'پاشنه و پنجه',
    'وسط‌کش',
    'سایز',
];

$action    = ($_GET['action'] ?? 'new') === 'edit' ? 'edit' : 'new';
$productId = (int)($_GET['id'] ?? 0);

$errors = [];
$currentSpecs = [];
$priceTiers = [];
$images = [];

$product = [
    'code' => '',
    'name' => '',
    'slug' => '',
    'description' => '',
    'base_moq' => '48',
    'category_id' => '',
];

$price = 0;
$qty = 0;
$discountEnabled = false;

/* ═══════════════ ویرایش محصول ═══════════════ */
if ($action === 'edit' && $productId > 0) {

    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        header('Location: /admin/products');
        exit;
    }

    $stmt = $db->prepare("
        SELECT price FROM product_prices
        WHERE product_id = ? AND customer_group = 'wholesale' AND min_qty = 1
        LIMIT 1
    ");
    $stmt->execute([$productId]);
    $price = (int)($stmt->fetchColumn() ?: 0);

    $stmt = $db->prepare("
        SELECT min_qty, price FROM product_prices
        WHERE product_id = ? AND customer_group = 'wholesale' AND min_qty > 1
        ORDER BY min_qty ASC
    ");
    $stmt->execute([$productId]);

    foreach ($stmt->fetchAll() as $row) {
        $tp = (int)$row['price'];
        $percent = ($price > 0 && $tp < $price)
            ? round((1 - ($tp / $price)) * 100, 2) : 0;

        $priceTiers[] = [
            'min_qty' => (int)$row['min_qty'],
            'percent' => $percent,
            'price'   => $tp,
        ];
    }
    $discountEnabled = !empty($priceTiers);

    $stmt = $db->prepare("SELECT qty_available FROM inventory WHERE product_id = ? LIMIT 1");
    $stmt->execute([$productId]);
    $qty = (int)($stmt->fetchColumn() ?: 0);

    $stmt = $db->prepare("
        SELECT id, image_path, alt_text, is_main, sort_order
        FROM product_images
        WHERE product_id = ?
        ORDER BY is_main DESC, sort_order ASC, id ASC
    ");
    $stmt->execute([$productId]);
    $images = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT spec_key, spec_value FROM product_specs WHERE product_id = ?");
    $stmt->execute([$productId]);
    foreach ($stmt->fetchAll() as $s) {
        $currentSpecs[$s['spec_key']] = $s['spec_value'];
    }
}

/* ═══════════════ حذف عکس ═══════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete_image') {

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
                $stmt = $db->prepare("
                    UPDATE product_images SET is_main = 1
                    WHERE product_id = ?
                    ORDER BY sort_order ASC, id ASC
                    LIMIT 1
                ");
                $stmt->execute([$productId]);
            }
        }
    }

    header('Location: /admin/product-form?action=edit&id=' . $productId . '&img=deleted');
    exit;
}

/* ═══════════════ تعیین عکس اصلی ═══════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'set_main') {

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

/* ═══════════════ آپلود فوری عکس (AJAX) ═══════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'upload_image') {

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
            $existingCount + count($uploaded)
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
            . ($failed ? ' — ' . count($failed) . ' خطا' : '')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ═══════════════ ثبت فرم اصلی ═══════════════ */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['do'] ?? '') !== 'delete_image' &&
    ($_POST['do'] ?? '') !== 'set_main' &&
    ($_POST['do'] ?? '') !== 'upload_image'
) {

    $code        = strtoupper(trim($_POST['code'] ?? ''));
    $name        = trim($_POST['name'] ?? '');
    $slug        = trim($_POST['slug'] ?? '');
    $slugEn      = trim($_POST['slug_en'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $baseMoq    = max(1, (int)($_POST['base_moq'] ?? 1));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $price      = max(0, (int)($_POST['price'] ?? 0));
    $qty        = max(0, (int)($_POST['qty'] ?? 0));

    $discountEnabled = isset($_POST['discount_enabled']) && $_POST['discount_enabled'] === '1';

    $tierQtyInput     = $_POST['discount_qty'] ?? [];
    $tierPercentInput = $_POST['discount_percent'] ?? [];

    $tierQtyInput     = is_array($tierQtyInput) ? $tierQtyInput : [];
    $tierPercentInput = is_array($tierPercentInput) ? $tierPercentInput : [];

    $priceTiers = [];

    $specs = [];
    foreach ($specFields as $key) {
        $v = trim($_POST['spec_' . md5($key)] ?? '');
        if ($v !== '') $specs[] = [$key, $v];
    }

    if (!preg_match('/^S\d{3}$/', $code)) $errors['code'] = 'کد باید مثل S125 باشد';
    if (mb_strlen($name) < 3) $errors['name'] = 'نام محصول را کامل وارد کنید';
    if ($categoryId < 1) $errors['category_id'] = 'دسته را انتخاب کنید';
    if ($price < 1000) $errors['price'] = 'قیمت را به تومان وارد کنید';

    if (!$errors && $slug === '') {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($slugEn !== '' ? $slugEn : $code));
        $base = trim($base, '-');
        if ($base === '') $base = strtolower($code);
        $slug = $base . '-' . strtolower($code);
    }

    if (!$errors) {
        $stmt = $db->prepare("SELECT id FROM products WHERE code = ? AND id != ? LIMIT 1");
        $stmt->execute([$code, $productId]);
        if ($stmt->fetch()) $errors['code'] = 'این کد قبلاً استفاده شده است';
    }

    if (!$errors && $discountEnabled) {
        $lastQty = 0;
        $rowCount = max(count($tierQtyInput), count($tierPercentInput));

        for ($i = 0; $i < $rowCount; $i++) {
            $tierQty = (int)($tierQtyInput[$i] ?? 0);
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

            $calculatedPrice = (int)round($price * (1 - ($percent / 100)), -2);

            if ($calculatedPrice < 1 || $calculatedPrice >= $price) {
                $errors['discount'] = 'قیمت پله نامعتبر است';
                break;
            }

            $priceTiers[] = [
                'min_qty' => $tierQty,
                'percent' => $percent,
                'price'   => $calculatedPrice,
            ];
            $lastQty = $tierQty;
        }

        if (!$errors && !$priceTiers) $errors['discount'] = 'حداقل یک پله تخفیف تعریف کنید';
    }

    /* ═══════ ذخیره در DB ═══════ */
    if (!$errors) {

        try {
            $db->beginTransaction();

            if ($action === 'new') {
                $db->prepare("
                    INSERT INTO products (category_id, code, name, slug, description, base_moq)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$categoryId, $code, $name, $slug, $description ?: null, $baseMoq]);

                $productId = (int)$db->lastInsertId();
            } else {
                $db->prepare("
                    UPDATE products SET category_id=?, code=?, name=?, slug=?, description=?, base_moq=?
                    WHERE id = ?
                ")->execute([$categoryId, $code, $name, $slug, $description ?: null, $baseMoq, $productId]);
            }

            /* قیمت‌ها */
            $db->prepare("DELETE FROM product_prices WHERE product_id = ? AND customer_group = 'wholesale'")
                ->execute([$productId]);

            $db->prepare("INSERT INTO product_prices (product_id, customer_group, min_qty, price) VALUES (?, 'wholesale', 1, ?)")
                ->execute([$productId, $price]);

            foreach ($priceTiers as $tier) {
                $db->prepare("INSERT INTO product_prices (product_id, customer_group, min_qty, price) VALUES (?, 'wholesale', ?, ?)")
                    ->execute([$productId, $tier['min_qty'], $tier['price']]);
            }

            /* موجودی — روش مطمئن (UPDATE + INSERT) */
            /* موجودی — بررسی وجود رکورد قبل از UPDATE/INSERT */
            $stmt = $db->prepare("SELECT COUNT(*) FROM inventory WHERE product_id = ?");
            $stmt->execute([$productId]);
            $invExists = ((int)$stmt->fetchColumn()) > 0;

            if ($invExists) {
                $db->prepare("UPDATE inventory SET qty_available = ? WHERE product_id = ?")
                    ->execute([$qty, $productId]);
            } else {
                $db->prepare("INSERT INTO inventory (product_id, qty_available) VALUES (?, ?)")
                    ->execute([$productId, $qty]);
            }

            /* مشخصات */
            $db->prepare("DELETE FROM product_specs WHERE product_id = ?")->execute([$productId]);

            if ($specs) {
                $insSpec = $db->prepare("INSERT INTO product_specs (product_id, spec_key, spec_value, sort_order) VALUES (?, ?, ?, ?)");
                foreach ($specs as $i => $sv) {
                    $insSpec->execute([$productId, $sv[0], $sv[1], $i]);
                }
            }

            $db->commit();

            /* ⭐ ریدایرکت موفق */
            header('Location: /admin/product-form?action=edit&id=' . $productId . '&saved=1');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors['general'] = 'ذخیره محصول ناموفق بود: ' . $e->getMessage();
        }
    }

    $product = array_merge($product, [
        'code'        => $code,
        'name'        => $name,
        'slug'        => $slug,
        'description' => $description,
        'base_moq'    => $baseMoq,
        'category_id' => $categoryId,
    ]);
}

/* ═══════════════ دسته‌ها ═══════════════ */
$categories = $db->query("
    SELECT id, name FROM categories
    WHERE is_active = 1
    ORDER BY sort_order
")->fetchAll();

$activeMenu = 'products';
$pageTitle  = ($action === 'edit' ? 'ویرایش محصول' : 'محصول جدید');

ob_start();
?>

<link rel="stylesheet" href="/assets/css/product-form-images.css">

<div style="margin-bottom:16px;">
    <a href="/admin/products" style="color:var(--teal-700);font-size:.82rem;">← بازگشت</a>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="form-alert" style="display:block;background:#e8f7f3;color:var(--teal-800);margin-bottom:16px;">
        ✓ تغییرات ذخیره شد
    </div>
<?php endif; ?>

<?php if (isset($_GET['img'])): ?>
    <div class="form-alert" style="display:block;background:#e8f7f3;color:var(--teal-800);margin-bottom:16px;">
        <?= $_GET['img'] === 'deleted' ? '✓ عکس حذف شد' : '✓ عکس اصلی تغییر کرد' ?>
    </div>
<?php endif; ?>


<!-- ═══════ فرم مخفی برای عملیات عکس (بیرون از فرم اصلی) ═══════ -->

<form id="form_set_main" method="post" style="display:none;">
    <input type="hidden" name="do" value="set_main">
</form>

<form id="form_delete_img" method="post" style="display:none;">
    <input type="hidden" name="do" value="delete_image">
</form>


<!-- ═══════ فرم اصلی ═══════ -->

<form id="mainProductForm" method="post" class="co-form" style="max-width:100%;" enctype="multipart/form-data">

    <input type="hidden" name="do" value="save">

    <?php if ($errors): ?>
        <div class="form-alert error" style="display:block;">
            ⚠️
            <?php foreach ($errors as $er): ?>
                <?= htmlspecialchars($er, ENT_QUOTES, 'UTF-8') ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>


    <!-- ════════ مشخصات اصلی ════════ -->
    <div class="form-row">
        <div class="form-field">
            <label>کد محصول <i>*</i></label>
            <input type="text" name="code" dir="ltr"
                value="<?= htmlspecialchars($product['code'], ENT_QUOTES, 'UTF-8') ?>"
                placeholder="S125" maxlength="4">
            <?php if (isset($errors['code'])): ?>
                <span class="field-error"><?= htmlspecialchars($errors['code']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-field">
            <label>دسته‌بندی <i>*</i></label>
            <select name="category_id">
                <option value="">— انتخاب کنید —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $product['category_id'] == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['category_id'])): ?>
                <span class="field-error"><?= htmlspecialchars($errors['category_id']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-field">
        <label>نام محصول <i>*</i></label>
        <input type="text" name="name"
            value="<?= htmlspecialchars($product['name']) ?>"
            placeholder="مثلاً: جوراب نخی مردانه ساق‌بلند">
        <?php if (isset($errors['name'])): ?>
            <span class="field-error"><?= htmlspecialchars($errors['name']) ?></span>
        <?php endif; ?>
    </div>

    <div class="form-row">
        <div class="form-field">
            <label>نامک انگلیسی (اختیاری)</label>
            <input type="text" name="slug_en" dir="ltr" placeholder="m-cotton-long">
        </div>
        <div class="form-field">
            <label>حداقل سفارش (MOQ) <i>*</i></label>
            <input type="number" name="base_moq" dir="ltr"
                value="<?= htmlspecialchars($product['base_moq']) ?>" min="1">
        </div>
    </div>

    <div class="form-row">
        <div class="form-field">
            <label>قیمت همکاری پایه (تومان) <i>*</i></label>
            <input type="number" name="price" dir="ltr" value="<?= (int)$price ?>" min="0" step="100">
            <?php if (isset($errors['price'])): ?>
                <span class="field-error"><?= htmlspecialchars($errors['price']) ?></span>
            <?php endif; ?>
        </div>
        <div class="form-field">
            <label>موجودی انبار (جفت)</label>
            <input type="number" name="qty" dir="ltr" value="<?= (int)$qty ?>" min="0" step="1">
        </div>
    </div>


    <!-- ════════ قیمت پلکانی ════════ -->
    <div class="form-field" style="margin-top:8px;padding:18px;border:1px solid var(--card-border);border-radius:16px;">

        <label style="display:block;margin-bottom:8px;">قیمت‌گذاری پلکانی</label>

        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:14px;">
            <input type="checkbox" name="discount_enabled" value="1" id="discountEnabled" <?= $discountEnabled ? 'checked' : '' ?>>
            <span>این محصول دارای تخفیف پلکانی است</span>
        </label>

        <div id="discountTiersBox" style="<?= $discountEnabled ? '' : 'display:none;' ?>">
            <div id="discountTiers">
                <?php if ($priceTiers): ?>
                    <?php foreach ($priceTiers as $tier): ?>
                        <div class="discount-tier" style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:center;margin-bottom:10px;">
                            <input type="number" name="discount_qty[]" value="<?= (int)$tier['min_qty'] ?>" min="<?= (int)$product['base_moq'] ?>" step="1" placeholder="تعداد">
                            <input type="number" name="discount_percent[]" value="<?= htmlspecialchars((string)$tier['percent']) ?>" min="0.01" max="99.99" step="0.01" placeholder="درصد">
                            <button type="button" class="btn discount-remove">حذف</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" id="addDiscountTier" class="btn" style="margin-top:4px;">+ افزودن پله</button>
        </div>

        <?php if (isset($errors['discount'])): ?>
            <span class="field-error" style="display:block;margin-top:10px;"><?= htmlspecialchars($errors['discount']) ?></span>
        <?php endif; ?>
    </div>


    <!-- ════════ تصاویر ════════ -->
    <div class="form-field pfi-block">

        <div class="pfi-head">
            <div>
                <h3>تصاویر محصول</h3>
                <p>حداکثر <b>۵ عکس</b> — ستاره‌دار = عکس اصلی</p>
            </div>
            <span class="pfi-counter">
                <?= count($images) ?> / ۵
            </span>
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
                                <button type="submit"
                                    form="form_set_main"
                                    name="image_id"
                                    value="<?= (int)$img['id'] ?>"
                                    class="pfi-btn pfi-btn-main"
                                    title="عکس اصلی شود">
                                    ★ اصلی شود
                                </button>
                            <?php endif; ?>

                            <button type="submit"
                                form="form_delete_img"
                                name="image_id"
                                value="<?= (int)$img['id'] ?>"
                                class="pfi-btn pfi-btn-del"
                                title="حذف"
                                onclick="return confirm('این عکس حذف شود؟');">
                                🗑 حذف
                            </button>

                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>


        <?php if (count($images) < 5): ?>

            <div class="pfi-uploader">

                <input type="file"
                    id="pfiInput"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                    style="display:none;">

                <div class="pfi-drop" id="pfiDrop">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                        <polyline points="17 8 12 3 7 8" />
                        <line x1="12" y1="3" x2="12" y2="15" />
                    </svg>
                    <b>فایل‌ها را بکشید اینجا یا کلیک کنید</b>
                    <small>JPG / PNG / WebP — هر فایل زیر ۲ مگابایت</small>
                </div>

                <div class="pfi-preview" id="pfiPreview"></div>

                <div class="pfi-actions-row" id="pfiActionsRow" style="display:none;">
                    <button type="button" class="pfi-btn-upload" id="pfiUploadBtn">
                        ⬆ آپلود عکس‌ها
                    </button>
                    <button type="button" class="pfi-btn-clear" id="pfiClearBtn">
                        ✕ انصراف
                    </button>
                </div>

                <div class="pfi-status" id="pfiStatus"></div>

            </div>

        <?php else: ?>

            <div class="pfi-limit">
                ✓ حداکثر عکس (۵) — برای افزودن عکس جدید، یکی را حذف کنید
            </div>

        <?php endif; ?>

    </div>


    <!-- ════════ توضیحات ════════ -->
    <div class="form-field" style="margin-top:24px;">
        <label>توضیحات</label>
        <textarea name="description" rows="6" placeholder="معرفی کوتاه و دقیق..."><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
    </div>


    <!-- ════════ مشخصات ════════ -->
    <?php foreach ($specFields as $key): ?>
        <div class="form-field">
            <label><?= htmlspecialchars($key) ?></label>
            <input type="text" name="spec_<?= md5($key) ?>"
                value="<?= htmlspecialchars($currentSpecs[$key] ?? '') ?>">
        </div>
    <?php endforeach; ?>


    <button type="submit" class="btn btn-orange co-submit" style="margin-top:24px;">
        <?= $action === 'edit' ? 'ذخیره تغییرات' : 'افزودن محصول' ?> →
    </button>

</form>


<!-- ═══ نوار ذخیره شناور ═══ -->

<div class="pf-sticky-bar" id="pfStickyBar" aria-hidden="true">
    <div class="pf-sticky-inner">

        <div class="pf-sticky-info">
            <span class="pf-sticky-dot" id="pfStickyDot"></span>
            <span class="pf-sticky-text" id="pfStickyText">
                تغییری ثبت نشده
            </span>
        </div>

        <div class="pf-sticky-actions">

            <?php if ($action === 'edit'): ?>
                <a href="/product/<?= htmlspecialchars($product['slug']) ?>"
                    target="_blank"
                    rel="noopener"
                    class="pf-sticky-btn pf-sticky-btn-view">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                        <circle cx="12" cy="12" r="3" />
                    </svg>
                    <span>مشاهده</span>
                </a>
            <?php endif; ?>

            <button type="submit"
                form="mainProductForm"
                class="pf-sticky-btn pf-sticky-btn-save"
                id="pfStickySave">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
                    <polyline points="17 21 17 13 7 13 7 21" />
                    <polyline points="7 3 7 8 15 8" />
                </svg>
                <span>ذخیره تغییرات</span>
            </button>

        </div>

    </div>
</div>

</form>


<script>
    (function() {
        'use strict';

        /* ─── Discount tiers ─── */
        var enabled = document.getElementById('discountEnabled');
        var box = document.getElementById('discountTiersBox');
        var tiers = document.getElementById('discountTiers');
        var addBtn = document.getElementById('addDiscountTier');

        if (enabled && box) {
            enabled.addEventListener('change', function() {
                box.style.display = enabled.checked ? '' : 'none';
            });
        }

        if (addBtn && tiers) {
            addBtn.addEventListener('click', function() {
                var row = document.createElement('div');
                row.className = 'discount-tier';
                row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:center;margin-bottom:10px;';
                row.innerHTML = '' +
                    '<input type="number" name="discount_qty[]" min="1" step="1" placeholder="تعداد">' +
                    '<input type="number" name="discount_percent[]" min="0.01" max="99.99" step="0.01" placeholder="درصد">' +
                    '<button type="button" class="btn discount-remove">حذف</button>';
                tiers.appendChild(row);
            });

            tiers.addEventListener('click', function(e) {
                var btn = e.target.closest('.discount-remove');
                if (!btn) return;
                var rows = tiers.querySelectorAll('.discount-tier');
                if (rows.length === 1) {
                    rows[0].querySelectorAll('input').forEach(function(i) {
                        i.value = '';
                    });
                    return;
                }
                btn.closest('.discount-tier').remove();
            });
        }

        /* ─── آپلودر عکس ─── */
        var input = document.getElementById('pfiInput');
        var drop = document.getElementById('pfiDrop');
        var preview = document.getElementById('pfiPreview');
        var actionsRow = document.getElementById('pfiActionsRow');
        var uploadBtn = document.getElementById('pfiUploadBtn');
        var clearBtn = document.getElementById('pfiClearBtn');
        var status = document.getElementById('pfiStatus');

        if (!input || !drop || !uploadBtn) return;

        var MAX = 5;
        var MAX_SIZE = 2 * 1024 * 1024;
        var productId = <?= (int)$productId ?>;

        drop.addEventListener('click', function() {
            input.click();
        });

        ['dragenter', 'dragover'].forEach(function(ev) {
            drop.addEventListener(ev, function(e) {
                e.preventDefault();
                drop.classList.add('is-drag');
            });
        });

        ['dragleave', 'drop'].forEach(function(ev) {
            drop.addEventListener(ev, function(e) {
                e.preventDefault();
                drop.classList.remove('is-drag');
            });
        });

        drop.addEventListener('drop', function(e) {
            setFiles(Array.from(e.dataTransfer.files));
        });

        input.addEventListener('change', function() {
            setFiles(Array.from(input.files));
        });

        function setFiles(files) {
            status.innerHTML = '';
            var valid = [];
            var errors = [];

            files.forEach(function(f) {
                if (!/image\/(jpeg|png|webp)/i.test(f.type)) {
                    errors.push(f.name + ' — فرمت پشتیبانی نمی‌شه');
                    return;
                }
                if (f.size > MAX_SIZE) {
                    errors.push(f.name + ' — بزرگ‌تر از ۲ مگ');
                    return;
                }
                valid.push(f);
            });

            valid = valid.slice(0, MAX);

            if (errors.length) {
                status.innerHTML = '<div class="pfi-alert pfi-alert-error">⚠️ ' +
                    errors.join('<br>') + '</div>';
            }

            if (!valid.length) {
                actionsRow.style.display = 'none';
                return;
            }

            try {
                var dt = new DataTransfer();
                valid.forEach(function(f) {
                    dt.items.add(f);
                });
                input.files = dt.files;
            } catch (err) {
                console.warn('DataTransfer not supported:', err);
            }

            preview.innerHTML = '';
            valid.forEach(function(f) {
                var url = URL.createObjectURL(f);
                var el = document.createElement('div');
                el.className = 'pfi-preview-item';
                el.innerHTML = '<img src="' + url + '" alt="">' +
                    '<span class="pfi-preview-name">' + f.name + '</span>';
                preview.appendChild(el);
            });

            actionsRow.style.display = 'flex';
            uploadBtn.textContent = '⬆ آپلود ' + valid.length + ' عکس';
            uploadBtn.disabled = false;
        }

        uploadBtn.addEventListener('click', function() {

            if (!input.files.length) {
                status.innerHTML = '<div class="pfi-alert pfi-alert-error">⚠️ عکسی انتخاب نشده</div>';
                return;
            }

            var fd = new FormData();
            fd.append('do', 'upload_image');

            for (var i = 0; i < input.files.length; i++) {
                fd.append('images[]', input.files[i]);
            }

            uploadBtn.disabled = true;
            uploadBtn.textContent = 'در حال آپلود...';
            status.innerHTML = '<div class="pfi-alert pfi-alert-loading">⏳ در حال آپلود...</div>';

            fetch('/admin/product-form?action=edit&id=' + productId, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.ok) {
                        status.innerHTML = '<div class="pfi-alert pfi-alert-success">✓ ' +
                            data.message +
                            (data.failed && data.failed.length ?
                                '<br>⚠️ ' + data.failed.join('<br>') :
                                '') +
                            '</div>';

                        setTimeout(function() {
                            window.location.reload();
                        }, 900);
                    } else {
                        status.innerHTML = '<div class="pfi-alert pfi-alert-error">⚠️ ' +
                            (data.error || 'خطا') +
                            '</div>';
                        uploadBtn.disabled = false;
                        uploadBtn.textContent = '⬆ تلاش دوباره';
                    }
                })
                .catch(function(err) {
                    console.error('UPLOAD_ERROR:', err);
                    status.innerHTML = '<div class="pfi-alert pfi-alert-error">⚠️ خطای ارتباط با سرور</div>';
                    uploadBtn.disabled = false;
                    uploadBtn.textContent = '⬆ تلاش دوباره';
                });
        });

        clearBtn.addEventListener('click', function() {
            input.value = '';
            preview.innerHTML = '';
            status.innerHTML = '';
            actionsRow.style.display = 'none';
        });

    })();

    /* ═══ نوار ذخیره شناور ═══ */
    var stickyBar = document.getElementById('pfStickyBar');
    var stickyDot = document.getElementById('pfStickyDot');
    var stickyText = document.getElementById('pfStickyText');
    var mainForm = document.getElementById('mainProductForm');
    var saveBtn = document.getElementById('pfStickySave');

    if (stickyBar && mainForm && saveBtn) {

        var isDirty = false;
        var isSubmitting = false;

        /* چک کن کاربر جایی از صفحه اسکرول کرده */
        function updateBarVisibility() {
            var scrollY = window.pageYOffset || document.documentElement.scrollTop;
            var form = mainForm.getBoundingClientRect();

            /* وقتی راس فرم از بالا رد شد، نوار بیاد */
            if (form.top < 100 || scrollY > 200) {
                stickyBar.classList.add('is-visible');
                stickyBar.setAttribute('aria-hidden', 'false');
            } else if (!isDirty) {
                stickyBar.classList.remove('is-visible');
                stickyBar.setAttribute('aria-hidden', 'true');
            }
        }

        /* هر تغییر توی فرم → dirty */
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

        /* رویداد تغییر */
        mainForm.addEventListener('input', function(e) {
            if (e.target.closest('#pfiInput')) return; /* عکس‌ها را نادیده بگیر */
            if (isSubmitting) return;
            setDirty(true);
        });

        mainForm.addEventListener('change', function(e) {
            if (e.target.closest('#pfiInput')) return;
            if (isSubmitting) return;
            setDirty(true);
        });

        /* کلیک دکمه ذخیره شناور */
        saveBtn.addEventListener('click', function(e) {
            isSubmitting = true;
            saveBtn.disabled = true;
            saveBtn.querySelector('span').textContent = 'در حال ذخیره...';

            /* فرم اصلی رو submit کن */
            mainForm.submit();
        });

        /* اسکرول */
        window.addEventListener('scroll', updateBarVisibility, {
            passive: true
        });

        /* موقع لود — اگه پرچم saved باشه */
        <?php if (isset($_GET['saved'])): ?>
            setDirty(false);
        <?php endif; ?>

        /* هشدار قبل از خروج با تغییرات ذخیره نشده */
        window.addEventListener('beforeunload', function(e) {
            if (isDirty && !isSubmitting) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });

        /* موقع لود، بار رو مخفی کن */
        setTimeout(updateBarVisibility, 300);
    }
</script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
