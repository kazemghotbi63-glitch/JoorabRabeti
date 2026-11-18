<?php
/* ════ RABETI-CUSTOM-ORDER | تنظیمات سفارشات اختصاصی (ادمین) | v2 | 2026-10-25 ════ */

declare(strict_types=1);

/* ═══ Bootstrap ═══ */
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

if (session_status() === PHP_SESSION_NONE) session_start();

/* ═══════════════════════════════════════════════════════════════
   کلاس مدیریت تنظیمات
═══════════════════════════════════════════════════════════════ */
class CustomOrderSettings
{
    private static array $cache = [];

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        try {
            $stmt = db()->prepare("SELECT setting_value, setting_type FROM custom_order_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if (!$row) return $default;

            $val = $row['setting_value'];
            $val = match ($row['setting_type']) {
                'int'     => (int)$val,
                'decimal' => (float)$val,
                'bool'    => (bool)(int)$val,
                'json'    => json_decode((string)$val, true) ?: [],
                default   => $val,
            };
            self::$cache[$key] = $val;
            return $val;
        } catch (Throwable $e) {
            return $default;
        }
    }

    public static function set(string $key, $value): bool
    {
        try {
            $type = self::getType($key);
            $stored = match ($type) {
                'bool'  => $value ? '1' : '0',
                'json'  => json_encode($value, JSON_UNESCAPED_UNICODE),
                default => (string)$value,
            };
            $stmt = db()->prepare("UPDATE custom_order_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
            $stmt->execute([$stored, $key]);
            unset(self::$cache[$key]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function all(?string $group = null): array
    {
        try {
            $sql = "SELECT * FROM custom_order_settings";
            $params = [];
            if ($group) {
                $sql .= " WHERE setting_group = ?";
                $params[] = $group;
            }
            $sql .= " ORDER BY sort_order, id";
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function getType(string $key): string
    {
        try {
            $stmt = db()->prepare("SELECT setting_type FROM custom_order_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            return (string)$stmt->fetchColumn() ?: 'string';
        } catch (Throwable $e) {
            return 'string';
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   گارد ادمین
═══════════════════════════════════════════════════════════════ */
require_admin();

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$msg = '';

/* ═══ POST ذخیره ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $values = $_POST['settings'] ?? [];

        foreach ($values as $key => $value) {
            CustomOrderSettings::set((string)$key, $value);
        }

        // چک‌باکس‌های unchecked
        $allBools = array_filter(CustomOrderSettings::all(), fn($s) => $s['setting_type'] === 'bool');
        foreach ($allBools as $s) {
            if (!isset($values[$s['setting_key']])) {
                CustomOrderSettings::set($s['setting_key'], '0');
            }
        }

        $_SESSION['co_settings_flash'] = 'تنظیمات با موفقیت ذخیره شد.';
        header('Location: custom-order-settings.php');
        exit;
    } catch (Throwable $e) {
        $msg = 'خطا در ذخیره: ' . $e->getMessage();
    }
}

if (!empty($_SESSION['co_settings_flash'])) {
    $msg = (string)$_SESSION['co_settings_flash'];
    unset($_SESSION['co_settings_flash']);
}

/* ═══ گروه‌بندی ═══ */
$groups = [
    'order'     => '📦 سفارش و MOQ',
    'quotation' => '🧾 پیش‌فاکتور',
    'sample'    => '🔬 نمونه',
    'payment'   => '💳 پرداخت',
    'shipping'  => '🚚 ارسال',
    'sms'       => '📱 اعلان SMS',
    'contract'  => '📜 قرارداد',
    'general'   => '⚙️ عمومی',
];

$allSettings = CustomOrderSettings::all();
$settingsByGroup = [];
foreach ($allSettings as $s) {
    $settingsByGroup[$s['setting_group']][] = $s;
}

$coDbError = '';
if (!$allSettings) {
    $coDbError = 'جدول custom_order_settings خالی است یا وجود ندارد. لطفاً SQL ساخت جدول را اجرا کنید.';
}

/* ───────── رندر — داخل لایوت ادمین ───────── */
$activeMenu = 'custom';
$pageTitle  = 'تنظیمات سفارش‌های اختصاصی';

/* استایل اختصاصی این صفحه — همهٔ انتخابگرها زیر .co-settings نام‌گذاری شده‌اند
   تا با admin.css (که کلاس‌هایی مثل .btn دارد) تداخل نکنند. */
$pageStyles = <<<'STYLE'
<style>
.co-settings { font-family: inherit; direction: rtl; font-size: 13px; color: var(--admin-text); }
.co-settings .container { max-width: 1100px; margin: 0 auto; }
.co-settings .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px; }
.co-settings .header h1 { color: var(--admin-primary); font-size: 22px; }
.co-settings .header a { color: var(--admin-primary); text-decoration:none; font-size:13px; font-weight:600; padding:10px 16px; background:#fff; border:1px solid var(--admin-border); border-radius:8px; }
.co-settings .header a:hover { background: var(--admin-primary-soft); }
.co-settings .tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
.co-settings .tab { padding:10px 18px; background:#fff; border:1px solid var(--admin-border); border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; color: var(--admin-text-muted); transition: all .15s; }
.co-settings .tab:hover { background: var(--admin-surface-soft); }
.co-settings .tab.active { background: var(--admin-primary); color:#fff; border-color: var(--admin-primary); }
.co-settings .panel { display:none; background: var(--admin-surface); padding:24px; border-radius: var(--admin-radius-md); box-shadow: var(--admin-shadow-sm); border:1px solid var(--admin-border); }
.co-settings .panel.active { display:block; }
.co-settings .form-group { margin-bottom:20px; padding-bottom:20px; border-bottom:1px solid #f3f4f6; }
.co-settings .form-group:last-child { border-bottom:none; padding-bottom:0; }
.co-settings .form-group label { display:block; font-size:13px; font-weight:700; margin-bottom:8px; color: var(--admin-text); }
.co-settings .form-group input[type="text"],
.co-settings .form-group input[type="number"],
.co-settings .form-group textarea { width:100%; padding:10px 12px; border:1px solid var(--admin-border-strong); border-radius:8px; font-family:inherit; font-size:13px; background:#fff; }
.co-settings .form-group input:focus,
.co-settings .form-group textarea:focus { outline:none; border-color: var(--admin-primary); box-shadow: 0 0 0 3px rgba(23,62,74,.1); }
.co-settings .form-group textarea { min-height:100px; resize:vertical; }
.co-settings .form-group .hint { font-size:11px; color: var(--admin-text-muted); margin-top:6px; }
.co-settings .checkbox-wrap { display:flex; align-items:center; gap:10px; padding:12px; background: var(--admin-surface-soft); border-radius:8px; }
.co-settings .checkbox-wrap input[type="checkbox"] { width:20px; height:20px; cursor:pointer; }
.co-settings .checkbox-wrap span { font-size:13px; color: var(--admin-text); }
.co-settings .co-btn { padding:12px 32px; background: var(--admin-primary); color:#fff; border:0; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; font-family:inherit; }
.co-settings .co-btn:hover { background: var(--admin-primary-dark); }
.co-settings .msg { padding:12px 16px; background: var(--admin-success-soft); color: var(--admin-success); border:1px solid #86efac; border-radius:8px; margin-bottom:18px; font-weight:600; }
.co-settings .msg.err { background: var(--admin-danger-soft); color: var(--admin-danger); border-color: #fca5a5; }
.co-settings .save-bar { position:sticky; bottom:0; background: var(--admin-surface); padding:16px 24px; border-top:2px solid var(--admin-border); margin-top:20px; text-align:left; box-shadow: 0 -4px 12px rgba(0,0,0,.05); border-radius: var(--admin-radius-md); }
</style>
STYLE;

ob_start();
?>

<!-- ═══ هدر صفحه ═══ -->
<div class="admin-page-intro">
    <div>
        <span class="admin-kicker">مدیریت فروش</span>
        <h1>⚙️ تنظیمات سفارش‌های اختصاصی</h1>
        <p>پیکربندی گردش‌کار، اعلان‌ها و مقادیر پیش‌فرض ماژول سفارش اختصاصی.</p>
    </div>
    <a href="/admin/custom-orders.php" class="admin-soft-action">← بازگشت به لیست سفارشات</a>
</div>

<div class="co-settings">
    <div class="container">
        <div class="header" style="display:none">
            <h1>⚙️ تنظیمات سفارشات اختصاصی</h1>
            <a href="custom-orders.php">← بازگشت به لیست سفارشات</a>
        </div>

        <?php if ($coDbError): ?>
            <div class="msg err">⚠️ <?= $h($coDbError) ?></div>
        <?php endif; ?>

        <?php if ($msg): ?><div class="msg">✅ <?= $h($msg) ?></div><?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>

            <div class="tabs">
                <?php foreach ($groups as $gk => $gl): ?>
                    <button type="button" class="tab <?= $gk === 'order' ? 'active' : '' ?>" data-tab="<?= $h($gk) ?>">
                        <?= $h($gl) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($groups as $gk => $gl): ?>
                <div class="panel <?= $gk === 'order' ? 'active' : '' ?>" data-panel="<?= $h($gk) ?>">
                    <?php if (!empty($settingsByGroup[$gk])): ?>
                        <?php foreach ($settingsByGroup[$gk] as $s): ?>
                            <div class="form-group">
                                <label><?= $h($s['setting_label']) ?></label>

                                <?php if ($s['setting_type'] === 'bool'): ?>
                                    <div class="checkbox-wrap">
                                        <input type="checkbox"
                                            name="settings[<?= $h($s['setting_key']) ?>]"
                                            value="1"
                                            <?= (int)$s['setting_value'] ? 'checked' : '' ?>>
                                        <span><?= $s['setting_hint'] ? $h($s['setting_hint']) : 'فعال / غیرفعال' ?></span>
                                    </div>

                                <?php elseif ($s['setting_type'] === 'text'): ?>
                                    <textarea name="settings[<?= $h($s['setting_key']) ?>]"><?= $h($s['setting_value']) ?></textarea>
                                    <?php if ($s['setting_hint']): ?><p class="hint"><?= $h($s['setting_hint']) ?></p><?php endif; ?>

                                <?php elseif ($s['setting_type'] === 'json'): ?>
                                    <textarea name="settings[<?= $h($s['setting_key']) ?>]" dir="ltr" style="font-family:monospace;font-size:12px;"><?= $h($s['setting_value']) ?></textarea>
                                    <?php if ($s['setting_hint']): ?><p class="hint"><?= $h($s['setting_hint']) ?> — فرمت JSON</p><?php endif; ?>

                                <?php elseif ($s['setting_type'] === 'int'): ?>
                                    <input type="number" name="settings[<?= $h($s['setting_key']) ?>]" value="<?= $h($s['setting_value']) ?>">
                                    <?php if ($s['setting_hint']): ?><p class="hint"><?= $h($s['setting_hint']) ?></p><?php endif; ?>

                                <?php elseif ($s['setting_type'] === 'decimal'): ?>
                                    <input type="number" step="0.01" name="settings[<?= $h($s['setting_key']) ?>]" value="<?= $h($s['setting_value']) ?>">
                                    <?php if ($s['setting_hint']): ?><p class="hint"><?= $h($s['setting_hint']) ?></p><?php endif; ?>

                                <?php else: ?>
                                    <input type="text" name="settings[<?= $h($s['setting_key']) ?>]" value="<?= $h($s['setting_value']) ?>">
                                    <?php if ($s['setting_hint']): ?><p class="hint"><?= $h($s['setting_hint']) ?></p><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color:#9ca3af;text-align:center;padding:30px;">تنظیماتی در این گروه وجود ندارد.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="save-bar">
                <button type="submit" class="co-btn">💾 ذخیره همه تنظیمات</button>
            </div>
        </form>
    </div>
</div><!-- /.co-settings -->

    <script>
        document.querySelectorAll('.co-settings .tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                var target = this.dataset.tab;
                document.querySelectorAll('.co-settings .tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.co-settings .panel').forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                var panel = document.querySelector('.co-settings .panel[data-panel="' + target + '"]');
                if (panel) panel.classList.add('active');
            });
        });
    </script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
