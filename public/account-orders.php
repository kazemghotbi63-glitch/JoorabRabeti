<?php
/* [FILE] public/account-orders.php — Customer my-orders */

require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

if (!is_logged_in()) {
    header('Location: /');
    exit;
}

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$jalali = static function (?string $mysqlDate, bool $withTime = false): string {
    if (!$mysqlDate) return '—';
    $ts = strtotime($mysqlDate);
    if ($ts === false) return '—';
    $gy = (int)date('Y', $ts);
    $gm = (int)date('n', $ts);
    $gd = (int)date('j', $ts);
    $g = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? $gy + 1 : $gy;
    $d = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) - 80 + $gd + $g[$gm - 1];
    $jy += 33 * intdiv($d, 12053);
    $d %= 12053;
    $jy += 4 * intdiv($d, 1461);
    $d %= 1461;
    if ($d > 365) {
        $jy += intdiv($d - 1, 365);
        $d = ($d - 1) % 365;
    }
    $jm = ($d < 186) ? 1 + intdiv($d, 31) : 7 + intdiv($d - 186, 30);
    $jd = 1 + (($d < 186) ? $d % 31 : ($d - 186) % 30);
    $out = $jy . '/' . str_pad((string)$jm, 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$jd, 2, '0', STR_PAD_LEFT);
    return $withTime ? $out . ' — ' . date('H:i', $ts) : $out;
};

$userId = (int)($_SESSION['admin_id'] ?? $_SESSION['customer_id'] ?? 0);
$db = db();

$statusLabels = [
    'pending_review' => 'در انتظار تائید واحد مالی',
    'processing'     => 'ارسال درخواست به انبار',
    'packed'         =>  'بسته بندی و آماده تحویل',
    'shipped-pik'    => 'تحویل پیک جهت ارسال',
    'shipped-post'   => 'تحویل پست جهت ارسال',
    'shipped'        => 'ارسال شد',
    'delivered'      => 'تحویل شد',
    'cancelled'      => 'لغو شده',
];

$stmt = $db->prepare("
    SELECT o.*, i.invoice_number, i.status AS pay_status
    FROM orders o
    LEFT JOIN invoices i ON i.order_id = o.id
    WHERE o.user_id = ?
    ORDER BY o.id DESC LIMIT 50
");
$stmt->execute([$userId]);
$orders = $stmt->fetchAll();

/* ═══ برای هر سفارش: تأییدشده / در انتظار / مانده (بستانکار هم) ═══ */
$orderFinancials = [];
foreach ($orders as $o) {
    if (!$o['invoice_number']) {
        $orderFinancials[(int)$o['id']] = [
            'paid' => 0,
            'pending' => 0,
            'remaining' => (int)$o['total'],
            'has_pending' => false,
        ];
        continue;
    }
    $stmt = $db->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN ps.status='verified' THEN COALESCE(v.verified_amount,0) ELSE 0 END),0) AS paid,
          COALESCE(SUM(CASE WHEN ps.status='pending' THEN ps.claimed_amount ELSE 0 END),0) AS pending
        FROM payment_submissions ps
        LEFT JOIN payment_verifications v ON v.payment_submission_id = ps.id
        WHERE ps.invoice_id = (SELECT id FROM invoices WHERE invoice_number = ?)
    ");
    $stmt->execute([$o['invoice_number']]);
    $fin = $stmt->fetch();

    $paid    = (int)($fin['paid'] ?? 0);
    $pending = (int)($fin['pending'] ?? 0);

    /* بدون max(0) — منفی یعنی بستانکار */
    $orderFinancials[(int)$o['id']] = [
        'paid'        => $paid,
        'pending'     => $pending,
        'remaining'   => (int)$o['total'] - $paid,
        'has_pending' => $pending > 0,
    ];
}

$pageTitle = 'سفارش‌های من | رابطی';
ob_start();
?>

<nav class="breadcrumb"><a href="/">خانه</a> › <span>سفارش‌های من</span></nav>

<section class="catalog-head">
    <h1 class="catalog-title">سفارش‌های من</h1>
    <p class="catalog-sub"><?= count($orders) ?> سفارش — مانده‌ها بر اساس تأیید واحد مالی محاسبه می‌شود</p>
</section>

<?php if ($orders): ?>
    <div class="my-orders">

        <?php foreach ($orders as $o):
            $fin = $orderFinancials[(int)$o['id']];
            $remaining  = $fin['remaining'];
            $hasPending = $fin['has_pending'];
            $isCreditor = ($remaining < 0);
        ?>
            <article class="my-order-card">

                <div class="mo-head">
                    <div>
                        <b dir="ltr"><?= $h($o['order_number']) ?></b>
                        <small><?= $jalali($o['created_at'], true) ?></small>
                    </div>
                    <span class="st-badge <?= [
                                                'pending_review' => 'st-new',
                                                'processing' => 'st-read',
                                                'packed' => 'st-quoted',
                                                'shipped' => 'st-quoted',
                                                'shipped-pik'  => 'st-quoted',
                                                'shipped-post'  => 'st-quoted',
                                                'delivered' => 'st-converted',
                                                'cancelled' => 'st-rejected',
                                            ][$o['status']] ?>"><?= $statusLabels[$o['status']] ?></span>
                </div>

                <div class="mo-body">
                    <div class="mo-row">
                        <span>مبلغ فاکتور</span>
                        <b><?= number_format((float)$o['total']) ?> تومان</b>
                    </div>
                    <div class="mo-row">
                        <span>پرداخت تأییدشده</span>
                        <b><?= number_format($fin['paid']) ?> تومان</b>
                    </div>

                    <?php if ($hasPending): ?>
                        <div class="mo-row">
                            <span>در انتظار بررسی مالی</span>
                            <b style="color:var(--cta-d);"><?= number_format($fin['pending']) ?> تومان</b>
                        </div>
                    <?php endif; ?>

                    <div class="mo-row">
                        <span>مانده بدهی</span>
                        <?php if ($remaining > 0): ?>
                            <b class="stock out"><?= number_format($remaining) ?> تومان</b>
                        <?php elseif ($isCreditor): ?>
                            <b class="stock in">بستانکار: <?= number_format(abs($remaining)) ?> تومان</b>
                        <?php else: ?>
                            <b class="stock in">تسویه کامل ✓</b>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mo-actions">
                    <?php if ($o['status'] !== 'cancelled' && $remaining > 0 && !$hasPending): ?>
                        <a href="/payment?order=<?= $h($o['order_number']) ?>" class="btn btn-primary">
                            💳 پرداخت مانده بدهی (<?= number_format($remaining) ?> تومان)
                        </a>
                    <?php elseif ($hasPending): ?>
                        <a href="/payment?order=<?= $h($o['order_number']) ?>" class="btn btn-outline">
                            پیگیری پرداخت در انتظار بررسی
                        </a>
                    <?php elseif ($isCreditor): ?>
                        <span class="btn btn-outline" style="cursor:default;">
                            ✓ بستانکار — نیازی به پرداخت نیست
                        </span>
                    <?php endif; ?>

                    <?php if ($o['invoice_number']): ?>
                        <a href="/invoice-print?inv=<?= $h($o['invoice_number']) ?>" target="_blank" class="btn btn-outline">🧾 فاکتور</a>
                    <?php endif; ?>
                </div>

            </article>
        <?php endforeach; ?>

    </div>
<?php else: ?>
    <div class="empty-state">
        <div class="empty-icon">📦</div>
        <h2>هنوز سفارشی ثبت نکرده‌اید</h2>
        <a href="/products" class="btn btn-primary">شروع خرید</a>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
