<?php

require_once ROOT_PATH . '/app/services/Time.php';

final class ShippingService
{
    private const TEHRAN = 'تهران';

    /**
     * تعداد روزهایی که برای انتخاب پیک نمایش داده می‌شود.
     *
     * حتی اگر تنظیم DB اشتباهاً 57 باشد،
     * بیشتر از این مقدار به مشتری نمایش داده نمی‌شود.
     */
    private const MAX_VISIBLE_PEEK_DAYS = 3;

    /**
     * =========================================================
     * گزینه‌های مجاز ارسال
     * =========================================================
     *
     * تهران:
     * - پیک
     * - پست
     * - تحویل حضوری
     *
     * شهرستان:
     * - فقط پست
     */
    public static function getOptions(
        string $province
    ): array {

        $now = Time::now();

        $province = self::normalizeProvince(
            $province
        );

        $isTehran = (
            $province === self::TEHRAN
        );

        $methods = [];

        /*
         * =====================================================
         * تهران — پیک
         * =====================================================
         *
         * پیک برای امروز نمایش داده نمی‌شود.
         *
         * طبق قانون فعلی:
         * امروز پیک نداریم.
         *
         * بنابراین اولین روز قابل انتخاب،
         * اولین روز کاری بعدی است.
         */
        if ($isTehran) {

            $methods['peyk'] = [
                'label' => 'پیک موتوری',

                'fee' => Setting::getInt(
                    'ship_fee_peyk_tehran',
                    80000
                ),

                'days' => self::buildPeykDays(
                    $now
                ),

                /*
                 * اطلاعات کمکی برای UI
                 */
                'customer_selects_time' => true,
                'customer_selects_date' => true,
            ];
        }

        /*
         * =====================================================
         * پست
         * =====================================================
         */
        $postFee = $isTehran
            ? Setting::getInt(
                'ship_fee_post_tehran',
                45000
            )
            : Setting::getInt(
                'ship_fee_post_other',
                65000
            );

        /*
         * تهران:
         * تاریخ تحویل به پست قابل نمایش است،
         * ولی ساعت به مشتری نمایش داده نمی‌شود.
         *
         * شهرستان:
         * مشتری اصلاً تاریخ انتخاب نمی‌کند.
         * فقط بازه 48 تا 72 ساعت کاری نمایش داده می‌شود.
         */
        $methods['post'] = [
            'label' => 'پست پیشتاز',

            'fee' => $postFee,

            'days' => $isTehran
                ? self::buildTehranPostDays($now)
                : [],

            'customer_selects_time' => false,

            'customer_selects_date' => $isTehran,

            'estimate' => $isTehran
                ? 'تحویل مرسوله به پست در اولین زمان کاری'
                : '۴۸ تا ۷۲ ساعت کاری',
        ];

        /*
         * =====================================================
         * تحویل حضوری
         * =====================================================
         *
         * هیچ تاریخ یا ساعت انتخابی ندارد.
         */
        if ($isTehran) {

            $methods['in_person'] = [
                'label' => 'تحویل حضوری از کارگاه',

                'fee' => Setting::getInt(
                    'ship_fee_in_person',
                    0
                ),

                'days' => [],

                'customer_selects_time' => false,
                'customer_selects_date' => false,

                'estimate' =>
                'بدون نیاز به انتخاب تاریخ یا ساعت',
            ];
        }

        return [
            'province' => $province,
            'is_tehran' => $isTehran,
            'methods' => $methods,
        ];
    }

    /**
     * =========================================================
     * پیک تهران
     * =========================================================
     *
     * قانون:
     * - امروز پیک نداریم.
     * - اولین گزینه = اولین روز کاری بعدی.
     * - بازه‌های زمانی از settings خوانده می‌شوند.
     */
    private static function buildPeykDays(
        DateTimeImmutable $now
    ): array {

        $window = Setting::getInt(
            'ship_window_days',
            self::MAX_VISIBLE_PEEK_DAYS
        );

        /*
         * جلوگیری از نمایش تعداد غیرمنطقی روزها
         */
        $window = max(
            1,
            min(
                $window,
                self::MAX_VISIBLE_PEEK_DAYS
            )
        );

        $capacity = Setting::getInt(
            'ship_capacity_peyk',
            8
        );

        $slotKeys = [
            'ship_slot_peyk_1',
            'ship_slot_peyk_2',
            'ship_slot_peyk_3',
        ];

        /*
         * امروز پیک نداریم.
         * بنابراین همیشه از اولین روز کاری بعد شروع می‌کنیم.
         */
        $day = Time::nextWorkingDay(
            $now
        );

        $out = [];

        for (
            $counter = 0;
            $counter < $window;
            $counter++
        ) {

            /*
             * اگر به هر دلیل این روز تعطیل بود،
             * به روز کاری بعد برو.
             */
            while (!Time::isWorkingDay($day)) {

                $day = $day->modify(
                    '+1 day'
                );
            }

            $slots = [];

            foreach ($slotKeys as $slotKey) {

                $range = trim(
                    Setting::get(
                        $slotKey,
                        ''
                    )
                );

                if (
                    $range === ''
                    || !str_contains(
                        $range,
                        '-'
                    )
                ) {
                    continue;
                }

                [$startS, $endS] =
                    array_pad(
                        explode(
                            '-',
                            $range,
                            2
                        ),
                        2,
                        ''
                    );

                $startS = trim($startS);
                $endS   = trim($endS);

                if (
                    !$startS
                    || !$endS
                ) {
                    continue;
                }

                $reserved =
                    self::slotReserved(
                        $day,
                        'peyk',
                        $range
                    );

                $left = max(
                    0,
                    $capacity - $reserved
                );

                $slots[] = [
                    'id' =>
                    'peyk|'
                        . $day->format('Y-m-d')
                        . '|'
                        . $range,

                    'label' =>
                    self::slotLabelFa(
                        $startS,
                        $endS
                    ),

                    'left' => $left,

                    'available' =>
                    $left > 0,
                ];
            }

            if ($slots) {

                $out[] = [
                    'date' =>
                    $day->format('Y-m-d'),

                    'label' =>
                    Time::dayLabelFa(
                        $day
                    ),

                    'slots' => $slots,
                ];
            }

            $day = $day->modify(
                '+1 day'
            );
        }

        return $out;
    }

    /**
     * =========================================================
     * پست تهران
     * =========================================================
     *
     * مشتری تاریخ تحویل به پست را می‌بیند.
     *
     * ساعت داخلی پست به مشتری نمایش داده نمی‌شود.
     */
    private static function buildTehranPostDays(
        DateTimeImmutable $now
    ): array {

        $window = Setting::getInt(
            'ship_window_days',
            3
        );

        $window = max(
            1,
            min(
                $window,
                3
            )
        );

        $capacity = Setting::getInt(
            'ship_capacity_post',
            20
        );

        /*
         * برای پست تهران:
         * اگر امروز روز کاری و هنوز قبل از cutoff هست،
         * امروز می‌تواند گزینه تحویل به پست باشد.
         *
         * بعد از cutoff:
         * امروز حذف می‌شود و از روز کاری بعد شروع می‌کنیم.
         */
        if (
            Time::isWorkingDay($now)
            && !Time::isAfterCutoff($now)
        ) {

            $day = $now->setTime(
                0,
                0,
                0
            );
        } else {

            $day = Time::nextWorkingDay(
                $now
            );
        }

        $out = [];

        while (
            count($out) < $window
        ) {

            if (
                !Time::isWorkingDay($day)
            ) {

                $day = $day->modify(
                    '+1 day'
                );

                continue;
            }

            $reserved =
                self::slotReserved(
                    $day,
                    'post',
                    'dispatch'
                );

            $left = max(
                0,
                $capacity - $reserved
            );

            $out[] = [
                'date' =>
                $day->format('Y-m-d'),

                'label' =>
                Time::dayLabelFa(
                    $day
                ),

                'slots' => [[

                    'id' =>
                    'post|'
                        . $day->format('Y-m-d')
                        . '|dispatch',

                    /*
                     * ساعت حذف شد.
                     */
                    'label' =>
                    'تحویل مرسوله به پست',

                    'left' => $left,

                    'available' =>
                    $left > 0,
                ]],
            ];

            $day = $day->modify(
                '+1 day'
            );
        }

        return $out;
    }

    /**
     * =========================================================
     * ظرفیت Slot
     * =========================================================
     */
    private static function slotReserved(
        DateTimeImmutable $day,
        string $method,
        string $label
    ): int {

        try {

            $stmt = db()->prepare(
                "SELECT reserved
                 FROM delivery_slots
                 WHERE slot_date = ?
                   AND method = ?
                   AND slot_label = ?
                 LIMIT 1"
            );

            $stmt->execute([
                $day->format('Y-m-d'),
                $method,
                $label,
            ]);

            return (int)(
                $stmt->fetchColumn() ?: 0
            );
        } catch (Throwable $e) {

            return 0;
        }
    }

    /**
     * =========================================================
     * ایجاد Slot در DB
     * =========================================================
     */
    public static function ensureSlotRow(
        string $date,
        string $method,
        string $label,
        string $start,
        string $end,
        int $capacity
    ): int {

        $db = db();

        $stmt = $db->prepare(
            "SELECT id, reserved
             FROM delivery_slots
             WHERE slot_date = ?
               AND method = ?
               AND slot_label = ?
             LIMIT 1"
        );

        $stmt->execute([
            $date,
            $method,
            $label,
        ]);

        $row = $stmt->fetch();

        if ($row) {
            return (int)$row['reserved'];
        }

        $db->prepare(
            "INSERT INTO delivery_slots
                (
                    slot_date,
                    method,
                    slot_label,
                    slot_start,
                    slot_end,
                    capacity
                )
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $date,
            $method,
            $label,
            $start,
            $end,
            $capacity,
        ]);

        return 0;
    }

    /**
     * =========================================================
     * اعتبارسنجی انتخاب نهایی
     * =========================================================
     *
     * این متد در مرحله ثبت نهایی سفارش دوباره
     * انتخاب مشتری را بررسی می‌کند.
     */
    public static function validateChoice(
        string $province,
        string $method,
        string $slotId,
        int $addressId,
        int $userId
    ): array {

        $options = self::getOptions(
            $province
        );

        if (
            !isset(
                $options['methods'][$method]
            )
        ) {

            throw new RuntimeException(
                'این روش ارسال برای منطقه شما مجاز نیست'
            );
        }

        $parts = explode(
            '|',
            $slotId,
            3
        );

        if (count($parts) !== 3) {

            throw new RuntimeException(
                'گزینه ارسال انتخاب‌شده معتبر نیست'
            );
        }

        [$m, $date, $range] = $parts;

        if ($m !== $method) {

            throw new RuntimeException(
                'روش ارسال انتخاب‌شده معتبر نیست'
            );
        }

        foreach (
            $options['methods'][$method]['days']
            as $day
        ) {

            if (
                $day['date'] !== $date
            ) {
                continue;
            }

            foreach (
                $day['slots']
                as $slot
            ) {

                if (
                    $slot['id'] === $slotId
                    && $slot['available']
                ) {

                    return [
                        'method' => $method,

                        'date' => $date,

                        'label' =>
                        $slot['label'],

                        'fee' =>
                        $options['methods'][$method]['fee'],
                    ];
                }
            }
        }

        throw new RuntimeException(
            'بازه ارسال انتخابی شما دیگر موجود نیست — گزینه جدید انتخاب کنید'
        );
    }

    /**
     * =========================================================
     * رزرو ظرفیت
     * =========================================================
     *
     * فقط برای پیک و پست تهران که dispatch date
     * قابل رزرو است.
     *
     * شهرستان و حضوری Slot ندارند.
     */
    public static function reserveSlot(
        string $slotId
    ): void {

        $parts = explode(
            '|',
            $slotId,
            3
        );

        if (count($parts) !== 3) {
            return;
        }

        [$method, $date, $range] = $parts;

        /*
         * شهرستان:
         * اصلاً Slot رزرو نمی‌کند.
         *
         * حضوری:
         * Slot ندارد.
         */
        if (
            $method === 'in_person'
        ) {
            return;
        }

        /*
         * پست:
         * Slot فقط برای تاریخ تحویل به پست است.
         */
        if (
            $method === 'post'
            && $range === 'dispatch'
        ) {

            $capacity = Setting::getInt(
                'ship_capacity_post',
                20
            );

            self::ensureSlotRow(
                $date,
                'post',
                'dispatch',
                '',
                '',
                $capacity
            );

            $stmt = db()->prepare(
                "UPDATE delivery_slots
                 SET reserved = reserved + 1
                 WHERE slot_date = ?
                   AND method = ?
                   AND slot_label = ?
                   AND reserved < capacity"
            );

            $stmt->execute([
                $date,
                'post',
                'dispatch',
            ]);

            if (
                $stmt->rowCount() !== 1
            ) {

                throw new RuntimeException(
                    'ظرفیت تحویل مرسوله به پست تکمیل شده است'
                );
            }

            return;
        }

        /*
         * پیک:
         * Slot واقعی دارد.
         */
        if (
            $method === 'peyk'
        ) {

            if (
                !str_contains(
                    $range,
                    '-'
                )
            ) {

                throw new RuntimeException(
                    'بازه پیک نامعتبر است'
                );
            }

            [$start, $end] =
                array_pad(
                    explode(
                        '-',
                        $range,
                        2
                    ),
                    2,
                    ''
                );

            $capacity = Setting::getInt(
                'ship_capacity_peyk',
                8
            );

            self::ensureSlotRow(
                $date,
                'peyk',
                $range,
                trim($start),
                trim($end),
                $capacity
            );

            $stmt = db()->prepare(
                "UPDATE delivery_slots
                 SET reserved = reserved + 1
                 WHERE slot_date = ?
                   AND method = ?
                   AND slot_label = ?
                   AND reserved < capacity"
            );

            $stmt->execute([
                $date,
                'peyk',
                $range,
            ]);

            if (
                $stmt->rowCount() !== 1
            ) {

                throw new RuntimeException(
                    'ظرفیت بازه انتخابی پیک تکمیل شده است'
                );
            }

            return;
        }

        throw new RuntimeException(
            'روش ارسال برای رزرو ظرفیت معتبر نیست'
        );
    }

    /**
     * =========================================================
     * آزادسازی ظرفیت
     * =========================================================
     */
    public static function releaseSlot(
        string $slotId
    ): void {

        $parts = explode(
            '|',
            $slotId,
            3
        );

        if (count($parts) !== 3) {
            return;
        }

        [$method, $date, $range] = $parts;

        /*
         * حضوری و شهرستان Slot ندارند.
         */
        if (
            $method === 'in_person'
        ) {
            return;
        }

        $label = $range;

        /*
         * پست تهران
         */
        if (
            $method === 'post'
            && $range === 'dispatch'
        ) {
            $label = 'dispatch';
        }

        db()->prepare(
            "UPDATE delivery_slots
             SET reserved = GREATEST(0, reserved - 1)
             WHERE slot_date = ?
               AND method = ?
               AND slot_label = ?"
        )->execute([
            $date,
            $method,
            $label,
        ]);
    }

    /**
     * =========================================================
     * نرمال‌سازی نام استان
     * =========================================================
     */
    public static function normalizeProvince(
        string $province
    ): string {

        $province = trim(
            $province
        );

        /*
         * یکسان‌سازی حروف فارسی
         */
        $province = str_replace(
            [
                'ي',
                'ى',
                'ك',
                'ۀ',
                'ه‌',
            ],
            [
                'ی',
                'ی',
                'ک',
                'ه',
                'ه',
            ],
            $province
        );

        return $province;
    }

    /**
     * =========================================================
     * برچسب بازه زمانی
     * =========================================================
     */
    private static function slotLabelFa(
        string $start,
        string $end
    ): string {

        return self::faTime(
            $start
        )
            . ' تا '
            . self::faTime(
                $end
            );
    }

    /**
     * تبدیل ساعت به اعداد فارسی
     */
    private static function faTime(
        string $time
    ): string {

        return Time::faDigits(
            $time
        );
    }
}
