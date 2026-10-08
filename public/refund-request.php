<?php
/* [FILE] public/refund-request.php — Customer refund request */

require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

if (!is_logged_in()) {
    header('Location: /');
    exit;
}

$db = db();
$userId = (int)($_SESSION['customer_id'] ?? $_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /account-orders');
    exit;
}

$invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));
$sheba = strtoupper(preg_replace('/\s+/', '', (string)($_POST['sheba'] ?? '')));
$holder = trim((string)($_POST['account_holder'] ?? ''));

/* اعتبارسنجی شبا — ساختاری + چک‌دیجیت ساده */
$shebaDigits = (str_starts_with($sheba, 'IR')) ? substr($sheba, 2) : $sheba;
if (!preg_match('/^\d{24}$/', $shebaDigits)) {
    header('Location: /account-orders?refund_error=shaba');
    exit;
}

$stmt = $db->prepare("
    SELECT i.id, i.total, i.paid_total
    FROM invoices i
    WHERE i.invoice_number = ? AND i.user_id = ?
    LIMIT 1
");
$stmt->execute([$invoiceNumber, $userId]);
$inv = $stmt->fetch();

if (!$inv) {
    header('Location: /account-orders');
    exit;
}

/* بستانکار بودن — computed */
$creditor = max(0, (int)$inv['paid_total'] - (int)$inv['total']);
if ($creditor <= 0) {
    header('Location: /account-orders');
    exit;
}

/* جلوگیری از درخواست تکراری pending */
$chk = $db->prepare("SELECT COUNT(*) FROM refund_requests
                     WHERE invoice_id=? AND status='pending'");
$chk->execute([(int)$inv['id']]);
if ((int)$chk->fetchColumn() > 0) {
    header('Location: /account-orders');
    exit;
}

$db->prepare("INSERT INTO refund_requests (invoice_id, user_id, amount, sheba, account_holder)
              VALUES (?,?,?,?,?)")
    ->execute([(int)$inv['id'], $userId, $creditor, 'IR' . $shebaDigits, $holder]);

$db->prepare("INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
              VALUES (?, 'refund_requested', 'invoice', ?, ?)")
    ->execute([$userId, (int)$inv['id'], $_SERVER['REMOTE_ADDR'] ?? null]);

header('Location: /account-orders?refund=ok');
exit;
