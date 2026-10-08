<?php

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

require_admin();

/* تغییر وضعیت (فاز بک‌اند با امنیت کامل می‌شود) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $allowed = ['new','in_review','quoted','converted','rejected'];
    if ($id > 0 && in_array($status, $allowed)) {
        db()->prepare("UPDATE custom_order_requests SET status=? WHERE id=?")
            ->execute([$status, $id]);
        header('Location: /admin/custom-orders');
        exit;
    }
}

 $requests = db()->query("
    SELECT * FROM custom_order_requests ORDER BY (status='new') DESC, id DESC
")->fetchAll();

 $statusLabels = ['new'=>'جدید','in_review'=>'در بررسی','quoted'=>'قیمت داده شد','converted'=>'تبدیل شد','rejected'=>'رد شد'];

 $activeMenu = 'custom';
 $pageTitle  = 'سفارش‌های اختصاصی';
ob_start();
?>

<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr><th>کد</th><th>نام / موبایل</th><th>برند</th><th>نوع / تعداد</th><th>توضیحات</th><th>وضعیت ← تغییر</th></tr>
        </thead>
        <tbody>
            <?php foreach ($requests as $r): ?>
            <tr>
                <td><b>CR-<?= str_pad($r['id'], 5, '0', STR_PAD_LEFT) ?></b></td>
                <td>
                    <?= htmlspecialchars($r['name']) ?><br>
                    <span dir="ltr" style="color:var(--muted);font-size:.75rem;"><?= htmlspecialchars($r['mobile']) ?></span>
                </td>
                <td><?= htmlspecialchars($r['brand_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['product_type'] ?? '—') ?><br><span style="font-size:.75rem;color:var(--muted);"><?= number_format($r['qty']) ?> جفت</span></td>
                <td style="max-width:220px;font-size:.78rem;color:var(--muted);"><?= htmlspecialchars(mb_substr($r['description'] ?? '', 0, 80)) ?>...</td>
                <td>
                    <span class="st-badge st-<?= $r['status'] ?>"><?= $statusLabels[$r['status']] ?></span><br><br>
                    <form method="post" style="display:flex;gap:6px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <select name="status" style="font-size:.75rem;padding:6px;border:1px solid var(--card-border);border-radius:8px;">
                            <?php foreach ($statusLabels as $key => $lbl): ?>
                            <option value="<?= $key ?>" <?= $r['status'] === $key ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-orange" style="padding:6px 14px;font-size:.72rem;">ثبت</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
 $content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';