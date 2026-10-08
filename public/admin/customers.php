<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

require_admin();

$db = db();

/* ─── عملیات: تأیید / رد / تعلیق شرکت ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $do = $_POST['do'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    $map = [
        'approve' => ['active',    'customer.approve'],
        'reject'  => ['rejected',  'customer.reject'],
        'suspend' => ['suspended', 'customer.suspend'],
    ];

    if ($id > 0 && isset($map[$do])) {
        [$status, $action] = $map[$do];

        $db->prepare("UPDATE companies SET status = ?, approved_at = NOW() WHERE id = ?")
            ->execute([$status, $id]);

        $db->prepare("
            INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
            VALUES (?, ?, 'company', ?, ?)
        ")->execute([$_SESSION['admin_id'], $action, $id, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);

        header('Location: /admin/customers');
        exit;
    }
}

/* ─── لیست شرکت‌ها با مدیر آن‌ها ─── */
$companies = $db->query("
    SELECT c.*,
           (SELECT u.full_name FROM company_users cu
            JOIN users u ON u.id = cu.user_id
            WHERE cu.company_id = c.id AND cu.role = 'owner' LIMIT 1) AS owner_name,
           (SELECT u.mobile FROM company_users cu
            JOIN users u ON u.id = cu.user_id
            WHERE cu.company_id = c.id AND cu.role = 'owner' LIMIT 1) AS owner_mobile,
           (SELECT COUNT(*) FROM company_users cu WHERE cu.company_id = c.id) AS user_count
    FROM companies c
    ORDER BY (c.status = 'pending') DESC, c.id DESC
")->fetchAll();

$statusLabels = [
    'pending'   => 'در انتظار تأیید',
    'active'    => 'فعال',
    'suspended' => 'معلق',
    'rejected'  => 'رد شده',
];

$activeMenu = 'customers';
$pageTitle  = 'مشتریان';

ob_start();
?>

<?php if ($companies): ?>

    <div class="admin-table-wrap">

        <table class="admin-table">

            <thead>
                <tr>
                    <th>شرکت / فروشگاه</th>
                    <th>مدیر</th>
                    <th>اعضا</th>
                    <th>گروه قیمت</th>
                    <th>وضعیت</th>
                    <th style="min-width:220px;">عملیات</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($companies as $c): ?>

                    <tr style="<?= $c['status'] === 'pending' ? 'background:#fffbea;' : '' ?>">

                        <td>
                            <b><?= htmlspecialchars($c['name']) ?></b><br>
                            <span style="font-size:.7rem;color:var(--muted);">
                                ثبت: <?= $c['created_at'] ?>
                            </span>
                        </td>

                        <td>
                            <?= htmlspecialchars($c['owner_name'] ?? '—') ?><br>
                            <span dir="ltr" style="font-size:.75rem;color:var(--muted);">
                                <?= htmlspecialchars($c['owner_mobile'] ?? '') ?>
                            </span>
                        </td>

                        <td><?= (int)$c['user_count'] ?></td>

                        <td>
                            <span class="st-badge st-quoted">
                                <?= ['retail' => 'خرده', 'wholesale' => 'عمده', 'vip' => 'VIP'][$c['customer_group']] ?? 'عمده' ?>
                            </span>
                        </td>

                        <td>
                            <span class="st-badge <?= [
                                                        'pending'   => 'st-new',
                                                        'active'    => 'st-converted',
                                                        'suspended' => 'st-rejected',
                                                        'rejected'  => 'st-rejected',
                                                    ][$c['status']] ?>">
                                <?= $statusLabels[$c['status']] ?? $c['status'] ?>
                            </span>
                        </td>

                        <td>

                            <?php if ($c['status'] === 'pending'): ?>

                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="approve">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn" style="padding:6px 14px;font-size:.72rem;background:#e8f5ee;color:#1a7f4b;font-weight:800;">
                                        ✓ تأیید
                                    </button>
                                </form>

                                <form method="post" style="display:inline;" onsubmit="return confirm('این درخواست رد شود؟');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="reject">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn" style="padding:6px 14px;font-size:.72rem;background:#fff0f0;color:#c62828;">
                                        ✗ رد
                                    </button>
                                </form>

                            <?php elseif ($c['status'] === 'active'): ?>

                                <form method="post" style="display:inline;" onsubmit="return confirm('این شرکت تعلیق شود؟');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="suspend">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn" style="padding:6px 14px;font-size:.72rem;background:#fff4e0;color:#a06010;">
                                        ⏸ تعلیق
                                    </button>
                                </form>

                            <?php else: ?>

                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="approve">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn" style="padding:6px 14px;font-size:.72rem;background:var(--teal-100);color:var(--teal-800);">
                                        ↺ فعال‌سازی
                                    </button>
                                </form>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

<?php else: ?>

    <div class="empty-state">
        <h2>هنوز مشتری‌ای ثبت‌نام نکرده است</h2>
    </div>

<?php endif; ?>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
