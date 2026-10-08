<?php
/* [FILE] public/admin/shipping-settings.php — تنظیمات ارسال و زمان‌بندی */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

require_admin();

$db = db();
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/* ═══ ساخت جدول settings اگر نیست ═══ */
try {
    $db->query("SELECT 1 FROM settings LIMIT 1");
} catch (Throwable $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(60) PRIMARY KEY,
        `value` TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ═══ تعریف فیلدها ═══ */
$fields = [

    /* هزینه‌ها */
    ['key' => 'ship_fee_peyk_tehran',    'label' => 'هزینه پیک (تهران)',    'type' => 'number', 'group' => 'هزینه‌ها'],
    ['key' => 'ship_fee_post_tehran',    'label' => 'هزینه پست (تهران)',    'type' => 'number', 'group' => 'هزینه‌ها'],
    ['key' => 'ship_fee_post_other',     'label' => 'هزینه پست (شهرستان)',  'type' => 'number', 'group' => 'هزینه‌ها'],
    ['key' => 'ship_fee_in_person',      'label' => 'هزینه تحویل حضوری',    'type' => 'number', 'group' => 'هزینه‌ها'],

    /* زمان‌بندی */
    ['key' => 'ship_cutoff_time',            'label' => 'ساعت cutoff سفارش',  'type' => 'text',   'group' => 'زمان‌بندی'],
    ['key' => 'ship_prep_minutes_peyk',      'label' => 'آماده‌سازی پیک (دقیقه)', 'type' => 'number', 'group' => 'زمان‌بندی'],
    ['key' => 'ship_prep_minutes_in_person', 'label' => 'آماده‌سازی حضوری (دقیقه)', 'type' => 'number', 'group' => 'زمان‌بندی'],
    ['key' => 'ship_window_days',            'label' => 'پنجره نمایش (روز)',   'type' => 'number', 'group' => 'زمان‌بندی'],

    /* ظرفیت */
    ['key' => 'ship_capacity_peyk',      'label' => 'ظرفیت پیک (هر بازه)',   'type' => 'number', 'group' => 'ظرفیت'],
    ['key' => 'ship_capacity_post',      'label' => 'ظرفیت پست (هر روز)',    'type' => 'number', 'group' => 'ظرفیت'],
    ['key' => 'ship_capacity_in_person', 'label' => 'ظرفیت حضوری (هر بازه)', 'type' => 'number', 'group' => 'ظرفیت'],

    /* بازه‌های پیک */
    ['key' => 'ship_slot_peyk_1', 'label' => 'بازه ۱ پیک', 'type' => 'text', 'group' => 'بازه‌های پیک'],
    ['key' => 'ship_slot_peyk_2', 'label' => 'بازه ۲ پیک', 'type' => 'text', 'group' => 'بازه‌های پیک'],
    ['key' => 'ship_slot_peyk_3', 'label' => 'بازه ۳ پیک', 'type' => 'text', 'group' => 'بازه‌های پیک'],

    /* بازه‌های حضوری */
    ['key' => 'ship_slot_in_person_1', 'label' => 'بازه ۱ حضوری', 'type' => 'text', 'group' => 'بازه‌های حضوری'],
    ['key' => 'ship_slot_in_person_2', 'label' => 'بازه ۲ حضوری', 'type' => 'text', 'group' => 'بازه‌های حضوری'],

    /* ساعات پست */
    ['key' => 'ship_post_hours_start', 'label' => 'شروع ساعات پست', 'type' => 'text', 'group' => 'ساعات پست'],
    ['key' => 'ship_post_hours_end',   'label' => 'پایان ساعات پست', 'type' => 'text', 'group' => 'ساعات پست'],

    /* اعلامیه سایت */
    ['key' => 'site_announce', 'label' => 'متن نوار اعلان سایت', 'type' => 'text', 'group' => 'اعلان سایت'],
];

/* ═══ خواندن مقادیر فعلی ═══ */
$keys = array_column($fields, 'key');
$placeholders = implode(',', array_fill(0, count($keys), '?'));

$stmt = $db->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ($placeholders)");
$stmt->execute($keys);
$current = [];
foreach ($stmt->fetchAll() as $row) {
    $current[$row['key']] = $row['value'];
}

$defaults = [
    'ship_fee_peyk_tehran' => '80000',
    'ship_fee_post_tehran' => '45000',
    'ship_fee_post_other' => '65000',
    'ship_fee_in_person' => '0',
    'ship_cutoff_time' => '16:00',
    'ship_prep_minutes_peyk' => '60',
    'ship_prep_minutes_in_person' => '120',
    'ship_window_days' => '7',
    'ship_capacity_peyk' => '8',
    'ship_capacity_post' => '20',
    'ship_capacity_in_person' => '6',
    'ship_slot_peyk_1' => '10:00-13:00',
    'ship_slot_peyk_2' => '13:00-16:00',
    'ship_slot_peyk_3' => '16:00-19:00',
    'ship_slot_in_person_1' => '10:00-13:00',
    'ship_slot_in_person_2' => '16:00-19:00',
    'ship_post_hours_start' => '10:00',
    'ship_post_hours_end' => '17:00',
    'site_announce' => 'ارسال رایگان برای سفارش‌های بالای ۱۰ میلیون تومان',
];

/* ═══ تبدیل تاریخ شمسی به میلادی (برای تعطیلات) ═══ */
$jalaliToGreg = static function (int $jy, int $jm, int $jd): array {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8)
        + intdiv(($jy % 33) + 3, 4) + $jd
        + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;

    if ($days > 36524) {
        $gy += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }

    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;

    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }

    $gd = $days + 1;
    $leap = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0));
    $md = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    $gm = 0;
    for ($i = 1; $i <= 12; $i++) {
        if ($gd <= $md[$i]) {
            $gm = $i;
            break;
        }
        $gd -= $md[$i];
    }

    return [$gy, $gm, $gd];
};

/* ═══ ذخیره ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();


    $updated = 0;

    foreach ($fields as $f) {
        $key = $f['key'];
        if (!array_key_exists($key, $_POST['val'] ?? [])) continue;
        $value = trim((string)$_POST['val'][$key]);

        $chk = $db->prepare("SELECT COUNT(*) FROM settings WHERE `key`=?");
        $chk->execute([$key]);

        if ((int)$chk->fetchColumn() > 0) {
            $db->prepare("UPDATE settings SET `value`=? WHERE `key`=?")->execute([$value, $key]);
        } else {
            $db->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?)")->execute([$key, $value]);
        }
        $updated++;
    }

    /* ═══ تعطیلات — ورودی شمسی، ذخیره میلادی ═══ */
    $holidaysRaw = trim((string)($_POST['holidays'] ?? ''));
    $db->exec("DELETE FROM holidays");

    foreach (preg_split('/\r\n|\r|\n/', $holidaysRaw) as $line) {
        $line = trim($line);
        if ($line === '') continue;

        /* فرمت: 1405/01/01 نوروز یا 1405-01-01 = نوروز */
        if (preg_match('/^(1[34]\d{2})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})\s*[=:|\-]?\s*(.*)$/u', $line, $m)) {
            [$gy, $gm, $gd] = $jalaliToGreg((int)$m[1], (int)$m[2], (int)$m[3]);
            $date = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
            $db->prepare("INSERT INTO holidays (day, title) VALUES (?,?)")
                ->execute([$date, trim($m[4]) ?: 'تعطیل رسمی']);
        }
        /* میلادی هم قبول */ elseif (preg_match('/^(20\d{2})-(\d{2})-(\d{2})\s*[=:|\-]?\s*(.*)$/', $line, $m)) {
            $db->prepare("INSERT INTO holidays (day, title) VALUES (?,?)")
                ->execute([$m[1] . '-' . $m[2] . '-' . $m[3], trim($m[4]) ?: 'تعطیل رسمی']);
        }
    }

    header('Location: /admin/shipping-settings?success=' . urlencode("$updated تنظیم ذخیره شد."));
    exit;
}

/* مقدار نمایشی هر فیلد */
foreach ($fields as &$f) {
    $f['value'] = $current[$f['key']] ?? $defaults[$f['key']] ?? '';
}
unset($f);

/* ═══ تعطیلات فعلی — نمایش شمسی ═══ */
$holidaysText = '';
foreach ($db->query("SELECT day, title FROM holidays ORDER BY day") as $hh) {
    /* تبدیل میلادی ذخیره‌شده به شمسی برای نمایش */
    $ts = strtotime($hh['day']);
    $jalaliDisplay = '';
    if ($ts !== false) {
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
        $jalaliDisplay = $jy . '/' . str_pad((string)$jm, 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$jd, 2, '0', STR_PAD_LEFT);
    }
    $holidaysText .= $jalaliDisplay . ' = ' . $hh['title'] . "\n";
}

$success = trim((string)($_GET['success'] ?? ''));

$pageTitle  = 'تنظیمات ارسال و زمان‌بندی';
$activeMenu = 'shipping-settings';

ob_start();
?>

<style>
    .ship-set-group {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 18px;
        margin-bottom: 16px;
    }

    .ship-set-group h3 {
        margin: 0 0 14px;
        font-size: 15px;
        font-weight: 800;
        color: #173e4a;
    }

    .ship-set-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .ship-set-field label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #334155;
        margin-bottom: 5px;
    }

    .ship-set-field input {
        width: 100%;
        box-sizing: border-box;
        padding: 9px 11px;
        border: 1px solid #dbe2ea;
        border-radius: 9px;
        font-family: inherit;
        font-size: 13px;
    }

    .ship-set-field small {
        display: block;
        margin-top: 4px;
        color: #94a3b8;
        font-size: 10px;
    }

    @media (max-width:760px) {
        .ship-set-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="finc">

    <?php if ($success): ?><div class="finc-alert ok">✅ <?= $h($success) ?></div><?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <?php
        $groups = [];
        foreach ($fields as $f) $groups[$f['group']][] = $f;
        ?>

        <?php foreach ($groups as $groupName => $groupFields): ?>
            <div class="ship-set-group">
                <h3><?= $h($groupName) ?></h3>

                <div class="ship-set-grid">
                    <?php foreach ($groupFields as $f): ?>
                        <div class="ship-set-field">
                            <label><?= $h($f['label']) ?></label>

                            <?php if ($f['type'] === 'number'): ?>
                                <input type="number" name="val[<?= $h($f['key']) ?>]"
                                    value="<?= $h($f['value']) ?>" min="0">
                            <?php else: ?>
                                <input type="text" name="val[<?= $h($f['key']) ?>]"
                                    value="<?= $h($f['value']) ?>"
                                    <?= str_contains($f['key'], 'time') || str_contains($f['key'], 'slot')
                                        ? 'dir="ltr" placeholder="HH:MM"' : '' ?>>
                            <?php endif; ?>

                            <?php if (str_starts_with($f['key'], 'ship_slot_')): ?>
                                <small>قالب: HH:MM-HH:MM (۲۴ ساعته)</small>
                            <?php elseif (str_starts_with($f['key'], 'ship_fee_')): ?>
                                <small>تومان</small>
                            <?php elseif (str_contains($f['key'], 'cutoff')): ?>
                                <small>بعد از این ساعت، ارسال همان روز غیرفعال</small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- ═══ تعطیلات — ورودی شمسی ═══ -->
        <div class="ship-set-group">
            <h3>🗓 تعطیلات رسمی</h3>
            <p style="margin:0 0 10px;font-size:12px;color:#64748b;">
                در این روزها هیچ بازه ارسالی پیشنهاد نمی‌شود.
                هر خط یک تاریخ <b>شمسی</b> سپس عنوان — مثال:
                <code>1405/01/01 نوروز</code>
            </p>
            <textarea name="holidays" rows="5" dir="rtl"
                style="width:100%;box-sizing:border-box;padding:10px;
                border:1px solid #dbe2ea;border-radius:9px;font-family:inherit;font-size:12px;"
                placeholder="1405/01/01 نوروز"><?= $h($holidaysText) ?></textarea>
            <small style="display:block;margin-top:4px;color:#94a3b8;font-size:10px;">
                تاریخ‌ها شمسی وارد می‌شوند و سیستم خودش تبدیل می‌کند.
            </small>
        </div>

        <button type="submit" class="btn btn-orange"
            style="padding:12px 30px;font-size:.85rem;">
            💾 ذخیره همه تنظیمات
        </button>
    </form>

</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
