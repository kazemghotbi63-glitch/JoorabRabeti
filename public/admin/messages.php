<?php

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

require_admin();

/* علامت‌گذاری به‌عنوان خوانده‌شده */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        db()->prepare("UPDATE contact_messages SET status='read' WHERE id=?")->execute([$id]);
        header('Location: /admin/messages');
        exit;
    }
}

$messages = db()->query("
    SELECT * FROM contact_messages ORDER BY (status='new') DESC, id DESC
")->fetchAll();

$activeMenu = 'messages';
$pageTitle  = 'پیام‌های تماس';
ob_start();
?>

<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th>#</th>
                <th>نام / موبایل</th>
                <th>موضوع</th>
                <th>پیام</th>
                <th>وضعیت</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($messages): foreach ($messages as $m): ?>
                    <tr>
                        <td><?= $m['id'] ?></td>
                        <td><?= htmlspecialchars($m['name']) ?><br><span dir="ltr" style="font-size:.75rem;color:var(--muted);"><?= htmlspecialchars($m['mobile']) ?></span></td>
                        <td><?= htmlspecialchars($m['subject'] ?? '—') ?></td>
                        <td style="max-width:260px;font-size:.78rem;color:var(--muted);"><?= htmlspecialchars(mb_substr($m['message'], 0, 100)) ?></td>
                        <td><span class="st-badge st-<?= $m['status'] ?>"><?= ['new' => 'جدید', 'read' => 'خوانده شد', 'answered' => 'پاسخ داده شد'][$m['status']] ?></span></td>
                        <td>
                            <?php if ($m['status'] === 'new'): ?>
                                <form method="post">
                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                    <button class="btn btn-orange" style="padding:6px 14px;font-size:.72rem;">خوانده شد</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach;
            else: ?>
                <tr>
                    <td colspan="6" style="text-align:center;color:var(--muted);padding:30px;">پیامی وجود ندارد</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
