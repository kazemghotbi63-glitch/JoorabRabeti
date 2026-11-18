<?php
/* Auto-detect bootstrap (local + host) */
$__bp = [
    __DIR__ . '/../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../config/bootstrap.php',
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

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$num = trim($_GET['num'] ?? '');

$buyerId = (int)($_SESSION['customer_id'] ?? $_SESSION['admin_id'] ?? 0);

$stmt = db()->prepare("
    SELECT i.invoice_number
    FROM invoices i
    JOIN orders o ON o.id = i.order_id
    WHERE o.order_number = ? AND o.user_id = ?
    LIMIT 1
");
$stmt->execute([$num, $buyerId]);
$invNumber = $stmt->fetchColumn() ?: '';

$pageTitle = 'سفارش ثبت شد | رابطی';

ob_start();
?>

<section class="page-head">
    <h1 class="section-title">✅ سفارش شما ثبت شد</h1>
    <p class="section-sub">
        شماره سفارش: <strong><?= $h($num) ?></strong>
    </p>
</section>

<div style="text-align: center; padding: 40px 20px;">

    <?php if ($invNumber): ?>
        <p style="margin-bottom: 20px; font-size: 15px;">
            فاکتور شما آماده شده است.
        </p>

        <a href="/invoice-print?inv=<?= $h($invNumber) ?>"
            target="_blank"
            class="btn btn-primary"
            style="margin-bottom: 10px; display: inline-block;">
            🧾 مشاهده / چاپ فاکتور
        </a>

        <br>
    <?php else: ?>
        <p style="margin-bottom: 20px; font-size: 15px;">
            همکاران ما در اسرع وقت با شما تماس خواهند گرفت.
        </p>
    <?php endif; ?>

    <a href="/account-orders" class="btn btn-outline" style="margin: 10px;">
        📋 سفارش‌های من
    </a>

    <a href="/products" class="btn btn-outline" style="margin: 10px;">
        🛍️ ادامه خرید
    </a>

</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
