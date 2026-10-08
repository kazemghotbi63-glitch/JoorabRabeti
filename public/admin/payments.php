<?php

declare(strict_types=1);

/* [FILE] public/admin/payments.php — Financial Center (ادمین) */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/config/settings.php';
require_once ROOT_PATH . '/app/services/SmsService.php';
require_once ROOT_PATH . '/app/services/OrderSmsService.php';

require_admin();

$db = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);

/* ═══════════ Helpers ═══════════ */

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$money = static fn($v): string => number_format(max(0, (int)$v)) . ' تومان';

$toEn = static function (string $raw): int {
    $clean = trim(str_replace([',', '٬', ' '], '', strtr($raw, [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
    ])));

    return filter_var(
        $clean,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    ) ?: 0;
};

$jalali = static function (?string $mysqlDate, bool $withTime = false): string {
    if (!$mysqlDate) {
        return '—';
    }

    $ts = strtotime($mysqlDate);

    if ($ts === false) {
        return '—';
    }

    $gy = (int)date('Y', $ts);
    $gm = (int)date('n', $ts);
    $gd = (int)date('j', $ts);

    $g = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

    $jy = ($gy <= 1600) ? 0 : 979;

    $gy -= ($gy <= 1600) ? 621 : 1600;

    $gy2 = ($gm > 2) ? $gy + 1 : $gy;

    $d =
        (365 * $gy)
        + intdiv($gy2 + 3, 4)
        - intdiv($gy2 + 99, 100)
        + intdiv($gy2 + 399, 400)
        - 80
        + $gd
        + $g[$gm - 1];

    $jy += 33 * intdiv($d, 12053);
    $d %= 12053;

    $jy += 4 * intdiv($d, 1461);
    $d %= 1461;

    if ($d > 365) {
        $jy += intdiv($d - 1, 365);
        $d = ($d - 1) % 365;
    }

    $jm = ($d < 186)
        ? 1 + intdiv($d, 31)
        : 7 + intdiv($d - 186, 30);

    $jd = 1 + (
        ($d < 186)
        ? $d % 31
        : ($d - 186) % 30
    );

    $out =
        $jy . '/'
        . str_pad((string)$jm, 2, '0', STR_PAD_LEFT) . '/'
        . str_pad((string)$jd, 2, '0', STR_PAD_LEFT);

    return $withTime
        ? $out . ' — ' . date('H:i', $ts)
        : $out;
};

$subStatusLabel = static fn(string $s): string => match ($s) {
    'pending' => 'در انتظار بررسی',
    'verified' => 'تأیید شد',
    'adjusted' => 'اصلاح شد',
    'rejected' => 'رد شد',
    default => $s,
};

$subStatusClass = static fn(string $s): string => match ($s) {
    'pending' => 'warning',
    'verified' => 'success',
    'adjusted' => 'info',
    'rejected' => 'danger',
    default => 'muted',
};

$methodLabel = static fn(string $m): string => match ($m) {
    'sheba' => 'شبا',
    'card_to_card' => 'کارت به کارت',
    'cash_receipt' => 'فیش نقدی',
    'online' => 'درگاه',
    default => $m,
};

$invStatusLabel = static fn(string $s): string => match ($s) {
    'unpaid' => 'پرداخت نشده',
    'partial' => 'پرداخت ناقص',
    'paid' => 'پرداخت کامل',
    'void' => 'باطل',
    default => $s,
};

/* ═══ ارسال SMS تأیید سفارش — فقط بعد از COMMIT ═══ */

$sendOrderApprovedSms = static function (array $fin): void {
    if (
        empty($fin['order_transitioned_to_processing'])
        || empty($fin['order_id'])
    ) {
        return;
    }

    try {
        OrderSmsService::orderApproved(
            (int)$fin['order_id']
        );
    } catch (Throwable $e) {
        /*
         * SMS نباید باعث rollback عملیات مالی شود.
         * سفارش و پرداخت قبلاً COMMIT شده‌اند.
         */
        error_log(
            'ORDER APPROVED SMS FAILED: '
                . $e->getMessage()
        );
    }
};

/* ═══ نتیجه مالی — COMPUTED از حقیقت (total / paid_total) ═══ */

$reconcile = static function (int $invoiceId) use ($db): array {

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(v.verified_amount), 0)
        FROM payment_verifications v
        INNER JOIN payment_submissions ps
            ON ps.id = v.payment_submission_id
        WHERE ps.invoice_id = ?
          AND v.status IN ('verified','adjusted')
    ");

    $stmt->execute([$invoiceId]);

    $paid = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT total
        FROM invoices
        WHERE id = ?
    ");

    $stmt->execute([$invoiceId]);

    $total = (int)$stmt->fetchColumn();

    /* ⭐ تسویه‌های بستانکاری انجام‌شده کم می‌شوند —
       پولی که برگشته دیگر «دریافت‌شده» نیست */

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM financial_transactions
        WHERE invoice_id = ?
          AND type = 'creditor_settlement'
          AND status = 'completed'
    ");

    $stmt->execute([$invoiceId]);

    $settledRefunds = (int)$stmt->fetchColumn();

    $effectivePaid = $paid - $settledRefunds;

    /* پولی که نزد شرکت مانده */
    $remaining = $total - $effectivePaid;

    if ($remaining > 0) {
        $type = 'debtor';
        $amount = $remaining;
    } elseif ($remaining < 0) {
        $type = 'creditor';
        $amount = abs($remaining);
    } else {
        $type = 'settled';
        $amount = 0;
    }

    $status = ($effectivePaid >= $total && $effectivePaid > 0)
        ? 'paid'
        : (($effectivePaid > 0) ? 'partial' : 'unpaid');

    $db->prepare("
        UPDATE invoices
        SET
            paid_total = ?,
            status = ?,
            reconciliation_type = ?,
            reconciliation_amount = ?
        WHERE id = ?
    ")->execute([
        $effectivePaid,
        $status,
        $type,
        $amount,
        $invoiceId
    ]);

    /*
     * شناسه سفارش مرتبط با فاکتور
     */
    $orderId = null;

    $stmt = $db->prepare("
        SELECT order_id
        FROM invoices
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$invoiceId]);

    $orderIdValue = $stmt->fetchColumn();

    if (
        $orderIdValue !== false
        && $orderIdValue !== null
    ) {
        $orderId = (int)$orderIdValue;
    }

    /*
     * فقط Transition واقعی:
     *
     * pending_review → processing
     *
     * rowCount() مشخص می‌کند که آیا واقعاً
     * وضعیت سفارش تغییر کرده است یا خیر.
     */
    $orderTransitionedToProcessing = false;

    if (
        $status === 'paid'
        && $orderId !== null
    ) {
        $stmt = $db->prepare("
            UPDATE orders
            SET status = 'processing'
            WHERE id = ?
              AND status = 'pending_review'
        ");

        $stmt->execute([$orderId]);

        $orderTransitionedToProcessing =
            ($stmt->rowCount() === 1);
    }

    return [
        'paid' => $effectivePaid,
        'total' => $total,
        'remaining' => $remaining,
        'type' => $type,
        'amount' => $amount,
        'status' => $status,
        'order_id' => $orderId,
        'order_transitioned_to_processing' =>
        $orderTransitionedToProcessing,
    ];
};

/* ═══ CSRF ═══ */

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] =
                bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

$csrf = csrf_token();

/* ═══════════ POST ═══════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals(
        $csrf,
        (string)($_POST['csrf'] ?? '')
    )) {
        header('Location: /admin/payments');
        exit;
    }

    $action = trim(
        (string)($_POST['action'] ?? '')
    );

    /* ═══ تأیید با مبلغ واقعی ═══ */

    if ($action === 'verify_payment') {

        $subId = (int)(
            $_POST['submission_id'] ?? 0
        );

        $actual = $toEn(
            (string)($_POST['actual_amount'] ?? '')
        );

        $note = trim(
            (string)($_POST['note'] ?? '')
        );

        try {

            $db->beginTransaction();

            $stmt = $db->prepare("
                SELECT
                    ps.*,
                    i.total AS invoice_total,
                    i.invoice_number
                FROM payment_submissions ps
                INNER JOIN invoices i
                    ON i.id = ps.invoice_id
                WHERE ps.id = ?
                FOR UPDATE
            ");

            $stmt->execute([$subId]);

            $sub = $stmt->fetch();

            if (!$sub) {
                throw new RuntimeException(
                    'پرداخت پیدا نشد.'
                );
            }

            if ($sub['status'] !== 'pending') {
                throw new RuntimeException(
                    'این پرداخت قبلاً بررسی شده است.'
                );
            }

            if ($actual <= 0) {
                throw new RuntimeException(
                    'مبلغ واقعی باید بیشتر از صفر باشد.'
                );
            }

            $db->prepare("
                INSERT INTO payment_verifications
                (
                    payment_submission_id,
                    verified_amount,
                    status,
                    note,
                    verified_by,
                    verified_at
                )
                VALUES (?,?,?,?,?,NOW())
            ")->execute([
                $subId,
                $actual,
                'verified',
                $note !== '' ? $note : null,
                $adminId
            ]);

            $db->prepare("
                UPDATE payment_submissions
                SET
                    status = 'verified',
                    updated_at = NOW()
                WHERE id = ?
                  AND status = 'pending'
            ")->execute([$subId]);

            /*
             * نتیجه مالی و Transition سفارش
             */
            $fin = $reconcile(
                (int)$sub['invoice_id']
            );

            /* تاریخچه — با ستون‌های واقعی جدول */
            $db->prepare("
                INSERT INTO payment_verification_history
                (
                    payment_submission_id,
                    invoice_id,
                    old_verified_amount,
                    new_verified_amount,
                    old_status,
                    new_status,
                    reason,
                    changed_by,
                    ip
                )
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $subId,
                (int)$sub['invoice_id'],
                0,
                $actual,
                'pending',
                'verified',
                ($note !== ''
                    ? $note . ' | '
                    : ''
                )
                    . 'ادعای مشتری: '
                    . number_format(
                        (int)$sub['claimed_amount']
                    ),
                $adminId,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            /*
             * اول تراکنش مالی کاملاً Commit می‌شود.
             */
            $db->commit();

            /*
             * سپس SMS ارسال می‌شود.
             * خطای SMS عملیات مالی را خراب نمی‌کند.
             */
            $sendOrderApprovedSms($fin);

            $msg =
                'تأیید شد — مبلغ واقعی: '
                . $money($actual);

            if (
                (int)$sub['claimed_amount']
                !== $actual
            ) {
                $msg .=
                    ' | ادعای مشتری: '
                    . $money(
                        (int)$sub['claimed_amount']
                    );
            }

            if ($fin['remaining'] > 0) {
                $msg .=
                    ' | بدهکار خودکار: '
                    . $money($fin['remaining']);
            } elseif ($fin['remaining'] < 0) {
                $msg .=
                    ' | بستانکار: '
                    . $money(
                        abs($fin['remaining'])
                    );
            }

            header(
                'Location: /admin/payments?success='
                    . urlencode($msg)
            );

            exit;
        } catch (Throwable $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            header(
                'Location: /admin/payments?error='
                    . urlencode($e->getMessage())
            );

            exit;
        }
    }

    /* ═══ اصلاح مبلغ تأییدشده ═══ */

    if ($action === 'adjust_payment') {

        $subId = (int)(
            $_POST['submission_id'] ?? 0
        );

        $newAmt = $toEn(
            (string)($_POST['actual_amount'] ?? '')
        );

        $reason = trim(
            (string)($_POST['reason'] ?? '')
        );

        try {

            $db->beginTransaction();

            $stmt = $db->prepare("
                SELECT
                    ps.*,
                    v.id AS vid,
                    v.verified_amount
                FROM payment_submissions ps
                INNER JOIN payment_verifications v
                    ON v.payment_submission_id = ps.id
                WHERE ps.id = ?
                FOR UPDATE
            ");

            $stmt->execute([$subId]);

            $sub = $stmt->fetch();

            if (!$sub) {
                throw new RuntimeException(
                    'پرداخت پیدا نشد.'
                );
            }

            if (
                !in_array(
                    $sub['status'],
                    ['verified', 'adjusted'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'فقط پرداخت تأییدشده قابل اصلاح است.'
                );
            }

            if ($newAmt <= 0) {
                throw new RuntimeException(
                    'مبلغ نامعتبر است.'
                );
            }

            if ($reason === '') {
                throw new RuntimeException(
                    'ثبت دلیل الزامی است.'
                );
            }

            if (
                (int)$sub['verified_amount']
                === $newAmt
            ) {
                throw new RuntimeException(
                    'مبلغ جدید تفاوتی ندارد.'
                );
            }

            $oldAmount =
                (int)$sub['verified_amount'];

            $db->prepare("
                UPDATE payment_verifications
                SET
                    verified_amount = ?,
                    status = 'adjusted',
                    note = ?,
                    verified_by = ?,
                    verified_at = NOW()
                WHERE id = ?
            ")->execute([
                $newAmt,
                $reason,
                $adminId,
                (int)$sub['vid']
            ]);

            /*
             * بازمحاسبه مالی + تشخیص Transition
             */
            $fin = $reconcile(
                (int)$sub['invoice_id']
            );

            $db->prepare("
                INSERT INTO payment_verification_history
                (
                    payment_submission_id,
                    invoice_id,
                    old_verified_amount,
                    new_verified_amount,
                    old_status,
                    new_status,
                    reason,
                    changed_by,
                    ip
                )
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $subId,
                (int)$sub['invoice_id'],
                $oldAmount,
                $newAmt,
                $sub['status'],
                'adjusted',
                $reason,
                $adminId,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            /*
             * اول Commit
             */
            $db->commit();

            /*
             * سپس SMS فقط اگر همین عملیات باعث
             * pending_review → processing شده باشد.
             */
            $sendOrderApprovedSms($fin);

            header(
                'Location: /admin/payments?success='
                    . urlencode(
                        'اصلاح شد: '
                            . $money($oldAmount)
                            . ' ← '
                            . $money($newAmt)
                            . ' | نتیجه فعلی: '
                            . $fin['type']
                            . (
                                $fin['amount'] > 0
                                ? ' ' . $money($fin['amount'])
                                : ''
                            )
                    )
            );

            exit;
        } catch (Throwable $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            header(
                'Location: /admin/payments?error='
                    . urlencode($e->getMessage())
            );

            exit;
        }
    }

    /* ═══ رد پرداخت ═══ */

    if ($action === 'reject_payment') {

        $subId = (int)(
            $_POST['submission_id'] ?? 0
        );

        $reason = trim(
            (string)($_POST['reason'] ?? '')
        );

        try {

            $db->beginTransaction();

            $stmt = $db->prepare("
                SELECT
                    ps.*,
                    i.invoice_number
                FROM payment_submissions ps
                INNER JOIN invoices i
                    ON i.id = ps.invoice_id
                WHERE ps.id = ?
                FOR UPDATE
            ");

            $stmt->execute([$subId]);

            $sub = $stmt->fetch();

            if (!$sub) {
                throw new RuntimeException(
                    'پرداخت پیدا نشد.'
                );
            }

            if ($sub['status'] !== 'pending') {
                throw new RuntimeException(
                    'قبلاً بررسی شده است.'
                );
            }

            if ($reason === '') {
                throw new RuntimeException(
                    'دلیل رد الزامی است.'
                );
            }

            $db->prepare("
                INSERT INTO payment_verifications
                (
                    payment_submission_id,
                    verified_amount,
                    status,
                    note,
                    verified_by,
                    verified_at
                )
                VALUES (?,NULL,'rejected',?,?,NOW())
            ")->execute([
                $subId,
                $reason,
                $adminId
            ]);

            $db->prepare("
                UPDATE payment_submissions
                SET
                    status = 'rejected',
                    updated_at = NOW()
                WHERE id = ?
                  AND status = 'pending'
            ")->execute([$subId]);

            $reconcile(
                (int)$sub['invoice_id']
            );

            $db->prepare("
                INSERT INTO payment_verification_history
                (
                    payment_submission_id,
                    invoice_id,
                    old_verified_amount,
                    new_verified_amount,
                    old_status,
                    new_status,
                    reason,
                    changed_by,
                    ip
                )
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $subId,
                (int)$sub['invoice_id'],
                0,
                0,
                'pending',
                'rejected',
                $reason,
                $adminId,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $db->commit();

            header(
                'Location: /admin/payments?success='
                    . urlencode('پرداخت رد شد.')
            );

            exit;
        } catch (Throwable $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            header(
                'Location: /admin/payments?error='
                    . urlencode($e->getMessage())
            );

            exit;
        }
    }

    /* ═══ تسویه بستانکاری + علامت‌گذاری درخواست بازگشت وجه ═══ */

    if ($action === 'settle_creditor') {

        $invoiceId = (int)(
            $_POST['invoice_id'] ?? 0
        );

        $amount = $toEn(
            (string)($_POST['amount'] ?? '')
        );

        $method = trim(
            (string)($_POST['method'] ?? '')
        );

        $docNo = trim(
            (string)($_POST['document_no'] ?? '')
        );

        $refNo = trim(
            (string)($_POST['reference_no'] ?? '')
        );

        try {

            $db->beginTransaction();

            $stmt = $db->prepare("
                SELECT *
                FROM invoices
                WHERE id = ?
                FOR UPDATE
            ");

            $stmt->execute([$invoiceId]);

            $invoice = $stmt->fetch();

            if (!$invoice) {
                throw new RuntimeException(
                    'فاکتور پیدا نشد.'
                );
            }

            /* اگر قبلاً تسویه شده → فقط اصلاح و اطلاع */
            $preFin = $reconcile($invoiceId);

            if ($preFin['type'] !== 'creditor') {

                $db->commit();

                header(
                    'Location: /admin/payments?success='
                        . urlencode(
                            'این فاکتور قبلاً تسویه شده است.'
                        )
                );

                exit;
            }

            $fin = $reconcile($invoiceId);

            if ($fin['type'] !== 'creditor') {
                throw new RuntimeException(
                    'این فاکتور بستانکار نیست.'
                );
            }

            if ($amount <= 0) {
                throw new RuntimeException(
                    'مبلغ تسویه باید بیشتر از صفر باشد.'
                );
            }

            if ($amount > $fin['amount']) {
                throw new RuntimeException(
                    'مبلغ تسویه بیشتر از بستانکاری فعلی است.'
                );
            }

            $allowedSettlementMethods = [
                'sheba',
                'card_to_card',
                'cash_receipt',
                'online'
            ];

            if (!in_array(
                $method,
                $allowedSettlementMethods,
                true
            )) {
                throw new RuntimeException(
                    'روش تسویه نامعتبر است.'
                );
            }

            if (
                $docNo === ''
                && $refNo === ''
            ) {
                throw new RuntimeException(
                    'ثبت شماره سند یا شماره پیگیری برای تسویه الزامی است.'
                );
            }

            $db->prepare("
                INSERT INTO financial_transactions
                (
                    invoice_id,
                    user_id,
                    type,
                    amount,
                    method,
                    document_no,
                    reference_no,
                    status,
                    created_by,
                    completed_at
                )
                VALUES (
                    ?,
                    ?,
                    'creditor_settlement',
                    ?,
                    ?,
                    ?,
                    ?,
                    'completed',
                    ?,
                    NOW()
                )
            ")->execute([
                $invoiceId,
                (int)$invoice['user_id'],
                $amount,
                $method,
                $docNo ?: null,
                $refNo ?: null,
                $adminId
            ]);

            $txnId = (int)$db->lastInsertId();

            /* ⭐ بازمحاسبه بعد از تسویه — با کم‌شدن وجه برگشتی */
            $fin = $reconcile($invoiceId);

            /* درخواست بازگشت وجه مشتری → paid */
            $stmt = $db->prepare("
                SELECT id
                FROM refund_requests
                WHERE invoice_id = ?
                  AND status = 'pending'
                ORDER BY id DESC
                LIMIT 1
            ");

            $stmt->execute([$invoiceId]);

            if ($refundRow = $stmt->fetch()) {

                $db->prepare("
                    UPDATE refund_requests
                    SET
                        status = 'paid',
                        paid_at = NOW(),
                        admin_note = ?
                    WHERE id = ?
                ")->execute([
                    'سند تسویه #' . $txnId
                        . (
                            $refNo !== ''
                            ? ' | پیگیری: ' . $refNo
                            : ''
                        ),
                    (int)$refundRow['id']
                ]);
            }

            $db->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    action,
                    entity,
                    entity_id,
                    ip
                )
                VALUES (
                    ?,
                    'creditor_settled',
                    'invoice',
                    ?,
                    ?
                )
            ")->execute([
                $adminId,
                $invoiceId,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $db->commit();

            /*
             * $fin بعد از reconcile دوم است —
             * مانده بعد از تسویه را از قبل دارد.
             */
            header(
                'Location: /admin/payments?success='
                    . urlencode(
                        $fin['amount'] === 0
                            ? 'بستانکاری کاملاً تسویه شد.'
                            : 'تسویه ثبت شد. مانده بستانکاری: '
                            . $money($fin['amount'])
                    )
            );

            exit;
        } catch (Throwable $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            header(
                'Location: /admin/payments?error='
                    . urlencode($e->getMessage())
            );

            exit;
        }
    }

    header('Location: /admin/payments');
    exit;
}

/* ═══════════ فیلترها — شامل بدهکار/بستانکار/تسویه ═══════════ */

$filter = trim(
    (string)($_GET['filter'] ?? 'all')
);

$allowedFilters = [
    'all',
    'pending',
    'verified',
    'rejected',
    'debtor',
    'creditor',
    'settled'
];

if (!in_array(
    $filter,
    $allowedFilters,
    true
)) {
    $filter = 'all';
}

$search = trim(
    (string)($_GET['q'] ?? '')
);

/* ═══════════ آمار ═══════════ */

$stats = [
    'pending' => (int)$db->query("
        SELECT COUNT(*)
        FROM payment_submissions
        WHERE status='pending'
    ")->fetchColumn(),

    'verified' => (int)$db->query("
        SELECT COUNT(*)
        FROM payment_submissions
        WHERE status='verified'
    ")->fetchColumn(),

    'rejected' => (int)$db->query("
        SELECT COUNT(*)
        FROM payment_submissions
        WHERE status='rejected'
    ")->fetchColumn(),

    'debtor' => (int)$db->query("
        SELECT COUNT(*)
        FROM invoices
        WHERE reconciliation_type='debtor'
          AND reconciliation_amount>0
    ")->fetchColumn(),

    'creditor' => (int)$db->query("
        SELECT COUNT(*)
        FROM invoices
        WHERE reconciliation_type='creditor'
          AND reconciliation_amount>0
    ")->fetchColumn(),

    'settled' => (int)$db->query("
        SELECT COUNT(*)
        FROM invoices
        WHERE reconciliation_type='settled'
    ")->fetchColumn(),
];

/* ═══════════ لیست فاکتورها — یک ردیف برای هر فاکتور ═══════════ */

$where = [];
$params = [];

switch ($filter) {

    case 'pending':
        $where[] = "
            EXISTS (
                SELECT 1
                FROM payment_submissions f
                WHERE f.invoice_id=i.id
                  AND f.status='pending'
            )
        ";
        break;

    case 'verified':
        $where[] = "
            EXISTS (
                SELECT 1
                FROM payment_submissions f
                WHERE f.invoice_id=i.id
                  AND f.status='verified'
            )
        ";
        break;

    case 'rejected':
        $where[] = "
            EXISTS (
                SELECT 1
                FROM payment_submissions f
                WHERE f.invoice_id=i.id
                  AND f.status='rejected'
            )
        ";
        break;

    case 'debtor':
        $where[] = "
            i.reconciliation_type='debtor'
            AND i.reconciliation_amount>0
        ";
        break;

    case 'creditor':
        $where[] = "
            i.reconciliation_type='creditor'
            AND i.reconciliation_amount>0
        ";
        break;

    case 'settled':
        $where[] = "
            i.reconciliation_type='settled'
        ";
        break;
}

if ($search !== '') {

    $where[] = "
        (
            i.invoice_number LIKE ?
            OR o.order_number LIKE ?
            OR u.full_name LIKE ?
            OR u.mobile LIKE ?
            OR u.company_name LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    array_push(
        $params,
        $like,
        $like,
        $like,
        $like,
        $like
    );
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$stmt = $db->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.total,
        i.paid_total,
        i.status AS invoice_status,
        i.reconciliation_type,
        i.reconciliation_amount,
        i.issued_at,
        o.order_number,
        o.status AS order_status,
        u.full_name,
        u.mobile,
        u.company_name,

        (
            SELECT COALESCE(SUM(ps.claimed_amount),0)
            FROM payment_submissions ps
            WHERE ps.invoice_id=i.id
              AND ps.status='pending'
        ) AS pending_amount,

        (
            SELECT COUNT(*)
            FROM payment_submissions ps
            WHERE ps.invoice_id=i.id
        ) AS submission_count

    FROM invoices i

    INNER JOIN orders o
        ON o.id = i.order_id

    INNER JOIN users u
        ON u.id = i.user_id

    {$whereSql}

    ORDER BY
        (i.reconciliation_type='debtor') DESC,

        (
            EXISTS (
                SELECT 1
                FROM payment_submissions p2
                WHERE p2.invoice_id=i.id
                  AND p2.status='pending'
            )
        ) DESC,

        i.issued_at DESC

    LIMIT 100
");

$stmt->execute($params);

$invoices = $stmt->fetchAll();

$success = trim(
    (string)($_GET['success'] ?? '')
);

$error = trim(
    (string)($_GET['error'] ?? '')
);

$recLabels = [
    'debtor' => 'بدهکار',
    'creditor' => 'بستانکار',
    'settled' => 'تسویه'
];

$recClasses = [
    'debtor' => 'danger',
    'creditor' => 'info',
    'settled' => 'success'
];

$pageTitle = 'مرکز مالی';
$activeMenu = 'payments';

ob_start();
?>

<div class="finc">

    <?php if ($success): ?>
        <div class="finc-alert ok">
            ✅ <?= $h($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="finc-alert err">
            ⚠️ <?= $h($error) ?>
        </div>
    <?php endif; ?>

    <!-- ═══ آمار — شامل بدهکار/بستانکار/تسویه ═══ -->

    <div class="finc-stats">

        <div class="finc-stat">
            <span>در انتظار بررسی</span>
            <strong><?= number_format($stats['pending']) ?></strong>
        </div>

        <div class="finc-stat">
            <span>تأییدشده</span>
            <strong><?= number_format($stats['verified']) ?></strong>
        </div>

        <div class="finc-stat">
            <span>ردشده</span>
            <strong><?= number_format($stats['rejected']) ?></strong>
        </div>

        <div class="finc-stat hot">
            <span>بدهکار</span>
            <strong><?= number_format($stats['debtor']) ?></strong>
        </div>

        <div class="finc-stat">
            <span>بستانکار</span>
            <strong><?= number_format($stats['creditor']) ?></strong>
        </div>

        <div class="finc-stat">
            <span>تسویه</span>
            <strong><?= number_format($stats['settled']) ?></strong>
        </div>

    </div>

    <!-- ═══ فیلتر — هفت حالت کامل ═══ -->

    <div class="finc-toolbar">

        <?php

        $filters = [
            'all'      => 'همه',
            'pending'  => 'در انتظار بررسی',
            'verified' => 'تأییدشده',
            'rejected' => 'ردشده',
            'debtor'   => 'بدهکار',
            'creditor' => 'بستانکار',
            'settled'  => 'تسویه',
        ];

        foreach ($filters as $k => $lbl):

            $url = '/admin/payments'
                . ($k !== 'all'
                    ? '?filter=' . $k
                    : '');

        ?>

            <a
                href="<?= $url ?>"
                class="finc-filter <?= $filter === $k ? 'on' : '' ?>">
                <?= $lbl ?>
            </a>

        <?php endforeach; ?>

        <form
            method="get"
            class="finc-search">

            <?php if ($filter !== 'all'): ?>

                <input
                    type="hidden"
                    name="filter"
                    value="<?= $h($filter) ?>">

            <?php endif; ?>

            <input
                type="text"
                name="q"
                value="<?= $h($search) ?>"
                placeholder="فاکتور / سفارش / مشتری / موبایل">

            <button>جستجو</button>

        </form>

    </div>

    <!-- ═══ فاکتورها ═══ -->

    <?php if (!$invoices): ?>

        <div class="finc-empty">
            با فیلتر فعلی فاکتوری وجود ندارد.
        </div>

    <?php endif; ?>

    <?php foreach ($invoices as $inv):

        $invId = (int)$inv['id'];

        $invTotal = (int)$inv['total'];

        $paidTotal = (int)$inv['paid_total'];

        $pending = (int)$inv['pending_amount'];

        $remaining = $invTotal - $paidTotal;

        /* نتیجه مالی — COMPUTED (منبع حقیقت) */

        if ($remaining > 0) {

            $recType = 'بدهکار';
            $recAmount = $remaining;
            $recClass = 'danger';
        } elseif ($remaining < 0) {

            $recType = 'بستانکار';
            $recAmount = abs($remaining);
            $recClass = 'info';
        } else {

            $recType = 'تسویه';
            $recAmount = 0;
            $recClass = 'success';
        }

        /* پرداخت‌های این فاکتور */

        $stmt = $db->prepare("
            SELECT
                ps.*,
                v.verified_amount,
                v.status AS v_status,
                v.note AS v_note,
                v.verified_at,
                vu.full_name AS verifier_name
            FROM payment_submissions ps

            LEFT JOIN payment_verifications v
                ON v.payment_submission_id = ps.id

            LEFT JOIN users vu
                ON vu.id = v.verified_by

            WHERE ps.invoice_id = ?

            ORDER BY ps.id DESC
        ");

        $stmt->execute([$invId]);

        $subs = $stmt->fetchAll();

        /* تاریخچه — با ستون‌های واقعی */

        $stmt = $db->prepare("
            SELECT
                h.*,
                u.full_name AS changer
            FROM payment_verification_history h

            LEFT JOIN users u
                ON u.id = h.changed_by

            WHERE h.invoice_id = ?

            ORDER BY h.id DESC

            LIMIT 15
        ");

        $stmt->execute([$invId]);

        $histories = $stmt->fetchAll();

        /*
         * اصلاح مبلغ تأییدشده فقط برای فاکتور بدهکار مجاز است.
         *
         * اگر فاکتور بستانکار باشد، مبلغ تأییدشده نباید دوباره «اصلاح»
         * شود؛ بلکه اضافه‌پرداخت باید از مسیر «تسویه بستانکاری» ثبت شود.
         */

        $canAdjust = ($remaining > 0);

        /*
         * مبلغ بستانکاری واقعی که باید به مشتری تسویه شود.
         * این مبلغ از همان منبع حقیقت مالی می‌آید:
         * total - paid_total
         */

        $creditorAmount =
            $remaining < 0
            ? abs($remaining)
            : 0;

        /* روش‌های قابل ثبت برای تسویه بستانکاری */

        $settlementMethods = [
            'sheba'        => 'شبا',
            'card_to_card' => 'کارت به کارت',
            'cash_receipt' => 'فیش نقدی',
            'online'       => 'درگاه',
        ];

    ?>

        <div class="finc-card">

            <div class="finc-head">

                <div>

                    <div class="finc-inv">

                        <span dir="ltr">
                            <?= $h($inv['invoice_number']) ?>
                        </span>

                        <small dir="ltr">
                            سفارش:
                            <?= $h($inv['order_number']) ?>
                            ·
                            <?= $h(
                                $invStatusLabel(
                                    $inv['invoice_status']
                                )
                            ) ?>
                        </small>

                    </div>

                </div>

                <div class="finc-cust">

                    <b>
                        <?= $h($inv['full_name']) ?>
                    </b>

                    <?= $h(
                        $inv['company_name'] ?: ''
                    ) ?>

                    ·

                    <span dir="ltr">
                        <?= $h($inv['mobile']) ?>
                    </span>

                </div>

            </div>

            <div class="finc-grid">

                <div class="finc-box">

                    <span>مبلغ فاکتور</span>

                    <strong>
                        <?= $money($invTotal) ?>
                    </strong>

                </div>

                <div class="finc-box ok">

                    <span>پرداخت تأییدشده</span>

                    <strong>
                        <?= $money($paidTotal) ?>
                    </strong>

                </div>

                <div class="finc-box <?= $pending > 0 ? 'hot' : '' ?>">

                    <span>
                        در انتظار بررسی (ادعا)
                    </span>

                    <strong>
                        <?= $pending > 0
                            ? $money($pending)
                            : '—'
                        ?>
                    </strong>

                </div>

                <div class="finc-box <?= $remaining > 0
                                            ? 'deb'
                                            : ($remaining < 0
                                                ? 'info'
                                                : 'ok')
                                        ?>">

                    <span>
                        <?= $remaining < 0
                            ? 'بستانکاری قابل تسویه'
                            : 'مانده قطعی'
                        ?>
                    </span>

                    <strong>
                        <?= $money(abs($remaining)) ?>
                    </strong>

                </div>

            </div>

            <div style="margin-bottom:12px;">

                <span
                    class="finc-badge <?= $recClasses[$inv['reconciliation_type']] ?? 'muted' ?>">

                    نتیجه سیستمی:

                    <?= $recLabels[$inv['reconciliation_type']] ?? '—' ?>

                    <?= (int)$inv['reconciliation_amount'] > 0
                        ? ' — ' . $money(
                            (int)$inv['reconciliation_amount']
                        )
                        : ''
                    ?>

                </span>

                <span class="finc-badge muted">

                    صدور:
                    <?= $jalali($inv['issued_at']) ?>

                </span>

            </div>

            <?php if (
                $remaining < 0
                && $creditorAmount > 0
            ): ?>

                <!-- ═══ تسویه بستانکاری — مسیر مستقل از اصلاح پرداخت ═══ -->

                <div
                    class="finc-creditor-settlement"
                    style="margin:0 0 16px;padding:14px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;">

                    <div
                        style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">

                        <div>

                            <strong
                                style="display:block;font-size:14px;">
                                تسویه بستانکاری مشتری
                            </strong>

                            <span
                                style="display:block;margin-top:4px;font-size:12px;color:#475569;">
                                این مبلغ اضافه‌پرداخت مشتری است و باید به حساب او برگشت داده شود.
                            </span>

                        </div>

                        <div
                            style="font-size:16px;font-weight:800;color:#1d4ed8;">
                            مبلغ قابل تسویه:
                            <?= $money($creditorAmount) ?>
                        </div>

                    </div>

                    <form
                        method="post"
                        class="finc-verify"
                        style="display:grid;grid-template-columns:minmax(150px,1fr) minmax(150px,1fr) minmax(150px,1fr) minmax(180px,1.2fr) auto;gap:8px;align-items:end;">

                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= $h($csrf) ?>">

                        <input
                            type="hidden"
                            name="action"
                            value="settle_creditor">

                        <input
                            type="hidden"
                            name="invoice_id"
                            value="<?= $invId ?>">

                        <div>

                            <label
                                style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;">
                                مبلغ بازگشت
                            </label>

                            <input
                                type="number"
                                name="amount"
                                min="1"
                                max="<?= $creditorAmount ?>"
                                required
                                value="<?= $creditorAmount ?>"
                                placeholder="مبلغ تسویه">

                        </div>

                        <div>

                            <label
                                style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;">
                                روش تسویه
                            </label>

                            <select
                                name="method"
                                required
                                style="width:100%;min-height:38px;">

                                <option value="">
                                    انتخاب روش
                                </option>

                                <?php foreach (
                                    $settlementMethods
                                    as $methodKey => $methodText
                                ): ?>

                                    <option
                                        value="<?= $h($methodKey) ?>">
                                        <?= $h($methodText) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div>

                            <label
                                style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;">
                                شماره سند
                            </label>

                            <input
                                type="text"
                                name="document_no"
                                placeholder="مثلاً سند مالی 1258">

                        </div>

                        <div>

                            <label
                                style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;">
                                شماره پیگیری / تراکنش
                            </label>

                            <input
                                type="text"
                                name="reference_no"
                                placeholder="شماره پیگیری بانک">

                        </div>

                        <button
                            type="submit"
                            class="go"
                            style="background:#2563eb;min-height:38px;white-space:nowrap;"
                            onclick="return confirm('آیا مبلغ <?= $h($money($creditorAmount)) ?> به مشتری تسویه شده است؟');">
                            ثبت تسویه بستانکاری
                        </button>

                    </form>

                </div>

            <?php endif; ?>

            <?php foreach ($subs as $s): ?>

                <div
                    class="finc-sub <?= $s['status'] === 'pending'
                                        ? 'pending'
                                        : ''
                                    ?>">

                    <div class="finc-sub-head">

                        <div
                            class="finc-meta"
                            style="margin:0;">

                            <b>
                                <?= $h(
                                    $methodLabel(
                                        $s['method']
                                    )
                                ) ?>
                            </b>

                            <span>
                                ادعا:
                                <b>
                                    <?= $money(
                                        (int)$s['claimed_amount']
                                    ) ?>
                                </b>
                            </span>

                            <?php if ($s['reference_no']): ?>

                                <span dir="ltr">
                                    پیگیری:
                                    <?= $h(
                                        $s['reference_no']
                                    ) ?>
                                </span>

                            <?php endif; ?>

                            <?php if ($s['receipt_path']): ?>

                                <a
                                    href="<?= $h($s['receipt_path']) ?>"
                                    target="_blank">
                                    🖼 فیش
                                </a>

                            <?php endif; ?>

                            <span>
                                <?= $jalali(
                                    $s['created_at'],
                                    true
                                ) ?>
                            </span>

                        </div>

                        <span
                            class="finc-badge <?= $subStatusClass($s['status']) ?>">
                            <?= $h(
                                $subStatusLabel(
                                    $s['status']
                                )
                            ) ?>
                        </span>

                    </div>

                    <?php if (
                        $s['verified_amount'] !== null
                    ): ?>

                        <div class="finc-note">

                            تأیید مالی:

                            <b>
                                <?= $money(
                                    (int)$s['verified_amount']
                                ) ?>
                            </b>

                            <?php if ($s['v_note']): ?>

                                —
                                <?= $h($s['v_note']) ?>

                            <?php endif; ?>

                            <?php if ($s['verifier_name']): ?>

                                (
                                <?= $h(
                                    $s['verifier_name']
                                ) ?>

                                —

                                <?= $jalali(
                                    $s['verified_at'],
                                    true
                                ) ?>
                                )

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                    <?php if (
                        $s['status'] === 'pending'
                    ): ?>

                        <form
                            method="post"
                            class="finc-verify">

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= $h($csrf) ?>">

                            <input
                                type="hidden"
                                name="action"
                                value="verify_payment">

                            <input
                                type="hidden"
                                name="submission_id"
                                value="<?= (int)$s['id'] ?>">

                            <label
                                style="font-size:11px;font-weight:700;">
                                مبلغ واقعی بانک:
                            </label>

                            <input
                                type="number"
                                name="actual_amount"
                                min="1"
                                required
                                placeholder="مبلغ رسید بانکی">

                            <input
                                type="text"
                                name="note"
                                placeholder="توضیح مالی (اختیاری)">

                            <button
                                type="submit"
                                class="go">
                                ✓ تأیید و ثبت
                            </button>

                        </form>

                        <form
                            method="post"
                            class="finc-verify">

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= $h($csrf) ?>">

                            <input
                                type="hidden"
                                name="action"
                                value="reject_payment">

                            <input
                                type="hidden"
                                name="submission_id"
                                value="<?= (int)$s['id'] ?>">

                            <input
                                type="text"
                                name="reason"
                                required
                                placeholder="دلیل رد (الزامی)">

                            <button
                                type="submit"
                                class="no">
                                ✗ رد
                            </button>

                        </form>

                    <?php endif; ?>

                    <?php if (
                        in_array(
                            $s['status'],
                            ['verified', 'adjusted'],
                            true
                        )
                        && $canAdjust
                    ): ?>

                        <form
                            method="post"
                            class="finc-verify">

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= $h($csrf) ?>">

                            <input
                                type="hidden"
                                name="action"
                                value="adjust_payment">

                            <input
                                type="hidden"
                                name="submission_id"
                                value="<?= (int)$s['id'] ?>">

                            <label
                                style="font-size:11px;font-weight:700;">
                                اصلاح مبلغ تأییدشده:
                            </label>

                            <input
                                type="number"
                                name="actual_amount"
                                min="1"
                                required
                                value="<?= (int)$s['verified_amount'] ?>">

                            <input
                                type="text"
                                name="reason"
                                required
                                placeholder="دلیل اصلاح (الزامی)">

                            <button
                                type="submit"
                                class="go"
                                style="background:#2563eb;">
                                ثبت اصلاح
                            </button>

                        </form>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

            <?php if ($histories): ?>

                <details class="finc-hist">

                    <summary>
                        📜 تاریخچه مالی
                        (<?= count($histories) ?>)
                    </summary>

                    <ul>

                        <?php foreach (
                            $histories
                            as $hh
                        ): ?>

                            <li>

                                <?= $jalali(
                                    $hh['changed_at'],
                                    true
                                ) ?>

                                —

                                <?= $h(
                                    $hh['changer'] ?? '—'
                                ) ?>:

                                <?= number_format(
                                    (int)(
                                        $hh['old_verified_amount']
                                        ?? 0
                                    )
                                ) ?>

                                ←

                                <?= number_format(
                                    (int)(
                                        $hh['new_verified_amount']
                                        ?? 0
                                    )
                                ) ?>

                                <?= !empty($hh['reason'])
                                    ? ' — ' . $h(
                                        $hh['reason']
                                    )
                                    : ''
                                ?>

                            </li>

                        <?php endforeach; ?>

                    </ul>

                </details>

            <?php endif; ?>

            <div class="finc-links">

                <a
                    href="/invoice-print?inv=<?= $h($inv['invoice_number']) ?>"
                    target="_blank">
                    🧾 فاکتور
                </a>

                <a
                    href="/invoice-pdf.php?inv=<?= $h($inv['invoice_number']) ?>"
                    target="_blank">
                    📄 PDF
                </a>

            </div>

        </div>

    <?php endforeach; ?>

</div>

<?php

$content = ob_get_clean();

require ROOT_PATH . '/views/layouts/admin.php';
