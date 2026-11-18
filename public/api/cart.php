<?php
/* Auto-detect bootstrap (local + host) */
$__bp = [
    __DIR__ . '/../../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../../config/bootstrap.php',
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

header('Content-Type: application/json; charset=utf-8');

$db = db();
$isCustomer = is_customer_logged_in();
$userId = $isCustomer ? (int)($_SESSION['customer_id'] ?? 0) : 0;

/* ═══════════════════════════════════════════════════════════
 * سبد مهمان (Session)
 * ═══════════════════════════════════════════════════════════ */
function getGuestCart(): array
{
    if (!isset($_SESSION['guest_cart']) || !is_array($_SESSION['guest_cart'])) {
        $_SESSION['guest_cart'] = [];
    }
    return $_SESSION['guest_cart'];
}
function saveGuestCart(array $cart): void
{
    $_SESSION['guest_cart'] = $cart;
}
function guestCartCount(): int
{
    return array_sum(getGuestCart());
}

/* ═══════════════════════════════════════════════════════════
 * سبد کاربر (DB)
 * ═══════════════════════════════════════════════════════════ */
function getOrCreateCart(PDO $db, int $userId): int
{
    $stmt = $db->prepare("SELECT id FROM carts WHERE user_id = ? AND status='active' LIMIT 1");
    $stmt->execute([$userId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

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
function cartCount(PDO $db, int $cartId): int
{
    $stmt = $db->prepare("SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE cart_id = ?");
    $stmt->execute([$cartId]);
    return (int)$stmt->fetchColumn();
}

/* ═══════════════════════════════════════════════════════════
 * پردازش درخواست
 * ═══════════════════════════════════════════════════════════ */

$method = $_SERVER['REQUEST_METHOD'];

/* ─── POST: add / update / remove ─── */
if ($method === 'POST') {
    verify_csrf();

    $action = trim($_POST['action'] ?? 'add'); // add | update | remove
    $productId = (int)($_POST['product_id'] ?? 0);
    $qty = max(0, (int)($_POST['qty'] ?? 0));

    /* ═══ حالت مهمان ═══ */
    if (!$isCustomer) {
        $cart = getGuestCart();

        if ($action === 'remove' || $action === 'update' && $qty <= 0) {
            unset($cart[$productId]);
            saveGuestCart($cart);
            echo json_encode(['ok' => true, 'removed' => true, 'count' => guestCartCount()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'update') {
            if ($productId < 1 || $qty < 1) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'تعداد نامعتبر'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $cart[$productId] = $qty;
            saveGuestCart($cart);
            echo json_encode(['ok' => true, 'qty' => $qty, 'count' => guestCartCount()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        /* add */
        if ($productId < 1) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'محصول نامعتبر'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $db->prepare("SELECT id, base_moq, GREATEST(0, COALESCE(inv.qty_physical,0)-COALESCE(inv.reserved,0)-COALESCE(inv.unsellable,0)) AS stock FROM products p LEFT JOIN inventory inv ON inv.product_id=p.id WHERE p.id=? AND p.is_active=1 LIMIT 1");
        $stmt->execute([$productId]);
        $p = $stmt->fetch();
        if (!$p) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'محصول یافت نشد'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $inCart = (int)($cart[$productId] ?? 0);
        if ((int)$p['stock'] < $inCart + $qty) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'موجودی کافی نیست'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $cart[$productId] = $inCart + $qty;
        saveGuestCart($cart);
        echo json_encode(['ok' => true, 'count' => guestCartCount(), 'msg' => '✓ به سبد اضافه شد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ═══ حالت کاربر لاگین‌کرده ═══ */
    $cartId = getOrCreateCart($db, $userId);

    if ($action === 'remove' || ($action === 'update' && $qty <= 0)) {
        $db->prepare("DELETE FROM cart_items WHERE cart_id=? AND product_id=?")->execute([$cartId, $productId]);
        echo json_encode(['ok' => true, 'removed' => true, 'count' => cartCount($db, $cartId)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update') {
        $db->prepare("UPDATE cart_items SET qty=? WHERE cart_id=? AND product_id=?")->execute([$qty, $cartId, $productId]);
        echo json_encode(['ok' => true, 'qty' => $qty, 'count' => cartCount($db, $cartId)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* add */
    $stmt = $db->prepare("SELECT qty FROM cart_items WHERE cart_id=? AND product_id=? LIMIT 1");
    $stmt->execute([$cartId, $productId]);
    $inCart = (int)($stmt->fetchColumn() ?: 0);

    $stmt = $db->prepare("SELECT GREATEST(0, COALESCE(inv.qty_physical,0)-COALESCE(inv.reserved,0)-COALESCE(inv.unsellable,0)) AS stock FROM products p LEFT JOIN inventory inv ON inv.product_id=p.id WHERE p.id=? LIMIT 1");
    $stmt->execute([$productId]);
    $stock = (int)$stmt->fetchColumn();

    if ($stock < $inCart + $qty) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'موجودی کافی نیست'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db->prepare("INSERT INTO cart_items (cart_id, product_id, qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)")
        ->execute([$cartId, $productId, $qty]);

    echo json_encode(['ok' => true, 'count' => cartCount($db, $cartId), 'msg' => '✓ به سبد اضافه شد'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ─── GET: خواندن سبد ─── */
if ($method === 'GET') {
    if ($isCustomer) {
        $cartId = getOrCreateCart($db, $userId);
        $stmt = $db->prepare("SELECT product_id, qty FROM cart_items WHERE cart_id=?");
        $stmt->execute([$cartId]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[(string)$row['product_id']] = (int)$row['qty'];
        }
        echo json_encode(['ok' => true, 'items' => $items, 'count' => cartCount($db, $cartId)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cart = getGuestCart();
    $items = [];
    foreach ($cart as $pid => $qty) {
        $items[(string)$pid] = (int)$qty;
    }
    echo json_encode(['ok' => true, 'items' => $items, 'count' => guestCartCount()], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'درخواست نامعتبر'], JSON_UNESCAPED_UNICODE);
