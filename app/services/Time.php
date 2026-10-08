<?php

/**
 * ابزار زمان و محاسبات روزهای کاری
 *
 * قوانین:
 * - زمان سیستم بر اساس APP_TIMEZONE
 * - تاریخ ذخیره‌شده در DB میلادی است
 * - تاریخ نمایش داده‌شده به مشتری شمسی است
 * - جمعه و تعطیلات ثبت‌شده در holidays روز کاری نیستند
 */
final class Time
{
    /**
     * زمان فعلی سیستم
     */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            'now',
            new DateTimeZone(APP_TIMEZONE)
        );
    }

    /**
     * ساعت قطع سفارش
     *
     * پیش‌فرض: 17:00
     */
    public static function cutoff(): DateTimeImmutable
    {
        $time = Setting::get('ship_cutoff_time', '17:00');

        [$hour, $minute] = array_pad(
            array_map('intval', explode(':', $time)),
            2,
            0
        );

        return self::now()->setTime(
            $hour,
            $minute,
            0
        );
    }

    /**
     * آیا زمان داده‌شده بعد از ساعت قطع سفارش است؟
     */
    public static function isAfterCutoff(
        ?DateTimeImmutable $time = null
    ): bool {
        $time = $time ?: self::now();

        $cutoffTime = Setting::get(
            'ship_cutoff_time',
            '17:00'
        );

        [$hour, $minute] = array_pad(
            array_map('intval', explode(':', $cutoffTime)),
            2,
            0
        );

        $cutoff = $time->setTime(
            $hour,
            $minute,
            0
        );

        return $time >= $cutoff;
    }

    /**
     * جمعه
     *
     * N:
     * 1 = Monday
     * ...
     * 5 = Friday
     * 6 = Saturday
     * 7 = Sunday
     */
    public static function isFriday(
        DateTimeImmutable $d
    ): bool {
        return (int)$d->format('N') === 5;
    }

    /**
     * بررسی تعطیلی ثبت‌شده در DB
     */
    public static function isHoliday(
        DateTimeImmutable $d
    ): bool {
        try {
            $stmt = db()->prepare(
                "SELECT COUNT(*)
                 FROM holidays
                 WHERE day = ?"
            );

            $stmt->execute([
                $d->format('Y-m-d')
            ]);

            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            /*
             * اگر جدول holidays هنوز وجود نداشته باشد،
             * فقط جمعه به عنوان روز غیرکاری در نظر گرفته می‌شود.
             */
            return false;
        }
    }

    /**
     * آیا روز کاری است؟
     */
    public static function isWorkingDay(
        DateTimeImmutable $d
    ): bool {
        return !self::isFriday($d)
            && !self::isHoliday($d);
    }

    /**
     * اولین روز کاری بعد از تاریخ داده‌شده
     */
    public static function nextWorkingDay(
        DateTimeImmutable $date
    ): DateTimeImmutable {

        $d = $date
            ->modify('+1 day')
            ->setTime(0, 0, 0);

        for ($i = 0; $i < 60; $i++) {

            if (self::isWorkingDay($d)) {
                return $d;
            }

            $d = $d
                ->modify('+1 day')
                ->setTime(0, 0, 0);
        }

        return $date;
    }

    /**
     * افزودن تعداد روز کاری
     *
     * مثال:
     * addWorkingDays(today, 1)
     * = اولین روز کاری بعد
     */
    public static function addWorkingDays(
        DateTimeImmutable $date,
        int $days
    ): DateTimeImmutable {

        if ($days <= 0) {
            return $date;
        }

        $d = $date;

        while ($days > 0) {

            $d = $d
                ->modify('+1 day')
                ->setTime(0, 0, 0);

            if (self::isWorkingDay($d)) {
                $days--;
            }
        }

        return $d;
    }

    /**
     * اولین زمان کاری در یک روز
     */
    public static function firstWorkingMoment(
        DateTimeImmutable $time
    ): DateTimeImmutable {

        $workStart = Setting::get(
            'ship_post_hours_start',
            '10:00'
        );

        [$hour, $minute] = array_pad(
            array_map('intval', explode(':', $workStart)),
            2,
            0
        );

        $d = $time;

        for ($i = 0; $i < 60; $i++) {

            if (self::isWorkingDay($d)) {

                $candidate = $d->setTime(
                    $hour,
                    $minute,
                    0
                );

                if ($candidate >= $time) {
                    return $candidate;
                }
            }

            $d = $d
                ->modify('+1 day')
                ->setTime(0, 0, 0);
        }

        return $time;
    }

    /**
     * تبدیل میلادی به شمسی
     *
     * خروجی:
     * 1405/07/03
     */
    public static function jalaliDate(
        DateTimeImmutable $date
    ): string {

        [$jy, $jm, $jd] = self::gregorianToJalali(
            (int)$date->format('Y'),
            (int)$date->format('m'),
            (int)$date->format('d')
        );

        return sprintf(
            '%04d/%02d/%02d',
            $jy,
            $jm,
            $jd
        );
    }

    /**
     * نام روز + تاریخ شمسی
     *
     * مثال:
     * شنبه 1405/07/04
     */
    public static function dayLabelFa(
        DateTimeImmutable $d
    ): string {

        $days = [
            1 => 'دوشنبه',
            2 => 'سه‌شنبه',
            3 => 'چهارشنبه',
            4 => 'پنجشنبه',
            5 => 'جمعه',
            6 => 'شنبه',
            7 => 'یکشنبه',
        ];

        return $days[(int)$d->format('N')]
            . ' '
            . self::jalaliDate($d);
    }

    /**
     * فقط تاریخ شمسی
     */
    public static function jalaliDateFa(
        DateTimeImmutable $d
    ): string {
        return self::jalaliDate($d);
    }

    /**
     * تبدیل اعداد انگلیسی به فارسی
     */
    public static function faDigits(
        string $value
    ): string {

        return strtr($value, [
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
        ]);
    }

    /**
     * تبدیل میلادی به شمسی
     */
    private static function gregorianToJalali(
        int $gy,
        int $gm,
        int $gd
    ): array {

        $gDaysInMonth = [
            0,
            31,
            28,
            31,
            30,
            31,
            30,
            31,
            31,
            30,
            31,
            30,
            31
        ];

        $gy2 = ($gm > 2)
            ? ($gy + 1)
            : $gy;

        $days =
            355666
            + (365 * $gy)
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400)
            + $gd;

        for ($i = 1; $i < $gm; $i++) {
            $days += $gDaysInMonth[$i];
        }

        $jy = -1595
            + 33 * intdiv($days, 12053);

        $days %= 12053;

        $jy += 4 * intdiv($days, 1461);

        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv(
                $days - 1,
                365
            );

            $days = ($days - 1) % 365;
        }

        if ($days < 186) {

            $jm = 1 + intdiv(
                $days,
                31
            );

            $jd = 1 + (
                $days % 31
            );
        } else {

            $jm = 7 + intdiv(
                $days - 186,
                30
            );

            $jd = 1 + (
                ($days - 186) % 30
            );
        }

        return [
            $jy,
            $jm,
            $jd
        ];
    }
}
