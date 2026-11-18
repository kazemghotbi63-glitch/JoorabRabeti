<?php

/**
 * [FILE] app/services/ShippingService.php — v3
 * (Weight + Zone + Tiered Post + Factor + Free Shipping)
 */
class ShippingService
{
    /* ═══════════════════════════════════════════════════════════
       ۱) Zone Resolution
       ═══════════════════════════════════════════════════════════ */

    public static function resolveZone(PDO $db, string $province): string
    {
        $stmt = $db->prepare("SELECT zone FROM shipping_zones WHERE province = ? LIMIT 1");
        $stmt->execute([$province]);
        $z = $stmt->fetchColumn();
        return in_array($z, ['near', 'far', 'very_far'], true) ? $z : 'far';
    }

    /* ═══════════════════════════════════════════════════════════
       ۲) Origin
       ═══════════════════════════════════════════════════════════ */

    public static function getOrigin(PDO $db): array
    {
        $stmt = $db->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ('ship_origin_province','ship_origin_city')");
        $stmt->execute();
        $out = ['province' => 'تهران', 'city' => 'تهران'];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['key'] === 'ship_origin_province' && $row['value'] !== '')
                $out['province'] = $row['value'];
            if ($row['key'] === 'ship_origin_city' && $row['value'] !== '')
                $out['city'] = $row['value'];
        }
        return $out;
    }

    /* ═══════════════════════════════════════════════════════════
       ۳) Settings Loader
       ═══════════════════════════════════════════════════════════ */

    public static function loadSettings(PDO $db, bool $forceReload = false): array
    {
        static $cache = null;
        if ($cache !== null && !$forceReload) return $cache;

        $prefixes = ['ship_', 'site_announce'];
        $where = [];
        $params = [];
        foreach ($prefixes as $p) {
            $where[] = "`key` LIKE ?";
            $params[] = $p . '%';
        }
        $stmt = $db->prepare("SELECT `key`,`value` FROM settings WHERE " . implode(' OR ', $where));
        $stmt->execute($params);

        $cache = [];
        foreach ($stmt->fetchAll() as $row) $cache[$row['key']] = $row['value'];
        return $cache;
    }

    /* ═══════════════════════════════════════════════════════════
       ۴) Weight Calculation
       ═══════════════════════════════════════════════════════════ */

    public static function calculateCartWeight(PDO $db, array $cartItems): array
    {
        $s = self::loadSettings($db);

        $gramsPerPair  = max(0, (int)($s['ship_grams_per_pair']  ?? 250));
        $gramsPerJin   = max(0, (int)($s['ship_grams_per_jin']   ?? 3000));
        $gramsPerBaste = max(0, (int)($s['ship_grams_per_baste'] ?? 2500));

        $totalGrams = 0;
        $totalPairs = 0;
        $breakdown  = [];
        $unitCounts = [];

        $pids = array_values(array_filter(array_map(
            static fn($i) => (int)($i['product_id'] ?? 0),
            $cartItems
        )));
        $productWeights = [];
        if ($pids) {
            $in = implode(',', array_fill(0, count($pids), '?'));
            try {
                $stmt = $db->prepare("SELECT product_id, grams_per_unit FROM product_weights WHERE product_id IN ($in)");
                $stmt->execute($pids);
                foreach ($stmt->fetchAll() as $row) {
                    $productWeights[(int)$row['product_id']] = (int)$row['grams_per_unit'];
                }
            } catch (Throwable $e) { /* جدول نیست */
            }
        }

        foreach ($cartItems as $item) {
            $pid   = (int)($item['product_id'] ?? 0);
            $qty   = (int)($item['qty'] ?? 0);
            if ($qty <= 0) continue;

            $unit  = (string)($item['unit_code'] ?? 'جفتی');

            $multiplier = match (true) {
                str_contains($unit, 'جین')  => 12,
                str_contains($unit, 'بسته') => 10,
                default                     => 1,
            };

            $pairs = $qty * $multiplier;
            $totalPairs += $pairs;

            // شمارش واحدها برای شرط رایگان
            $unitKey = str_contains($unit, 'جین') ? 'جین'
                : (str_contains($unit, 'بسته') ? 'بسته' : 'جفتی');
            $unitCounts[$unitKey] = ($unitCounts[$unitKey] ?? 0) + $qty;

            if (isset($productWeights[$pid]) && $productWeights[$pid] > 0) {
                $grams = $qty * $productWeights[$pid];
            } elseif (str_contains($unit, 'جین')) {
                $grams = $qty * $gramsPerJin;
            } elseif (str_contains($unit, 'بسته')) {
                $grams = $qty * $gramsPerBaste;
            } else {
                $grams = $qty * $gramsPerPair;
            }

            $totalGrams += $grams;

            $breakdown[] = [
                'product_id' => $pid,
                'qty'        => $qty,
                'unit'       => $unit,
                'pairs'      => $pairs,
                'grams'      => $grams,
            ];
        }

        return [
            'grams'       => $totalGrams,
            'pairs'       => $totalPairs,
            'breakdown'   => $breakdown,
            'unit_counts' => $unitCounts,
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       ۵) Fee: Weight-Based (حالت موجود)
       ═══════════════════════════════════════════════════════════ */

    public static function calculateFee(PDO $db, string $method, int $weightG, string $zone, bool $isTehran = false): int
    {
        $s = self::loadSettings($db);

        if ($method === 'peyk') {
            return max(0, (int)($s['ship_fee_peyk_tehran'] ?? 80000));
        }
        if ($method === 'in_person') {
            return max(0, (int)($s['ship_fee_in_person'] ?? 0));
        }

        $base = max(0, (int)($s["ship_fee_{$method}_base"] ?? 0));

        if ($isTehran) {
            $rateKey = "ship_rate_{$method}_tehran";
        } else {
            $zoneKey = match ($zone) {
                'near'     => 'near',
                'far'      => 'far',
                'very_far' => 'very_far',
                default    => 'far',
            };
            $rateKey = "ship_rate_{$method}_{$zoneKey}";
        }

        $rate = max(0, (int)($s[$rateKey] ?? 0));

        $kg = (int)ceil($weightG / 1000);
        if ($kg < 1) $kg = 1;

        return $base + ($kg * $rate);
    }

    /* ═══════════════════════════════════════════════════════════
       ۶) Fee: Tiered (پلکانی بر اساس مبلغ)
       ═══════════════════════════════════════════════════════════ */

    public static function getPostTierForAmount(PDO $db, int $orderAmount): ?array
    {
        $amount = max(0, $orderAmount);
        try {
            $stmt = $db->prepare("
                SELECT id, min_amount, max_amount, fee
                FROM shipping_post_tiers
                WHERE min_amount <= :a
                  AND (max_amount IS NULL OR :a2 < max_amount)
                ORDER BY min_amount DESC
                LIMIT 1
            ");
            $stmt->execute([':a' => $amount, ':a2' => $amount]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function getPostFeeByAmount(PDO $db, int $orderAmount): int
    {
        $tier = self::getPostTierForAmount($db, $orderAmount);
        if ($tier !== null) return max(0, (int)$tier['fee']);

        $s = self::loadSettings($db);
        return max(0, (int)($s['ship_fee_post_base'] ?? 0));
    }

    /* ═══════════════════════════════════════════════════════════
       ۷) Fee: Factor (پایه + پله‌ای بر مبلغ)
       ═══════════════════════════════════════════════════════════ */

    public static function getFactorFee(PDO $db, int $orderAmount): int
    {
        $s = self::loadSettings($db);

        $base      = max(0, (int)($s['ship_factor_base_fee']       ?? 200000));
        $threshold = max(0, (int)($s['ship_factor_threshold']      ?? 0));
        $step      = max(1, (int)($s['ship_factor_step']           ?? 1000000));
        $increment = max(0, (int)($s['ship_factor_increment']      ?? 50000));

        if ($orderAmount <= $threshold) return $base;

        $excess = $orderAmount - $threshold;
        $steps  = (int)floor($excess / $step);
        return $base + ($steps * $increment);
    }

    /* ═══════════════════════════════════════════════════════════
       ۸) Free Shipping Check (مقدم بر همه)
       ═══════════════════════════════════════════════════════════ */

    /**
     * بررسی شرط ارسال رایگان.
     *
     * @return array{is_free:bool, rule_id:?int, reason:string}
     */
    public static function checkFreeShipping(
        PDO $db,
        string $method,
        int $orderAmount,
        array $unitCounts
    ): array {
        try {
            $stmt = $db->query("SELECT * FROM shipping_free_rules WHERE is_active = 1 ORDER BY sort_order, id");
            $rules = $stmt->fetchAll();
        } catch (Throwable $e) {
            return ['is_free' => false, 'rule_id' => null, 'reason' => 'table_missing'];
        }

        foreach ($rules as $rule) {
            $methods = json_decode($rule['methods_json'] ?? '["all"]', true);
            if (!is_array($methods)) $methods = ['all'];

            $methodMatches = in_array('all', $methods, true) || in_array($method, $methods, true);
            if (!$methodMatches) continue;

            $threshold = max(0, (int)$rule['threshold']);

            if ($rule['rule_type'] === 'amount') {
                if ($orderAmount >= $threshold) {
                    return ['is_free' => true, 'rule_id' => (int)$rule['id'], 'reason' => 'amount_threshold'];
                }
            } elseif ($rule['rule_type'] === 'unit') {
                $unitName = (string)($rule['unit_name'] ?? '');
                $count = (int)($unitCounts[$unitName] ?? 0);
                if ($threshold > 0 && $count >= $threshold) {
                    return ['is_free' => true, 'rule_id' => (int)$rule['id'], 'reason' => 'unit_threshold'];
                }
            }
        }

        return ['is_free' => false, 'rule_id' => null, 'reason' => 'no_rule'];
    }

    /* ═══════════════════════════════════════════════════════════
       ۹) Mode Detection (کدام لایه‌ها فعال‌اند؟)
       ═══════════════════════════════════════════════════════════ */

    public static function getActiveModes(PDO $db): array
    {
        $s = self::loadSettings($db);
        return [
            'tier'   => ($s['ship_mode_tier_enabled']   ?? '0') === '1',
            'factor' => ($s['ship_mode_factor_enabled'] ?? '0') === '1',
            'weight' => ($s['ship_mode_weight_enabled'] ?? '1') === '1',
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       ۱۰) Main Fee Resolver (اولویت: رایگان → پلکانی → ضریب → وزنی)
       ═══════════════════════════════════════════════════════════ */

    /**
     * @return array{
     *   fee:int,
     *   calc_mode:string,
     *   calc_base_amount:?int,
     *   calc_tier_min:?int,
     *   calc_tier_max:?int,
     *   calc_free_rule_id:?int
     * }
     */
    public static function getFee(
        PDO $db,
        string $method,
        int $orderAmount,
        int $weightG,
        int $pairs,
        array $unitCounts,
        string $zone,
        bool $isTehran
    ): array {
        // ۱) ارسال رایگان — برای همه روش‌ها اعمال می‌شود (طبق تیک‌های ادمین)
        $free = self::checkFreeShipping($db, $method, $orderAmount, $unitCounts);
        if ($free['is_free']) {
            return [
                'fee'               => 0,
                'calc_mode'         => 'free',
                'calc_base_amount'  => $orderAmount,
                'calc_tier_min'     => null,
                'calc_tier_max'     => null,
                'calc_free_rule_id' => $free['rule_id'],
            ];
        }

        $modes = self::getActiveModes($db);

        // ── ۲) حالت‌های مبلغی (پلکانی/ضریب) فقط برای پست ──
        if ($method === 'post') {
            if ($modes['tier']) {
                $tier = self::getPostTierForAmount($db, $orderAmount);
                $fee  = $tier !== null ? (int)$tier['fee'] : self::getPostFeeByAmount($db, $orderAmount);
                return [
                    'fee'               => max(0, $fee),
                    'calc_mode'         => 'tier',
                    'calc_base_amount'  => $orderAmount,
                    'calc_tier_min'     => $tier['min_amount'] ?? null,
                    'calc_tier_max'     => $tier['max_amount'] ?? null,
                    'calc_free_rule_id' => null,
                ];
            }

            if ($modes['factor']) {
                $fee = self::getFactorFee($db, $orderAmount);
                return [
                    'fee'               => max(0, $fee),
                    'calc_mode'         => 'factor',
                    'calc_base_amount'  => $orderAmount,
                    'calc_tier_min'     => null,
                    'calc_tier_max'     => null,
                    'calc_free_rule_id' => null,
                ];
            }
        }

        // ── ۳) وزنی (برای پست اگر حالت مبلغی خاموش بود + همه روش‌های دیگر) ──
        if ($modes['weight']) {
            $fee = self::calculateFee($db, $method, $weightG, $zone, $isTehran);
            return [
                'fee'               => max(0, $fee),
                'calc_mode'         => 'weight',
                'calc_base_amount'  => $weightG,
                'calc_tier_min'     => null,
                'calc_tier_max'     => null,
                'calc_free_rule_id' => null,
            ];
        }

        // ── ۴) اگر حالت وزنی هم خاموش بود، برای روش‌های غیرپست حداقل هزینه ثابت ──
        // (پیک/حضوری همیشه هزینه ثابت دارند حتی اگر حالت وزنی خاموش باشد)
        if (in_array($method, ['peyk', 'in_person'], true)) {
            $fee = self::calculateFee($db, $method, $weightG, $zone, $isTehran);
            return [
                'fee'               => max(0, $fee),
                'calc_mode'         => 'fixed',
                'calc_base_amount'  => null,
                'calc_tier_min'     => null,
                'calc_tier_max'     => null,
                'calc_free_rule_id' => null,
            ];
        }

        // ── ۵) هیچ‌کدام ──
        return [
            'fee'               => 0,
            'calc_mode'         => 'none',
            'calc_base_amount'  => null,
            'calc_tier_min'     => null,
            'calc_tier_max'     => null,
            'calc_free_rule_id' => null,
        ];
    }

    /* ═══════════════════════════════════════════════════════════
       ۱۱) Slot Building (بدون تغییر)
       ═══════════════════════════════════════════════════════════ */

    public static function buildDays(
        string $method,
        int $prepMinutes,
        array $slotRanges,
        int $capacityPerSlot,
        int $windowDays,
        string $cutoff,
        array $holidays,
        array $bookedCounts
    ): array {
        $out = [];
        $dayNames = [1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه', 7 => 'یکشنبه'];
        $faDigits = ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'];

        $toJalali = static function (DateTimeImmutable $dt): string {
            $gy = (int)$dt->format('Y');
            $gm = (int)$dt->format('n');
            $gd = (int)$dt->format('j');
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
            return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        };

        $now      = new DateTimeImmutable('now');
        $todayCut = DateTimeImmutable::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $cutoff);
        $afterCut = $todayCut && $now >= $todayCut;

        for ($d = 0; $d < $windowDays; $d++) {
            $date = $now->modify("+{$d} days");
            if ($d === 0 && $afterCut) continue;
            if (in_array($date->format('Y-m-d'), $holidays, true)) continue;
            if (in_array($method, ['post', 'tipax', 'barbari'], true) && $date->format('N') === '5') continue;

            $slots = [];
            foreach ($slotRanges as $range) {
                $parts = explode('-', trim($range));
                if (count($parts) !== 2) continue;
                [$sh, $eh] = array_map('trim', $parts);

                $slotStart = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date->format('Y-m-d') . ' ' . $sh);
                if (!$slotStart) continue;

                $readyAt   = $now->modify("+{$prepMinutes} minutes");
                $available = true;
                if ($d === 0 && $slotStart < $readyAt) $available = false;

                $slotId = $date->format('Y-m-d') . '|' . trim($range);
                $left   = $capacityPerSlot - (int)($bookedCounts[$slotId] ?? 0);
                if ($left <= 0) $available = false;

                $slots[] = [
                    'id'        => $slotId,
                    'label'     => trim($range),
                    'available' => $available,
                    'left'      => max(0, $left),
                ];
            }

            if (!$slots) continue;

            $out[] = [
                'date'  => $date->format('Y-m-d'),
                'label' => $dayNames[(int)$date->format('N')] . ' ' . strtr($toJalali($date), $faDigits),
                'slots' => $slots,
            ];
        }

        return $out;
    }

    public static function loadBookedCounts(PDO $db, string $fromDate, string $toDate): array
    {
        try {
            $stmt = $db->prepare("
                SELECT shipping_date, shipping_slot, COUNT(*) AS cnt
                FROM orders
                WHERE shipping_date BETWEEN ? AND ?
                  AND status NOT IN ('cancelled','returned')
                GROUP BY shipping_date, shipping_slot
            ");
            $stmt->execute([$fromDate, $toDate]);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[$row['shipping_date'] . '|' . $row['shipping_slot']] = (int)$row['cnt'];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /* ═══════════════════════════════════════════════════════════
       ۱۲) Main Entry: getOptions
       ═══════════════════════════════════════════════════════════ */

    public static function getOptions(
        PDO $db,
        string $province,
        string $city,
        int $pairQty,
        int $weightG,
        int $orderAmount = 0,
        array $unitCounts = []
    ): array {
        $s        = self::loadSettings($db);
        $origin   = self::getOrigin($db);
        $zone     = self::resolveZone($db, $province);
        $isTehran = (trim($province) === trim($origin['province']));

        $windowDays = max(1, (int)($s['ship_window_days'] ?? 7));
        $cutoff     = (string)($s['ship_cutoff_time'] ?? '16:00');

        $holidays = [];
        try {
            foreach ($db->query("SELECT day FROM holidays") as $row) $holidays[] = $row['day'];
        } catch (Throwable $e) {
        }

        $from = (new DateTimeImmutable('now'))->format('Y-m-d');
        $to   = (new DateTimeImmutable("+{$windowDays} days"))->format('Y-m-d');
        $booked = self::loadBookedCounts($db, $from, $to);

        $maxPairsPeyk = max(0, (int)($s['ship_max_pairs_peyk'] ?? 100));
        $peykBlocked  = $pairQty > $maxPairsPeyk;
        $warning      = $peykBlocked
            ? "تعداد جفت سفارش ({$pairQty}) از سقف پیک ({$maxPairsPeyk}) بیشتر است؛ پیک موتوری حذف شد. لطفاً پست/تیپاکس/باربری را انتخاب کنید."
            : null;

        $methods = [];

        // helper: resolve fee با سه لایه
        $resolve = function (string $method) use ($db, $orderAmount, $weightG, $pairQty, $unitCounts, $zone, $isTehran) {
            return self::getFee($db, $method, $orderAmount, $weightG, $pairQty, $unitCounts, $zone, $isTehran);
        };

        // ۱) پیک
        if ($isTehran && !$peykBlocked) {
            $slots = [
                (string)($s['ship_slot_peyk_1'] ?? '10:00-13:00'),
                (string)($s['ship_slot_peyk_2'] ?? '13:00-16:00'),
                (string)($s['ship_slot_peyk_3'] ?? '16:00-19:00'),
            ];
            $slots = array_values(array_filter($slots, static fn($v) => $v !== ''));
            $calc = $resolve('peyk');
            $methods['peyk'] = [
                'label'            => 'پیک موتوری (تهران)',
                'fee'              => $calc['fee'],
                'calc'             => $calc,
                'estimate'         => '',
                'days'             => self::buildDays(
                    'peyk',
                    (int)($s['ship_prep_minutes_peyk'] ?? 60),
                    $slots,
                    (int)($s['ship_capacity_peyk'] ?? 8),
                    $windowDays,
                    $cutoff,
                    $holidays,
                    $booked
                ),
            ];
        }

        // ۲) پست
        $postSlots = [(string)($s['ship_post_hours_start'] ?? '10:00') . '-' . (string)($s['ship_post_hours_end'] ?? '17:00')];
        $calc = $resolve('post');
        $methods['post'] = [
            'label'    => $isTehran ? 'پست تهران' : 'پست شهرستان',
            'fee'      => $calc['fee'],
            'calc'     => $calc,
            'estimate' => 'تحویل ۲ تا ۵ روز کاری',
            'days'     => self::buildDays(
                'post',
                (int)($s['ship_prep_minutes_post'] ?? 180),
                $postSlots,
                (int)($s['ship_capacity_post'] ?? 20),
                $windowDays,
                $cutoff,
                $holidays,
                $booked
            ),
        ];

        // ۳) تیپاکس
        $tipaxSlots = [(string)($s['ship_tipax_hours_start'] ?? '09:00') . '-' . (string)($s['ship_tipax_hours_end'] ?? '18:00')];
        $calc = $resolve('tipax');
        $methods['tipax'] = [
            'label'    => $isTehran ? 'تیپاکس تهران' : 'تیپاکس شهرستان',
            'fee'      => $calc['fee'],
            'calc'     => $calc,
            'estimate' => 'تحویل ۱ تا ۳ روز کاری',
            'days'     => self::buildDays(
                'tipax',
                (int)($s['ship_prep_minutes_tipax'] ?? 120),
                $tipaxSlots,
                999,
                $windowDays,
                $cutoff,
                $holidays,
                $booked
            ),
        ];

        // ۴) باربری
        $barbariSlots = [(string)($s['ship_barbari_hours_start'] ?? '08:00') . '-' . (string)($s['ship_barbari_hours_end'] ?? '16:00')];
        $calc = $resolve('barbari');
        $methods['barbari'] = [
            'label'    => $isTehran ? 'باربری تهران' : 'باربری شهرستان',
            'fee'      => $calc['fee'],
            'calc'     => $calc,
            'estimate' => 'تحویل ۳ تا ۷ روز کاری',
            'days'     => self::buildDays(
                'barbari',
                (int)($s['ship_prep_minutes_barbari'] ?? 240),
                $barbariSlots,
                999,
                $windowDays,
                $cutoff,
                $holidays,
                $booked
            ),
        ];

        // ۵) حضوری
        if ($isTehran) {
            $inPersonSlots = [
                (string)($s['ship_slot_in_person_1'] ?? '10:00-13:00'),
                (string)($s['ship_slot_in_person_2'] ?? '16:00-19:00'),
            ];
            $inPersonSlots = array_values(array_filter($inPersonSlots, static fn($v) => $v !== ''));
            $calc = $resolve('in_person');
            $methods['in_person'] = [
                'label'    => 'تحویل حضوری (انبار)',
                'fee'      => $calc['fee'],
                'calc'     => $calc,
                'estimate' => '',

                'days'     => self::buildDays(
                    'in_person',
                    (int)($s['ship_prep_minutes_in_person'] ?? 120),
                    $inPersonSlots,
                    (int)($s['ship_capacity_in_person'] ?? 6),
                    $windowDays,
                    $cutoff,
                    $holidays,
                    $booked
                ),
            ];
        }

        // ── مرتب‌سازی: پیک اول، بعد بقیه از ارزان به گران
        uasort($methods, static function ($a, $b) {
            return $a['fee'] <=> $b['fee'];
        });
        if (isset($methods['peyk'])) {
            $peyk = $methods['peyk'];
            unset($methods['peyk']);
            $methods = ['peyk' => $peyk] + $methods;
        }

        return [
            'origin'       => $origin,
            'zone'         => $zone,
            'is_tehran'    => $isTehran,
            'weight_grams' => $weightG,
            'pair_qty'     => $pairQty,
            'order_amount' => $orderAmount,
            'unit_counts'  => $unitCounts,
            'methods'      => $methods,
            'warning'      => $warning,
        ];
    }
}
