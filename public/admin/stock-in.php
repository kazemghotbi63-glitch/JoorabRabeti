<?php
/* Auto-detect bootstrap (local + host) */
$__bp = [
    __DIR__ . '/../../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../../config/bootstrap.php',
];
foreach ($__bp as $__b) { if (is_file($__b)) { require_once $__b; break; } }
unset($__bp, $__b);
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/app/services/OrderService.php';

require_admin();

$db       = db();
$adminId  = (int)$_SESSION['admin_id'];
$flash    = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $productId = (int)($_POST['product_id'] ?? 0);
    $qty       = (int)($_POST['qty'] ?? 0);
    $reason    = trim((string)($_POST['reason'] ?? ''));

    if ($productId < 1) {
        $flashErr = 'محصول را انتخاب کنید';
    } elseif ($qty < 1) {
        $flashErr = 'مقدار باید یک عدد مثبت باشد';
    } elseif (mb_strlen($reason) < 3) {
        $flashErr = 'دلیل ورود کالا الزامی است (حداقل ۳ کاراکتر)';
    } else {
        try {
            OrderService::stockIn($db, $productId, $qty, $reason, $adminId);
            $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
                VALUES (?, 'inventory.stock_in', 'product', ?, ?)
            ")->execute([$adminId, $productId, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $flash = number_format($qty) . ' عدد به موجودی محصول اضافه شد.';
        } catch (Throwable $e) {
            $flashErr = $e->getMessage();
        }
    }
}

/* فهرست محصولات */
$products = $db->query("
    SELECT p.id, p.code, p.name,
           COALESCE(inv.qty_physical, 0) AS qty_physical,
           COALESCE(inv.qty_available, 0) AS qty_available_legacy
    FROM products p
    LEFT JOIN inventory inv ON inv.product_id = p.id
    WHERE p.is_active = 1
    ORDER BY p.name
")->fetchAll();

$activeMenu = 'inventory-stock-in';
$pageTitle  = 'ورود کالا (رسید)';

ob_start();
?>

<div class="admin-page-intro">
    <div>
        <span class="admin-kicker">عملیات</span>
        <h1>ورود کالا (رسید)</h1>
        <p>ورود کالا را با دلیل ثبت کنید. این عملیات در گردش موجودی ثبت می‌شود.</p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-success" style="background:#e8f5ee;color:#1a7f4b;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
        ✓ <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="alert alert-error" style="background:#fff0f0;color:#c62828;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
        ✗ <?= htmlspecialchars($flashErr) ?>
    </div>
<?php endif; ?>

<form method="post" style="background:#fff;border:1px solid var(--card-border);border-radius:14px;padding:20px;max-width:600px;">
    <?= csrf_field() ?>

    <div style="margin-bottom:14px;">
        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:6px;">محصول:</label>
        <select name="product_id" required
            style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:.82rem;">
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['id'] ?>">
                    <?= htmlspecialchars($p['code']) ?> — <?= htmlspecialchars($p['name']) ?>
                    (موجودی فعلی: <?= number_format((int)($p['qty_physical'] > 0 ? $p['qty_physical'] : $p['qty_available_legacy'])) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div style="margin-bottom:14px;">
        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:6px;">مقدار ورودی:</label>
        <input type="number" name="qty" required min="1"
            style="width:200px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:.82rem;">
    </div>

    <div style="margin-bottom:14px;">
        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:6px;">دلیل ورود (الزامی):</label>
        <input type="text" name="reason" required maxlength="255"
            placeholder="مثلاً: رسید از تولیدکننده‌ی X، شماره رسید #۱۲۳"
            style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:.82rem;">
    </div>

    <button type="submit"
        style="padding:10px 22px;background:var(--teal-700);color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:700;"
        onclick="return confirm('تأیید ورود کالا؟');">
        ثبت رسید
    </button>
</form>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
