<?php

declare(strict_types=1);


if (!function_exists('fa_digits')) {
    function fa_digits(string $s): string
    {
        return strtr($s, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}

if (!function_exists('en_digits')) {
    function en_digits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}

if (!function_exists('to_jalali')) {
 
    function to_jalali(?string $mysqlDate, bool $withTime = false): string
    {
        if (!$mysqlDate || $mysqlDate === '' || $mysqlDate === '0000-00-00 00:00:00') {
            return '—';
        }

        $ts = strtotime($mysqlDate);
        if ($ts === false) {
            return '—';
        }

        $gy = (int)date('Y', $ts);
        $gm = (int)date('n', $ts);
        $gd = (int)date('j', $ts);

        $g = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? $gy + 1 : $gy;

        $d = (365 * $gy)
           + intdiv($gy2 + 3, 4)
           - intdiv($gy2 + 99, 100)
           + intdiv($gy2 + 399, 400)
           - 80
           + $gd
           + $g[$gm - 1];

        $jy += 33 * intdiv($d, 12053);
        $d %= 12053;

        $jy += 4 * intdiv($d, 1461);
        $d %= 1461;

        if ($d > 365) {
            $jy += intdiv($d - 1, 365);
            $d = ($d - 1) % 365;
        }

        $jm = ($d < 186) ? 1 + intdiv($d, 31) : 7 + intdiv($d - 186, 30);
        $jd = 1 + (($d < 186) ? ($d % 31) : (($d - 186) % 30));

        $out = $jy . '/'
             . str_pad((string)$jm, 2, '0', STR_PAD_LEFT) . '/'
             . str_pad((string)$jd, 2, '0', STR_PAD_LEFT);

        if ($withTime) {
            $out .= ' — ' . date('H:i', $ts);
        }

        return fa_digits($out);
    }
}

if (!function_exists('jalali_to_gregorian')) {

    function jalali_to_gregorian(string $jDate): ?string
    {
        $jDate = trim(en_digits($jDate));
        if (!preg_match('/^(\d{4})[-\/\.](\d{1,2})[-\/\.](\d{1,2})$/', $jDate, $m)) {
            return null;
        }

        $jy = (int)$m[1];
        $jm = (int)$m[2];
        $jd = (int)$m[3];

        if ($jy < 1 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
            return null;
        }

        // الگوریتم Jalaali → Gregorian (مستند در jdatetime / Jalaali.js)
        $jy = ($jy <= 979) ? 0 : ($jy - 979);
        $days = 365 * $jy + intdiv($jy, 33) * 12053 + ($jy % 33) * 365
              + intdiv(($jy % 33) + 3, 4);

        if ($jm < 7) {
            $days += ($jm - 1) * 31;
        } else {
            $days += ($jm - 7) * 30 + 186;
        }
        $days += $jd;

        $gy = 1600 + 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $sal_a = 365;
        if (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) {
            $sal_a = 366;
        }

        $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if ($gy % 4 === 0) {
            $g_days_in_month[1] = 29;
        }

        $gm = 0;
        while ($days >= $g_days_in_month[$gm]) {
            $days -= $g_days_in_month[$gm];
            $gm++;
        }
        $gm++; // 1-based
        $gd = $days + 1;

        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }
}

if (!function_exists('jalali_today')) {
    function jalali_today(): string
    {
        return to_jalali(date('Y-m-d H:i:s'));
    }
}
