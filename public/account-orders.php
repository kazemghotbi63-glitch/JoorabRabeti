<?php
/* [FILE] public/account-orders.php — Customer my-orders */

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

if (!is_customer_logged_in()) {
    header('Location: ' . (is_admin_logged_in() ? '/admin' : '/'));
    exit;
}

/* ═══════════════════════════════════════════════════════════  
   مدیریت درخواست لغو سفارش (POST در همین فایل)  
   ═══════════════════════════════════════════════════════════ */
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    verify_csrf($_POST['csrf_token'] ?? null);

    $orderId = (int)($_POST['order_id'] ?? 0);
    $userId  = (int)($_SESSION['customer_id'] ?? 0);
    $db      = db();

    // حفظ فیلترها هنگام بازگشت  
    $backQuery = $_POST['back_query'] ?? '';
    $backUrl   = '/account-orders' . ($backQuery ? ('?' . $backQuery) : '');

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("  
            SELECT id, status FROM orders  
            WHERE id = ? AND user_id = ?  
            FOR UPDATE  
        ");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch();

        if (!$order) {
            throw new RuntimeException('سفارش یافت نشد.');
        }
        if ($order['status'] !== 'pending_review') {
            throw new RuntimeException('این سفارش در وضعیتی نیست که قابل لغو باشد.');
        }

        $stmt = $db->prepare("  
            SELECT COUNT(*) FROM payment_submissions ps  
            JOIN invoices i ON i.id = ps.invoice_id  
            WHERE i.order_id = ? AND ps.status IN ('pending','verified')  
        ");
        $stmt->execute([$orderId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('برای این سفارش پرداخت ثبت شده و امکان لغو وجود ندارد.');
        }

        // مقادیر رزروشده را پیش از صفرکردن آیتم‌ها می‌خوانیم تا فقط رزرو واقعی آزاد شود.
        $items = $db->prepare("
            SELECT product_id, SUM(COALESCE(qty_reserved, 0)) AS total_qty
            FROM order_items
            WHERE order_id = ? AND product_id IS NOT NULL
            GROUP BY product_id
        ");
        $items->execute([$orderId]);
        $reservedItems = $items->fetchAll(PDO::FETCH_ASSOC);

        // آزادسازی رزرو محصولات؛ بررسی ستون فقط در مسیر لغو سفارش لازم است.
        $hasReservedCol = (bool)$db->query("
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'products'
              AND COLUMN_NAME = 'reserved'
            LIMIT 1
        ")->fetchColumn();

        if ($hasReservedCol) {
            $updProduct = $db->prepare("
                UPDATE products
                SET reserved = GREATEST(COALESCE(reserved, 0) - ?, 0)
                WHERE id = ?
            ");
            foreach ($reservedItems as $it) {
                $qtyToRelease = (int)$it['total_qty'];
                if ($qtyToRelease > 0) {
                    $updProduct->execute([$qtyToRelease, (int)$it['product_id']]);
                }
            }
        }

        // لغو آیتم‌ها پس از ثبت مقدار رزرو قابل آزادسازی
        $db->prepare("
            UPDATE order_items
            SET qty_cancelled = qty, qty_reserved = 0
            WHERE order_id = ? AND qty_cancelled = 0
        ")->execute([$orderId]);

        $upd = $db->prepare("  
            UPDATE orders SET status = 'cancelled', updated_at = NOW()  
            WHERE id = ? AND user_id = ? AND status = 'pending_review'  
        ");
        $upd->execute([$orderId, $userId]);

        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('لغو سفارش انجام نشد.');
        }

        $db->commit();
        $_SESSION['flash_success'] = 'سفارش با موفقیت لغو شد.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_error'] = $e->getMessage();
    }

    header('Location: ' . $backUrl);
    exit;
}

/* ═══════════════════════════════════════════════════════════  
   داده‌های صفحه + فیلترها  
   ═══════════════════════════════════════════════════════════ */
$moneyFa = static function ($amount): string {
    $formatted = number_format((int)$amount);
    $formatted = strtr($formatted, [
        '0' => '۰',
        '1' => '۱',
        '2' => '۲',
        '3' => '۳',
        '4' => '۴',
        '5' => '۵',
        '6' => '۶',
        '7' => '۷',
        '8' => '۸',
        '9' => '۹',
        ',' => '٬',
    ]);
    return $formatted . ' تومان';
};

$h = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
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

$userId = (int)($_SESSION['customer_id'] ?? 0);
$db = db();

$statusLabels = [
    'pending_review' => 'در انتظار تائید واحد مالی',
    'processing'     => 'ارسال درخواست به انبار',
    'packed'         => 'بسته بندی و آماده تحویل',
    'shipped-pik'    => 'تحویل پیک جهت ارسال',
    'shipped-post'   => 'تحویل پست جهت ارسال',
    'shipped-barbari' => 'تحویل باربری جهت ارسال',
    'shipped-tipax'  => 'تحویل تیپاکس جهت ارسال',
    'shipped'        => 'ارسال شد',
    'delivered'      => 'تحویل شد',
    'cancelled'      => 'لغو شده',
];

/* ─── فیلترهای مجاز ─── */
$filterGroups = [
    'all'          => ['label' => 'همه', 'statuses' => []],
    'pending'      => ['label' => 'در انتظار تأیید مالی', 'statuses' => ['pending_review']],
    'processing'   => ['label' => 'در حال پردازش', 'statuses' => ['processing', 'packed']],
    'shipped'      => ['label' => 'ارسال‌شده', 'statuses' => ['shipped-pik', 'shipped-post', 'shipped-barbari', 'shipped-tipax', 'shipped']],
    'delivered'    => ['label' => 'تحویل‌شده', 'statuses' => ['delivered']],
    'cancelled'    => ['label' => 'لغوشده', 'statuses' => ['cancelled']],
];

$activeFilter = $_GET['status'] ?? 'all';
if (!isset($filterGroups[$activeFilter])) {
    $activeFilter = 'all';
}

$searchQ = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($searchQ) > 60) {
    $searchQ = mb_substr($searchQ, 0, 60);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

/* ─── ساخت شرط‌های WHERE ─── */
$where  = ['o.user_id = ?'];
$params = [$userId];

if (!empty($filterGroups[$activeFilter]['statuses'])) {
    $ph = implode(',', array_fill(0, count($filterGroups[$activeFilter]['statuses']), '?'));
    $where[] = "o.status IN ($ph)";
    foreach ($filterGroups[$activeFilter]['statuses'] as $s) {
        $params[] = $s;
    }
}

if ($searchQ !== '') {
    $where[]  = "o.order_number LIKE ?";
    $params[] = '%' . $searchQ . '%';
}

$whereSql = implode(' AND ', $where);

/* ─── شمارش کل ─── */
$countStmt = $db->prepare("SELECT COUNT(*) FROM orders o WHERE $whereSql");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

/* ─── واکشی صفحه جاری ─── */
$sql = "
    SELECT o.*,
           (SELECT i.invoice_number
            FROM invoices i
            WHERE i.order_id = o.id
            ORDER BY i.id DESC
            LIMIT 1) AS invoice_number
    FROM orders o
    WHERE $whereSql
    ORDER BY o.id DESC
    LIMIT ? OFFSET ?
";
$stmt = $db->prepare($sql);
foreach ($params as $index => $value) {
    $stmt->bindValue($index + 1, $value);
}
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ═══ تجمیع مالی همه فاکتورهای سفارش‌ها در یک کوئری ═══ */
$orderFinancials = [];
$orderIds = array_map(
    static function ($order) {
        return (int)$order['id'];
    },
    $orders
);
if ($orderIds) {
    $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
    $financialSql = "
        SELECT i.order_id,
               COALESCE(SUM(payment_totals.paid), 0) AS paid,
               COALESCE(SUM(payment_totals.pending), 0) AS pending
        FROM invoices i
        LEFT JOIN (
            SELECT ps.invoice_id,
                   SUM(CASE WHEN ps.status = 'verified'
                            THEN COALESCE(verification_totals.verified_amount, 0)
                            ELSE 0 END) AS paid,
                   SUM(CASE WHEN ps.status = 'pending'
                            THEN COALESCE(ps.claimed_amount, 0)
                            ELSE 0 END) AS pending
            FROM payment_submissions ps
            LEFT JOIN (
                SELECT payment_submission_id,
                       SUM(verified_amount) AS verified_amount
                FROM payment_verifications
                GROUP BY payment_submission_id
            ) verification_totals
              ON verification_totals.payment_submission_id = ps.id
            GROUP BY ps.invoice_id
        ) payment_totals ON payment_totals.invoice_id = i.id
        WHERE i.order_id IN ($inPlaceholders)
        GROUP BY i.order_id
    ";
    $financialStmt = $db->prepare($financialSql);
    $financialStmt->execute($orderIds);
    foreach ($financialStmt->fetchAll(PDO::FETCH_ASSOC) as $fin) {
        $orderFinancials[(int)$fin['order_id']] = [
            'paid' => (int)$fin['paid'],
            'pending' => (int)$fin['pending'],
            'has_pending' => (int)$fin['pending'] > 0,
        ];
    }
}

foreach ($orders as $order) {
    $id = (int)$order['id'];
    $paid = $orderFinancials[$id]['paid'] ?? 0;
    $pending = $orderFinancials[$id]['pending'] ?? 0;
    $orderFinancials[$id] = [
        'paid' => $paid,
        'pending' => $pending,
        'remaining' => (int)$order['total'] - $paid,
        'has_pending' => $pending > 0,
    ];
}

/* ─── رشته کوئری برای حفظ فیلترها ─── */
$qsParts = [];
if ($activeFilter !== 'all') $qsParts[] = 'status=' . urlencode($activeFilter);
if ($searchQ !== '')          $qsParts[] = 'q=' . urlencode($searchQ);
if ($page > 1)                $qsParts[] = 'page=' . $page;
$backQuery = implode('&', $qsParts);

/* ─── مراحل پیگیری سفارش (برای نوار پیشرفت) ─── */
$statusSteps = ['pending_review', 'processing', 'packed', 'shipped', 'delivered'];
$statusStepIndex = static function (string $status) use ($statusSteps): int {
    switch ($status) {
    case 'pending_review':
        return 0;

    case 'processing':
        return 1;

    case 'packed':
        return 2;

    case 'shipped':
    case 'shipped-pik':
    case 'shipped-post':
    case 'shipped-barbari':
    case 'shipped-tipax':
        return 3;

    case 'delivered':
        return 4;

    default:
        return -1;
}
};

$pageTitle = 'سفارش‌های من | رابطی';
ob_start();
?>

<div class="rbt-orders" dir="rtl">

    <!-- ================= HEADER ================= -->
    <header class="rbt-ord-head">
        <div>
            <nav class="rbt-breadcrumb">
                <a href="/">خانه</a>
                <span class="rbt-bc-sep">/</span>
                <span>سفارش‌های من</span>
            </nav>
            <h1>سفارش‌های من</h1>
            <p class="rbt-ord-sub">
                <?= strtr((string)$totalCount, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']) ?> سفارش
                <?php if ($activeFilter !== 'all'): ?>
                    · <?= $h($filterGroups[$activeFilter]['label']) ?>
                <?php endif; ?>
                <?php if ($searchQ !== ''): ?>
                    · جستجو: «<?= $h($searchQ) ?>»
                <?php endif; ?>
            </p>
        </div>
        <span class="rbt-ord-index" aria-hidden="true">RABETI / ORDERS</span>
    </header>

    <!-- ================= FLASH ================= -->
    <?php if ($flashSuccess): ?>
        <div class="rbt-flash rbt-flash-ok" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="m5 12 4 4L19 6" />
                <circle cx="12" cy="12" r="10" />
            </svg>
            <?= $h($flashSuccess) ?>
        </div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="rbt-flash rbt-flash-err" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <circle cx="12" cy="12" r="10" />
                <path d="M12 8v5M12 16h.01" />
            </svg>
            <?= $h($flashError) ?>
        </div>
    <?php endif; ?>

    <!-- ================= FILTERS + SEARCH ================= -->
    <div class="rbt-filter-bar">

        <div class="rbt-pills-scroll">
            <?php foreach ($filterGroups as $key => $g):
                $isActive = ($activeFilter === $key);
                $urlParts = [];
                if ($key !== 'all') $urlParts[] = 'status=' . urlencode($key);
                if ($searchQ !== '') $urlParts[] = 'q=' . urlencode($searchQ);
                $url = '/account-orders' . ($urlParts ? ('?' . implode('&', $urlParts)) : '');
            ?>
                <a href="<?= $h($url) ?>" class="rbt-pill <?= $isActive ? 'is-active' : '' ?>">
                    <?= $h($g['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="/account-orders" class="rbt-search">
            <?php if ($activeFilter !== 'all'): ?>
                <input type="hidden" name="status" value="<?= $h($activeFilter) ?>">
            <?php endif; ?>

            <input type="text" name="q" value="<?= $h($searchQ) ?>"
                placeholder="جستجوی شماره سفارش…" dir="ltr">

            <?php if ($searchQ !== ''): ?>
                <a href="/account-orders<?= $activeFilter !== 'all' ? ('?status=' . urlencode($activeFilter)) : '' ?>"
                    class="rbt-search-clear" title="پاک‌کردن" aria-label="پاک‌کردن جستجو">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </a>
            <?php endif; ?>

            <button type="submit" class="rbt-search-btn" aria-label="جستجو">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                </svg>
                <span>جستجو</span>
            </button>
        </form>

    </div>

    <!-- ================= ORDERS LIST ================= -->
    <?php if ($orders): ?>
        <div class="rbt-order-list">

            <?php foreach ($orders as $o):
                $fin = $orderFinancials[(int)$o['id']];
                $remaining  = $fin['remaining'];
                $hasPending = $fin['has_pending'];
                $isCreditor = ($remaining < 0);
                $isCancelled = ($o['status'] === 'cancelled');
                $stepIdx = $statusStepIndex($o['status']);

                $canCancel = ($o['status'] === 'pending_review'
                    && $fin['paid'] === 0
                    && !$hasPending);

                $badgeClass = [
                    'pending_review'   => 'rbt-st-pending',
                    'processing'       => 'rbt-st-processing',
                    'packed'           => 'rbt-st-processing',
                    'shipped'          => 'rbt-st-shipped',
                    'shipped-pik'      => 'rbt-st-shipped',
                    'shipped-post'     => 'rbt-st-shipped',
                    'shipped-barbari'  => 'rbt-st-shipped',
                    'shipped-tipax'    => 'rbt-st-shipped',
                    'delivered'        => 'rbt-st-delivered',
                    'cancelled'        => 'rbt-st-cancelled',
                ][$o['status']] ?? 'rbt-st-pending';
            ?>
                <article class="rbt-order-card <?= $isCancelled ? 'is-cancelled' : '' ?>">

                    <!-- head -->
                    <div class="rbt-oc-head">
                        <div class="rbt-oc-id">
                            <b dir="ltr"><?= $h($o['order_number']) ?></b>
                            <small><?= $jalali($o['created_at'], true) ?></small>
                        </div>
                        <span class="rbt-badge <?= $badgeClass ?>">
                            <span class="rbt-badge-dot"></span>
                            <?= $h($statusLabels[$o['status']] ?? 'وضعیت نامشخص: [' . $o['status'] . ']') ?>
                        </span>
                    </div>

                    <!-- progress tracker -->
                    <?php if (!$isCancelled): ?>
                        <div class="rbt-track" aria-hidden="true">
                            <?php
                            $trackLabels = ['تأیید مالی', 'پردازش', 'بسته‌بندی', 'ارسال', 'تحویل'];
                            foreach ($trackLabels as $i => $tl):
                                $cls = $i < $stepIdx ? 'is-done' : ($i === $stepIdx ? 'is-current' : '');
                            ?>
                                <div class="rbt-track-step <?= $cls ?>">
                                    <span class="rbt-track-dot">
                                        <?php if ($i < $stepIdx): ?>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                                                <path d="m5 12 4 4L19 6" />
                                            </svg>
                                        <?php endif; ?>
                                    </span>
                                    <small><?= $tl ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- financials -->
                    <div class="rbt-oc-body">
                        <div class="rbt-oc-row">
                            <span>مبلغ فاکتور</span>
                            <b><?= $moneyFa((float)$o['total']) ?></b>
                        </div>
                        <div class="rbt-oc-row">
                            <span>پرداخت تأییدشده</span>
                            <b class="rbt-ok"><?= $moneyFa($fin['paid']) ?></b>
                        </div>
                        <?php if ($hasPending): ?>
                            <div class="rbt-oc-row">
                                <span>در انتظار بررسی مالی</span>
                                <b class="rbt-warn"><?= $moneyFa($fin['pending']) ?></b>
                            </div>
                        <?php endif; ?>
                        <div class="rbt-oc-row rbt-oc-total">
                            <span>مانده بدهی</span>
                            <?php if ($remaining > 0): ?>
                                <b class="rbt-debt"><?= $moneyFa($remaining) ?></b>
                            <?php elseif ($isCreditor): ?>
                                <b class="rbt-ok">بستانکار: <?= $moneyFa(abs($remaining)) ?></b>
                            <?php else: ?>
                                <b class="rbt-ok">تسویه کامل ✓</b>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- actions -->
                    <div class="rbt-oc-actions">
                        <?php if ($isCancelled): ?>
                            <span class="rbt-btn rbt-btn-disabled">این سفارش لغو شده است</span>
                        <?php else: ?>
                            <?php if ($remaining > 0 && !$hasPending): ?>
                                <a href="/payment?order=<?= $h($o['order_number']) ?>" class="rbt-btn rbt-btn-gold">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                        <rect x="3" y="6" width="18" height="13" rx="2" />
                                        <path d="M3 10h18M7 15h4" />
                                    </svg>
                                    پرداخت مانده (<?= $moneyFa($remaining) ?>)
                                </a>
                            <?php elseif ($hasPending): ?>
                                <a href="/payment?order=<?= $h($o['order_number']) ?>" class="rbt-btn rbt-btn-ghost">
                                    پیگیری پرداخت در انتظار بررسی
                                </a>
                            <?php elseif ($isCreditor): ?>
                                <span class="rbt-btn rbt-btn-disabled">✓ بستانکار — نیازی به پرداخت نیست</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($o['invoice_number'] && !$isCancelled): ?>
                            <a href="/invoice-print?inv=<?= $h($o['invoice_number']) ?>"
                                target="_blank" class="rbt-btn rbt-btn-ghost">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                    <path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z" />
                                    <path d="M9 8h6M9 12h6" />
                                </svg>
                                فاکتور
                            </a>
                        <?php endif; ?>

                        <?php if ($canCancel): ?>
                            <form method="post" action="/account-orders" class="rbt-inline-form js-cancel-order-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel_order">
                                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                <input type="hidden" name="back_query" value="<?= $h($backQuery) ?>">
                                <button type="submit" class="rbt-btn rbt-btn-danger">لغو سفارش</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!in_array($o['status'], ['cancelled', 'pending_review'], true)): ?>
                            <form method="post" action="/api/reorder" class="rbt-inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                <button type="submit" class="rbt-btn rbt-btn-ghost">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                        <path d="M3 12a9 9 0 1 0 3-6.7L3 8" />
                                        <path d="M3 3v5h5" />
                                    </svg>
                                    تکرار سفارش
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                </article>
            <?php endforeach; ?>

        </div>

        <!-- ================= PAGINATION ================= -->
        <?php if ($totalPages > 1): ?>
            <nav class="rbt-pagination">
                <?php
                $mkUrl = static function (int $p) use ($activeFilter, $searchQ): string {
                    $parts = ['page=' . $p];
                    if ($activeFilter !== 'all') $parts[] = 'status=' . urlencode($activeFilter);
                    if ($searchQ !== '')          $parts[] = 'q=' . urlencode($searchQ);
                    return '/account-orders?' . implode('&', $parts);
                };
                ?>
                <?php if ($page > 1): ?>
                    <a href="<?= $h($mkUrl($page - 1)) ?>" class="rbt-page-btn">‹ قبلی</a>
                <?php endif; ?>
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="<?= $h($mkUrl($p)) ?>"
                        class="rbt-page-btn <?= $p === $page ? 'is-active' : '' ?>">
                        <?= strtr((string)$p, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']) ?>
                    </a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= $h($mkUrl($page + 1)) ?>" class="rbt-page-btn">بعدی ›</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

    <?php else: ?>
        <!-- ================= EMPTY STATE ================= -->
        <div class="rbt-empty">
            <div class="rbt-empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                    <path d="M12 2 3 7v10l9 5 9-5V7l-9-5Z" />
                    <path d="M12 22V12M3 7l9 5 9-5" />
                </svg>
            </div>
            <?php if ($activeFilter !== 'all' || $searchQ !== ''): ?>
                <h2>سفارشی با این فیلتر یافت نشد</h2>
                <p>می‌توانید فیلترها را تغییر دهید یا جستجو را پاک کنید.</p>
                <a href="/account-orders" class="rbt-btn rbt-btn-gold">نمایش همه سفارش‌ها</a>
            <?php else: ?>
                <h2>هنوز سفارشی ثبت نکرده‌اید</h2>
                <p>اولین سفارش عمده خود را همین حالا ثبت کنید.</p>
                <a href="/products" class="rbt-btn rbt-btn-gold">شروع خرید</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>


<dialog class="rbt-cancel-dialog" id="rbtCancelDialog" aria-labelledby="rbtCancelTitle">
    <div class="rbt-cancel-dialog-icon" aria-hidden="true">!</div>
    <h2 id="rbtCancelTitle">لغو سفارش</h2>
    <p>آیا از لغو این سفارش مطمئن هستید؟ این عملیات قابل بازگشت نیست.</p>
    <div class="rbt-cancel-dialog-actions">
        <button type="button" class="rbt-btn rbt-btn-ghost" data-cancel-dismiss>انصراف</button>
        <button type="button" class="rbt-btn rbt-btn-danger" data-cancel-confirm>بله، لغو سفارش</button>
    </div>
</dialog>

<script>
    (() => {
        const dialog = document.getElementById('rbtCancelDialog');
        if (!dialog || typeof dialog.showModal !== 'function') return;

        let activeForm = null;
        let approvedForm = null;

        document.querySelectorAll('.js-cancel-order-form').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (approvedForm === form) {
                    approvedForm = null;
                    return;
                }
                event.preventDefault();
                activeForm = form;
                dialog.showModal();
            });
        });

        dialog.querySelector('[data-cancel-dismiss]')?.addEventListener('click', () => {
            dialog.close();
            activeForm = null;
        });

        dialog.querySelector('[data-cancel-confirm]')?.addEventListener('click', () => {
            if (!activeForm) return;
            const form = activeForm;
            activeForm = null;
            approvedForm = form;
            dialog.close();
            form.requestSubmit();
        });

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
                activeForm = null;
            }
        });
    })();
</script>

<style>
    /* =========================================================  
   RABETI ORDERS — LUXURY DARK & GOLD  
========================================================= */

    @font-face {
        font-family: 'Vazirmatn';
        src: url('/fonts/Vazirmatn-Medium.woff2') format('woff2');
        font-weight: 500;
        font-style: normal;
        font-display: swap;
    }

    @font-face {
        font-family: 'Vazirmatn';
        src: url('/fonts/Vazirmatn-Bold.woff2') format('woff2');
        font-weight: 700;
        font-style: normal;
        font-display: swap;
    }

    .rbt-orders {
        --rbt-ink: #0e1a20;
        --rbt-ink-2: #14242c;
        --rbt-gold: #c9a35c;
        --rbt-gold-soft: #e6cf9e;
        --rbt-text: #22333b;
        --rbt-muted: #7d8a90;
        --rbt-border: #e9e4da;

        direction: rtl;
        width: 100%;
        max-width: 1100px;
        margin: 0 auto;
        padding: 0 28px 60px;
        color: var(--rbt-text);
        font-family: 'Vazirmatn', Tahoma, sans-serif;
        box-sizing: border-box;
    }

    .rbt-orders *,
    .rbt-orders *::before,
    .rbt-orders *::after {
        box-sizing: border-box;
    }

    .rbt-orders a {
        text-decoration: none;
    }

    .rbt-orders button,
    .rbt-orders input {
        font: inherit;
    }


    /* ================= HEADER ================= */

    .rbt-ord-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 16px;
        padding: 26px 0 20px;
        border-bottom: 1px solid var(--rbt-border);
        margin-bottom: 20px;
    }

    .rbt-breadcrumb {
        font-size: 11px;
        color: var(--rbt-muted);
        margin-bottom: 10px;
    }

    .rbt-breadcrumb a {
        color: var(--rbt-gold);
    }

    .rbt-bc-sep {
        margin: 0 6px;
        color: #c9c2b2;
    }

    .rbt-ord-head h1 {
        font-size: clamp(24px, 3vw, 32px);
        font-weight: 700;
        margin: 0 0 6px;
        color: var(--rbt-text);
        letter-spacing: -.4px;
    }

    .rbt-ord-sub {
        font-size: 12.5px;
        color: var(--rbt-muted);
        margin: 0;
    }

    .rbt-ord-index {
        font: 700 9px Arial, sans-serif;
        letter-spacing: 2.5px;
        color: #c9c2b2;
        white-space: nowrap;
        padding-bottom: 6px;
    }


    /* ================= FLASH ================= */

    .rbt-flash {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 13px 16px;
        border-radius: 12px;
        font-size: 12.5px;
        font-weight: 500;
        margin-bottom: 16px;
    }

    .rbt-flash svg {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
    }

    .rbt-flash-ok {
        background: #eef6f0;
        border: 1px solid #cfe5d6;
        color: #2c6e4a;
    }

    .rbt-flash-err {
        background: #fdf0ec;
        border: 1px solid #f2d4c8;
        color: #a04a2c;
    }


    /* ================= FILTER BAR ================= */

    .rbt-filter-bar {
        display: flex;
        flex-direction: column;
        gap: 14px;
        margin-bottom: 26px;
    }

    .rbt-pills-scroll {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        padding-bottom: 6px;
        scrollbar-width: none;
        -ms-overflow-style: none;
        -webkit-overflow-scrolling: touch;
    }

    .rbt-pills-scroll::-webkit-scrollbar {
        display: none;
    }

    .rbt-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 9px 18px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 500;
        white-space: nowrap;
        border: 1px solid var(--rbt-border);
        color: var(--rbt-muted);
        background: #fff;
        transition: all .2s ease;
    }

    .rbt-pill:hover {
        border-color: #d5cbb6;
        color: var(--rbt-text);
    }

    .rbt-pill.is-active {
        background: linear-gradient(135deg, #15262f, #0e1a20);
        color: var(--rbt-gold-soft);
        border-color: transparent;
        box-shadow: 0 6px 16px rgba(14, 26, 32, .25);
    }


    /* ================= SEARCH ================= */

    .rbt-search {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #fff;
        border: 1px solid var(--rbt-border);
        border-radius: 14px;
        padding: 6px;
        transition: border-color .2s, box-shadow .2s;
    }

    .rbt-search:focus-within {
        border-color: var(--rbt-gold);
        box-shadow: 0 0 0 3px rgba(201, 163, 92, .14);
    }

    .rbt-search input {
        flex: 1;
        min-width: 0;
        border: 0;
        outline: none;
        background: transparent;
        padding: 10px 14px;
        font-size: 13px;
        color: var(--rbt-text);
        text-align: right;
    }

    .rbt-search input::placeholder {
        color: #b0b6b4;
        font-size: 12px;
    }

    .rbt-search-clear {
        display: grid;
        place-items: center;
        width: 32px;
        height: 32px;
        flex-shrink: 0;
        border-radius: 8px;
        color: #a8b2b6;
        transition: background .2s, color .2s;
    }

    .rbt-search-clear:hover {
        background: #f6f2ea;
        color: var(--rbt-text);
    }

    .rbt-search-clear svg {
        width: 15px;
        height: 15px;
    }

    .rbt-search-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
        min-height: 42px;
        padding: 0 20px;
        border: 0;
        border-radius: 10px;
        background: linear-gradient(135deg, #d9b878, #b08a45);
        color: #0e1a20;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: transform .2s, box-shadow .2s;
    }

    .rbt-search-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(201, 163, 92, .35);
    }

    .rbt-search-btn svg {
        width: 16px;
        height: 16px;
    }


    /* ================= ORDER CARDS ================= */

    .rbt-order-list {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .rbt-order-card {
        background: #fff;
        border: 1px solid var(--rbt-border);
        border-radius: 18px;
        padding: 24px 26px;
        position: relative;
        overflow: hidden;
        transition: transform .25s, box-shadow .25s, border-color .25s;
    }

    .rbt-order-card::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        left: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--rbt-gold), transparent);
        opacity: 0;
        transition: opacity .25s;
    }

    .rbt-order-card:hover {
        transform: translateY(-3px);
        border-color: #ddd2bd;
        box-shadow: 0 16px 36px rgba(30, 40, 45, .09);
    }

    .rbt-order-card:hover::before {
        opacity: 1;
    }

    .rbt-order-card.is-cancelled {
        opacity: .75;
    }

    .rbt-order-card.is-cancelled::before {
        background: linear-gradient(90deg, #c98, transparent);
        opacity: .5;
    }

    /* head */
    .rbt-oc-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 14px;
        margin-bottom: 18px;
    }

    .rbt-oc-id {
        display: flex;
        flex-direction: column;
        gap: 5px;
        min-width: 0;
    }

    .rbt-oc-id b {
        font-size: 15px;
        font-weight: 700;
        color: var(--rbt-ink);
        font-family: Arial, sans-serif;
        letter-spacing: .3px;
    }

    .rbt-oc-id small {
        font-size: 11px;
        color: var(--rbt-muted);
    }

    /* status badges */
    .rbt-badge {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 7px 14px;
        border-radius: 50px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .rbt-badge-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: currentColor;
    }

    .rbt-st-pending {
        background: #fdf3e3;
        color: #a06a1c;
    }

    .rbt-st-pending .rbt-badge-dot {
        animation: rbtPulse 1.6s ease-in-out infinite;
    }

    .rbt-st-processing {
        background: #eaf1f5;
        color: #2c5d78;
    }

    .rbt-st-shipped {
        background: #efe9f7;
        color: #5d4a8a;
    }

    .rbt-st-delivered {
        background: #e9f5ee;
        color: #2c6e4a;
    }

    .rbt-st-cancelled {
        background: #f5efec;
        color: #96503c;
    }

    @keyframes rbtPulse {

        0%,
        100% {
            box-shadow: 0 0 0 0 rgba(160, 106, 28, .35);
        }

        50% {
            box-shadow: 0 0 0 5px rgba(160, 106, 28, 0);
        }
    }

    /* progress tracker */
    .rbt-track {
        display: flex;
        align-items: flex-start;
        margin-bottom: 20px;
        padding: 14px 6px 4px;
        border-radius: 12px;
        background: #faf8f4;
        border: 1px solid #f0ebe0;
        overflow: hidden;
        /* اطمینان از عدم بیرون‌زدگی خط */
    }

    .rbt-track-step {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 7px;
        position: relative;
        text-align: center;
    }

    .rbt-track-step::before {
        content: '';
        position: absolute;
        top: 9px;
        left: 50%;
        width: 100%;
        height: 2px;
        background: #e5ddcc;
    }

    /* اولین مرحله (راست‌ترین) مرحله قبلی ندارد */
    .rbt-track-step:first-child::before {
        display: none;
    }

    .rbt-track-step.is-done::before,
    .rbt-track-step.is-current::before {
        background: linear-gradient(90deg, #d9b878, var(--rbt-gold));
    }

    .rbt-track-dot {
        position: relative;
        z-index: 1;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        background: #fff;
        border: 2px solid #e0d7c4;
        color: #fff;
    }

    .rbt-track-dot svg {
        width: 11px;
        height: 11px;
    }

    .rbt-track-step.is-done .rbt-track-dot {
        background: var(--rbt-gold);
        border-color: var(--rbt-gold);
    }

    .rbt-track-step.is-current .rbt-track-dot {
        background: var(--rbt-ink);
        border-color: var(--rbt-gold);
        box-shadow: 0 0 0 4px rgba(201, 163, 92, .2);
    }

    .rbt-track-step small {
        font-size: 9.5px;
        color: #a8a294;
        white-space: nowrap;
    }

    .rbt-track-step.is-done small,
    .rbt-track-step.is-current small {
        color: var(--rbt-ink);
        font-weight: 700;
    }

    /* financials */
    .rbt-oc-body {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 10px 24px;
        padding: 16px 0;
        border-top: 1px solid var(--rbt-border);
        border-bottom: 1px solid var(--rbt-border);
        margin-bottom: 16px;
    }

    .rbt-oc-row {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .rbt-oc-row span {
        font-size: 10.5px;
        color: var(--rbt-muted);
    }

    .rbt-oc-row b {
        font-size: 13.5px;
        font-weight: 700;
        color: var(--rbt-text);
        font-variant-numeric: tabular-nums;
    }

    .rbt-oc-row .rbt-ok {
        color: #2c6e4a;
    }

    .rbt-oc-row .rbt-warn {
        color: #a06a1c;
    }

    .rbt-oc-row .rbt-debt {
        color: #a04a2c;
    }

    .rbt-oc-total b {
        font-size: 14.5px;
    }

    /* actions */
    .rbt-oc-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 9px;
    }

    .rbt-inline-form {
        display: inline-flex;
        margin: 0;
    }

    .rbt-cancel-dialog {
        width: min(420px, calc(100% - 32px));
        padding: 28px;
        border: 1px solid #e9e4da;
        border-radius: 20px;
        color: #22333b;
        text-align: center;
        font-family: 'Vazirmatn', Tahoma, sans-serif;
        box-shadow: 0 24px 80px rgba(14, 26, 32, .24);
    }

    .rbt-cancel-dialog::backdrop {
        background: rgba(10, 20, 25, .48);
        backdrop-filter: blur(3px);
    }

    .rbt-cancel-dialog-icon {
        display: grid;
        place-items: center;
        width: 46px;
        height: 46px;
        margin: 0 auto 14px;
        border-radius: 50%;
        background: #fdf0ec;
        color: #a04a2c;
        font-size: 24px;
        font-weight: 700;
    }

    .rbt-cancel-dialog h2 {
        margin: 0 0 8px;
        font-size: 17px;
    }

    .rbt-cancel-dialog p {
        margin: 0 0 22px;
        color: #7d8a90;
        font-size: 12.5px;
        line-height: 1.9;
    }

    .rbt-cancel-dialog-actions {
        display: flex;
        justify-content: center;
        gap: 9px;
    }

    .rbt-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 42px;
        padding: 0 18px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 700;
        border: 1px solid transparent;
        font-family: inherit;
        cursor: pointer;
        transition: transform .2s, box-shadow .2s, background .2s, border-color .2s, color .2s;
    }

    .rbt-btn svg {
        width: 16px;
        height: 16px;
        flex-shrink: 0;
    }

    .rbt-btn-gold {
        background: linear-gradient(135deg, #d9b878, #b08a45);
        color: #0e1a20;
    }

    .rbt-btn-gold:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 18px rgba(201, 163, 92, .35);
    }

    .rbt-btn-ghost {
        background: transparent;
        color: var(--rbt-text);
        border-color: var(--rbt-border);
    }

    .rbt-btn-ghost:hover {
        border-color: var(--rbt-gold);
        color: var(--rbt-ink);
        transform: translateY(-2px);
    }

    .rbt-btn-danger {
        background: transparent;
        color: #a04a2c;
        border-color: #e8c9bd;
    }

    .rbt-btn-danger:hover {
        background: #fdf0ec;
        border-color: #a04a2c;
    }

    .rbt-btn-disabled {
        background: #f6f2ea;
        color: var(--rbt-muted);
        cursor: default;
    }


    /* ================= PAGINATION ================= */

    .rbt-pagination {
        display: flex;
        gap: 7px;
        justify-content: center;
        margin: 28px 0 0;
        flex-wrap: wrap;
    }

    .rbt-page-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 40px;
        height: 40px;
        padding: 0 14px;
        border-radius: 10px;
        border: 1px solid var(--rbt-border);
        background: #fff;
        color: var(--rbt-muted);
        font-size: 12.5px;
        font-weight: 500;
        transition: all .2s;
    }

    .rbt-page-btn:hover {
        border-color: var(--rbt-gold);
        color: var(--rbt-ink);
    }

    .rbt-page-btn.is-active {
        background: linear-gradient(135deg, #15262f, #0e1a20);
        color: var(--rbt-gold-soft);
        border-color: transparent;
        font-weight: 700;
    }


    /* ================= EMPTY STATE ================= */

    .rbt-empty {
        text-align: center;
        padding: 60px 24px;
        border: 1px dashed #ddd2bd;
        border-radius: 18px;
        background: #fcfaf6;
    }

    .rbt-empty-icon {
        width: 70px;
        height: 70px;
        margin: 0 auto 20px;
        display: grid;
        place-items: center;
        border-radius: 18px;
        background: linear-gradient(160deg, #12242e, #0e1a20);
        color: var(--rbt-gold);
    }

    .rbt-empty-icon svg {
        width: 34px;
        height: 34px;
    }

    .rbt-empty h2 {
        font-size: 19px;
        font-weight: 700;
        margin: 0 0 8px;
        color: var(--rbt-text);
    }

    .rbt-empty p {
        font-size: 12.5px;
        color: var(--rbt-muted);
        margin: 0 0 22px;
    }


    /* ================= RESPONSIVE ================= */

    @media (max-width: 760px) {
        .rbt-orders {
            padding: 0 15px 40px;
        }

        .rbt-ord-head {
            padding: 18px 0 14px;
            margin-bottom: 16px;
        }

        .rbt-ord-head h1 {
            font-size: 23px;
        }

        .rbt-ord-index {
            display: none;
        }

        .rbt-pill {
            padding: 8px 15px;
            font-size: 11px;
        }

        .rbt-search {
            padding: 5px;
        }

        .rbt-search-btn {
            min-height: 40px;
            padding: 0 14px;
        }

        .rbt-search-btn span {
            display: none;
        }

        /* فقط آیکون در موبایل */

        .rbt-order-card {
            padding: 18px 16px;
            border-radius: 15px;
        }

        .rbt-oc-head {
            flex-direction: column;
            gap: 10px;
            margin-bottom: 14px;
        }

        .rbt-badge {
            align-self: flex-start;
        }

        .rbt-track {
            padding: 12px 2px 4px;
        }

        .rbt-track-step small {
            font-size: 8.5px;
        }

        .rbt-track-dot {
            width: 17px;
            height: 17px;
        }

        .rbt-oc-body {
            grid-template-columns: 1fr 1fr;
            gap: 12px 16px;
            padding: 14px 0;
        }

        .rbt-oc-row b {
            font-size: 12.5px;
        }

        .rbt-oc-actions {
            flex-direction: column;
        }

        .rbt-inline-form {
            width: 100%;
        }

        .rbt-btn {
            width: 100%;
        }

        .rbt-empty {
            padding: 44px 18px;
        }
    }

    @media (max-width: 390px) {
        .rbt-orders {
            padding-right: 11px;
            padding-left: 11px;
        }

        .rbt-oc-body {
            grid-template-columns: 1fr;
        }

        .rbt-track-step small {
            font-size: 8px;
        }
    }


    /* ================= ACCESSIBILITY ================= */

    .rbt-orders a:focus-visible,
    .rbt-orders button:focus-visible,
    .rbt-orders input:focus-visible {
        outline: 3px solid rgba(201, 163, 92, .4);
        outline-offset: 2px;
    }

    @media (prefers-reduced-motion: reduce) {

        .rbt-orders *,
        .rbt-orders *::before,
        .rbt-orders *::after {
            transition-duration: .01ms !important;
            animation-duration: .01ms !important;
        }
    }
</style>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
