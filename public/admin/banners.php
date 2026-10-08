<?php
require_once __DIR__ . '/../../config/bootstrap.php';
// banners.php

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/config/upload.php';

require_admin();

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();


    $do = $_POST['do'] ?? '';

    // ============================================================
    // ذخیره / افزودن بنر
    // ============================================================
    if ($do === 'save') {

        $id        = (int)($_POST['id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        $subtitle  = trim($_POST['subtitle'] ?? '');
        $link      = trim($_POST['link'] ?? '/products');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive  = ((int)($_POST['is_active'] ?? 0) === 1) ? 1 : 0;

        $desktopImgPath = null;
        $mobileImgPath  = null;

        // --------------------------------------------------------
        // آپلود تصویر دسکتاپ
        // --------------------------------------------------------
        if (
            isset($_FILES['image']) &&
            !empty($_FILES['image']['name'])
        ) {
            $desktopImgPath = upload_image(
                $_FILES['image'],
                'products'
            );
        }

        // --------------------------------------------------------
        // آپلود تصویر موبایل
        // --------------------------------------------------------
        if (
            isset($_FILES['mobile_image']) &&
            !empty($_FILES['mobile_image']['name'])
        ) {
            $mobileImgPath = upload_image(
                $_FILES['mobile_image'],
                'products'
            );
        }

        // --------------------------------------------------------
        // ویرایش بنر موجود
        // --------------------------------------------------------
        if ($id > 0) {

            $sql = "
                UPDATE banners
                SET
                    title = ?,
                    subtitle = ?,
                    link = ?,
                    sort_order = ?,
                    is_active = ?
            ";

            $params = [
                $title,
                $subtitle,
                $link,
                $sortOrder,
                $isActive
            ];

            if ($desktopImgPath) {
                $sql .= ", image_path = ?";
                $params[] = $desktopImgPath;
            }

            if ($mobileImgPath) {
                $sql .= ", mobile_image_path = ?";
                $params[] = $mobileImgPath;
            }

            $sql .= " WHERE id = ? AND position = 'hero'";
            $params[] = $id;

            db()->prepare($sql)->execute($params);
        } else {

            // ----------------------------------------------------
            // افزودن بنر جدید
            // ----------------------------------------------------
            db()->prepare("
                INSERT INTO banners
                (
                    title,
                    subtitle,
                    image_path,
                    mobile_image_path,
                    link,
                    position,
                    sort_order,
                    is_active
                )
                VALUES (?, ?, ?, ?, ?, 'hero', ?, ?)
            ")->execute([
                $title,
                $subtitle,
                $desktopImgPath,
                $mobileImgPath,
                $link,
                $sortOrder,
                $isActive
            ]);
        }

        header('Location: /admin/banners');
        exit;
    }

    // ============================================================
    // حذف بنر
    // ============================================================
    if ($do === 'delete') {

        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            db()->prepare("
                DELETE FROM banners
                WHERE id = ?
                  AND position = 'hero'
            ")->execute([$id]);
        }

        header('Location: /admin/banners');
        exit;
    }
}

// ================================================================
// دریافت تمام بنرهای Hero
// ================================================================
$banners = db()->query("
    SELECT *
    FROM banners
    WHERE position = 'hero'
    ORDER BY sort_order ASC, id ASC
")->fetchAll();

$activeMenu = 'banners';
$pageTitle  = 'بنر صفحه اصلی';

ob_start();
?>

<style>
    .banner-page {
        max-width: 1180px;
        margin: 0 auto;
        padding: 10px 0 40px;
    }

    .banner-page-head {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 24px;
    }

    .banner-page-head h2 {
        margin: 0 0 6px;
        font-size: 25px;
        font-weight: 800;
        letter-spacing: -0.3px;
    }

    .banner-page-head p {
        margin: 0;
        color: #7d8790;
        font-size: 13px;
        line-height: 1.9;
    }

    .banner-card,
    .banner-create {
        background: #fff;
        border: 1px solid #e7ebee;
        border-radius: 18px;
        box-shadow: 0 8px 28px rgba(20, 35, 45, .05);
    }

    .banner-card {
        padding: 20px;
        margin-bottom: 18px;
    }

    .banner-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        padding-bottom: 14px;
        margin-bottom: 16px;
        border-bottom: 1px solid #edf0f2;
    }

    .banner-card-title {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }

    .banner-card-title strong {
        font-size: 14px;
        color: #20303a;
    }

    .banner-meta {
        color: #8a949b;
        font-size: 12px;
    }

    .banner-status {
        display: inline-flex;
        align-items: center;
        padding: 5px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 800;
        white-space: nowrap;
    }

    .banner-status.active {
        background: #e8f7ee;
        color: #18794e;
    }

    .banner-status.inactive {
        background: #f1f3f5;
        color: #78828a;
    }

    .banner-form-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 14px;
    }

    .banner-form-grid .form-field {
        margin: 0;
    }

    .banner-form-grid label {
        display: block;
        margin-bottom: 7px;
        color: #34444f;
        font-size: 12px;
        font-weight: 700;
    }

    .banner-form-grid input[type="text"],
    .banner-form-grid input[type="number"],
    .banner-form-grid select,
    .banner-form-grid textarea {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #dfe5e9;
        border-radius: 11px;
        background: #fbfcfd;
        color: #263640;
        font-family: inherit;
        font-size: 13px;
        padding: 10px 12px;
        outline: none;
        transition: .18s ease;
    }

    .banner-form-grid input[type="text"]:focus,
    .banner-form-grid input[type="number"]:focus,
    .banner-form-grid select:focus,
    .banner-form-grid textarea:focus {
        border-color: #76a8b6;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(118, 168, 182, .10);
    }

    .banner-form-grid textarea {
        resize: vertical;
        min-height: 72px;
        line-height: 1.9;
    }

    .banner-three {
        display: grid;
        grid-template-columns: 1.4fr .7fr .7fr;
        gap: 12px;
    }

    .banner-media-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        margin-top: 4px;
    }

    .banner-media {
        min-width: 0;
        padding: 12px;
        border: 1px solid #e6eaed;
        border-radius: 14px;
        background: #fafbfc;
    }

    .banner-media-label {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 9px;
        color: #354650;
        font-size: 12px;
        font-weight: 800;
    }

    .banner-media-badge {
        padding: 4px 8px;
        border-radius: 999px;
        background: #eef4f6;
        color: #58737d;
        font-size: 10px;
        font-weight: 700;
    }

    .banner-preview {
        width: 100%;
        aspect-ratio: 16 / 7;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        margin-bottom: 9px;
        border: 1px solid #e9edef;
        border-radius: 11px;
        background: #f3f5f6;
    }

    .banner-preview.mobile-preview {
        aspect-ratio: 4 / 5;
    }

    .banner-preview img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: block;
    }

    .banner-empty-preview {
        color: #9aa3a9;
        font-size: 11px;
    }

    .banner-file {
        width: 100%;
        box-sizing: border-box;
        font-family: inherit;
        font-size: 11px;
        color: #65727b;
    }

    .banner-file::file-selector-button {
        border: 1px solid #dce3e7;
        border-radius: 9px;
        padding: 7px 10px;
        margin-left: 8px;
        background: #fff;
        color: #354650;
        font-family: inherit;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
    }

    .field-hint {
        display: block;
        margin-top: 6px;
        color: #8a959c;
        font-size: 10px;
        line-height: 1.8;
    }

    .banner-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 16px;
    }

    .banner-save {
        border: 0;
        border-radius: 10px;
        padding: 10px 16px;
        background: #d87932;
        color: #fff;
        font-family: inherit;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
        transition: .18s ease;
    }

    .banner-save:hover {
        transform: translateY(-1px);
        filter: brightness(.97);
    }

    .banner-delete {
        border: 1px solid #e6b4b0;
        border-radius: 10px;
        padding: 9px 14px;
        background: #fff;
        color: #bd4b42;
        font-family: inherit;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }

    .banner-create {
        padding: 20px;
        margin-top: 24px;
        background: linear-gradient(180deg, #fcfdfd 0%, #f7f9fa 100%);
    }

    .banner-create-head {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .banner-create-icon {
        width: 34px;
        height: 34px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #edf4f6;
        color: #517784;
        font-size: 19px;
        font-weight: 500;
    }

    .banner-create h3 {
        margin: 0 0 3px;
        color: #243640;
        font-size: 15px;
    }

    .banner-create p {
        margin: 0;
        color: #849098;
        font-size: 11px;
        line-height: 1.8;
    }

    .banner-create .banner-media-grid {
        margin-bottom: 2px;
    }

    .banner-empty {
        padding: 30px 20px;
        text-align: center;
        border: 1px dashed #d8dee2;
        border-radius: 16px;
        color: #8b959b;
        background: #fbfcfc;
        font-size: 13px;
    }

    @media (max-width: 820px) {
        .banner-three {
            grid-template-columns: 1fr 1fr;
        }

        .banner-three .form-field:first-child {
            grid-column: 1 / -1;
        }

        .banner-media-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 560px) {
        .banner-page {
            padding-bottom: 25px;
        }

        .banner-page-head {
            align-items: flex-start;
        }

        .banner-page-head h2 {
            font-size: 21px;
        }

        .banner-card,
        .banner-create {
            padding: 14px;
            border-radius: 14px;
        }

        .banner-card-head {
            align-items: flex-start;
        }

        .banner-three {
            grid-template-columns: 1fr;
        }

        .banner-three .form-field:first-child {
            grid-column: auto;
        }
    }
</style>

<div class="banner-page">

    <!-- ========================================================
         عنوان صفحه
    ========================================================= -->

    <div class="banner-page-head">
        <div>
            <h2>بنرهای صفحه اصلی</h2>
            <p>
                تصاویر دسکتاپ و موبایل را جداگانه تنظیم کنید تا هر دستگاه
                نسخه مناسب را نمایش دهد.
            </p>
        </div>
    </div>

    <!-- ========================================================
         بنرهای موجود
    ========================================================= -->

    <?php if (!empty($banners)): ?>

        <?php foreach ($banners as $b): ?>

            <form
                method="post"
                enctype="multipart/form-data"
                class="banner-card">
                <?= csrf_field() ?>

                <input type="hidden" name="do" value="save">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">

                <!-- سربرگ -->
                <div class="banner-card-head">

                    <div class="banner-card-title">
                        <strong>
                            بنر #<?= (int)$b['id'] ?>
                        </strong>

                        <span class="banner-meta">
                            ترتیب <?= (int)$b['sort_order'] ?>
                        </span>
                    </div>

                    <?php if ((int)$b['is_active'] === 1): ?>

                        <span class="banner-status active">
                            ● فعال
                        </span>

                    <?php else: ?>

                        <span class="banner-status inactive">
                            ● غیرفعال
                        </span>

                    <?php endif; ?>

                </div>

                <div class="banner-form-grid">

                    <!-- تیتر -->
                    <div class="form-field">

                        <label>تیتر اصلی</label>

                        <input
                            type="text"
                            name="title"
                            value="<?= $h($b['title'] ?? '') ?>"
                            placeholder="مثلاً جوراب‌هایی که فروش می‌روند">

                    </div>

                    <!-- زیرتیتر -->
                    <div class="form-field">

                        <label>زیرتیتر</label>

                        <textarea
                            name="subtitle"
                            rows="2"
                            placeholder="تأمین مستقیم از کارگاه؛ قیمت همکاری و موجودی واقعی"><?= $h($b['subtitle'] ?? '') ?></textarea>

                    </div>

                    <!-- لینک + ترتیب + وضعیت -->
                    <div class="banner-three">

                        <div class="form-field">

                            <label>لینک دکمه</label>

                            <input
                                type="text"
                                name="link"
                                dir="ltr"
                                value="<?= $h($b['link'] ?? '/products') ?>">

                        </div>

                        <div class="form-field">

                            <label>ترتیب نمایش</label>

                            <input
                                type="number"
                                name="sort_order"
                                min="0"
                                value="<?= (int)$b['sort_order'] ?>">

                            <span class="field-hint">
                                عدد کمتر = نمایش زودتر
                            </span>

                        </div>

                        <div class="form-field">

                            <label>وضعیت</label>

                            <select name="is_active">

                                <option
                                    value="1"
                                    <?= (int)$b['is_active'] === 1 ? 'selected' : '' ?>>
                                    فعال
                                </option>

                                <option
                                    value="0"
                                    <?= (int)$b['is_active'] === 0 ? 'selected' : '' ?>>
                                    غیرفعال
                                </option>

                            </select>

                        </div>

                    </div>

                    <!-- تصاویر -->
                    <div class="banner-media-grid">

                        <!-- دسکتاپ -->
                        <div class="banner-media">

                            <div class="banner-media-label">
                                <span>تصویر دسکتاپ</span>
                                <span class="banner-media-badge">
                                    Desktop
                                </span>
                            </div>

                            <div class="banner-preview">

                                <?php if (!empty($b['image_path'])): ?>

                                    <img
                                        src="<?= $h($b['image_path']) ?>"
                                        alt="">

                                <?php else: ?>

                                    <span class="banner-empty-preview">
                                        تصویر دسکتاپ ثبت نشده
                                    </span>

                                <?php endif; ?>

                            </div>

                            <input
                                class="banner-file"
                                type="file"
                                name="image"
                                accept="image/jpeg,image/png,image/webp">

                            <span class="field-hint">
                                پیشنهاد: 1600×800 یا 1200×600 پیکسل
                            </span>

                        </div>

                        <!-- موبایل -->
                        <div class="banner-media">

                            <div class="banner-media-label">
                                <span>تصویر موبایل</span>
                                <span class="banner-media-badge">
                                    Mobile
                                </span>
                            </div>

                            <div class="banner-preview mobile-preview">

                                <?php if (!empty($b['mobile_image_path'])): ?>

                                    <img
                                        src="<?= $h($b['mobile_image_path']) ?>"
                                        alt="">

                                <?php else: ?>

                                    <span class="banner-empty-preview">
                                        تصویر موبایل ثبت نشده
                                    </span>

                                <?php endif; ?>

                            </div>

                            <input
                                class="banner-file"
                                type="file"
                                name="mobile_image"
                                accept="image/jpeg,image/png,image/webp">

                            <span class="field-hint">
                                پیشنهاد: 800×1000 یا 1080×1350 پیکسل
                            </span>

                        </div>

                    </div>

                    <span class="field-hint">
                        اگر تصویر جدیدی انتخاب نکنید، تصویر قبلی حفظ می‌شود.
                        بهتر است نسخه موبایل، کادر و ترکیب‌بندی مخصوص خودش را داشته باشد.
                    </span>

                </div>

                <!-- دکمه‌ها -->
                <div class="banner-actions">

                    <button
                        type="submit"
                        class="banner-save">
                        ذخیره تغییرات
                    </button>

                    <button
                        type="submit"
                        name="do"
                        value="delete"
                        class="banner-delete"
                        onclick="return confirm('آیا از حذف این بنر مطمئن هستید؟');">
                        حذف بنر
                    </button>

                </div>

            </form>

        <?php endforeach; ?>

    <?php else: ?>

        <div class="banner-empty">
            هنوز هیچ بنر Hero ثبت نشده است.
        </div>

    <?php endif; ?>

    <!-- ========================================================
         افزودن بنر جدید
    ========================================================= -->

    <div class="banner-create">

        <div class="banner-create-head">

            <div class="banner-create-icon">
                +
            </div>

            <div>
                <h3>افزودن بنر جدید</h3>
                <p>
                    برای بهترین نمایش، نسخه دسکتاپ و موبایل را جداگانه بارگذاری کنید.
                </p>
            </div>

        </div>

        <form
            method="post"
            enctype="multipart/form-data">
            <?= csrf_field() ?>

            <input
                type="hidden"
                name="do"
                value="save">

            <div class="banner-form-grid">

                <!-- تیتر -->
                <div class="form-field">

                    <label>تیتر اصلی</label>

                    <input
                        type="text"
                        name="title"
                        placeholder="مثلاً جوراب‌هایی که فروش می‌روند">

                </div>

                <!-- زیرتیتر -->
                <div class="form-field">

                    <label>زیرتیتر</label>

                    <textarea
                        name="subtitle"
                        rows="2"
                        placeholder="تأمین مستقیم از کارگاه؛ قیمت همکاری و موجودی واقعی"></textarea>

                </div>

                <!-- لینک + ترتیب + وضعیت -->
                <div class="banner-three">

                    <div class="form-field">

                        <label>لینک دکمه</label>

                        <input
                            type="text"
                            name="link"
                            dir="ltr"
                            value="/products">

                    </div>

                    <div class="form-field">

                        <label>ترتیب نمایش</label>

                        <input
                            type="number"
                            name="sort_order"
                            min="0"
                            value="0">

                    </div>

                    <div class="form-field">

                        <label>وضعیت</label>

                        <select name="is_active">

                            <option value="1" selected>
                                فعال
                            </option>

                            <option value="0">
                                غیرفعال
                            </option>

                        </select>

                    </div>

                </div>

                <!-- تصاویر جدید -->
                <div class="banner-media-grid">

                    <div class="banner-media">

                        <div class="banner-media-label">
                            <span>تصویر دسکتاپ</span>
                            <span class="banner-media-badge">
                                Desktop
                            </span>
                        </div>

                        <div class="banner-preview">
                            <span class="banner-empty-preview">
                                انتخاب تصویر دسکتاپ
                            </span>
                        </div>

                        <input
                            class="banner-file"
                            type="file"
                            name="image"
                            accept="image/jpeg,image/png,image/webp"
                            required>

                        <span class="field-hint">
                            پیشنهاد: 1600×800 یا 1200×600 پیکسل
                        </span>

                    </div>

                    <div class="banner-media">

                        <div class="banner-media-label">
                            <span>تصویر موبایل</span>
                            <span class="banner-media-badge">
                                Mobile
                            </span>
                        </div>

                        <div class="banner-preview mobile-preview">
                            <span class="banner-empty-preview">
                                انتخاب تصویر موبایل
                            </span>
                        </div>

                        <input
                            class="banner-file"
                            type="file"
                            name="mobile_image"
                            accept="image/jpeg,image/png,image/webp">

                        <span class="field-hint">
                            پیشنهاد: 800×1000 یا 1080×1350 پیکسل
                        </span>

                    </div>

                </div>

                <span class="field-hint">
                    تصویر موبایل اختیاری است؛ اگر نداشته باشید، در مرحله بعد می‌توانیم
                    fallback خودکار به تصویر دسکتاپ اضافه کنیم.
                </span>

            </div>

            <div class="banner-actions">

                <button
                    type="submit"
                    class="banner-save">
                    افزودن بنر
                </button>

            </div>

        </form>

    </div>

</div>

<?php
$content = ob_get_clean();

require ROOT_PATH . '/views/layouts/admin.php';
?>