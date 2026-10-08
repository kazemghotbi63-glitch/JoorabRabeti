<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
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

            if ($parentId !== null) {
                $stmt = $db->prepare("SELECT id FROM categories WHERE id = ? LIMIT 1");
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
                        $icon = ($oldIcon === '' || str_starts_with($oldIcon, '/uploads/'))
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
 * برگرداندن لیست درختی + محصولات تجمیعی
 */
function build_category_tree(PDO $db): array
{
    $rows = $db->query("
        SELECT
            c.*,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS own_products
        FROM categories c
        ORDER BY c.sort_order ASC, c.id ASC
    ")->fetchAll();

    /* گروه‌بندی بر اساس والد */
    $byParent = [];
    foreach ($rows as $r) {
        $pid = $r['parent_id'] !== null ? (int)$r['parent_id'] : 0;
        $byParent[$pid][] = $r;
    }

    /* مرتب‌سازی بازگشتی — محصولات تجمیعی */
    $flat = [];

    $walk = function (int $parentId, int $depth) use (&$walk, &$flat, $byParent): int {
        $total = 0;
        foreach ($byParent[$parentId] ?? [] as $row) {

            $childrenProducts = $walk((int)$row['id'], $depth + 1);

            $totalHere = (int)$row['own_products'] + $childrenProducts;

            $row['depth'] = $depth;
            $row['product_count'] = $totalHere;
            $flat[] = $row;

            $total += $totalHere;
        }
        return $total;
    };

    $walk(0, 0);

    return $flat;
}

$categories = build_category_tree($db);

/* همه دسته‌ها برای dropdown والد */
$allCategories = $db->query("
    SELECT id, name, parent_id
    FROM categories
    ORDER BY sort_order, id
")->fetchAll();

$activeMenu = 'categories';
$pageTitle  = 'دسته‌بندی‌ها';

ob_start();
?>

<style>
    .cat-tree-name {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .cat-tree-indent {
        display: inline-block;
        color: #cbd5e1;
        font-family: monospace;
        letter-spacing: -2px;
        user-select: none;
    }

    .cat-badge-parent {
        display: inline-block;
        padding: 2px 8px;
        background: #ecfeff;
        color: #0e7490;
        border-radius: 999px;
        font-size: .65rem;
        margin-inline-start: 6px;
    }

    .cat-badge-child {
        display: inline-block;
        padding: 2px 8px;
        background: #f1f5f9;
        color: #64748b;
        border-radius: 999px;
        font-size: .65rem;
        margin-inline-start: 6px;
    }

    .cat-row-parent {
        background: #f8fafc;
    }

    .cat-row-parent td {
        font-weight: 700;
    }
</style>

<?php if ($error): ?>
    <div class="form-alert error" style="display:block;margin-bottom:18px;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="form-alert" style="display:block;margin-bottom:18px;background:#e8f7f3;color:var(--teal-800);">
        <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>

<!-- ═══ افزودن ═══ -->
<div class="admin-card" style="padding:18px;margin-bottom:22px;">
    <h2 class="section-title" style="font-size:1rem;margin-bottom:14px;">
        افزودن دسته‌بندی جدید
    </h2>

    <form method="post" enctype="multipart/form-data"
        style="display:grid;grid-template-columns:1.4fr 1.2fr 80px 1fr 100px auto;gap:10px;align-items:end;">

        <input type="hidden" name="do" value="add">

        <div>
            <label style="display:block;font-size:.7rem;color:var(--muted);margin-bottom:4px;">نام</label>
            <input type="text" name="name" required
                style="width:100%;padding:10px 14px;border:1px solid var(--card-border);border-radius:12px;font-family:inherit;">
        </div>

        <div>
            <label style="display:block;font-size:.7rem;color:var(--muted);margin-bottom:4px;">دسته والد</label>
            <select name="parent_id"
                style="width:100%;padding:10px;border:1px solid var(--card-border);border-radius:12px;">
                <option value="0">— بدون والد (دسته اصلی) —</option>
                <?php foreach ($allCategories as $c): ?>
                    <?php if ($c['parent_id'] === null): ?>
                        <option value="<?= (int)$c['id'] ?>">
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display:block;font-size:.7rem;color:var(--muted);margin-bottom:4px;">ایموجی</label>
            <input type="text" name="icon" placeholder="🧦"
                style="width:100%;text-align:center;padding:10px;border:1px solid var(--card-border);border-radius:12px;font-size:1.1rem;">
        </div>

        <div>
            <label style="display:block;font-size:.7rem;color:var(--muted);margin-bottom:4px;">عکس</label>
            <input type="file" name="icon_img" accept="image/jpeg,image/png,image/webp" style="font-size:.72rem;">
        </div>

        <div>
            <label style="display:block;font-size:.7rem;color:var(--muted);margin-bottom:4px;">ترتیب</label>
            <input type="number" name="sort_order" value="99" min="0"
                style="width:100%;padding:10px;border:1px solid var(--card-border);border-radius:12px;text-align:center;">
        </div>

        <button type="submit" class="btn btn-orange" style="padding:11px 22px;font-size:.82rem;">
            + افزودن
        </button>
    </form>
</div>

<!-- ═══ لیست درختی ═══ -->
<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th style="width:120px;">ترتیب</th>
                <th style="width:80px;">تصویر</th>
                <th>نام</th>
                <th style="width:110px;">محصولات</th>
                <th style="width:110px;">وضعیت</th>
                <th style="min-width:320px;">عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $index => $c): ?>
                <?php
                $isParent = ($c['parent_id'] === null);
                $rowClass = $isParent ? 'cat-row-parent' : '';
                $depth    = (int)$c['depth'];
                ?>
                <tr class="<?= $rowClass ?>">

                    <td>
                        <div style="display:flex;align-items:center;gap:4px;">
                            <b><?= (int)$c['sort_order'] ?></b>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="do" value="move">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <input type="hidden" name="dir" value="up">
                                <button type="submit" title="بالا"
                                    style="border:none;background:none;cursor:pointer;color:var(--teal-800);font-size:.8rem;padding:0 2px;">▲</button>
                            </form>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="do" value="move">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <input type="hidden" name="dir" value="down">
                                <button type="submit" title="پایین"
                                    style="border:none;background:none;cursor:pointer;color:var(--teal-800);font-size:.8rem;padding:0 2px;">▼</button>
                            </form>
                        </div>
                    </td>

                    <td>
                        <?php
                        $imagePath = trim($c['image_path'] ?? '');
                        $iconVal = trim($c['icon'] ?? '');
                        if (str_starts_with($iconVal, '/uploads/')) $iconVal = '🧦';
                        ?>
                        <?php if ($imagePath !== ''): ?>
                            <img src="<?= htmlspecialchars($imagePath) ?>" alt=""
                                style="width:52px;height:52px;object-fit:cover;border-radius:12px;border:1px solid var(--card-border);">
                        <?php else: ?>
                            <span style="font-size:1.7rem;"><?= htmlspecialchars($iconVal ?: '🧦') ?></span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div class="cat-tree-name">
                            <?php if ($depth > 0): ?>
                                <span class="cat-tree-indent">
                                    <?= str_repeat('───', $depth) ?>▸
                                </span>
                            <?php endif; ?>

                            <b><?= htmlspecialchars($c['name']) ?></b>

                            <?php if ($isParent): ?>
                                <span class="cat-badge-parent">دسته اصلی</span>
                            <?php else: ?>
                                <span class="cat-badge-child">زیرمجموعه</span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td>
                        <?php if ((int)$c['product_count'] > 0): ?>
                            <b><?= number_format((int)$c['product_count']) ?></b>
                        <?php else: ?>
                            <span style="color:var(--muted);">0</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="st-badge <?= $c['is_active'] ? 'st-converted' : 'st-rejected' ?>">
                            <?= $c['is_active'] ? 'فعال' : 'غیرفعال' ?>
                        </span>
                    </td>

                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">

                            <button type="button" class="btn"
                                onclick='openEditCategory(
                                    <?= (int)$c["id"] ?>,
                                    <?= htmlspecialchars(json_encode($c["name"], JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>,
                                    <?= htmlspecialchars(json_encode(str_starts_with(trim($c["icon"] ?? ""), "/uploads/") ? "" : ($c["icon"] ?? ""), JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>,
                                    <?= (int)$c["sort_order"] ?>,
                                    <?= $c["parent_id"] !== null ? (int)$c["parent_id"] : 0 ?>
                                )'
                                style="padding:6px 12px;font-size:.72rem;background:var(--teal-100);color:var(--teal-800);">
                                ✏️ ویرایش
                            </button>

                            <form method="post" style="display:inline;">
                                <input type="hidden" name="do" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn" style="padding:6px 12px;font-size:.72rem;">
                                    <?= $c['is_active'] ? 'غیرفعال' : 'فعال' ?>
                                </button>
                            </form>

                            <form method="post" style="display:inline;"
                                onsubmit="return confirm('حذف شود؟');">
                                <input type="hidden" name="do" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="btn"
                                    style="padding:6px 12px;font-size:.72rem;background:#fff0f0;color:#c62828;">
                                    🗑
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ═══ Modal ویرایش ═══ -->
<div id="editCategoryModal"
    style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;padding:20px;">

    <div style="width:min(500px,100%);background:#fff;border-radius:18px;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto;">

        <h3 style="margin:0 0 18px;">ویرایش دسته‌بندی</h3>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="do" value="edit">
            <input type="hidden" name="id" id="edit_category_id">

            <div class="form-field">
                <label>نام دسته‌بندی</label>
                <input type="text" name="name" id="edit_category_name" required>
            </div>

            <div class="form-field">
                <label>دسته والد</label>
                <select name="parent_id" id="edit_category_parent">
                    <option value="0">— بدون والد (دسته اصلی) —</option>
                    <?php foreach ($allCategories as $c): ?>
                        <?php if ($c['parent_id'] === null): ?>
                            <option value="<?= (int)$c['id'] ?>">
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <small style="display:block;margin-top:6px;color:var(--muted);">
                    توجه: اگر این دسته زیرمجموعه دارد، آن‌ها هم با آن منتقل می‌شوند.
                </small>
            </div>

            <div class="form-row" style="display:grid;grid-template-columns:1fr 110px;gap:12px;">
                <div class="form-field">
                    <label>ایموجی</label>
                    <input type="text" name="icon" id="edit_category_icon" placeholder="🧦">
                </div>
                <div class="form-field">
                    <label>ترتیب</label>
                    <input type="number" name="sort_order" id="edit_category_sort" min="0"
                        style="text-align:center;">
                </div>
            </div>

            <div class="form-field">
                <label>تصویر جدید</label>
                <input type="file" name="icon_img" accept="image/jpeg,image/png,image/webp">
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px;">
                <button type="button" class="btn" onclick="closeEditCategory()">انصراف</button>
                <button type="submit" class="btn btn-orange">ذخیره تغییرات</button>
            </div>
        </form>

    </div>
</div>

<script>
    function openEditCategory(id, name, icon, sortOrder, parentId) {
        document.getElementById('edit_category_id').value = id;
        document.getElementById('edit_category_name').value = name;
        document.getElementById('edit_category_icon').value = icon || '';
        document.getElementById('edit_category_sort').value = sortOrder ?? 99;
        document.getElementById('edit_category_parent').value = parentId || 0;

        document.getElementById('editCategoryModal').style.display = 'flex';
    }

    function closeEditCategory() {
        document.getElementById('editCategoryModal').style.display = 'none';
    }

    document.getElementById('editCategoryModal')
        .addEventListener('click', function(event) {
            if (event.target === this) closeEditCategory();
        });
</script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
