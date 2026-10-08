<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = trim($_GET['num'] ?? '');
$stmt = db()->prepare("SELECT invoice_number FROM invoices WHERE order_id = (SELECT id FROM orders WHERE order_number = ?) LIMIT 1");
$stmt->execute([$num]);
$invNumber = $stmt->fetchColumn() ?: '';
<?php if ($invNumber): ?>
<a href="/invoice-print?inv=<?= $h($invNumber) ?>" target="_blank" class="btn btn-outline" style="margin-bottom:10px;display:inline-block;">
    🧾 مشاهده / چاپ فاکتور
</a>
<?php endif; ?>
$pageTitle = 'سفارش ثبت شد | رابطی';
ob_start();
?>

<div class="empty-state">
    <div class="empty-icon">🎉</div>
    <h2>سفارش شما با موفقیت ثبت شد</h2>
    <p class="catalog-sub" style="margin-bottom:6px;">شماره سفارش شما:</p>
    <p style="font-size:1.3rem;font-weight:800;color:var(--brand);direction:ltr;" dir="ltr"><?= $h($num) ?></p>
    <p class="catalog-sub" style="margin:16px auto 22px;max-width:420px;line-height:2;">
        موجودی انبار برای شما رزرو شد. کارشناس فروش برای هماهنگی ارسال و پرداخت تماس می‌گیرد.
    </p>
    <a href="/invoice-print?inv=<?= $h($invNum ?? '') ?>" target="_blank" class="btn btn-outline" style="margin-bottom:10px;">
        🧾 مشاهده / چاپ فاکتور
    </a>
    <a href="/products" class="btn btn-primary">ادامه خرید</a>
</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
