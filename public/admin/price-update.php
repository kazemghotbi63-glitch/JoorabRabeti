<?php
/* [FILE] public/admin/price-update.php — افزایش/کاهش گروهی قیمت */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

require_admin();

$db = db();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$toEn = static function (string $raw): int {
    $clean = trim(str_replace([',', '٬', ' '], '', strtr(
        $raw,
        ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']
    )));
    return filter_var($clean, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
};

$categories = $db->query("SELECT id, name FROM categories WHERE is_active=1 ORDER BY sort_order")->fetchAll();

$preview = null;
$applied = false;

/* ═══ پیش‌نمایش تغییرات (بدون ذخیره) ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'preview') {
    $mode     = $_POST['mode'] ?? 'percent';           // percent | amount
    $value    = (int)($_POST['value'] ?? 0);
    $sign     = ($_POST['sign'] ?? 'increase') === 'decrease' ? -1 : 1;
    $scope    = $_POST['scope'] ?? 'all';              // all | category
    $catId    = (int)($_POST['category_id'] ?? 0);
    $round    = (int)($_POST['round_digits'] ?? 4);    // 4 = چهار رقم آخر صفر

    if ($value <= 0) {
        $preview = ['error' => 'مقدار تغییر را وارد کنید.'];
    } elseif ($scope === 'category' && $catId < 1) {
        $preview = ['error' => 'دسته را انتخاب کنید.'];
    } else {
        /* گرد کردن: ۴ رقم آخر صفر (به تومان) */
        $roundFactor = (int)pow(10, $round);

        $sql = "
            SELECT pp.id, pp.product_id, pp.price,
                   p.code, p.name, c.name AS cat_name
            FROM product_prices pp
            INNER JOIN products p ON p.id = pp.product_id
            INNER JOIN categories c ON c.id = p.category_id
            WHERE pp.customer_group = 'wholesale' AND pp.min_qty = 1
              AND pp.price > 0
        ";
        $params = [];
        if ($scope === 'category') {
            $sql .= " AND p.category_id = ? ";
            $params[] = $catId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        $list = [];
        foreach ($items as $it) {
            $old = (int)$it['price'];

            if ($mode === 'percent') {
                $raw = $old + ($sign * (int)round($old * $value / 100));
            } else {
                $raw = $old + ($sign * $value);
            }

            if ($raw < 1000) $raw = 1000;   /* کف قیمت */

            /* گرد کردن به مضرب ۱۰^n */
            $new = (int)(round($raw / $roundFactor) * $roundFactor);
            if ($new < $roundFactor) $new = $roundFactor;

            if ($new !== $old) {
                $list[] = [
                    'id' => (int)$it['id'],
                    'product_id' => (int)$it['product_id'],
                    'code' => $it['code'],
                    'name' => $it['name'],
                    'cat' => $it['cat_name'],
                    'old' => $old,
                    'new' => $new,
                    'diff' => $new - $old,
                ];
            }
        }

        $preview = [
            'items' => $list,
            'mode' => $mode,
            'value' => $value,
            'sign' => $sign,
            'scope' => $scope,
            'catId' => $catId,
            'round' => $round
        ];
    }
}

/* ═══ اعمال نهایی ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'apply') {

    $changes = json_decode((string)($_POST['changes'] ?? '[]'), true);

    if (!is_array($changes) || !$changes) {
        header('Location: /admin/price-update?error=' . urlencode('تغییری برای اعمال نیست.'));
        exit;
    }

    try {
        $db->beginTransaction();

        $upd = $db->prepare("UPDATE product_prices SET price = ? WHERE id = ?");
        $applied = 0;

        foreach ($changes as $c) {
            $upd->execute([(int)$c['new'], (int)$c['id']]);
            $applied += $upd->rowCount();
        }

        /* نتیجه مالی همه فاکتورها را به‌روز نکنیم — قیمت‌های قدیمی snapshot هستند ✅ */

        $db->prepare("INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
                      VALUES (?, 'prices.bulk_update', 'product_prices', NULL, ?)")
            ->execute([$_SESSION['admin_id'], $_SERVER['REMOTE_ADDR'] ?? null]);

        $db->commit();

        header('Location: /admin/price-update?success=' . urlencode(
            'قیمت ' . $applied . ' محصول به‌روزرسانی شد.'
        ));
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        header('Location: /admin/price-update?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$pageTitle  = 'تغییر گروهی قیمت';
$activeMenu = 'price-update';

ob_start();
?>

<nav class="breadcrumb"><a href="/">خانه</a> › <span>تغییر گروهی قیمت</span></nav>

<section class="catalog-head">
    <h1 class="catalog-title">افزایش / کاهش گروهی قیمت</h1>
    <p class="catalog-sub">بر اساس درصد یا مبلغ — برای همه محصولات یا یک دسته — با پیش‌نمایش قبل از اعمال</p>
</section>

<?php if (isset($_GET['success'])): ?>
    <div class="form-alert" style="display:block;margin-bottom:16px;background:#e8f7f3;color:var(--teal-800);">
        ✅ <?= $h($_GET['success']) ?>
    </div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <div class="form-alert error" style="display:block;margin-bottom:16px;">⚠️ <?= $h($_GET['error']) ?></div>
<?php endif; ?>

<!-- ═══ فرم تنظیم تغییر ═══ -->
<div class="admin-card" style="padding:18px;margin-bottom:20px;">
    <form method="post">
        <input type="hidden" name="do" value="preview">

        <div style="display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:14px;">

            <div class="form-field">
                <label>نوع تغییر</label>
                <select name="mode">
                    <option value="percent">بر اساس درصد (٪)</option>
                    <option value="amount">بر اساس مبلغ (تومان)</option>
                </select>
            </div>

            <div class="form-field">
                <label>جهت تغییر</label>
                <select name="sign">
                    <option value="increase">↑ افزایش</option>
                    <option value="decrease">↓ کاهش</option>
                </select>
            </div>

            <div class="form-field">
                <label>مقدار</label>
                <input type="number" name="value" min="1" value="5" required>
                <span class="field-hint">درصد یا تومان</span>
            </div>

            <div class="form-field">
                <label>گرد کردن — ارقام آخر صفر</label>
                <select name="round_digits">
                    <option value="4" selected>۴ رقم آخر صفر (۱۰,۰۰۰)</option>
                    <option value="5">۵ رقم آخر صفر (۱۰۰,۰۰۰)</option>
                    <option value="3">۳ رقم آخر صفر (۱,۰۰۰)</option>
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
            <div class="form-field">
                <label>دامنه تغییر</label>
                <div style="display:flex;gap:14px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;">
                        <input type="radio" name="scope" value="all" checked> همه محصولات
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;">
                        <input type="radio" name="scope" value="category"> فقط دسته:
                    </label>
                    <select name="category_id" style="min-width:180px;padding:8px 10px;
                        border:1px solid #dbe2ea;border-radius:9px;font-family:inherit;">
                        <option value="0">— انتخاب دسته —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= $h($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-orange" style="padding:11px 24px;font-size:.82rem;">
                👁 نمایش پیش‌نمایش
            </button>
        </div>
    </form>
</div>

<!-- ═══ پیش‌نمایش ═══ -->
<?php if (is_array($preview)): ?>
    <?php if (isset($preview['error'])): ?>
        <div class="form-alert error" style="display:block;">⚠️ <?= $h($preview['error']) ?></div>
    <?php elseif (empty($preview['items'])): ?>
        <div class="form-alert" style="display:block;background:#e8f7f3;color:var(--teal-800);">
            با این تنظیمات هیچ قیمتی تغییر نمی‌کند (همه گرد شده‌اند به همان مقدار قبلی).
        </div>
    <?php else: ?>

        <div class="form-alert" style="display:block;background:#e8f7f3;color:var(--teal-800);margin-bottom:14px;">
            ✅ <?= count($preview['items']) ?> محصول تغییر می‌کند — بررسی کنید و تأیید نهایی را بزنید
        </div>

        <div class="admin-table-wrap" style="margin-bottom:16px;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>کد</th>
                        <th>محصول</th>
                        <th>دسته</th>
                        <th>قیمت فعلی</th>
                        <th>قیمت جدید</th>
                        <th>تغییر</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview['items'] as $it): ?>
                        <tr>
                            <td dir="ltr"><b><?= $h($it['code']) ?></b></td>
                            <td><?= $h($it['name']) ?></td>
                            <td><?= $h($it['cat']) ?></td>
                            <td><?= number_format($it['old']) ?></td>
                            <td style="color:<?= $it['diff'] > 0 ? 'var(--admin-success)' : 'var(--admin-danger)' ?>;font-weight:800;">
                                <?= number_format($it['new']) ?>
                            </td>
                            <td style="color:<?= $it['diff'] > 0 ? 'var(--admin-success)' : 'var(--admin-danger)' ?>;">
                                <?= $it['diff'] > 0 ? '+' : '' ?><?= number_format($it['diff']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- فرم تأیید نهایی — تغییرات در فیلد مخفی -->
        <form method="post" onsubmit="return confirm('قیمت <?= count($preview['items']) ?> محصول اعمال شود؟ این عملیات قابل بازگشت فوری نیست.');">
            <input type="hidden" name="do" value="apply">
            <input type="hidden" name="changes" value='<?= $h(json_encode($preview['items'], JSON_UNESCAPED_UNICODE)) ?>'>
            <button type="submit" class="btn btn-orange" style="padding:12px 28px;font-size:.85rem;">
                ✓ تأیید و اعمال تغییرات (<?= count($preview['items']) ?> محصول)
            </button>
        </form>

    <?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
