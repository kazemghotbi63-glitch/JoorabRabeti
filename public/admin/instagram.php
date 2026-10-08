<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/config/upload.php';

require_admin();

$db = db();
$error = '';
$success = '';

/* ═══════════════════════════════════════════════
   POST
   ═══════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $do = $_POST['do'] ?? '';

    /* ─── افزودن ─── */
    if ($do === 'add') {

        $caption   = trim($_POST['caption'] ?? '');
        $link      = trim($_POST['link'] ?? '');
        $sortOrder = max(0, (int)($_POST['sort_order'] ?? 99));

        $imagePath = upload_image($_FILES['image'] ?? [], 'instagram');

        if (!$imagePath) {
            $error = 'آپلود تصویر ناموفق بود.';
        } else {
            $db->prepare("
                INSERT INTO instagram_posts
                    (image_path, caption, link, sort_order, is_active)
                VALUES
                    (?,?,?,?,1)
            ")->execute([
                $imagePath,
                $caption !== '' ? $caption : null,
                $link !== '' ? $link : null,
                $sortOrder
            ]);

            header('Location: /admin/instagram?success=added');
            exit;
        }
    }

    /* ─── ویرایش ─── */
    if ($do === 'edit') {

        $id        = (int)($_POST['id'] ?? 0);
        $caption   = trim($_POST['caption'] ?? '');
        $link      = trim($_POST['link'] ?? '');
        $sortOrder = max(0, (int)($_POST['sort_order'] ?? 99));

        if ($id <= 0) {
            $error = 'شناسه نامعتبر است.';
        } else {
            $stmt = $db->prepare("SELECT image_path FROM instagram_posts WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if (!$row) {
                $error = 'پست پیدا نشد.';
            } else {
                $imagePath = $row['image_path'];
                $newImage = upload_image($_FILES['image'] ?? [], 'instagram');
                if ($newImage) $imagePath = $newImage;

                $db->prepare("
                    UPDATE instagram_posts
                    SET caption=?, link=?, sort_order=?, image_path=?
                    WHERE id=?
                ")->execute([
                    $caption !== '' ? $caption : null,
                    $link !== '' ? $link : null,
                    $sortOrder,
                    $imagePath,
                    $id
                ]);

                header('Location: /admin/instagram?success=edited');
                exit;
            }
        }
    }

    /* ─── فعال/غیرفعال ─── */
    if ($do === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE instagram_posts SET is_active = 1 - is_active WHERE id=?")
                ->execute([$id]);
            header('Location: /admin/instagram?success=toggle');
            exit;
        }
    }

    /* ─── حذف ─── */
    if ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("SELECT image_path FROM instagram_posts WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if ($row) {
                $file = ROOT_PATH . '/public' . $row['image_path'];
                if (is_file($file)) @unlink($file);

                $db->prepare("DELETE FROM instagram_posts WHERE id=?")->execute([$id]);
            }
            header('Location: /admin/instagram?success=deleted');
            exit;
        }
    }
}

if (isset($_GET['success'])) {
    $success = [
        'added'   => 'پست اضافه شد.',
        'edited'  => 'ویرایش شد.',
        'deleted' => 'حذف شد.',
        'toggle'  => 'وضعیت تغییر کرد.',
    ][$_GET['success']] ?? '';
}

$posts = $db->query("
    SELECT * FROM instagram_posts
    ORDER BY sort_order ASC, id DESC
")->fetchAll();

$activeMenu = 'instagram';
$pageTitle  = 'گالری اینستاگرام';

ob_start();
?>

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

<!-- افزودن -->
<div class="admin-card" style="padding:18px;margin-bottom:22px;">
    <h2 class="section-title" style="font-size:1rem;margin-bottom:14px;">
        افزودن پست جدید
    </h2>

    <form method="post" enctype="multipart/form-data"
        style="display:grid;grid-template-columns:1fr 1fr 1fr 90px auto;gap:10px;align-items:end;">

        <input type="hidden" name="do" value="add">

        <div>
            <label style="display:block;font-size:.7rem;color:var(--muted);margin-bottom:4px;">
                تصویر *
            </label>
            <input type="file" name="image" required
                accept="image/jpeg,image/png,image/webp"
                style="font-size:.72rem;padding:8px;">
        </div>

        <div>
            <label style="display:block;font-size:.7rem;color:var(--muted);margin-bottom:4px;">
                متن (کپشن)
            </label>
            <input type="text" name="caption"
                placeholder="مثلاً: جدیدترین طرح"
                style="width:100%;padding:10px 14px;border:1px solid var(--card-border);border-radius:12px;font-family:inherit;">
        </div>

        <div>
            <label style="display:block;font-size:.7rem;color:var(--muted);margin-bottom:4px;">
                لینک پست (اختیاری)
            </label>
            <input type="url" name="link" dir="ltr"
                placeholder="https://instagram.com/p/..."
                style="width:100%;padding:10px 14px;border:1px solid var(--card-border);border-radius:12px;font-family:inherit;">
        </div>

        <div>
            <label style="display:block;font-size:.7rem;color:var(--muted);margin-bottom:4px;">
                ترتیب
            </label>
            <input type="number" name="sort_order" value="99" min="0"
                style="width:100%;padding:10px;border:1px solid var(--card-border);border-radius:12px;text-align:center;">
        </div>

        <button type="submit" class="btn btn-orange"
            style="padding:11px 22px;font-size:.82rem;">
            + افزودن
        </button>
    </form>
</div>

<!-- لیست -->
<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th style="width:100px;">تصویر</th>
                <th>متن</th>
                <th>لینک</th>
                <th style="width:80px;">ترتیب</th>
                <th style="width:100px;">وضعیت</th>
                <th style="width:200px;">عملیات</th>
            </tr>
        </thead>
        <tbody>

            <?php if (!$posts): ?>
                <tr>
                    <td colspan="6" style="text-align:center;padding:30px;color:var(--muted);">
                        هنوز پستی اضافه نشده.
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($posts as $post): ?>
                <tr>
                    <td>
                        <img src="<?= htmlspecialchars($post['image_path']) ?>" alt=""
                            style="width:64px;height:64px;object-fit:cover;border-radius:10px;border:1px solid var(--card-border);">
                    </td>

                    <td>
                        <?= htmlspecialchars($post['caption'] ?? '—') ?>
                    </td>

                    <td>
                        <?php if (!empty($post['link'])): ?>
                            <a href="<?= htmlspecialchars($post['link']) ?>"
                                target="_blank" rel="noopener"
                                dir="ltr"
                                style="color:var(--teal-700);font-size:.75rem;">
                                مشاهده
                            </a>
                        <?php else: ?>
                            <span style="color:var(--muted);">—</span>
                        <?php endif; ?>
                    </td>

                    <td style="text-align:center;">
                        <?= (int)$post['sort_order'] ?>
                    </td>

                    <td>
                        <span class="st-badge <?= $post['is_active'] ? 'st-converted' : 'st-rejected' ?>">
                            <?= $post['is_active'] ? 'فعال' : 'غیرفعال' ?>
                        </span>
                    </td>

                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">

                            <button type="button" class="btn"
                                onclick='openEditIg(
                                    <?= (int)$post["id"] ?>,
                                    <?= htmlspecialchars(json_encode($post["caption"] ?? "", JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>,
                                    <?= htmlspecialchars(json_encode($post["link"] ?? "", JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>,
                                    <?= (int)$post["sort_order"] ?>
                                )'
                                style="padding:6px 12px;font-size:.72rem;background:var(--teal-100);color:var(--teal-800);">
                                ✏️ ویرایش
                            </button>

                            <form method="post" style="display:inline;">
                                <input type="hidden" name="do" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                                <button class="btn" style="padding:6px 12px;font-size:.72rem;">
                                    <?= $post['is_active'] ? 'غیرفعال' : 'فعال' ?>
                                </button>
                            </form>

                            <form method="post" style="display:inline;"
                                onsubmit="return confirm('حذف شود؟');">
                                <input type="hidden" name="do" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
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

<!-- Modal ویرایش -->
<div id="editIgModal"
    style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;padding:20px;">

    <div style="width:min(500px,100%);background:#fff;border-radius:18px;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.2);">

        <h3 style="margin:0 0 18px;">ویرایش پست</h3>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="do" value="edit">
            <input type="hidden" name="id" id="edit_ig_id">

            <div class="form-field">
                <label>متن (کپشن)</label>
                <input type="text" name="caption" id="edit_ig_caption">
            </div>

            <div class="form-field">
                <label>لینک پست</label>
                <input type="url" name="link" id="edit_ig_link" dir="ltr">
            </div>

            <div class="form-field">
                <label>ترتیب</label>
                <input type="number" name="sort_order" id="edit_ig_sort" min="0"
                    style="text-align:center;">
            </div>

            <div class="form-field">
                <label>تصویر جدید (اختیاری)</label>
                <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px;">
                <button type="button" class="btn" onclick="closeEditIg()">انصراف</button>
                <button type="submit" class="btn btn-orange">ذخیره</button>
            </div>
        </form>

    </div>
</div>

<script>
    function openEditIg(id, caption, link, sortOrder) {
        document.getElementById('edit_ig_id').value = id;
        document.getElementById('edit_ig_caption').value = caption || '';
        document.getElementById('edit_ig_link').value = link || '';
        document.getElementById('edit_ig_sort').value = sortOrder ?? 99;
        document.getElementById('editIgModal').style.display = 'flex';
    }

    function closeEditIg() {
        document.getElementById('editIgModal').style.display = 'none';
    }
    document.getElementById('editIgModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditIg();
    });
</script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
