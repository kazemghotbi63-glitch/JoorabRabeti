<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

/* سبد فقط برای کاربر وارد‌شده (ادمین یا مشتری) */
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'برای خرید ابتدا وارد شوید'], JSON_UNESCAPED_UNICODE);
    exit;
}

 $userId = $_SESSION['admin_id'] ?? $_SESSION['customer_id'];
 $db = db();

/* helper: سبد فعال کاربر را بگیر یا بساز */
/* helper: سبد فعال کاربر — بازیافت سبد تبدیل‌شده یا ساخت جدید */
function getOrCreateCart(PDO $db, int $userId): int {
    $stmt = $db->prepare("SELECT id FROM carts WHERE user_id = ? AND status='active' LIMIT 1");
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    /* سبد قدیمی (converted) وجود دارد → همان را دوباره فعال کن */
    $stmt = $db->prepare("SELECT id FROM carts WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $db->prepare("UPDATE carts SET status='active' WHERE id=?")->execute([$id]);
        return (int)$id;
    }

    $db->prepare("INSERT INTO carts (user_id) VALUES (?)")->execute([$userId]);
    return (int)$db->lastInsertId();
}

/* helper: تعداد اقلام سبد */
function cartCount(PDO $db, int $cartId): int {
    $stmt = $db->prepare("SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE cart_id = ?");
    $stmt->execute([$cartId]);
    return (int)$stmt->fetchColumn();
}

 $method = $_SERVER['REQUEST_METHOD'];
 $action = $_GET['action'] ?? '';

if ($method === 'POST') {

    verify_csrf();

    $productId = (int)($_POST['product_id'] ?? 0);
    $qty       = max(1, (int)($_POST['qty'] ?? 1));

    if ($productId < 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'محصول نامعتبر'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* محصول فعال و موجود؟ (اعتبارسنجی سرور — نه فقط فرانت) */
    $stmt = $db->prepare("
        SELECT p.id, p.base_moq, COALESCE(inv.qty_available,0) AS stock
        FROM products p
        LEFT JOIN inventory inv ON inv.product_id = p.id
        WHERE p.id = ? AND p.is_active = 1 LIMIT 1
    ");
    $stmt->execute([$productId]);
    $p = $stmt->fetch();

    if (!$p) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'محصول یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($p['stock'] < $qty) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'موجودی کافی نیست — موجودی فعلی: ' . number_format($p['stock']) . ' جفت'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cartId = getOrCreateCart($db, $userId);

    /* Upsert: اگر قبلاً در سبد است، جمع شود */
    $db->prepare("
        INSERT INTO cart_items (cart_id, product_id, qty)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
    ")->execute([$cartId, $productId, $qty]);

    echo json_encode([
        'ok'   => true,
        'count' => cartCount($db, $cartId),
        'msg'  => '✓ به سبد اضافه شد'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET' && $action === 'count') {
    $stmt = $db->prepare("SELECT id FROM carts WHERE user_id = ? AND status='active' LIMIT 1");
    $stmt->execute([$userId]);
    $cartId = (int)($stmt->fetchColumn() ?: 0);
    echo json_encode(['ok' => true, 'count' => $cartId ? cartCount($db, $cartId) : 0]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'درخواست نامعتبر'], JSON_UNESCAPED_UNICODE);