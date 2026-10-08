<?php
/* [FILE] public/admin/financial-helpers.php — هلپرهای گزارش‌های مالی */

/* ═══ جلالی → میلادی (برای ورودی شمسی مدیر) ═══ */
function fin_jalaliToGregorian(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668
        + (365 * $jy)
        + (intdiv($jy, 33) * 8)
        + intdiv(($jy % 33) + 3, 4)
        + $jd
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
    $monthDays = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    $gm = 0;
    for ($i = 1; $i <= 12; $i++) {
        if ($gd <= $monthDays[$i]) {
            $gm = $i;
            break;
        }
        $gd -= $monthDays[$i];
    }

    return [$gy, $gm, $gd];
}

/* ═══ میلادی → جلالی (برای نمایش) ═══ */
function fin_jalali(?string $mysqlDate): string
{
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

    return $jy . '/' . str_pad((string)$jm, 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$jd, 2, '0', STR_PAD_LEFT);
}

/* ═══ پارس ورودی تاریخ — شمسی یا میلادی هر دو قبول ═══ */
function fin_parseDateInput(string $input, bool $isEnd = false): string
{
    $input = trim(strtr($input, [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9'
    ]));
    if ($input === '') return '';

    /* قالب شمسی: 1405/07/07 یا ۱۴۰۵/۰۷/۰۷ */
    if (preg_match('/^(1[34]\d{2})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/', $input, $m)) {
        [$gy, $gm, $gd] = fin_jalaliToGregorian((int)$m[1], (int)$m[2], (int)$m[3]);
        $out = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }
    /* قالب میلادی: 2026-09-29 */ elseif (preg_match('/^(20\d{2})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/', $input, $m)) {
        $out = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    } else {
        return '';
    }

    return $isEnd ? $out . ' 23:59:59' : $out;
}

/* ═══ بازه تاریخ ═══ */
function fin_dateRange(): array
{
    $from = fin_parseDateInput((string)($_GET['from'] ?? ''));
    $to   = fin_parseDateInput((string)($_GET['to'] ?? ''), true);

    if ($from === '') $from = date('Y-m-01');
    if ($to === '')   $to   = date('Y-m-d') . ' 23:59:59';

    return [$from, $to . (strlen($to) === 10 ? ' 23:59:59' : '')];
}

/* ═══ دوره‌های آماده ═══ */
function fin_presetRange(string $preset): ?array
{
    return match ($preset) {
        'month'   => [date('Y-m-01'), date('Y-m-d')],
        'quarter' => [date('Y-m-d', strtotime('-2 months', strtotime(date('Y-m-01')))), date('Y-m-d')],
        'half'    => [date('Y-m-d', strtotime('-5 months', strtotime(date('Y-m-01')))), date('Y-m-d')],
        'year'    => [date('Y-01-01'), date('Y-m-d')],
        default   => null,
    };
}

/* ═══ ساعت و بازه فارسی — 16:00-19:00 → «از ۴ عصر تا ۷ عصر» ═══ */
function fin_timeOfDayFa(int $hour): string
{
    if ($hour >= 5 && $hour < 12)  return 'صبح';
    if ($hour >= 12 && $hour < 17) return 'ظهر';
    if ($hour >= 17 && $hour < 21) return 'عصر';
    return 'شب';
}

function fin_slotLabelFa(string $range): string
{
    /* ورودی: "16:00-19:00" — خروجی: «از ۴ عصر تا ۷ عصر» */
    if (!str_contains($range, '-')) return $range;

    [$s, $e] = explode('-', $range, 2);

    $sh = (int)explode(':', trim($s))[0];
    $eh = (int)explode(':', trim($e))[0];

    $fa = static fn(int $n): string => strtr(
        (string)($n > 12 ? $n - 12 : $n),
        ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']
    );

    return 'از ' . $fa($sh) . ' ' . fin_timeOfDayFa($sh)
        . ' تا ' . $fa($eh) . ' ' . fin_timeOfDayFa($eh);
}

/* ═══ فرم بازه تاریخ — ورودی شمسی ═══ */
function fin_rangeForm(string $action, array $hidden = []): void
{
    [$from, $to] = $GLOBALS['finRange'];

    /* نمایش شمسی مقادیر فعلی کنار ورودی */
    $fromFa = fin_jalali(substr($from, 0, 10));
    $toFa   = fin_jalali(substr($to, 0, 10));

    $presets = [
        'month'   => 'این ماه',
        'quarter' => 'سه ماه اخیر',
        'half'    => 'شش ماه اخیر',
        'year'    => 'از ابتدای سال',
    ];

    echo '<div class="admin-card" style="padding:14px;margin-bottom:16px;">
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">';

    foreach ($hidden as $k => $v) {
        echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string)$v) . '">';
    }

    echo '<div>
        <label style="display:block;font-size:11px;font-weight:700;margin-bottom:4px;">
            از تاریخ (شمسی — مثال ۱۴۰۵/۰۷/۰۱)
        </label>
        <input type="text" name="from" dir="ltr" placeholder="1405/07/01"
            value="' . htmlspecialchars(substr($from, 0, 10)) . '"
            style="padding:8px 10px;border:1px solid #dbe2ea;border-radius:9px;font-family:inherit;">
        <small style="display:block;margin-top:3px;color:#94a3b8;font-size:10px;">
            فعلی: ' . $fromFa . '
        </small>
    </div>';

    echo '<div>
        <label style="display:block;font-size:11px;font-weight:700;margin-bottom:4px;">
            تا تاریخ
        </label>
        <input type="text" name="to" dir="ltr" placeholder="1405/07/30"
            value="' . htmlspecialchars(substr($to, 0, 10)) . '"
            style="padding:8px 10px;border:1px solid #dbe2ea;border-radius:9px;font-family:inherit;">
        <small style="display:block;margin-top:3px;color:#94a3b8;font-size:10px;">
            فعلی: ' . $toFa . '
        </small>
    </div>';

    echo '<div>
        <label style="display:block;font-size:11px;font-weight:700;margin-bottom:4px;">دوره‌های آماده</label>
        <select name="preset" onchange="this.form.submit()"
            style="padding:8px 10px;border:1px solid #dbe2ea;border-radius:9px;font-family:inherit;">
        <option value="">انتخاب دوره</option>';
    foreach ($presets as $k => $lbl) {
        echo '<option value="' . $k . '">' . $lbl . '</option>';
    }
    echo '</select></div>';

    echo '<button type="submit" class="btn btn-orange" style="padding:9px 20px;font-size:.78rem;">اعمال فیلتر</button>';

    echo '</form></div>';
}

/* ═══ اعمال preset ═══ */
function fin_applyPreset(): void
{
    $preset = trim((string)($_GET['preset'] ?? ''));
    if ($preset === '') return;
    $range = fin_presetRange($preset);
    if ($range) {
        $_GET['from'] = $range[0];
        $_GET['to']   = $range[1];
    }
}
