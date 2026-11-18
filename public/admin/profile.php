<?php
/* Auto-detect bootstrap (local + host) */
$__bp = [
    __DIR__ . '/../../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../../config/bootstrap.php',
];
foreach ($__bp as $__b) { if (is_file($__b)) { require_once $__b; break; } }
unset($__bp, $__b);
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

require_admin();

$db       = db();
$adminId  = (int)$_SESSION['admin_id'];
$flash    = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = $_POST['do'] ?? '';

    /* ۱) تغییر نام و موبایل */
    if ($do === 'update_info') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $mobile   = trim((string)($_POST['mobile'] ?? ''));
        if (mb_strlen($fullName) < 3) {
            $flashErr = 'نام و نام خانوادگی الزامی است';
        } elseif (!preg_match('/^09\d{9}$/', $mobile)) {
            $flashErr = 'شماره موبایل معتبر نیست';
        } else {
            // چک تکراری نبودن موبایل
            $stmt = $db->prepare("SELECT id FROM users WHERE mobile = ? AND id != ? LIMIT 1");
            $stmt->execute([$mobile, $adminId]);
            if ($stmt->fetch()) {
                $flashErr = 'این موبایل متعلق به کاربر دیگری است';
            } else {
                $db->prepare("UPDATE users SET full_name = ?, mobile = ? WHERE id = ?")
                    ->execute([$fullName, $mobile, $adminId]);

                $_SESSION['admin_name'] = $fullName;

                $db->prepare("
                    INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
                    VALUES (?, 'profile.update', 'user', ?, ?)
                ")->execute([$adminId, $adminId, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);

                $flash = 'اطلاعات پروفایل به‌روزرسانی شد.';
            }
        }
    }

    /* ۲) تغییر رمز عبور */
    if ($do === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (strlen($new) < 8) {
            $flashErr = 'رمز جدید باید حداقل ۸ کاراکتر باشد';
        } elseif ($new !== $confirm) {
            $flashErr = 'تکرار رمز با رمز جدید مطابقت ندارد';
        } else {
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$adminId]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($current, $hash)) {
                $flashErr = 'رمز فعلی نادرست است';
            } else {
                $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $adminId]);

                $db->prepare("
                    INSERT INTO audit_logs (user_id, action, entity, entity_id, ip)
                    VALUES (?, 'profile.change_password', 'user', ?, ?)
                ")->execute([$adminId, $adminId, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);

                $flash = 'رمز عبور تغییر کرد.';
            }
        }
    }
}

/* بارگذاری اطلاعات کاربر */
$stmt = $db->prepare("SELECT id, full_name, mobile, last_login_at, created_at FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$adminId]);
$admin = $stmt->fetch();

if (!$admin) {
    logout_session();
    header('Location: /admin/login');
    exit;
}

$activeMenu = 'profile';
$pageTitle  = 'پروفایل مدیر';

ob_start();
?>

<div class="admin-page-intro">
    <div>
        <span class="admin-kicker">حساب کاربری</span>
        <h1>پروفایل مدیر</h1>
        <p>مدیریت اطلاعات حساب کاربری و رمز عبور.</p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-success" style="background:#e8f5ee;color:#1a7f4b;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
        ✓ <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="alert alert-error" style="background:#fff0f0;color:#c62828;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
        ✗ <?= htmlspecialchars($flashErr) ?>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

    <!-- فرم ویرایش اطلاعات -->
    <div class="admin-card" style="background:#fff;border:1px solid var(--card-border);border-radius:14px;padding:18px;">
        <h3 style="font-size:.95rem;margin:0 0 12px;">اطلاعات حساب</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="update_info">

            <div style="margin-bottom:14px;">
                <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:6px;">نام و نام خانوادگی:</label>
                <input type="text" name="full_name" required
                    value="<?= htmlspecialchars($admin['full_name']) ?>"
                    style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:6px;">شماره موبایل:</label>
                <input type="text" name="mobile" required pattern="^09\d{9}$" dir="ltr"
                    value="<?= htmlspecialchars($admin['mobile']) ?>"
                    style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;">
            </div>

            <div style="margin-bottom:14px;font-size:.72rem;color:#999;">
                آخرین ورود: <?= jdate_fa($admin['last_login_at'] ?? null, true) ?>
                <br>تاریخ ثبت: <?= jdate_fa($admin['created_at']) ?>
            </div>

            <button type="submit" style="padding:8px 18px;background:var(--teal-700);color:#fff;border:none;border-radius:6px;cursor:pointer;">
                ذخیره
            </button>
        </form>
    </div>

    <!-- فرم تغییر رمز -->
    <div class="admin-card" style="background:#fff;border:1px solid var(--card-border);border-radius:14px;padding:18px;">
        <h3 style="font-size:.95rem;margin:0 0 12px;">تغییر رمز عبور</h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="change_password">

            <div style="margin-bottom:14px;">
                <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:6px;">رمز فعلی:</label>
                <input type="password" name="current_password" required
                    style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:6px;">رمز جدید (حداقل ۸ کاراکتر):</label>
                <input type="password" name="new_password" required minlength="8"
                    style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="font-size:.82rem;font-weight:700;display:block;margin-bottom:6px;">تکرار رمز جدید:</label>
                <input type="password" name="confirm_password" required minlength="8"
                    style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;">
            </div>

            <button type="submit" style="padding:8px 18px;background:var(--teal-700);color:#fff;border:none;border-radius:6px;cursor:pointer;">
                تغییر رمز
            </button>
        </form>
    </div>

</div>

<!-- خروج امن -->
<div style="margin-top:20px;text-align:left;">
    <a href="/admin/logout?t=<?= urlencode(logout_token()) ?>" class="admin-soft-action" style="background:#fff0f0;color:#c62828;text-decoration:none;padding:8px 18px;border-radius:6px;display:inline-block;">
        خروج از سامانه
    </a>
</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
