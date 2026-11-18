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
    $productId   = (int)($_POST['product_id'] ?? 0);
    $newPhysical = (int)($_POST['new_physical'] ?? -1);
    $reason      = trim((string)($_POST['reason'] ?? ''));

    if ($productId < 1) {
        $flashErr = 'محصول را انتخاب کنید';
    } elseif ($newPhysical < 0) {
        $flashErr = 'مقدار موجودی باید یک عدد صحیح مثبت یا صفر باشد';
    } elseif (mb_strlen($reason) < 5) {
        $flashErr = 'دلیل اصلاح الزامی است (حداقل ۵ کاراکتر)';
    } else {
        try {
            OrderService::adjustPhysical($db, $productId, $newPhysical, $reason, $adminId);
            $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
                VALUES (?, 'inventory.adjust', 'product', ?, ?)
            ")->execute([$adminId, $productId, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            $flash = 'موجودی اصلاح شد.';
        } catch (Throwable $e) {
            $flashErr = $e->getMessage();
        }
    }
}

/* فهرست محصولات با موجودی فعلی */
$products = $db->query("
    SELECT p.id, p.code, p.name,
           COALESCE(inv.qty_physical, 0) AS qty_physical,
           COALESCE(inv.reserved, 0)     AS qty_reserved,
           COALESCE(inv.unsellable, 0)    AS qty_unsellable,
           COALESCE(inv.qty_available, 0) AS qty_available_legacy
    FROM products p
    LEFT JOIN inventory inv ON inv.product_id = p.id
    WHERE p.is_active = 1
    ORDER BY p.name
")->fetchAll();

$activeMenu = 'inventory-stock-adjust';
$pageTitle  = 'اصلاح موجودی';

ob_start();
?>

<div class="admin-page-intro">
    <div>
        <span class="admin-kicker">عملیات</span>
        <h1>اصلاح موجودی (فیزیکی)</h1>
        <p>این فرم مقدار <b>فیزیکی</b> موجودی را با مقدار صحیح جدید جایگزین می‌کند و یک رکورد در گردش موجودی ثبت می‌کند.</p>
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
        <select name="product_id" required id="product_select"
            onchange="updateCurrentStock()"
            style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:.82rem;">
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['id'] ?>"
                    data-current="<?= (int)($p['qty_physical'] > 0 ? $p['qty_physical'] : $p['qty_available_legacy']) ?>"
                    data-reserved="<?= (int)$p['qty_reserved'] ?>"
                    data-unsellable="<?= (int)$p['qty_unsellable'] ?>">
                    <?= htmlspecialchars($p['code']) ?> — <?= htmlspecialchars($p['name']) ?>
                    (فیزیکی: <?= number_format((int)($p['qty_physical'] > 0 ? $p['qty_physical'] : $p['qty_available_legacy'])) ?>، رزرو: <?= number_format((int)$p['qty_reserved']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p style="font-size:.72rem;color:#999;margin-top:4px;">
            موجودی فعلی: <span id="current_stock">—</span>
            | رزرو شده: <span id="current_reserved">—</span>
            | قابل فروش: <span id="current_avail">—</span>
        </p>
    </div>

    <div style="margin-bottom:14px;">
        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:6px;">موجودی صحیح فیزیکی:</label>
        <input type="number" name="new_physical" required min="0"
            style="width:200px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:.82rem;">
    </div>

    <div style="margin-bottom:14px;">
        <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:6px;">دلیل اصلاح (الزامی):</label>
        <textarea name="reason" required rows="3" maxlength="500"
            placeholder="مثلاً: شمارش فیزیکی انبار در تاریخ ۱۴۰۴/۰۷/۰۱، تفاوت با مقدار ثبت‌شده در سیستم"
            style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;font-size:.82rem;"></textarea>
    </div>

    <button type="submit"
        style="padding:10px 22px;background:var(--teal-700);color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:700;"
        onclick="return confirm('تأیید اصلاح موجودی؟ این عملیات در گردش موجودی ثبت می‌شود.');">
        ثبت اصلاح
    </button>
</form>

<script>
function updateCurrentStock() {
    var sel = document.getElementById('product_select');
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        document.getElementById('current_stock').textContent = '—';
        document.getElementById('current_reserved').textContent = '—';
        document.getElementById('current_avail').textContent = '—';
        return;
    }
    var phys = parseInt(opt.dataset.current || '0', 10);
    var res = parseInt(opt.dataset.reserved || '0', 10);
    var uns = parseInt(opt.dataset.unsellable || '0', 10);
    var avail = Math.max(0, phys - res - uns);
    document.getElementById('current_stock').textContent = phys.toLocaleString('fa-IR');
    document.getElementById('current_reserved').textContent = res.toLocaleString('fa-IR');
    document.getElementById('current_avail').textContent = avail.toLocaleString('fa-IR');
}
</script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
