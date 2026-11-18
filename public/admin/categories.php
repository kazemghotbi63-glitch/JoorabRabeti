<?php

declare(strict_types=1);

/* Auto-detect bootstrap (local + host) */
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

require_admin();

$db = db();

$error   = '';
$success = '';

/* ═══════════════════════════════════════════════════════════
   عملیات POST
   ═══════════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $do = $_POST['do'] ?? '';

    /* ─── افزودن ─── */
    if ($do === 'add') {

        $name      = trim($_POST['name'] ?? '');
        $icon      = trim($_POST['icon'] ?? '');
        $sortOrder = max(0, (int)($_POST['sort_order'] ?? 99));
        $parentId  = (int)($_POST['parent_id'] ?? 0);
        $parentId  = $parentId > 0 ? $parentId : null;

        if (mb_strlen($name) < 2) {
            $error = 'نام دسته‌بندی باید حداقل ۲ حرف باشد.';
        } else {

            /* اگر والد ارسال شده، باید واقعاً یک دستهٔ ریشه باشد */
            if ($parentId !== null) {
                $stmt = $db->prepare("SELECT id FROM categories WHERE id = ? AND parent_id IS NULL LIMIT 1");
                $stmt->execute([$parentId]);
                if (!$stmt->fetchColumn()) {
                    $parentId = null;
                }
            }

            $imagePath = upload_image($_FILES['icon_img'] ?? [], 'categories');
            if ($icon === '') $icon = '🧦';

            $maxId = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM categories")->fetchColumn();
            $slug  = 'cat-' . ($maxId + 1);

            $db->prepare("
                INSERT INTO categories
                    (name, slug, icon, image_path, sort_order, parent_id, is_active)
                VALUES
                    (?,?,?,?,?,?,1)
            ")->execute([$name, $slug, $icon, $imagePath, $sortOrder, $parentId]);

            header('Location: /admin/categories?success=added');
            exit;
        }
    }

    /* ─── ویرایش ─── */
    if ($do === 'edit') {

        $id        = (int)($_POST['id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $icon      = trim($_POST['icon'] ?? '');
        $sortOrder = max(0, (int)($_POST['sort_order'] ?? 99));
        $parentId  = (int)($_POST['parent_id'] ?? 0);
        $parentId  = $parentId > 0 ? $parentId : null;

        if ($id <= 0 || mb_strlen($name) < 2) {
            $error = 'اطلاعات ویرایش نامعتبر است.';
        } else {

            /* جلوگیری از حلقه: نه خودش، نه زیرمجموعه‌اش */
            if ($parentId === $id) {
                $error = 'یک دسته نمی‌تواند والد خودش باشد.';
            } elseif ($parentId !== null && self_is_descendant($db, $parentId, $id)) {
                $error = 'یک دسته نمی‌تواند زیرمجموعه‌ی خودش شود.';
            } else {

                $stmt = $db->prepare("SELECT icon, image_path FROM categories WHERE id=? LIMIT 1");
                $stmt->execute([$id]);
                $category = $stmt->fetch();

                if (!$category) {
                    $error = 'دسته‌بندی پیدا نشد.';
                } else {

                    $imagePath = trim($category['image_path'] ?? '');
                    $newImagePath = upload_image($_FILES['icon_img'] ?? [], 'categories');
                    if ($newImagePath) $imagePath = $newImagePath;

                    if ($icon === '') {
                        $oldIcon = trim($category['icon'] ?? '');
                        /* آیکون قبلی اگر مسیر عکس است، با ایموجی پیش‌فرض جایگزین کن */
                        $icon = (substr($oldIcon, 0, 9) === '/uploads/')
                            ? '🧦'
                            : $oldIcon;
                    }

                    $db->prepare("
                        UPDATE categories
                        SET name=?, icon=?, image_path=?, sort_order=?, parent_id=?
                        WHERE id=?
                    ")->execute([
                        $name,
                        $icon,
                        $imagePath !== '' ? $imagePath : null,
                        $sortOrder,
                        $parentId,
                        $id
                    ]);

                    header('Location: /admin/categories?success=edited');
                    exit;
                }
            }
        }
    }

    /* ─── فعال/غیرفعال ─── */
    if ($do === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE categories SET is_active = 1 - is_active WHERE id=?")
                ->execute([$id]);
            header('Location: /admin/categories?success=toggle');
            exit;
        }
    }

    /* ─── حذف ─── */
    if ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {

            $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id=?");
            $stmt->execute([$id]);
            $productCount = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE parent_id=?");
            $stmt->execute([$id]);
            $childCount = (int)$stmt->fetchColumn();

            if ($productCount > 0) {
                $error = 'این دسته دارای ' . number_format($productCount) . ' محصول است و قابل حذف نیست.';
            } elseif ($childCount > 0) {
                $error = 'این دسته ' . $childCount . ' زیرمجموعه دارد. اول زیرمجموعه‌ها را منتقل یا حذف کنید.';
            } else {
                $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
                header('Location: /admin/categories?success=deleted');
                exit;
            }
        }
    }

    /* ─── جابه‌جایی ترتیب (▲▼) — فقط بین هم‌خانواده‌ها ─── */
    if ($do === 'move') {

        $id  = (int)($_POST['id'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';

        $stmt = $db->prepare("SELECT id, sort_order, parent_id FROM categories WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $current = $stmt->fetch();

        if ($current) {

            if ($current['parent_id'] === null) {
                $parentClause = "parent_id IS NULL";
                $bindParent = [];
            } else {
                $parentClause = "parent_id = ?";
                $bindParent = [(int)$current['parent_id']];
            }

            if ($dir === 'up') {
                $stmt = $db->prepare("
                    SELECT id, sort_order FROM categories
                    WHERE $parentClause AND sort_order < ?
                    ORDER BY sort_order DESC, id DESC LIMIT 1
                ");
            } else {
                $stmt = $db->prepare("
                    SELECT id, sort_order FROM categories
                    WHERE $parentClause AND sort_order > ?
                    ORDER BY sort_order ASC, id ASC LIMIT 1
                ");
            }

            $stmt->execute(array_merge($bindParent, [(int)$current['sort_order']]));
            $neighbor = $stmt->fetch();

            if ($neighbor) {
                $db->prepare("UPDATE categories SET sort_order = -1 WHERE id=?")
                    ->execute([$current['id']]);

                $db->prepare("UPDATE categories SET sort_order = ? WHERE id=?")
                    ->execute([(int)$current['sort_order'], $neighbor['id']]);

                $db->prepare("UPDATE categories SET sort_order = ? WHERE id=?")
                    ->execute([(int)$neighbor['sort_order'], $current['id']]);
            }
        }

        header('Location: /admin/categories');
        exit;
    }
}

/* ─── پیام موفقیت ─── */
if (isset($_GET['success'])) {
    $success = [
        'added'   => 'دسته‌بندی اضافه شد.',
        'edited'  => 'دسته‌بندی ویرایش شد.',
        'deleted' => 'حذف شد.',
        'toggle'  => 'وضعیت تغییر کرد.',
    ][$_GET['success']] ?? '';
}

/* ═══════════════════════════════════════════════════════════
   توابع کمکی
   ═══════════════════════════════════════════════════════════ */

/**
 * چک می‌کند که $candidate زیرمجموعه‌ی $ancestor باشد یا نه.
 */
function self_is_descendant(PDO $db, int $candidate, int $ancestor): bool
{
    $cur = $candidate;
    $guard = 0;

    while ($cur > 0 && $guard < 50) {

        $stmt = $db->prepare("SELECT parent_id FROM categories WHERE id=? LIMIT 1");
        $stmt->execute([$cur]);
        $row = $stmt->fetch();

        if (!$row) return false;

        $parent = $row['parent_id'];
        if ($parent === null) return false;
        if ((int)$parent === $ancestor) return true;

        $cur = (int)$parent;
        $guard++;
    }

    return false;
}

/**
 * لیست دسته‌های اصلی (ریشه) + فرزندهای هر یک + تعداد محصولات تجمیعی.
 */
function load_category_hierarchy(PDO $db): array
{
    $rows = $db->query("
        SELECT
            c.*,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS own_products
        FROM categories c
        ORDER BY c.sort_order ASC, c.id ASC
    ")->fetchAll();

    $roots    = [];
    $children = [];   // parentId => [rows]

    foreach ($rows as $r) {
        if ($r['parent_id'] === null) {
            $roots[] = $r;
        } else {
            $children[(int)$r['parent_id']][] = $r;
        }
    }

    /* محاسبهٔ تعداد محصولات تجمیعی هر والد */
    foreach ($roots as &$root) {
        $childRows = $children[(int)$root['id']] ?? [];
        $childSum  = (int)$root['own_products'];
        foreach ($childRows as $ch) {
            $childSum += (int)$ch['own_products'];
        }
        $root['product_count'] = $childSum;
    }
    unset($root);

    return [$roots, $children];
}

[$rootCategories, $childrenByParent] = load_category_hierarchy($db);

$activeMenu = 'categories';
$pageTitle  = 'دسته‌بندی‌ها';

/* آماده‌سازی دادهٔ فرم در حالت خطا (برای بازگردانی مقادیر) */
$lastForm = [
    'name'       => '',
    'icon'       => '',
    'sort_order' => 99,
    'parent_id'  => 0,
];

ob_start();
?>

<style>
    /* ═══════════════════════════════════════════════════════════
       استایل اختصاصی صفحهٔ دسته‌بندی‌ها
       ═══════════════════════════════════════════════════════════ */

    .cat-page {
        display: flex;
        flex-direction: column;
        gap: 22px;
    }

    /* ─── کارت‌های بخش ─── */
    .cat-section {
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: var(--admin-radius-lg);
        box-shadow: var(--admin-shadow-sm);
        overflow: hidden;
    }

    .cat-section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 22px;
        background: var(--admin-primary-soft);
        border-bottom: 1px solid var(--admin-border);
        flex-wrap: wrap;
    }

    .cat-section-head h2 {
        font-size: 1rem;
        font-weight: 700;
        color: var(--admin-primary-dark);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .cat-section-head .cat-section-count {
        font-size: .75rem;
        color: var(--admin-text-muted);
        background: var(--admin-surface);
        padding: 3px 10px;
        border-radius: 999px;
        border: 1px solid var(--admin-border);
    }

    .cat-section-body {
        padding: 20px 22px 24px;
    }

    /* ─── کارت‌های دستهٔ اصلی ─── */
    .cat-root-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 16px;
    }

    .cat-root-card {
        position: relative;
        display: flex;
        flex-direction: column;
        background: var(--admin-surface);
        border: 1.5px solid var(--admin-border);
        border-radius: var(--admin-radius-md);
        padding: 16px 14px 14px;
        transition: border-color var(--admin-transition), box-shadow var(--admin-transition);
    }

    .cat-root-card:hover {
        border-color: var(--admin-primary);
        box-shadow: var(--admin-shadow-md);
    }

    .cat-root-card.is-inactive {
        opacity: .55;
    }

    .cat-root-thumb {
        width: 64px;
        height: 64px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--admin-surface-soft);
        border: 1px solid var(--admin-border);
        border-radius: var(--admin-radius-md);
        margin-bottom: 12px;
        overflow: hidden;
    }

    .cat-root-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .cat-root-thumb .emoji {
        font-size: 2rem;
        line-height: 1;
    }

    .cat-root-name {
        font-weight: 700;
        font-size: 1rem;
        color: var(--admin-text);
        margin-bottom: 6px;
        word-break: break-word;
    }

    .cat-root-meta {
        font-size: .75rem;
        color: var(--admin-text-muted);
        display: flex;
        flex-wrap: wrap;
        gap: 8px 12px;
        margin-bottom: 16px;
    }

    .cat-root-meta span {
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .cat-root-actions {
        margin-top: auto;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .cat-root-btnrow {
        display: flex;
        gap: 4px;
        flex-wrap: wrap;
        align-items: center;
    }

    .cat-badge-status {
        position: absolute;
        top: 12px;
        left: 12px;
        font-size: .65rem;
        padding: 3px 9px;
        border-radius: 999px;
        line-height: 1.6;
        font-weight: 600;
    }

    .cat-badge-status.on {
        background: var(--admin-success-soft);
        color: var(--admin-success);
    }

    .cat-badge-status.off {
        background: var(--admin-danger-soft);
        color: var(--admin-danger);
    }

    /* ─── زیرمجموعه‌های هر والد ─── */
    .cat-sub-wrap {
        margin-top: 18px;
        padding: 16px;
        background: var(--admin-surface-soft);
        border: 1px dashed var(--admin-border-strong);
        border-radius: var(--admin-radius-md);
    }

    .cat-sub-wrap.is-empty {
        padding: 18px;
        text-align: center;
        color: var(--admin-text-muted);
        font-size: .85rem;
    }

    .cat-sub-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 16px;
        flex-wrap: wrap;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--admin-border);
    }

    .cat-sub-head h4 {
        margin: 0;
        font-size: .9rem;
        font-weight: 700;
        color: var(--admin-text);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .cat-sub-head h4 span {
        color: var(--admin-text-muted);
        font-weight: 400;
        font-size: .75rem;
    }

    /* لیست زیرمجموعه‌ها */
    .cat-sub-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    /* آیتم زیرمجموعه */
    .cat-sub-item {
        display: flex;
        align-items: center;
        gap: 12px;
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: 12px;
        padding: 10px 14px;
        transition: all var(--admin-transition);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    }

    .cat-sub-item:hover {
        border-color: var(--admin-primary);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        transform: translateY(-1px);
    }

    .cat-sub-item.is-inactive {
        opacity: .6;
        background: var(--admin-surface-soft);
    }

    /* تصویر */
    .cat-sub-thumb {
        width: 44px;
        height: 44px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--admin-surface-soft);
        border: 1px solid var(--admin-border);
        border-radius: 10px;
        overflow: hidden;
    }

    .cat-sub-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .cat-sub-thumb .emoji {
        font-size: 1.4rem;
        line-height: 1;
    }

    /* اطلاعات */
    .cat-sub-info {
        flex: 1;
        min-width: 0;
    }

    .cat-sub-name {
        font-weight: 700;
        font-size: .85rem;
        color: var(--admin-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin-bottom: 4px;
    }

    .cat-sub-meta {
        font-size: .7rem;
        color: var(--admin-text-muted);
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    /* ─── دکمه‌های عملیات زیرمجموعه (لاکچری) ─── */
    .cat-sub-actions {
        display: flex;
        align-items: center;
        gap: 4px;
        flex-shrink: 0;
        background: var(--admin-surface-soft);
        padding: 4px;
        border-radius: 10px;
    }

    .cat-sub-actions .btn {
        padding: 0 !important;
        width: 30px !important;
        height: 30px !important;
        min-width: 30px !important;
        font-size: .8rem !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--admin-text-soft);
        transition: all 0.2s;
        cursor: pointer;
    }

    .cat-sub-actions .btn:hover {
        background: var(--admin-surface);
        border-color: var(--admin-border);
        color: var(--admin-text);
    }

    .cat-sub-actions .btn[title="حذف"]:hover {
        background: var(--admin-danger-soft);
        border-color: var(--admin-danger);
        color: var(--admin-danger);
    }

    .cat-sub-actions .btn.cat-edit-btn {
        background: var(--admin-info-soft) !important;
        color: var(--admin-info) !important;
        border-color: transparent !important;
    }

    .cat-sub-actions .btn.cat-edit-btn:hover {
        background: var(--admin-info) !important;
        color: #fff !important;
    }

    /* ─── فرم افزودن (inline زیر کارت اصلی) ─── */
    .cat-add-form {
        display: grid;
        grid-template-columns: 1.6fr 70px 1fr 80px auto;
        gap: 12px;
        align-items: end;
    }

    .cat-add-form .pf-field-label {
        display: block;
        font-size: .7rem;
        color: var(--admin-text-muted);
        margin-bottom: 5px;
        font-weight: 500;
    }

    .cat-add-form input[type="text"],
    .cat-add-form input[type="number"],
    .cat-add-form input[type="file"] {
        width: 100%;
        padding: 9px 12px;
        border: 1px solid var(--admin-border);
        border-radius: var(--admin-radius-md);
        font-family: inherit;
        font-size: .82rem;
        background: var(--admin-surface);
        height: 40px;
    }

    .cat-add-form input[type="file"] {
        padding: 6px 8px;
        font-size: .72rem;
    }

    .cat-add-form input:focus {
        outline: none;
        border-color: var(--admin-info);
        box-shadow: 0 0 0 3px var(--admin-info-soft);
    }

    /* ─── هشدارها ─── */
    .cat-alert {
        padding: 12px 18px;
        border-radius: var(--admin-radius-md);
        font-size: .85rem;
        margin-bottom: 18px;
        border: 1px solid transparent;
    }

    .cat-alert.error {
        background: var(--admin-danger-soft);
        color: var(--admin-danger);
        border-color: var(--admin-danger);
    }

    .cat-alert.success {
        background: var(--admin-success-soft);
        color: var(--admin-success);
        border-color: var(--admin-success);
    }

    /* ─── دکمهٔ افزودن زیرمجموعه ─── */
    .btn-add-sub {
        background: var(--admin-info-soft);
        color: var(--admin-info);
        border: 1px solid var(--admin-info);
        transition: all 0.2s;
    }

    .btn-add-sub:hover {
        background: var(--admin-info);
        color: #fff;
    }

    /* ─── مودال افزودن زیرمجموعه ─── */
    .cat-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(18, 30, 35, .55);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 20px;
    }

    .cat-modal-overlay.is-open {
        display: flex;
    }

    .cat-modal {
        background: var(--admin-surface);
        border-radius: var(--admin-radius-lg);
        width: 100%;
        max-width: 540px;
        max-height: 90vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        box-shadow: var(--admin-shadow-md);
    }

    .cat-modal-head {
        padding: 16px 22px;
        background: var(--admin-primary-soft);
        border-bottom: 1px solid var(--admin-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .cat-modal-head h3 {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        color: var(--admin-primary-dark);
    }

    .cat-modal-close {
        border: none;
        background: transparent;
        cursor: pointer;
        color: var(--admin-text-muted);
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background var(--admin-transition);
    }

    .cat-modal-close:hover {
        background: var(--admin-border);
        color: var(--admin-text);
    }

    .cat-modal-body {
        padding: 22px;
        overflow-y: auto;
    }

    .cat-modal-field {
        margin-bottom: 16px;
    }

    .cat-modal-field label {
        display: block;
        font-size: .78rem;
        color: var(--admin-text-soft);
        margin-bottom: 6px;
        font-weight: 500;
    }

    .cat-modal-field input[type="text"],
    .cat-modal-field input[type="number"],
    .cat-modal-field select {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid var(--admin-border);
        border-radius: var(--admin-radius-md);
        font-family: inherit;
        font-size: .9rem;
        background: var(--admin-surface);
    }

    .cat-modal-field input:focus,
    .cat-modal-field select:focus {
        outline: none;
        border-color: var(--admin-info);
        box-shadow: 0 0 0 3px var(--admin-info-soft);
    }

    .cat-modal-file {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .cat-modal-file .cat-file-btn {
        padding: 9px 14px;
        background: var(--admin-surface-soft);
        border: 1px solid var(--admin-border);
        border-radius: var(--admin-radius-md);
        font-size: .8rem;
        cursor: pointer;
        color: var(--admin-text-soft);
    }

    .cat-modal-file .cat-file-name {
        font-size: .78rem;
        color: var(--admin-text-muted);
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .cat-modal-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    .cat-modal-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        padding-top: 16px;
        border-top: 1px solid var(--admin-border);
        margin-top: 6px;
    }

    /* ─── لیست زیرمجموعه‌های موجود در داخل مودال ─── */
    .cat-modal-existing {
        margin-top: 18px;
        padding: 12px;
        background: var(--admin-surface-soft);
        border-radius: var(--admin-radius-md);
        border: 1px solid var(--admin-border);
    }

    .cat-modal-existing h4 {
        margin: 0 0 10px;
        font-size: .8rem;
        color: var(--admin-text-soft);
        font-weight: 600;
    }

    .cat-modal-existing-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .cat-modal-existing-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 7px 10px;
        background: var(--admin-surface);
        border: 1px solid var(--admin-border);
        border-radius: 8px;
        font-size: .8rem;
    }

    .cat-modal-existing-item.is-inactive {
        opacity: .55;
    }

    .cat-modal-existing-item .emoji {
        font-size: 1rem;
    }

    .cat-modal-existing-empty {
        font-size: .78rem;
        color: var(--admin-text-muted);
        text-align: center;
        padding: 8px;
    }

    /* ─── Responsive ─── */
    @media (max-width: 860px) {
        .cat-add-form {
            grid-template-columns: 1fr 1fr;
        }

        .cat-add-form .cat-add-submit {
            grid-column: 1 / -1;
        }
    }

    @media (max-width: 768px) {
        .cat-sub-item {
            flex-wrap: wrap;
            padding: 12px;
            gap: 10px;
        }

        .cat-sub-thumb {
            width: 40px;
            height: 40px;
        }

        .cat-sub-info {
            width: calc(100% - 55px);
        }

        .cat-sub-actions {
            width: 100%;
            justify-content: flex-end;
            margin-top: 6px;
            padding: 6px;
            background: var(--admin-surface-soft);
            border-radius: 8px;
        }

        .cat-sub-actions .btn {
            width: 36px !important;
            height: 36px !important;
            min-width: 36px !important;
            font-size: .9rem !important;
        }
    }

    @media (max-width: 480px) {
        .cat-section-head {
            padding: 12px 14px;
        }

        .cat-section-body {
            padding: 14px 14px 18px;
        }

        .cat-root-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="cat-page">

    <?php if ($error): ?>
        <div class="cat-alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="cat-alert success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════
         بخش ۱: دسته‌های اصلی (ریشه)
         ═══════════════════════════════════════════════════════════ -->
    <div class="cat-section">

        <div class="cat-section-head">
            <h2>
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H10l2 2h6.5A1.5 1.5 0 0 1 20 7.5v11a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13Z" />
                </svg>
                دسته‌های اصلی
            </h2>
            <span class="cat-section-count"><?= count($rootCategories) ?> دسته</span>
        </div>

        <div class="cat-section-body">

            <?php if (empty($rootCategories)): ?>
                <div style="text-align:center;padding:30px 20px;color:var(--admin-text-muted);font-size:.88rem;">
                    هنوز هیچ دستهٔ اصلی ثبت نشده است.<br>
                    از فرم زیر اولین دستهٔ اصلی (مثلاً «مردانه»، «زنانه»، «بچگانه») را اضافه کنید.
                </div>
            <?php else: ?>

                <div class="cat-root-grid">

                    <?php foreach ($rootCategories as $root):

                        $rootId    = (int)$root['id'];
                        $imagePath = trim($root['image_path'] ?? '');
                        $iconVal   = trim($root['icon'] ?? '');
                        if (substr($iconVal, 0, 9) === '/uploads/') $iconVal = '🧦';

                        $childRows = $childrenByParent[$rootId] ?? [];
                        $childCount = count($childRows);
                        $isActive  = (int)$root['is_active'] === 1;
                        $sortOrder = (int)$root['sort_order'];
                    ?>

                        <div class="cat-root-card <?= $isActive ? '' : 'is-inactive' ?>">

                            <span class="cat-badge-status <?= $isActive ? 'on' : 'off' ?>">
                                <?= $isActive ? 'فعال' : 'غیرفعال' ?>
                            </span>

                            <div class="cat-root-thumb">
                                <?php if ($imagePath !== ''): ?>
                                    <img src="<?= htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                <?php else: ?>
                                    <span class="emoji"><?= htmlspecialchars($iconVal ?: '🧦', ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="cat-root-name"><?= htmlspecialchars($root['name'], ENT_QUOTES, 'UTF-8') ?></div>

                            <div class="cat-root-meta">
                                <span>📦 <?= number_format((int)$root['product_count']) ?> محصول</span>
                                <span>↳ <?= $childCount ?> زیرمجموعه</span>
                                <span>#=<?= $sortOrder ?></span>
                            </div>

                            <div class="cat-root-actions">

                                <button type="button"
                                    class="btn btn-add-sub cat-add-sub-btn"
                                    data-parent-id="<?= $rootId ?>"
                                    data-parent-name="<?= htmlspecialchars($root['name'], ENT_QUOTES, 'UTF-8') ?>"
                                    style="padding:8px 12px;font-size:.78rem;">
                                    + افزودن زیرمجموعه
                                </button>

                                <div class="cat-root-btnrow">
                                    <button type="button" class="btn cat-edit-btn"
                                        data-id="<?= $rootId ?>"
                                        data-name="<?= htmlspecialchars($root['name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-icon="<?= htmlspecialchars($iconVal, ENT_QUOTES, 'UTF-8') ?>"
                                        data-sort="<?= $sortOrder ?>"
                                        data-parent="0"
                                        style="background:var(--admin-info-soft);color:var(--admin-info);padding:0 8px;height:28px;font-size:.72rem;">
                                        ✏️ ویرایش
                                    </button>

                                    <form method="post" style="display:inline; margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="move">
                                        <input type="hidden" name="id" value="<?= $rootId ?>">
                                        <input type="hidden" name="dir" value="up">
                                        <button type="submit" title="بالا" class="btn" style="padding:0;width:28px;height:28px;font-size:.7rem;">▲</button>
                                    </form>

                                    <form method="post" style="display:inline; margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="move">
                                        <input type="hidden" name="id" value="<?= $rootId ?>">
                                        <input type="hidden" name="dir" value="down">
                                        <button type="submit" title="پایین" class="btn" style="padding:0;width:28px;height:28px;font-size:.7rem;">▼</button>
                                    </form>

                                    <form method="post" style="display:inline; margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="toggle">
                                        <input type="hidden" name="id" value="<?= $rootId ?>">
                                        <button class="btn" style="padding:0 8px;height:28px;font-size:.7rem;">
                                            <?= $isActive ? 'غیرفعال' : 'فعال' ?>
                                        </button>
                                    </form>

                                    <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('حذف «<?= htmlspecialchars($root['name'], ENT_QUOTES, 'UTF-8') ?>»؟');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="delete">
                                        <input type="hidden" name="id" value="<?= $rootId ?>">
                                        <button class="btn" style="padding:0;width:28px;height:28px;font-size:.75rem;background:var(--admin-danger-soft);color:var(--admin-danger);">🗑</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

            <!-- فرم افزودن دستهٔ اصلی (بدون فیلد والد) -->
            <div style="margin-top:22px;padding:16px 18px;background:var(--admin-surface-soft);border:1px solid var(--admin-border);border-radius:var(--admin-radius-md);">
                <h3 style="font-size:.85rem;color:var(--admin-primary-dark);margin:0 0 12px;font-weight:700;">
                    ➕ افزودن دستهٔ اصلی جدید
                </h3>

                <form method="post" enctype="multipart/form-data" class="cat-add-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="add">
                    <input type="hidden" name="parent_id" value="0">

                    <div>
                        <label class="pf-field-label">نام دستهٔ اصلی</label>
                        <input type="text" name="name" required placeholder="مثلاً: مردانه"
                            value="<?= htmlspecialchars($lastForm['name'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div>
                        <label class="pf-field-label">ایموجی</label>
                        <input type="text" name="icon" placeholder="🧦" style="text-align:center;font-size:1.1rem;">
                    </div>

                    <div>
                        <label class="pf-field-label">عکس (اختیاری)</label>
                        <input type="file" name="icon_img" accept="image/jpeg,image/png,image/webp" style="font-size:.72rem;">
                    </div>

                    <div>
                        <label class="pf-field-label">ترتیب</label>
                        <input type="number" name="sort_order" value="99" min="0" style="text-align:center;">
                    </div>

                    <button type="submit" class="btn btn-orange cat-add-submit" style="padding:11px 22px;font-size:.82rem;height:40px;">
                        + افزودن دسته
                    </button>
                </form>
            </div>

        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         بخش ۲: زیرمجموعه‌ها (نمای کلی، تفکیک‌شده بر اساس والد)
         ═══════════════════════════════════════════════════════════ -->
    <div class="cat-section">

        <div class="cat-section-head">
            <h2>
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 17V7l-3 3" />
                    <path d="M9 7h6" />
                    <path d="M15 7v10l3-3" />
                </svg>
                زیرمجموعه‌ها (بر اساس دستهٔ اصلی)
            </h2>
            <span class="cat-section-count">
                <?= number_format(array_sum(array_map('count', $childrenByParent))) ?> زیرمجموعه
            </span>
        </div>

        <div class="cat-section-body">

            <?php if (empty($rootCategories)): ?>
                <div style="text-align:center;padding:20px;color:var(--admin-text-muted);font-size:.85rem;">
                    ابتدا یک دستهٔ اصلی بسازید تا بتوانید زیرمجموعه اضافه کنید.
                </div>
            <?php else: ?>
                <?php foreach ($rootCategories as $root):
                    $rootId = (int)$root['id'];
                    $childRows = $childrenByParent[$rootId] ?? [];
                ?>
                    <div class="cat-sub-wrap <?= empty($childRows) ? 'is-empty' : '' ?>"
                        style="margin-bottom:18px;">

                        <div class="cat-sub-head">
                            <h4>
                                <?= htmlspecialchars($root['name'], ENT_QUOTES, 'UTF-8') ?>
                                <span>(<?= count($childRows) ?> زیرمجموعه)</span>
                            </h4>
                            <button type="button" class="btn btn-add-sub cat-add-sub-btn"
                                data-parent-id="<?= $rootId ?>"
                                data-parent-name="<?= htmlspecialchars($root['name'], ENT_QUOTES, 'UTF-8') ?>"
                                style="padding:6px 14px;font-size:.75rem;">
                                + افزودن زیرمجموعه
                            </button>
                        </div>

                        <?php if (empty($childRows)): ?>
                            <div style="padding:10px 0 14px;font-size:.85rem;color:var(--admin-text-muted);">
                                هنوز زیرمجموعه‌ای برای این دسته ثبت نشده است.
                            </div>
                        <?php else: ?>
                            <div class="cat-sub-list">
                                <?php foreach ($childRows as $ch):
                                    $chImage = trim($ch['image_path'] ?? '');
                                    $chIcon  = trim($ch['icon'] ?? '');
                                    if (substr($chIcon, 0, 9) === '/uploads/') $chIcon = '🧦';
                                    $chActive = (int)$ch['is_active'] === 1;
                                ?>
                                    <div class="cat-sub-item <?= $chActive ? '' : 'is-inactive' ?>">

                                        <div class="cat-sub-thumb">
                                            <?php if ($chImage !== ''): ?>
                                                <img src="<?= htmlspecialchars($chImage, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                            <?php else: ?>
                                                <span class="emoji"><?= htmlspecialchars($chIcon ?: '🧦', ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="cat-sub-info">
                                            <div class="cat-sub-name"><?= htmlspecialchars($ch['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="cat-sub-meta">
                                                <span>📦 <?= number_format((int)$ch['own_products']) ?> محصول</span>
                                                <span>#= <?= (int)$ch['sort_order'] ?></span>
                                            </div>
                                        </div>

                                        <div class="cat-sub-actions">
                                            <button type="button" class="btn cat-edit-btn"
                                                data-id="<?= (int)$ch['id'] ?>"
                                                data-name="<?= htmlspecialchars($ch['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-icon="<?= htmlspecialchars($chIcon, ENT_QUOTES, 'UTF-8') ?>"
                                                data-sort="<?= (int)$ch['sort_order'] ?>"
                                                data-parent="<?= $rootId ?>"
                                                title="ویرایش">✏️</button>

                                            <form method="post" style="display:inline; margin:0;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="do" value="move">
                                                <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                                                <input type="hidden" name="dir" value="up">
                                                <button type="submit" title="انتقال به بالا" class="btn">▲</button>
                                            </form>

                                            <form method="post" style="display:inline; margin:0;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="do" value="move">
                                                <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                                                <input type="hidden" name="dir" value="down">
                                                <button type="submit" title="انتقال به پایین" class="btn">▼</button>
                                            </form>

                                            <form method="post" style="display:inline; margin:0;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="do" value="toggle">
                                                <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                                                <button type="submit" title="<?= $chActive ? 'غیرفعال کردن' : 'فعال کردن' ?>" class="btn">
                                                    <?= $chActive ? '👁️' : '🔇' ?>
                                                </button>
                                            </form>

                                            <form method="post" style="display:inline; margin:0;"
                                                onsubmit="return confirm('حذف «<?= htmlspecialchars($ch['name'], ENT_QUOTES, 'UTF-8') ?>»؟');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="do" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                                                <button type="submit" class="btn" title="حذف">🗑</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </div>

</div>

<!-- ═══════════════════════════════════════════════════════════
     مودال افزودن زیرمجموعه
     ═══════════════════════════════════════════════════════════ -->
<div id="addSubModal" class="cat-modal-overlay">
    <div class="cat-modal">

        <div class="cat-modal-head">
            <h3 id="addSubTitle">افزودن زیرمجموعه</h3>
            <button type="button" class="cat-modal-close" id="addSubClose" aria-label="بستن">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="cat-modal-body">

            <form method="post" enctype="multipart/form-data" id="addSubForm">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="add">
                <input type="hidden" name="parent_id" id="addSubParentId" value="0">

                <div class="cat-modal-field">
                    <label>نام زیرمجموعه <span style="color:var(--admin-danger);">*</span></label>
                    <input type="text" name="name" required placeholder="مثلاً: جوراب ساق‌بلند">
                </div>

                <div class="cat-modal-row">
                    <div class="cat-modal-field">
                        <label>ایموجی</label>
                        <input type="text" name="icon" placeholder="🧦" style="text-align:center;font-size:1.1rem;">
                    </div>
                    <div class="cat-modal-field">
                        <label>ترتیب</label>
                        <input type="number" name="sort_order" value="99" min="0" style="text-align:center;">
                    </div>
                </div>

                <div class="cat-modal-field">
                    <label>تصویر (اختیاری)</label>
                    <div class="cat-modal-file">
                        <label class="cat-file-btn" for="addSubImg">انتخاب تصویر</label>
                        <input type="file" name="icon_img" id="addSubImg"
                            accept="image/jpeg,image/png,image/webp" style="display:none;">
                        <span class="cat-file-name" id="addSubImgName">تصویری انتخاب نشده</span>
                    </div>
                </div>

                <div class="cat-modal-actions">
                    <button type="button" class="btn" id="addSubCancel">انصراف</button>
                    <button type="submit" class="btn btn-orange">+ افزودن زیرمجموعه</button>
                </div>
            </form>

            <!-- لیست زیرمجموعه‌های موجود این والد -->
            <div class="cat-modal-existing">
                <h4>زیرمجموعه‌های موجود این دسته:</h4>
                <div class="cat-modal-existing-list" id="addSubExisting"></div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     مودال ویرایش
     ═══════════════════════════════════════════════════════════ -->
<div id="editCategoryModal" class="cat-modal-overlay">
    <div class="cat-modal">

        <div class="cat-modal-head">
            <h3>ویرایش دسته‌بندی</h3>
            <button type="button" class="cat-modal-close" id="editCatClose" aria-label="بستن">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="cat-modal-body">
            <form method="post" enctype="multipart/form-data" id="editCategoryForm">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="edit">
                <input type="hidden" name="id" id="edit_category_id">

                <div class="cat-modal-field">
                    <label>نام دسته‌بندی <span style="color:var(--admin-danger);">*</span></label>
                    <input type="text" name="name" id="edit_category_name" required>
                </div>

                <div class="cat-modal-field" id="edit_parent_field">
                    <label>دستهٔ اصلی والد</label>
                    <select name="parent_id" id="edit_category_parent">
                        <option value="0">— این یک زیرمجموعهٔ مستقل است —</option>
                        <?php foreach ($rootCategories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>">
                                <?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="display:block;font-size:.72rem;color:var(--admin-text-muted);margin-top:5px;">
                        اگر این دسته خودش «دستهٔ اصلی» است، این گزینه را روی «مستقل» بگذارید.
                    </small>
                </div>

                <div class="cat-modal-row">
                    <div class="cat-modal-field">
                        <label>ایموجی</label>
                        <input type="text" name="icon" id="edit_category_icon" placeholder="🧦" style="text-align:center;font-size:1.1rem;">
                    </div>
                    <div class="cat-modal-field">
                        <label>ترتیب</label>
                        <input type="number" name="sort_order" id="edit_category_sort" min="0" style="text-align:center;">
                    </div>
                </div>

                <div class="cat-modal-field">
                    <label>تصویر جدید (اختیاری)</label>
                    <div class="cat-modal-file">
                        <label class="cat-file-btn" for="edit_category_img">انتخاب تصویر</label>
                        <input type="file" name="icon_img" id="edit_category_img"
                            accept="image/jpeg,image/png,image/webp" style="display:none;">
                        <span class="cat-file-name" id="edit_category_img_name">تصویری انتخاب نشده</span>
                    </div>
                    <small style="display:block;font-size:.72rem;color:var(--admin-text-muted);margin-top:5px;">
                        اگر تصویر جدید انتخاب کنید، جایگزین تصویر فعلی می‌شود.
                    </small>
                </div>

                <div class="cat-modal-actions">
                    <button type="button" class="btn" id="editCatCancel">انصراف</button>
                    <button type="submit" class="btn btn-orange">💾 ذخیره تغییرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     دادهٔ زیرمجموعه‌ها برای JS
     ═══════════════════════════════════════════════════════════ -->
<script>
    (function() {
        'use strict';

        /* نقشهٔ parentId => [زیرمجموعه‌ها] — برای نمایش در مودال افزودن */
        var childrenByParent = <?= json_encode(
                                    array_map(function ($kids) {
                                        return array_map(function ($k) {
                                            return [
                                                'id'     => (int)$k['id'],
                                                'name'   => $k['name'],
                                                'icon'   => (substr((string)($k['icon'] ?? ''), 0, 9) === '/uploads/') ? '🧦' : ((string)($k['icon'] ?? '')),
                                                'image'  => (string)($k['image_path'] ?? ''),
                                                'count'  => (int)$k['own_products'],
                                                'active' => (int)$k['is_active'] === 1,
                                            ];
                                        }, $kids);
                                    }, $childrenByParent),
                                    JSON_UNESCAPED_UNICODE
                                ) ?>;

        /* ─── عناصر مشترک ─── */
        function openModal(id) {
            var el = document.getElementById(id);
            if (el) el.classList.add('is-open');
        }

        function closeModal(id) {
            var el = document.getElementById(id);
            if (el) el.classList.remove('is-open');
        }

        /* ─── مودال افزودن زیرمجموعه ─── */
        var addSubBtns = document.querySelectorAll('.cat-add-sub-btn');
        addSubBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var pid = parseInt(btn.dataset.parentId, 10);
                var pname = btn.dataset.parentName || '';

                document.getElementById('addSubParentId').value = pid;
                document.getElementById('addSubTitle').textContent = 'افزودن زیرمجموعه به «' + pname + '»';

                /* پاک کردن فرم */
                var form = document.getElementById('addSubForm');
                form.reset();
                document.getElementById('addSubParentId').value = pid;
                document.getElementById('addSubImgName').textContent = 'تصویری انتخاب نشده';

                /* پر کردن لیست زیرمجموعه‌های موجود این والد */
                var list = document.getElementById('addSubExisting');
                var kids = childrenByParent[pid] || [];
                if (!kids.length) {
                    list.innerHTML = '<div class="cat-modal-existing-empty">هنوز زیرمجموعه‌ای ثبت نشده است.</div>';
                } else {
                    list.innerHTML = kids.map(function(k) {
                        var thumb = k.image ?
                            '<img src="' + k.image + '" alt="" style="width:24px;height:24px;object-fit:cover;border-radius:6px;border:1px solid var(--admin-border);">' :
                            '<span class="emoji">' + (k.icon || '🧦') + '</span>';
                        return '<div class="cat-modal-existing-item ' + (k.active ? '' : 'is-inactive') + '">' +
                            thumb +
                            '<b style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + k.name + '</b>' +
                            '<span style="color:var(--admin-text-muted);font-size:.72rem;">' + k.count + ' محصول</span>' +
                            '</div>';
                    }).join('');
                }

                openModal('addSubModal');
            });
        });

        document.getElementById('addSubClose').addEventListener('click', function() {
            closeModal('addSubModal');
        });
        document.getElementById('addSubCancel').addEventListener('click', function() {
            closeModal('addSubModal');
        });
        document.getElementById('addSubModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal('addSubModal');
        });

        /* نمایش نام فایل انتخاب‌شده */
        var addSubImg = document.getElementById('addSubImg');
        addSubImg.addEventListener('change', function() {
            var name = (addSubImg.files && addSubImg.files.length) ?
                addSubImg.files[0].name :
                'تصویری انتخاب نشده';
            document.getElementById('addSubImgName').textContent = name;
        });

        /* ─── مودال ویرایش ─── */
        var editBtns = document.querySelectorAll('.cat-edit-btn');
        editBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = btn.dataset.id;
                var name = btn.dataset.name || '';
                var icon = btn.dataset.icon || '';
                var sort = btn.dataset.sort || 99;
                var parent = btn.dataset.parent || 0;

                document.getElementById('edit_category_id').value = id;
                document.getElementById('edit_category_name').value = name;
                document.getElementById('edit_category_icon').value = icon;
                document.getElementById('edit_category_sort').value = sort;
                document.getElementById('edit_category_parent').value = parent;

                /* اگر والد = 0 یعنی این یک دستهٔ اصلی است → فیلد والد را مخفی کن */
                var parentField = document.getElementById('edit_parent_field');
                parentField.style.display = (parseInt(parent, 10) === 0) ? 'none' : 'block';

                /* پاک کردن انتخاب فایل قبلی */
                document.getElementById('edit_category_img_name').textContent = 'تصویری انتخاب نشده';

                openModal('editCategoryModal');
            });
        });

        document.getElementById('editCatClose').addEventListener('click', function() {
            closeModal('editCategoryModal');
        });
        document.getElementById('editCatCancel').addEventListener('click', function() {
            closeModal('editCategoryModal');
        });
        document.getElementById('editCategoryModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal('editCategoryModal');
        });

        /* نمایش نام فایل در مودال ویرایش */
        var editImg = document.getElementById('edit_category_img');
        editImg.addEventListener('change', function() {
            var name = (editImg.files && editImg.files.length) ?
                editImg.files[0].name :
                'تصویری انتخاب نشده';
            document.getElementById('edit_category_img_name').textContent = name;
        });

        /* ESC برای بستن مودال‌ها */
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('addSubModal');
                closeModal('editCategoryModal');
            }
        });
    })();
</script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
