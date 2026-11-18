<?php
/* [FILE] public/admin/shipping-settings.php — v3.1 (Fixed Array Indexing) */

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

require_admin();

$db = db();
$h  = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/* ─── ساخت جدول settings ─── */
try {
    $db->query("SELECT 1 FROM settings LIMIT 1");
} catch (Throwable $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(80) PRIMARY KEY,
        `value` TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ─── ساخت جدول وزن محصولات ─── */
try {
    $db->query("SELECT 1 FROM product_weights LIMIT 1");
} catch (Throwable $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS product_weights (
        product_id INT UNSIGNED NOT NULL PRIMARY KEY,
        grams_per_unit INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ─── ساخت جدول منطقه‌بندی استان‌ها ─── */
try {
    $db->query("SELECT 1 FROM shipping_zones LIMIT 1");
} catch (Throwable $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS shipping_zones (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        province VARCHAR(60) NOT NULL,
        zone ENUM('near','far','very_far') NOT NULL DEFAULT 'far',
        UNIQUE KEY uk_province (province)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ═══════════════════════════════════════════════════════════
   Migration v3: پلکان + قوانین رایگان + ستون‌های snapshot
   ═══════════════════════════════════════════════════════════ */

/* ۱) جدول پلکان هزینه پست */
try {
    $db->query("SELECT 1 FROM shipping_post_tiers LIMIT 1");
} catch (Throwable $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS shipping_post_tiers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        min_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
        max_amount BIGINT UNSIGNED NULL,
        fee BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        INDEX idx_min (min_amount)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ۲) جدول قوانین ارسال رایگان */
try {
    $db->query("SELECT 1 FROM shipping_free_rules LIMIT 1");
} catch (Throwable $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS shipping_free_rules (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rule_type ENUM('amount','unit') NOT NULL,
        threshold BIGINT UNSIGNED NOT NULL DEFAULT 0,
        unit_name VARCHAR(30) NULL,
        methods_json VARCHAR(200) NOT NULL DEFAULT '[\"all\"]',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ۳) seed پلکان پیش‌فرض */
try {
    $cnt = (int)$db->query("SELECT COUNT(*) FROM shipping_post_tiers")->fetchColumn();
    if ($cnt === 0) {
        $db->exec("INSERT INTO shipping_post_tiers (min_amount, max_amount, fee, sort_order) VALUES
            (0,       2000000, 200000, 1),
            (2000000, 4000000, 250000, 2)");
    }
} catch (Throwable $e) { /* نادیده */
}

/* ۴) ستون‌های snapshot در order_shipments */
try {
    $cols = $db->query("SHOW COLUMNS FROM order_shipments LIKE 'calc_mode'")->fetchAll();
    if (!$cols) {
        $db->exec("ALTER TABLE order_shipments
            ADD COLUMN calc_mode VARCHAR(20) NULL DEFAULT NULL COMMENT 'free|tier|factor|weight|none',
            ADD COLUMN calc_base_amount BIGINT UNSIGNED NULL DEFAULT NULL,
            ADD COLUMN calc_tier_min BIGINT UNSIGNED NULL DEFAULT NULL,
            ADD COLUMN calc_tier_max BIGINT UNSIGNED NULL DEFAULT NULL,
            ADD COLUMN calc_free_rule_id INT UNSIGNED NULL DEFAULT NULL");
    }
} catch (Throwable $e) { /* نادیده */
}

/* ═══════════════════════════════════════════════════════════
   تعریف فیلدهای تنظیمات
   ═══════════════════════════════════════════════════════════ */
$fields = [
    ['key' => 'ship_origin_province', 'label' => 'استان مبدأ ارسال', 'type' => 'text', 'group' => 'مبدأ ارسال'],
    ['key' => 'ship_origin_city',     'label' => 'شهر مبدأ ارسال',   'type' => 'text', 'group' => 'مبدأ ارسال'],
    ['key' => 'ship_grams_per_pair',   'label' => 'وزن هر جفت (گرم)',   'type' => 'number', 'group' => 'وزن'],
    ['key' => 'ship_grams_per_jin',    'label' => 'وزن هر جین (گرم)',    'type' => 'number', 'group' => 'وزن'],
    ['key' => 'ship_grams_per_baste',  'label' => 'وزن هر بسته (گرم)',   'type' => 'number', 'group' => 'وزن'],
    ['key' => 'ship_max_pairs_peyk', 'label' => 'حداکثر جفت برای پیک', 'type' => 'number', 'group' => 'محدودیت پیک'],
    ['key' => 'ship_fee_peyk_tehran',    'label' => 'هزینه پیک (تهران)',  'type' => 'number', 'group' => 'هزینه‌ها — پیک'],
    ['key' => 'ship_capacity_peyk',      'label' => 'ظرفیت پیک هر بازه',  'type' => 'number', 'group' => 'هزینه‌ها — پیک'],
    ['key' => 'ship_fee_in_person',      'label' => 'هزینه حضوری',        'type' => 'number', 'group' => 'هزینه‌ها — حضوری'],
    ['key' => 'ship_capacity_in_person', 'label' => 'ظرفیت حضوری هر بازه', 'type' => 'number', 'group' => 'هزینه‌ها — حضوری'],
    ['key' => 'ship_fee_post_base',        'label' => 'هزینه ثابت پست (fallback)', 'type' => 'number', 'group' => 'هزینه‌ها — پست'],
    ['key' => 'ship_rate_post_tehran',     'label' => 'نرخ هر کیلو پست تهران',    'type' => 'number', 'group' => 'هزینه‌ها — پست'],
    ['key' => 'ship_rate_post_near',       'label' => 'نرخ هر کیلو پست نزدیک',    'type' => 'number', 'group' => 'هزینه‌ها — پست'],
    ['key' => 'ship_rate_post_far',        'label' => 'نرخ هر کیلو پست دور',      'type' => 'number', 'group' => 'هزینه‌ها — پست'],
    ['key' => 'ship_rate_post_very_far',   'label' => 'نرخ هر کیلو پست خیلی دور', 'type' => 'number', 'group' => 'هزینه‌ها — پست'],
    ['key' => 'ship_capacity_post',        'label' => 'ظرفیت روزانه پست',         'type' => 'number', 'group' => 'هزینه‌ها — پست'],
    ['key' => 'ship_fee_tipax_base',       'label' => 'هزینه ثابت تیپاکس',           'type' => 'number', 'group' => 'هزینه‌ها — تیپاکس'],
    ['key' => 'ship_rate_tipax_tehran',    'label' => 'نرخ هر کیلو تیپاکس تهران',    'type' => 'number', 'group' => 'هزینه‌ها — تیپاکس'],
    ['key' => 'ship_rate_tipax_near',      'label' => 'نرخ هر کیلو تیپاکس نزدیک',    'type' => 'number', 'group' => 'هزینه‌ها — تیپاکس'],
    ['key' => 'ship_rate_tipax_far',       'label' => 'نرخ هر کیلو تیپاکس دور',      'type' => 'number', 'group' => 'هزینه‌ها — تیپاکس'],
    ['key' => 'ship_rate_tipax_very_far',  'label' => 'نرخ هر کیلو تیپاکس خیلی دور', 'type' => 'number', 'group' => 'هزینه‌ها — تیپاکس'],
    ['key' => 'ship_fee_barbari_base',      'label' => 'هزینه ثابت باربری',           'type' => 'number', 'group' => 'هزینه‌ها — باربری'],
    ['key' => 'ship_rate_barbari_tehran',   'label' => 'نرخ هر کیلو باربری تهران',    'type' => 'number', 'group' => 'هزینه‌ها — باربری'],
    ['key' => 'ship_rate_barbari_near',     'label' => 'نرخ هر کیلو باربری نزدیک',    'type' => 'number', 'group' => 'هزینه‌ها — باربری'],
    ['key' => 'ship_rate_barbari_far',      'label' => 'نرخ هر کیلو باربری دور',      'type' => 'number', 'group' => 'هزینه‌ها — باربری'],
    ['key' => 'ship_rate_barbari_very_far', 'label' => 'نرخ هر کیلو باربری خیلی دور', 'type' => 'number', 'group' => 'هزینه‌ها — باربری'],
    ['key' => 'ship_cutoff_time',            'label' => 'ساعت cutoff سفارش',        'type' => 'text',   'group' => 'زمان‌بندی'],
    ['key' => 'ship_prep_minutes_peyk',      'label' => 'آماده‌سازی پیک (دقیقه)',    'type' => 'number', 'group' => 'زمان‌بندی'],
    ['key' => 'ship_prep_minutes_in_person', 'label' => 'آماده‌سازی حضوری (دقیقه)', 'type' => 'number', 'group' => 'زمان‌بندی'],
    ['key' => 'ship_prep_minutes_post',      'label' => 'آماده‌سازی پست (دقیقه)',   'type' => 'number', 'group' => 'زمان‌بندی'],
    ['key' => 'ship_prep_minutes_tipax',     'label' => 'آماده‌سازی تیپاکس (دقیقه)', 'type' => 'number', 'group' => 'زمان‌بندی'],
    ['key' => 'ship_prep_minutes_barbari',   'label' => 'آماده‌سازی باربری (دقیقه)', 'type' => 'number', 'group' => 'زمان‌بندی'],
    ['key' => 'ship_window_days',            'label' => 'پنجره نمایش (روز)',        'type' => 'number', 'group' => 'زمان‌بندی'],
    ['key' => 'ship_slot_peyk_1', 'label' => 'بازه ۱ پیک', 'type' => 'text', 'group' => 'بازه‌های پیک'],
    ['key' => 'ship_slot_peyk_2', 'label' => 'بازه ۲ پیک', 'type' => 'text', 'group' => 'بازه‌های پیک'],
    ['key' => 'ship_slot_peyk_3', 'label' => 'بازه ۳ پیک', 'type' => 'text', 'group' => 'بازه‌های پیک'],
    ['key' => 'ship_slot_in_person_1', 'label' => 'بازه ۱ حضوری', 'type' => 'text', 'group' => 'بازه‌های حضوری'],
    ['key' => 'ship_slot_in_person_2', 'label' => 'بازه ۲ حضوری', 'type' => 'text', 'group' => 'بازه‌های حضوری'],
    ['key' => 'ship_post_hours_start',   'label' => 'شروع ساعات پست',   'type' => 'text', 'group' => 'ساعات سرویس‌های حمل'],
    ['key' => 'ship_post_hours_end',     'label' => 'پایان ساعات پست',  'type' => 'text', 'group' => 'ساعات سرویس‌های حمل'],
    ['key' => 'ship_tipax_hours_start',  'label' => 'شروع ساعات تیپاکس', 'type' => 'text', 'group' => 'ساعات سرویس‌های حمل'],
    ['key' => 'ship_tipax_hours_end',    'label' => 'پایان ساعات تیپاکس', 'type' => 'text', 'group' => 'ساعات سرویس‌های حمل'],
    ['key' => 'ship_barbari_hours_start', 'label' => 'شروع ساعات باربری', 'type' => 'text', 'group' => 'ساعات سرویس‌های حمل'],
    ['key' => 'ship_barbari_hours_end',  'label' => 'پایان ساعات باربری', 'type' => 'text', 'group' => 'ساعات سرویس‌های حمل'],
    ['key' => 'site_announce', 'label' => 'متن نوار اعلان سایت', 'type' => 'text', 'group' => 'اعلان سایت'],
];

/* ─── مقادیر فعلی ─── */
$keys = array_column($fields, 'key');
$extraKeys = [
    'ship_mode_tier_enabled',
    'ship_mode_factor_enabled',
    'ship_mode_weight_enabled',
    'ship_factor_base_fee',
    'ship_factor_threshold',
    'ship_factor_step',
    'ship_factor_increment',
];
$allKeys = array_merge($keys, $extraKeys);
$placeholders = implode(',', array_fill(0, count($allKeys), '?'));
$stmt = $db->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ($placeholders)");
$stmt->execute($allKeys);
$current = [];
foreach ($stmt->fetchAll() as $row) $current[$row['key']] = $row['value'];

$defaults = [
    'ship_origin_province' => 'تهران',
    'ship_origin_city' => 'تهران',
    'ship_grams_per_pair' => '250',
    'ship_grams_per_jin' => '3000',
    'ship_grams_per_baste' => '2500',
    'ship_max_pairs_peyk' => '100',
    'ship_fee_peyk_tehran' => '80000',
    'ship_capacity_peyk' => '8',
    'ship_fee_in_person' => '0',
    'ship_capacity_in_person' => '6',
    'ship_fee_post_base' => '20000',
    'ship_rate_post_tehran' => '15000',
    'ship_rate_post_near' => '20000',
    'ship_rate_post_far' => '30000',
    'ship_rate_post_very_far' => '45000',
    'ship_capacity_post' => '20',
    'ship_fee_tipax_base' => '30000',
    'ship_rate_tipax_tehran' => '18000',
    'ship_rate_tipax_near' => '25000',
    'ship_rate_tipax_far' => '35000',
    'ship_rate_tipax_very_far' => '50000',
    'ship_fee_barbari_base' => '40000',
    'ship_rate_barbari_tehran' => '12000',
    'ship_rate_barbari_near' => '18000',
    'ship_rate_barbari_far' => '25000',
    'ship_rate_barbari_very_far' => '35000',
    'ship_cutoff_time' => '16:00',
    'ship_prep_minutes_peyk' => '60',
    'ship_prep_minutes_in_person' => '120',
    'ship_prep_minutes_post' => '180',
    'ship_prep_minutes_tipax' => '120',
    'ship_prep_minutes_barbari' => '240',
    'ship_window_days' => '7',
    'ship_slot_peyk_1' => '10:00-13:00',
    'ship_slot_peyk_2' => '13:00-16:00',
    'ship_slot_peyk_3' => '16:00-19:00',
    'ship_slot_in_person_1' => '10:00-13:00',
    'ship_slot_in_person_2' => '16:00-19:00',
    'ship_post_hours_start' => '10:00',
    'ship_post_hours_end' => '17:00',
    'ship_tipax_hours_start' => '09:00',
    'ship_tipax_hours_end' => '18:00',
    'ship_barbari_hours_start' => '08:00',
    'ship_barbari_hours_end' => '16:00',
    'site_announce' => 'ارسال رایگان برای سفارش‌های بالای ۱۰ میلیون تومان',
    'ship_mode_tier_enabled' => '0',
    'ship_mode_factor_enabled' => '0',
    'ship_mode_weight_enabled' => '1',
    'ship_factor_base_fee' => '200000',
    'ship_factor_threshold' => '0',
    'ship_factor_step' => '1000000',
    'ship_factor_increment' => '50000',
];

/* ─── تبدیل شمسی ─── */
$jalaliToGreg = static function (int $jy, int $jm, int $jd): array {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd
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

/* ─── ذخیره ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $updated = 0;

    foreach ($fields as $f) {
        $key = $f['key'];
        if (!array_key_exists($key, $_POST['val'] ?? [])) continue;
        $value = trim((string)$_POST['val'][$key]);
        $chk = $db->prepare("SELECT COUNT(*) FROM settings WHERE `key`=?");
        $chk->execute([$key]);
        if ((int)$chk->fetchColumn() > 0)
            $db->prepare("UPDATE settings SET `value`=? WHERE `key`=?")->execute([$value, $key]);
        else
            $db->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?)")->execute([$key, $value]);
        $updated++;
    }

    foreach ($extraKeys as $key) {
        if (!array_key_exists($key, $_POST['val'] ?? [])) continue;
        $value = trim((string)$_POST['val'][$key]);
        $chk = $db->prepare("SELECT COUNT(*) FROM settings WHERE `key`=?");
        $chk->execute([$key]);
        if ((int)$chk->fetchColumn() > 0)
            $db->prepare("UPDATE settings SET `value`=? WHERE `key`=?")->execute([$value, $key]);
        else
            $db->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?)")->execute([$key, $value]);
        $updated++;
    }

    /* تعطیلات */
    $holidaysRaw = trim((string)($_POST['holidays'] ?? ''));
    $db->exec("DELETE FROM holidays");
    foreach (preg_split('/\r\n|\r|\n/', $holidaysRaw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^(1[34]\d{2})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})\s*[=:|\-]?\s*(.*)$/u', $line, $m)) {
            [$gy, $gm, $gd] = $jalaliToGreg((int)$m[1], (int)$m[2], (int)$m[3]);
            $db->prepare("INSERT INTO holidays (day,title) VALUES (?,?)")->execute([sprintf('%04d-%02d-%02d', $gy, $gm, $gd), trim($m[4]) ?: 'تعطیل رسمی']);
        } elseif (preg_match('/^(20\d{2})-(\d{2})-(\d{2})\s*[=:|\-]?\s*(.*)$/', $line, $m)) {
            $db->prepare("INSERT INTO holidays (day,title) VALUES (?,?)")->execute([$m[1] . '-' . $m[2] . '-' . $m[3], trim($m[4]) ?: 'تعطیل رسمی']);
        }
    }

    /* Zone */
    if (isset($_POST['zone']) && is_array($_POST['zone'])) {
        $db->exec("DELETE FROM shipping_zones");
        $ins = $db->prepare("INSERT INTO shipping_zones (province, zone) VALUES (?,?)");
        foreach ($_POST['zone'] as $province => $zone) {
            if (!in_array($zone, ['near', 'far', 'very_far'], true)) continue;
            $ins->execute([(string)$province, $zone]);
        }
    }

    /* قوانین رایگان */
    if (isset($_POST['free']) && is_array($_POST['free'])) {
        $db->exec("DELETE FROM shipping_free_rules");
        $ins = $db->prepare("INSERT INTO shipping_free_rules
            (rule_type, threshold, unit_name, methods_json, is_active, sort_order)
            VALUES (?,?,?,?,?,?)");
        $i = 0;
        foreach ($_POST['free'] as $fr) {
            $type = in_array($fr['type'] ?? '', ['amount', 'unit'], true) ? $fr['type'] : 'amount';
            $thr  = max(0, (int)($fr['threshold'] ?? 0));
            if ($thr <= 0) continue;

            // فقط اگر نوع «واحد» بود، واحد ذخیره شود. در غیر این صورت NULL
            $unit = ($type === 'unit') ? (string)($fr['unit'] ?? 'جین') : null;

            $methods = $fr['methods'] ?? ['all'];
            if (!is_array($methods) || !$methods) $methods = ['all'];
            if (in_array('all', $methods, true)) $methods = ['all'];

            $active = !empty($fr['active']) ? 1 : 0;
            $ins->execute([$type, $thr, $unit, json_encode($methods, JSON_UNESCAPED_UNICODE), $active, ++$i]);
        }
    }

    /* پلکان‌ها */
    if (isset($_POST['tier']) && is_array($_POST['tier'])) {
        $db->exec("DELETE FROM shipping_post_tiers");
        $ins = $db->prepare("INSERT INTO shipping_post_tiers (min_amount, max_amount, fee, sort_order) VALUES (?,?,?,?)");
        $i = 0;
        foreach ($_POST['tier'] as $t) {
            $min = max(0, (int)($t['min'] ?? 0));
            $maxStr = trim((string)($t['max'] ?? ''));
            $max = $maxStr === '' ? null : max(0, (int)$maxStr);
            $fee = max(0, (int)($t['fee'] ?? 0));
            if ($max !== null && $max <= $min) continue;
            $ins->execute([$min, $max, $fee, ++$i]);
        }
    }

    header('Location: /admin/shipping-settings?success=' . urlencode("$updated تنظیم ذخیره شد."));
    exit;
}

foreach ($fields as &$f) $f['value'] = $current[$f['key']] ?? $defaults[$f['key']] ?? '';
unset($f);

/* Zone فعلی */
$zones = [];
foreach ($db->query("SELECT province, zone FROM shipping_zones") as $z) $zones[$z['province']] = $z['zone'];

$provinceList = [
    'آذربایجان شرقی',
    'آذربایجان غربی',
    'اردبیل',
    'اصفهان',
    'البرز',
    'ایلام',
    'بوشهر',
    'تهران',
    'چهارمحال و بختیاری',
    'خراسان جنوبی',
    'خراسان رضوی',
    'خراسان شمالی',
    'خوزستان',
    'زنجان',
    'سمنان',
    'سیستان و بلوچستان',
    'فارس',
    'قزوین',
    'قم',
    'کردستان',
    'کرمان',
    'کرمانشاه',
    'کهگیلویه و بویراحمد',
    'گلستان',
    'گیلان',
    'لرستان',
    'مازندران',
    'مرکزی',
    'هرمزگان',
    'همدان',
    'یزد'
];

/* تعطیلات */
$holidaysText = '';
foreach ($db->query("SELECT day, title FROM holidays ORDER BY day") as $hh) {
    $ts = strtotime($hh['day']);
    if ($ts === false) continue;
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
    $holidaysText .= sprintf('%04d/%02d/%02d', $jy, $jm, $jd) . ' = ' . $hh['title'] . "\n";
}

$success = trim((string)($_GET['success'] ?? ''));
$pageTitle = 'تنظیمات ارسال و زمان‌بندی';
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
        border-bottom: 2px solid #f1f5f9;
        padding-bottom: 8px;
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

    .zone-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }

    .zone-table th,
    .zone-table td {
        padding: 8px 10px;
        border-bottom: 1px solid #e5e7eb;
        text-align: right;
    }

    .zone-table th {
        background: #f8fafc;
        font-weight: 800;
        color: #173e4a;
        font-size: 11px;
    }

    .zone-table select,
    .zone-table input[type=number],
    .zone-table input[type=text] {
        padding: 5px 8px;
        border: 1px solid #dbe2ea;
        border-radius: 6px;
        font-family: inherit;
        font-size: 12px;
        width: 100%;
        box-sizing: border-box;
    }

    @media(max-width:760px) {
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
        foreach ($groups as $groupName => $groupFields):
        ?>
            <div class="ship-set-group">
                <h3><?= $h($groupName) ?></h3>
                <div class="ship-set-grid">
                    <?php foreach ($groupFields as $f): ?>
                        <div class="ship-set-field">
                            <label><?= $h($f['label']) ?></label>
                            <?php if ($f['type'] === 'number'): ?>
                                <input type="number" name="val[<?= $h($f['key']) ?>]" value="<?= $h($f['value']) ?>" min="0">
                            <?php else: ?>
                                <input type="text" name="val[<?= $h($f['key']) ?>]" value="<?= $h($f['value']) ?>"
                                    <?= (str_contains($f['key'], 'time') || str_contains($f['key'], 'slot') || str_contains($f['key'], 'hours')) ? 'dir="ltr" placeholder="HH:MM"' : '' ?>>
                            <?php endif; ?>
                            <?php if (str_starts_with($f['key'], 'ship_slot_')): ?><small>قالب: HH:MM-HH:MM</small>
                            <?php elseif (str_starts_with($f['key'], 'ship_fee_')): ?><small>تومان</small>
                            <?php elseif (str_starts_with($f['key'], 'ship_grams_')): ?><small>گرم</small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- ═══ ۱) ارسال رایگان ═══ -->
        <div class="ship-set-group">
            <h3>🎁 ارسال رایگان (مقدم بر همه محاسبات)</h3>
            <p style="margin:0 0 12px;font-size:12px;color:#64748b;">
                اگر شرط برقرار باشد، هزینه ارسال صفر می‌شود و بقیه لایه‌ها نادیده گرفته می‌شوند.
                برای «همه روش‌ها» فقط تیک «همه» را بزنید.
            </p>
            <table class="zone-table" id="freeRuleTable">
                <thead>
                    <tr>
                        <th style="width:15%">نوع</th>
                        <th style="width:18%">آستانه</th>
                        <th style="width:15%">واحد</th>
                        <th>شامل روش‌ها</th>
                        <th style="width:8%">فعال</th>
                        <th style="width:6%"></th>
                    </tr>
                </thead>

                <tbody>
                    <?php
                    $freeRules = [];
                    try {
                        $freeRules = $db->query("SELECT * FROM shipping_free_rules ORDER BY sort_order, id")->fetchAll();
                    } catch (Throwable $e) {
                    }
                    if (!$freeRules) $freeRules = [['rule_type' => 'amount', 'threshold' => 10000000, 'unit_name' => null, 'methods_json' => '["all"]', 'is_active' => 1]];

                    $freeIdx = 0;
                    foreach ($freeRules as $fr):
                        $fm = json_decode($fr['methods_json'] ?? '["all"]', true) ?: ['all'];
                        $idx = $freeIdx++;
                    ?>
                        <tr>
                            <td>
                                <select name="free[<?= $idx ?>][type]" class="free-type-select" style="width:100%">
                                    <option value="amount" <?= $fr['rule_type'] === 'amount' ? 'selected' : '' ?>>مبلغ</option>
                                    <option value="unit" <?= $fr['rule_type'] === 'unit' ? 'selected' : '' ?>>واحد</option>
                                </select>
                            </td>
                            <td><input type="number" name="free[<?= $idx ?>][threshold]" value="<?= (int)$fr['threshold'] ?>" min="0" style="width:100%"></td>
                            <td>
                                <select name="free[<?= $idx ?>][unit]" class="free-unit-select" style="width:100%">
                                    <?php foreach (['جین', 'بسته', 'جفتی'] as $u): ?>
                                        <option value="<?= $u ?>" <?= ($fr['unit_name'] ?? '') === $u ? 'selected' : '' ?>><?= $u ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php foreach (['all' => 'همه', 'post' => 'پست', 'tipax' => 'تیپاکس', 'barbari' => 'باربری', 'peyk' => 'پیک', 'in_person' => 'حضوری'] as $mk => $mv): ?>
                                    <label style="display:inline-block;font-size:11px;margin-left:6px;">
                                        <input type="checkbox" name="free[<?= $idx ?>][methods][]" value="<?= $mk ?>"
                                            <?= (in_array('all', $fm) || in_array($mk, $fm)) ? 'checked' : '' ?>>
                                        <?= $mv ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                            <td style="text-align:center">
                                <input type="hidden" name="free[<?= $idx ?>][active]" value="0">
                                <input type="checkbox" name="free[<?= $idx ?>][active]" value="1" <?= $fr['is_active'] ? 'checked' : '' ?>>
                            </td>
                            <td>
                                <button type="button" onclick="this.closest('tr').remove()"
                                    style="background:#fee2e2;border:0;border-radius:6px;padding:6px 10px;cursor:pointer;color:#b91c1c">✕</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" onclick="addFreeRule()"
                style="margin-top:10px;background:#e0f2fe;border:0;border-radius:8px;padding:8px 16px;cursor:pointer;color:#0369a1;font-weight:700">
                + افزودن قانون
            </button>
        </div>

        <!-- ═══ ۲) حالت پلکانی ═══ -->
        <div class="ship-set-group">
            <h3>🪜 حالت پلکانی (بر اساس مبلغ فاکتور)</h3>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;margin-bottom:12px;">
                <input type="hidden" name="val[ship_mode_tier_enabled]" value="0">
                <input type="checkbox" name="val[ship_mode_tier_enabled]" value="1"
                    <?= ($current['ship_mode_tier_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                فعال
            </label>
            <p style="margin:0 0 12px;font-size:12px;color:#64748b;">
                بازه‌ها بر اساس مبلغ کالا پس از تخفیف. اگر «تا مبلغ» خالی باشد = بی‌نهایت (پلکان آخر).
                اولویت: پلکانی → ضریب → وزنی.
            </p>
            <table class="zone-table" id="postTierTable">
                <thead>
                    <tr>
                        <th>از مبلغ (تومان)</th>
                        <th>تا مبلغ (تومان)</th>
                        <th>هزینه پست (تومان)</th>
                        <th style="width:6%"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $postTiers = [];
                    try {
                        $postTiers = $db->query("SELECT * FROM shipping_post_tiers ORDER BY sort_order, min_amount")->fetchAll();
                    } catch (Throwable $e) {
                    }
                    if (!$postTiers) $postTiers = [
                        ['min_amount' => 0, 'max_amount' => 2000000, 'fee' => 200000],
                        ['min_amount' => 2000000, 'max_amount' => 4000000, 'fee' => 250000],
                    ];

                    $tierIdx = 0;
                    foreach ($postTiers as $t):
                        $tIdx = $tierIdx++;
                    ?>
                        <tr>
                            <td><input type="number" name="tier[<?= $tIdx ?>][min]" value="<?= (int)$t['min_amount'] ?>" min="0" style="width:100%"></td>
                            <td><input type="number" name="tier[<?= $tIdx ?>][max]" value="<?= $t['max_amount'] !== null ? (int)$t['max_amount'] : '' ?>" min="0" placeholder="بی‌نهایت" style="width:100%"></td>
                            <td><input type="number" name="tier[<?= $tIdx ?>][fee]" value="<?= (int)$t['fee'] ?>" min="0" style="width:100%"></td>
                            <td><button type="button" onclick="this.closest('tr').remove()"
                                    style="background:#fee2e2;border:0;border-radius:6px;padding:6px 10px;cursor:pointer;color:#b91c1c">✕</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" onclick="addTierRow()"
                style="margin-top:10px;background:#e0f2fe;border:0;border-radius:8px;padding:8px 16px;cursor:pointer;color:#0369a1;font-weight:700">
                + افزودن پلکان
            </button>
        </div>

        <!-- ═══ ۳) حالت ضریب ═══ -->
        <div class="ship-set-group">
            <h3>📐 حالت ضریب (پایه + پله‌ای بر مبلغ)</h3>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;margin-bottom:12px;">
                <input type="hidden" name="val[ship_mode_factor_enabled]" value="0">
                <input type="checkbox" name="val[ship_mode_factor_enabled]" value="1"
                    <?= ($current['ship_mode_factor_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                فعال
            </label>
            <p style="margin:0 0 12px;font-size:12px;color:#64748b;">
                فرمول: <code>fee = base + floor((amount - threshold) / step) × increment</code>
            </p>
            <div class="ship-set-grid">
                <div class="ship-set-field">
                    <label>هزینه پایه (تومان)</label>
                    <input type="number" name="val[ship_factor_base_fee]" value="<?= (int)($current['ship_factor_base_fee'] ?? 200000) ?>" min="0">
                </div>
                <div class="ship-set-field">
                    <label>آستانه شروع (تومان)</label>
                    <input type="number" name="val[ship_factor_threshold]" value="<?= (int)($current['ship_factor_threshold'] ?? 0) ?>" min="0">
                </div>
                <div class="ship-set-field">
                    <label>هر چند تومان یک پله</label>
                    <input type="number" name="val[ship_factor_step]" value="<?= (int)($current['ship_factor_step'] ?? 1000000) ?>" min="1">
                </div>
                <div class="ship-set-field">
                    <label>مبلغ افزایش هر پله (تومان)</label>
                    <input type="number" name="val[ship_factor_increment]" value="<?= (int)($current['ship_factor_increment'] ?? 50000) ?>" min="0">
                </div>
            </div>
        </div>

        <!-- ═══ ۴) حالت وزنی ═══ -->
        <div class="ship-set-group">
            <h3>⚖️ حالت وزنی (پیش‌فرض فعلی)</h3>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;">
                <input type="hidden" name="val[ship_mode_weight_enabled]" value="0">
                <input type="checkbox" name="val[ship_mode_weight_enabled]" value="1"
                    <?= ($current['ship_mode_weight_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                فعال (اگر هیچ‌کدام از بالا فعال نبود، از این استفاده می‌شود)
            </label>
        </div>

        <!-- ═══ منطقه‌بندی استان‌ها ═══ -->
        <div class="ship-set-group">
            <h3>🗺 منطقه‌بندی استان‌ها (Zone)</h3>
            <p style="margin:0 0 12px;font-size:12px;color:#64748b;">
                نزدیک = مرزهای مجاور مبدأ / دور = همان منطقه / خیلی دور = سایر استان‌ها.
                نرخ هر کیلوی پست/تیپاکس/باربری بر اساس این zone محاسبه می‌شود (فقط در حالت وزنی).
            </p>
            <table class="zone-table">
                <thead>
                    <tr>
                        <th>استان</th>
                        <th>Zone</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($provinceList as $p): ?>
                        <tr>
                            <td><?= $h($p) ?></td>
                            <td>
                                <select name="zone[<?= $h($p) ?>]">
                                    <?php foreach (['near' => 'نزدیک', 'far' => 'دور', 'very_far' => 'خیلی دور'] as $zk => $zv): ?>
                                        <option value="<?= $zk ?>" <?= ($zones[$p] ?? 'far') === $zk ? 'selected' : '' ?>><?= $zv ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ═══ تعطیلات ═══ -->
        <div class="ship-set-group">
            <h3>🗓 تعطیلات رسمی</h3>
            <p style="margin:0 0 10px;font-size:12px;color:#64748b;">
                هر خط یک تاریخ <b>شمسی</b> سپس عنوان — مثال: <code>1405/01/01 نوروز</code>
            </p>
            <textarea name="holidays" rows="5" dir="rtl"
                style="width:100%;box-sizing:border-box;padding:10px;border:1px solid #dbe2ea;border-radius:9px;font-family:inherit;font-size:12px;"
                placeholder="1405/01/01 نوروز"><?= $h($holidaysText) ?></textarea>
        </div>

        <button type="submit" class="btn btn-orange" style="padding:12px 30px;font-size:.85rem;">💾 ذخیره همه تنظیمات</button>
    </form>
</div>

<script>
    let freeCounter = <?= count($freeRules) ?>;
    let tierCounter = <?= count($postTiers) ?>;

    function addFreeRule() {
        const tbody = document.querySelector('#freeRuleTable tbody');
        const tr = document.createElement('tr');
        const idx = freeCounter++;
        tr.innerHTML = `
        <td><select name="free[${idx}][type]" class="free-type-select" style="width:100%"><option value="amount">مبلغ</option><option value="unit">واحد</option></select></td>
        <td><input type="number" name="free[${idx}][threshold]" min="0" style="width:100%"></td>
        <td><select name="free[${idx}][unit]" class="free-unit-select" style="width:100%" disabled><option value="جین">جین</option><option value="بسته">بسته</option><option value="جفتی">جفتی</option></select></td>
        <td>
            <?php foreach (['all' => 'همه', 'post' => 'پست', 'tipax' => 'تیپاکس', 'barbari' => 'باربری', 'peyk' => 'پیک', 'in_person' => 'حضوری'] as $mk => $mv): ?>
            <label style="display:inline-block;font-size:11px;margin-left:6px;"><input type="checkbox" name="free[${idx}][methods][]" value="<?= $mk ?>"> <?= $mv ?></label>
            <?php endforeach; ?>
        </td>
        <td style="text-align:center">
            <input type="hidden" name="free[${idx}][active]" value="0">
            <input type="checkbox" name="free[${idx}][active]" value="1" checked>
        </td>
        <td><button type="button" onclick="this.closest('tr').remove()" style="background:#fee2e2;border:0;border-radius:6px;padding:6px 10px;cursor:pointer;color:#b91c1c">✕</button></td>
    `;
        tbody.appendChild(tr);
    }

    function addTierRow() {
        const tbody = document.querySelector('#postTierTable tbody');
        const tr = document.createElement('tr');
        const idx = tierCounter++;
        tr.innerHTML = `
        <td><input type="number" name="tier[${idx}][min]" min="0" style="width:100%"></td>
        <td><input type="number" name="tier[${idx}][max]" min="0" placeholder="بی‌نهایت" style="width:100%"></td>
        <td><input type="number" name="tier[${idx}][fee]" min="0" style="width:100%"></td>
        <td><button type="button" onclick="this.closest('tr').remove()" style="background:#fee2e2;border:0;border-radius:6px;padding:6px 10px;cursor:pointer;color:#b91c1c">✕</button></td>
    `;
        tbody.appendChild(tr);
    }
    // مدیریت فعال/غیرفعال شدن فیلد واحد بر اساس انتخاب نوع (مبلغ/واحد)
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('free-type-select')) {
            const row = e.target.closest('tr');
            const unitSelect = row.querySelector('.free-unit-select');
            if (e.target.value === 'amount') {
                unitSelect.disabled = true;
                unitSelect.style.opacity = '0.5';
            } else {
                unitSelect.disabled = false;
                unitSelect.style.opacity = '1';
            }
        }
    });

    // اجرای اولیه برای ردیف‌های موجود در زمان لود صفحه
    document.querySelectorAll('.free-type-select').forEach(select => {
        const row = select.closest('tr');
        const unitSelect = row.querySelector('.free-unit-select');
        if (select.value === 'amount') {
            unitSelect.disabled = true;
            unitSelect.style.opacity = '0.5';
        }
    });
</script>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/admin.php';
