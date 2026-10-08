<?php
/* [FILE] public/payment.php — Customer payment page */

require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

if (!is_logged_in()) {
    header('Location: /');
    exit;
}

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$userId = (int)($_SESSION['admin_id'] ?? $_SESSION['customer_id'] ?? 0);
$db = db();

$orderNumber = trim((string)($_GET['order'] ?? ''));

if ($orderNumber === '') {
    header('Location: /');
    exit;
}

/* ─── Helpers ─── */
function moneyFa(int $amount): string
{
    return number_format(max(0, $amount)) . ' تومان';
}

function uploadReceipt(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'file' => null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'بارگذاری فایل ناموفق بود.'];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'حجم فیش حداکثر ۲ مگابایت است.'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'فایل ارسالی معتبر نیست.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'فرمت فیش باید JPG، PNG یا WEBP باشد.'];
    }

    $dir = PUBLIC_PATH . '/uploads/receipts';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'پوشه ذخیره فیش قابل ایجاد نیست.'];
    }

    $filename = 'receipt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($tmp, $dir . '/' . $filename)) {
        return ['ok' => false, 'error' => 'ذخیره فیش ناموفق بود.'];
    }
    return ['ok' => true, 'path' => '/uploads/receipts/' . $filename, 'file' => $dir . '/' . $filename];
}

function removeUploadedReceipt(?string $absolutePath): void
{
    if ($absolutePath !== null && $absolutePath !== '' && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

/* ─── Settings ─── */
$settings = [];
foreach ($db->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'payment_%'") as $s) {
    $settings[(string)$s['key']] = (string)$s['value'];
}

/* ─── Order lookup ─── */
$stmt = $db->prepare("
    SELECT o.id, o.order_number, o.user_id, o.status, o.total,
           i.id AS invoice_id, i.invoice_number,
           i.total AS invoice_total, i.paid_total, i.status AS invoice_status
    FROM orders o
    LEFT JOIN invoices i ON i.order_id = o.id
    WHERE o.order_number = ? AND o.user_id = ?
    LIMIT 1
");
$stmt->execute([$orderNumber, $userId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: /');
    exit;
}

/* ─── CSRF ─── */
if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
$csrf = csrf_token();

$success = isset($_GET['submitted']) && $_GET['submitted'] === '1';
$error = '';

/* ─── POST: ثبت درخواست پرداخت ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $error = (!hash_equals($csrf, $postedCsrf))
        ? 'درخواست نامعتبر است. صفحه را دوباره باز کنید.'
        : '';

    if ($error === '') {
        $method = trim((string)($_POST['method'] ?? ''));
        $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
        $claimedAmount = (int)($_POST['amount'] ?? 0);

        $validMethods = ['sheba', 'card_to_card', 'cash_receipt'];

        if (!in_array($method, $validMethods, true)) {
            $error = 'روش پرداخت نامعتبر است.';
        } elseif ($method === 'online') {
            $error = 'درگاه پرداخت آنلاین هنوز فعال نشده است.';
        } else {
            $upload = uploadReceipt($_FILES['receipt'] ?? []);

            if (!$upload['ok']) {
                $error = (string)$upload['error'];
            } else {
                $receiptPath = $upload['path'];
                $receiptAbs  = $upload['file'];

                try {
                    $db->beginTransaction();

                    /* قفل سفارش */
                    $lock = $db->prepare("SELECT id, total FROM orders WHERE id=? AND user_id=? FOR UPDATE");
                    $lock->execute([(int)$order['id'], $userId]);
                    $lockedOrder = $lock->fetch();
                    if (!$lockedOrder) throw new RuntimeException('order_not_found');

                    /* قفل/ساخت فاکتور */
                    $lockInv = $db->prepare("SELECT id, invoice_number, total, paid_total FROM invoices
                                             WHERE order_id=? AND user_id=? LIMIT 1 FOR UPDATE");
                    $lockInv->execute([(int)$lockedOrder['id'], $userId]);
                    $invoice = $lockInv->fetch();

                    if (!$invoice) {
                        $invNumber = null;
                        for ($i = 0; $i < 5; $i++) {
                            $candidate = 'INV-' . date('ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                            $chk = $db->prepare("SELECT id FROM invoices WHERE invoice_number=? LIMIT 1");
                            $chk->execute([$candidate]);
                            if (!$chk->fetch()) {
                                $invNumber = $candidate;
                                break;
                            }
                        }
                        if ($invNumber === null) throw new RuntimeException('inv_generation_failed');

                        $db->prepare("INSERT INTO invoices (invoice_number, order_id, user_id, total, paid_total, status) VALUES (?,?,?,?,0,'unpaid')")
                            ->execute([$invNumber, (int)$lockedOrder['id'], $userId, (int)$lockedOrder['total']]);
                        $invoiceId = (int)$db->lastInsertId();
                        $invoice = ['invoice_number' => $invNumber, 'total' => (int)$lockedOrder['total'], 'paid_total' => 0];
                    } else {
                        $invoiceId = (int)$invoice['id'];
                    }

                    /* مانده قطعی */
                    $invoiceTotal = (int)$invoice['total'];
                    $paidTotal    = (int)$invoice['paid_total'];
                    $invoiceRemaining = max(0, $invoiceTotal - $paidTotal);

                    /* pending submission موجود؟ */
                    $pend = $db->prepare("SELECT id FROM payment_submissions
                                          WHERE invoice_id=? AND user_id=? AND status='pending'
                                          LIMIT 1 FOR UPDATE");
                    $pend->execute([$invoiceId, $userId]);

                    if ($pend->fetch()) {
                        throw new RuntimeException('یک درخواست پرداخت در انتظار بررسی وجود دارد. تا تعیین تکلیف، پرداخت جدید امکان‌پذیر نیست.');
                    }

                    if ($invoiceRemaining <= 0) {
                        throw new RuntimeException('این فاکتور مبلغ قابل پرداختی ندارد.');
                    }

                    if ($claimedAmount <= 0) {
                        $error = 'مبلغ پرداخت را وارد کنید.';
                    } elseif ($claimedAmount > $invoiceRemaining) {
                        $error = 'مبلغ پرداخت نمی‌تواند بیشتر از ' . moneyFa($invoiceRemaining) . ' باشد.';
                    } elseif ($referenceNo === '' && $receiptPath === null) {
                        $error = 'شماره پیگیری یا تصویر فیش الزامی است.';
                    }

                    if ($error === '') {
                        $db->prepare("INSERT INTO payment_submissions
                            (invoice_id, user_id, method, claimed_amount, reference_no, receipt_path, status)
                            VALUES (?,?,?,?,?,?,'pending')")
                            ->execute([
                                $invoiceId,
                                $userId,
                                $method,
                                $claimedAmount,
                                $referenceNo !== '' ? $referenceNo : null,
                                $receiptPath
                            ]);

                        $subId = (int)$db->lastInsertId();

                        $db->prepare("INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
                                      VALUES (?, 'payment_submitted', 'payment_submission', ?, ?)")
                            ->execute([$userId, $subId, $_SERVER['REMOTE_ADDR'] ?? null]);

                        $db->commit();

                        header('Location: /payment?order=' . rawurlencode($orderNumber) . '&submitted=1');
                        exit;
                    }

                    $db->rollBack();
                    removeUploadedReceipt($receiptAbs);
                } catch (RuntimeException $re) {
                    if ($db->inTransaction()) $db->rollBack();
                    if (isset($receiptAbs)) removeUploadedReceipt($receiptAbs);
                    $error = $re->getMessage();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    if (isset($receiptAbs)) removeUploadedReceipt($receiptAbs);
                    $error = 'ثبت اطلاعات پرداخت انجام نشد. لطفاً دوباره تلاش کنید.';
                }
            }
        }
    }
}

/* ─── وضعیت نهایی برای نمایش ─── */
$stmt = $db->prepare("
    SELECT o.id, o.order_number, o.total,
           i.id AS invoice_id, i.invoice_number,
           i.total AS invoice_total, i.paid_total
    FROM orders o
    LEFT JOIN invoices i ON i.order_id = o.id
    WHERE o.order_number = ? AND o.user_id = ?
    LIMIT 1
");
$stmt->execute([$orderNumber, $userId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: /');
    exit;
}

$invoiceId    = (int)($order['invoice_id'] ?? 0);
$invoiceTotal = (int)($order['invoice_total'] ?? $order['total'] ?? 0);
$paidTotal    = (int)($order['paid_total'] ?? 0);
$remaining    = max(0, $invoiceTotal - $paidTotal);

$submissions = [];
if ($invoiceId > 0) {
    $stmt = $db->prepare("SELECT * FROM payment_submissions WHERE invoice_id=? AND user_id=? ORDER BY id DESC");
    $stmt->execute([$invoiceId, $userId]);
    $submissions = $stmt->fetchAll();
}

$pendingSubmission = null;
foreach ($submissions as $s) {
    if ($s['status'] === 'pending') {
        $pendingSubmission = $s;
        break;
    }
}
$hasPending = $pendingSubmission !== null;
$pendingAmount = $hasPending ? (int)$pendingSubmission['claimed_amount'] : 0;

$payableAmount = $remaining;

$pageTitle = 'پرداخت سفارش | رابطی';
ob_start();
?>

<nav class="breadcrumb">
    <a href="/">خانه</a> <span>›</span>
    <a href="/account-orders">سفارش‌های من</a> <span>›</span>
    <span>پرداخت</span>
</nav>

<section class="payment-page">

    <header class="payment-head">
        <div>
            <span class="payment-eyebrow">پرداخت سفارش</span>
            <h1>تکمیل پرداخت</h1>
            <p>سفارش <b dir="ltr"><?= $h($order['order_number']) ?></b></p>
        </div>
        <?php if (!empty($order['invoice_number'])): ?>
            <div class="payment-invoice-number">
                <span>شماره فاکتور</span>
                <b dir="ltr"><?= $h($order['invoice_number']) ?></b>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($success): ?>

        <div class="payment-result payment-result-success">
            <div class="payment-result-icon">✓</div>
            <div>
                <h2>درخواست پرداخت ثبت شد</h2>
                <p>
                    مبلغ اعلام‌شده شما ثبت شد و در انتظار بررسی مالی است.
                    پس از تأیید، اگر مانده‌ای باقی بماند دکمه «پرداخت مانده بدهی» فعال می‌شود.
                </p>
            </div>
        </div>

        <div class="payment-actions">
            <?php if (!empty($order['invoice_number'])): ?>
                <a href="/invoice-print?inv=<?= rawurlencode((string)$order['invoice_number']) ?>"
                    target="_blank" class="btn btn-outline">🧾 مشاهده / چاپ فاکتور</a>
            <?php endif; ?>
            <a href="/account-orders" class="btn btn-primary">بازگشت به سفارش‌ها</a>
        </div>

    <?php else: ?>

        <?php if ($error): ?>
            <div class="payment-alert payment-alert-error">⚠️ <?= $h($error) ?></div>
        <?php endif; ?>

        <div class="payment-summary">
            <div class="payment-summary-row">
                <span>مبلغ فاکتور</span>
                <strong><?= moneyFa($invoiceTotal) ?></strong>
            </div>
            <div class="payment-summary-row">
                <span>پرداخت تأییدشده</span>
                <strong class="payment-paid"><?= moneyFa($paidTotal) ?></strong>
            </div>
            <?php if ($pendingAmount > 0): ?>
                <div class="payment-summary-row">
                    <span>درخواست در انتظار بررسی</span>
                    <strong class="payment-pending"><?= moneyFa($pendingAmount) ?></strong>
                </div>
            <?php endif; ?>
            <div class="payment-summary-row payment-summary-total">
                <span>مبلغ قابل پرداخت</span>
                <strong><?= moneyFa($payableAmount) ?></strong>
            </div>
        </div>

        <?php if ($hasPending): ?>

            <div class="payment-result payment-result-warning">
                <div class="payment-result-icon">⏳</div>
                <div>
                    <h2>پرداخت شما در انتظار بررسی مالی است</h2>
                    <p>مبلغ اعلام‌شده: <b><?= moneyFa($pendingAmount) ?></b><br>
                        تا تعیین تکلیف، امکان ثبت پرداخت دیگری برای این فاکتور وجود ندارد.</p>
                </div>
            </div>

        <?php elseif ($payableAmount <= 0): ?>

            <div class="payment-result payment-result-success">
                <div class="payment-result-icon">✓</div>
                <div>
                    <h2>این فاکتور تسویه شده است</h2>
                    <p>مبلغ دیگری برای پرداخت وجود ندارد.</p>
                </div>
            </div>

        <?php else: ?>

            <form method="post" class="payment-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">

                <section class="payment-section">
                    <div class="payment-section-head">
                        <span class="payment-step">۱</span>
                        <div>
                            <h2>انتخاب روش پرداخت</h2>
                            <p>مبلغ دقیق قابل پرداخت: <b><?= moneyFa($payableAmount) ?></b></p>
                        </div>
                    </div>

                    <div class="payment-method-grid">
                        <label class="payment-method-card">
                            <input type="radio" name="method" value="sheba" required>
                            <span class="payment-method-icon">🏦</span>
                            <span class="payment-method-content">
                                <strong>انتقال با شبا</strong>
                                <small>واریز بانکی به شماره شبا</small>
                            </span>
                            <span class="payment-method-check">✓</span>
                        </label>

                        <label class="payment-method-card">
                            <input type="radio" name="method" value="card_to_card">
                            <span class="payment-method-icon">💳</span>
                            <span class="payment-method-content">
                                <strong>کارت به کارت</strong>
                                <small>انتقال مستقیم کارت به کارت</small>
                            </span>
                            <span class="payment-method-check">✓</span>
                        </label>

                        <label class="payment-method-card">
                            <input type="radio" name="method" value="cash_receipt">
                            <span class="payment-method-icon">🧾</span>
                            <span class="payment-method-content">
                                <strong>فیش نقدی</strong>
                                <small>واریز نقدی و ارسال فیش</small>
                            </span>
                            <span class="payment-method-check">✓</span>
                        </label>

                        <label class="payment-method-card payment-method-card-online">
                            <input type="radio" name="method" value="online" disabled>
                            <span class="payment-method-icon">🔒</span>
                            <span class="payment-method-content">
                                <strong>درگاه پرداخت آنلاین</strong>
                                <small>به‌زودی</small>
                            </span>
                        </label>
                    </div>
                </section>

                <section class="payment-section payment-bank-info" id="bankInfo" hidden>
                    <div class="payment-section-head">
                        <span class="payment-step">۲</span>
                        <div>
                            <h2>اطلاعات واریز</h2>
                        </div>
                    </div>

                    <div class="bank-detail" id="shebaBox" hidden>
                        <span>شماره شبا</span>
                        <div class="bank-value-row">
                            <code dir="ltr"><?= $h($settings['payment_sheba'] ?? '-') ?></code>
                            <button type="button" class="btn-copy" data-copy="<?= $h($settings['payment_sheba'] ?? '') ?>">کپی</button>
                        </div>
                        <small>به نام <?= $h($settings['payment_card_name'] ?? '') ?></small>
                    </div>

                    <div class="bank-detail" id="cardBox" hidden>
                        <span>شماره کارت</span>
                        <div class="bank-value-row">
                            <code dir="ltr"><?= $h($settings['payment_card'] ?? '-') ?></code>
                            <button type="button" class="btn-copy" data-copy="<?= $h($settings['payment_card'] ?? '') ?>">کپی</button>
                        </div>
                        <small>به نام <?= $h($settings['payment_card_name'] ?? '') ?></small>
                    </div>
                </section>

                <section class="payment-section" id="amountSection" hidden>
                    <div class="payment-section-head">
                        <span class="payment-step">۳</span>
                        <div>
                            <h2>مبلغ واریزی</h2>
                            <p>مبلغی که واقعاً واریز کرده‌اید را وارد کنید.</p>
                        </div>
                    </div>

                    <div class="payment-amount-box">
                        <label for="paymentAmount">مبلغ پرداخت</label>
                        <div class="payment-amount-input">
                            <input type="number" id="paymentAmount" name="amount"
                                min="1" max="<?= (int)$payableAmount ?>" step="1"
                                inputmode="numeric" placeholder="<?= (int)$payableAmount ?>">
                            <span>تومان</span>
                        </div>
                        <small>حداکثر قابل پرداخت: <b><?= moneyFa($payableAmount) ?></b></small>
                    </div>
                </section>

                <section class="payment-section" id="proofSection" hidden>
                    <div class="payment-section-head">
                        <span class="payment-step">۴</span>
                        <div>
                            <h2>اثبات واریز</h2>
                            <p>شماره پیگیری یا تصویر فیش را ثبت کنید.</p>
                        </div>
                    </div>

                    <div class="payment-proof-grid">
                        <div class="payment-field">
                            <label for="referenceNo">شماره پیگیری</label>
                            <input type="text" id="referenceNo" name="reference_no"
                                dir="ltr" maxlength="100" placeholder="مثلاً 840512">
                        </div>
                        <div class="payment-field">
                            <label for="receipt">تصویر فیش</label>
                            <input type="file" id="receipt" name="receipt"
                                accept="image/jpeg,image/png,image/webp">
                            <small>JPG / PNG / WEBP — حداکثر ۲MB</small>
                        </div>
                    </div>
                    <div class="payment-proof-note">حداقل یکی از «شماره پیگیری» یا «تصویر فیش» الزامی است.</div>
                </section>

                <div class="payment-submit-row">
                    <button type="submit" class="btn btn-primary payment-submit" id="paymentSubmit" disabled>
                        ثبت درخواست پرداخت
                    </button>
                </div>

            </form>

        <?php endif; ?>

    <?php endif; ?>

</section>

<script>
    (function() {
        const methods = document.querySelectorAll('input[name="method"]');
        const bankInfo = document.getElementById('bankInfo');
        const shebaBox = document.getElementById('shebaBox');
        const cardBox = document.getElementById('cardBox');
        const amountSection = document.getElementById('amountSection');
        const proofSection = document.getElementById('proofSection');
        const amountInput = document.getElementById('paymentAmount');
        const referenceInput = document.getElementById('referenceNo');
        const receiptInput = document.getElementById('receipt');
        const submitButton = document.getElementById('paymentSubmit');

        function validateForm() {
            if (!submitButton) return;
            const selected = document.querySelector('input[name="method"]:checked');
            if (!selected) {
                submitButton.disabled = true;
                return;
            }

            const amount = Number(amountInput?.value || 0);
            const max = Number(amountInput?.max || 0);
            const ref = (referenceInput?.value || '').trim();
            const hasReceipt = !!(receiptInput?.files?.length > 0);

            submitButton.disabled = !(amount > 0 && amount <= max && (ref !== '' || hasReceipt));
        }

        function updatePaymentForm() {
            const selected = document.querySelector('input[name="method"]:checked');
            if (!selected) return;

            if (bankInfo) bankInfo.hidden = false;
            if (shebaBox) shebaBox.hidden = selected.value !== 'sheba';
            if (cardBox) cardBox.hidden = selected.value !== 'card_to_card';
            if (amountSection) amountSection.hidden = false;
            if (proofSection) proofSection.hidden = false;

            if (amountInput && !amountInput.value) amountInput.value = '<?= (int)$payableAmount ?>';
            validateForm();
        }

        methods.forEach(m => m.addEventListener('change', updatePaymentForm));
        [amountInput, referenceInput].forEach(i => i?.addEventListener('input', validateForm));
        receiptInput?.addEventListener('change', validateForm);
    })();
</script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
