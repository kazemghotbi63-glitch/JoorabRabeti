<?php
/* [FILE] public/admin/report-ledger.php — گزارش دفتر مالی */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once __DIR__ . '/financial-helpers.php';

require_admin();

$db = db();

fin_applyPreset();
$finRange = fin_dateRange();
[$from, $to] = $finRange;

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v): string => number_format((int)$v) . ' تومان';

/* ═══ دفتر: هر فاکتور یک ردیف + تسویه‌ها + مانده جمعی ═══ */
$stmt = $db->prepare("
    SELECT i.id, i.invoice_number, i.total, i.paid_total, i.issued_at,
           o.order_number, u.full_name, u.company_name, u.mobile
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN users u ON u.id = i.user_id
    WHERE i.issued_at BETWEEN ? AND ?
    ORDER BY i.issued_at ASC, i.id ASC
");
$stmt->execute([$from, $to]);
$invoices = $stmt->fetchAll();

/* ═══ ساخت ردیف‌های دفتر ═══ */
$rows = [];
$balance = 0;    /* مانده جمعی — مثبت = بدهکار مشتری، منفی = بستانکار */

foreach ($invoices as $inv) {
    $invoiceId = (int)$inv['id'];
    $total = (int)$inv['total'];

    /* مانده فاکتور = total − paid_total */
    $invoiceRemaining = max(0, $total - (int)$inv['paid_total']);
    $invoicePaid = (int)$inv['paid_total'];

    /* ردیف بدهی (ایجاد فاکتور) */
    $rows[] = [
        'date' => $inv['issued_at'],
        'amount' => $total,
        'debit' => $invoiceRemaining,   /* بدهکار: مانده فاکتور */
        'credit' => 0,
        'invoice' => $inv['invoice_number'],
        'customer' => $inv['full_name'],
        'type' => 'فاکتور فروش',
    ];

    /* مانده جمعی بعد از فاکتور */
    $balance += $invoiceRemaining;

    /* ردیف‌های پرداخت تأییدشده (بستانکار) */
    $stmt = $db->prepare("
        SELECT v.verified_amount, v.verified_at
        FROM payment_verifications v
        INNER JOIN payment_submissions ps ON ps.id = v.payment_submission_id
        WHERE ps.invoice_id = ? AND v.status IN ('verified','adjusted')
        ORDER BY v.verified_at ASC
    ");
    $stmt->execute([$invoiceId]);
    foreach ($stmt->fetchAll() as $v) {
        $amt = (int)$v['verified_amount'];
        $rows[] = [
            'date' => $v['verified_at'],
            'amount' => $amt,
            'debit' => 0,
            'credit' => $amt,           /* بستانکار: دریافت وجه */
            'invoice' => $inv['invoice_number'],
            'customer' => $inv['full_name'],
            'type' => 'دریافت وجه',
        ];
        $balance -= $amt;
    }

    /* ردیف‌های بازگشت وجه (بستانکارِ منفی = بدهکار مجدد) */
    $stmt = $db->prepare("
        SELECT amount, completed_at
        FROM financial_transactions
        WHERE invoice_id = ? AND type='creditor_settlement' AND status='completed'
        ORDER BY completed_at ASC
    ");
    $stmt->execute([$invoiceId]);
    foreach ($stmt->fetchAll() as $r) {
        $amt = (int)$r['amount'];
        $rows[] = [
            'date' => $r['completed_at'],
            'amount' => $amt,
            'debit' => $amt,            /* برگشت وجه = بدهکار مجدد */
            'credit' => 0,
            'invoice' => $inv['invoice_number'],
            'customer' => $inv['full_name'],
            'type' => 'بازگشت وجه',
        ];
        $balance += $amt;
    }
}

/* مرتب‌سازی نهایی بر اساس تاریخ */
usort($rows, fn($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));

/* محاسبه مانده هر ردیف و جمع کل */
$totalDebit = 0;
$totalCredit = 0;
foreach ($rows as &$r) {
    $totalDebit  += $r['debit'];
    $totalCredit += $r['credit'];
}
unset($r);
$finalBalance = $totalDebit - $totalCredit;

$pageTitle  = 'دفتر مالی';
$activeMenu = 'reports';

ob_start();
?>

<nav class="breadcrumb"><a href="/">خانه</a> › <span>گزارش دفتر مالی</span></nav>

<section class="catalog-head">
    <h1 class="catalog-title">دفتر مالی</h1>
    <p class="catalog-sub">بدهکار / بستانکار / مانده جمعی — از <?= $h(fin_jalali($from)) ?> تا <?= $h(fin_jalali($to)) ?></p>
</section>

<?php fin_rangeForm('/admin/report-ledger'); ?>

<div class="stat-cards" style="grid-template-columns:repeat(3,minmax(0,1fr));">
    <div class="stat-card stat-card-accent">
        <div class="stat-card-top"><span class="stat-card-label">جمع بدهکار</span><span class="stat-card-icon">↓</span></div>
        <div class="num"><?= $money($totalDebit) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top"><span class="stat-card-label">جمع بستانکار</span><span class="stat-card-icon">↑</span></div>
        <div class="num"><?= $money($totalCredit) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top"><span class="stat-card-label">مانده نهایی</span><span class="stat-card-icon">Σ</span></div>
        <div class="num"><?= $money(abs($finalBalance)) ?> <?= $finalBalance >= 0 ? '(بدهکار)' : '(بستانکار)' ?></div>
    </div>
</div>

<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
            <tr>
                <th>#</th>
                <th>تاریخ</th>
                <th>شرح</th>
                <th>شماره فاکتور</th>
                <th>نام خریدار</th>
                <th>مبلغ</th>
                <th>بدهکار</th>
                <th>بستانکار</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1;
            foreach ($rows as $r): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= $h(fin_jalali($r['date'])) ?></td>
                    <td><?= $h($r['type']) ?></td>
                    <td dir="ltr"><?= $h($r['invoice']) ?></td>
                    <td><?= $h($r['customer']) ?></td>
                    <td><?= $money($r['amount']) ?></td>
                    <td style="color:var(--admin-danger);"><?= $r['debit'] > 0 ? $money($r['debit']) : '—' ?></td>
                    <td style="color:var(--admin-success);"><?= $r['credit'] > 0 ? $money($r['credit']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="background:#f8fafc;font-weight:800;">
                <td colspan="6" style="text-align:left;">جمع کل</td>
                <td style="color:var(--admin-danger);"><?= $money($totalDebit) ?></td>
                <td style="color:var(--admin-success);"><?= $money($totalCredit) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
