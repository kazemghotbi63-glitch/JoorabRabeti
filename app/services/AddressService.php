<?php
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/settings.php';

final class AddressService
{
    private const TEHRAN = 'تهران';

    public static function list(int $userId): array
    {
        $stmt = db()->prepare("SELECT * FROM customer_addresses
                               WHERE user_id=? AND is_active=1
                               ORDER BY is_default DESC, id DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function get(int $userId, int $addressId): ?array
    {
        $stmt = db()->prepare("SELECT * FROM customer_addresses
                               WHERE id=? AND user_id=? AND is_active=1 LIMIT 1");
        $stmt->execute([$addressId, $userId]);
        return $stmt->fetch() ?: null;
    }

    public static function getDefault(int $userId): ?array
    {
        $stmt = db()->prepare("SELECT * FROM customer_addresses
                               WHERE user_id=? AND is_active=1
                               ORDER BY is_default DESC, id DESC LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public static function create(int $userId, array $d): int
    {
        self::validate($d);

        $max = Setting::getInt('ship_max_addresses', 5);
        $count = count(self::list($userId));
        if ($count >= $max) {
            throw new RuntimeException('حداکثر ' . $max . ' آدرس فعال می‌توانید داشته باشید');
        }

        $isDefault = ($count === 0) ? 1 : (int)!empty($d['is_default']);
        if ($isDefault) {
            db()->prepare("UPDATE customer_addresses SET is_default=0 WHERE user_id=?")->execute([$userId]);
        }

        db()->prepare("
            INSERT INTO customer_addresses
                (user_id, label, province, city, address, postal_code, plate_no, unit_no,
                 receiver_name, receiver_mobile, is_default)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $userId,
            trim($d['label'] ?? '') ?: 'آدرس اصلی',
            self::normalizeProvince($d['province']),
            trim($d['city']),
            trim($d['address']),
            $d['postal_code'] ?: null,
            $d['plate_no'] ?: null,
            $d['unit_no'] ?: null,
            $d['receiver_name'] ?: null,
            $d['receiver_mobile'] ?: null,
            $isDefault,
        ]);
        return (int)db()->lastInsertId();
    }

    public static function update(int $userId, int $addressId, array $d): void
    {
        if (!self::get($userId, $addressId)) {
            throw new RuntimeException('آدرس یافت نشد');
        }
        self::validate($d);

        db()->prepare("
            UPDATE customer_addresses SET
                label=?, province=?, city=?, address=?, postal_code=?,
                plate_no=?, unit_no=?, receiver_name=?, receiver_mobile=?
            WHERE id=? AND user_id=?
        ")->execute([
            trim($d['label'] ?? '') ?: 'آدرس اصلی',
            self::normalizeProvince($d['province']),
            trim($d['city']),
            trim($d['address']),
            $d['postal_code'] ?: null,
            $d['plate_no'] ?: null,
            $d['unit_no'] ?: null,
            $d['receiver_name'] ?: null,
            $d['receiver_mobile'] ?: null,
            $addressId,
            $userId,
        ]);
    }

    /* حذف نرم — snapshot سفارشات امن می‌ماند */
    public static function deactivate(int $userId, int $addressId): void
    {
        db()->prepare("UPDATE customer_addresses SET is_active=0 WHERE id=? AND user_id=?")
            ->execute([$addressId, $userId]);
    }

    public static function setDefault(int $userId, int $addressId): void
    {
        db()->prepare("UPDATE customer_addresses SET is_default=0 WHERE user_id=?")->execute([$userId]);
        db()->prepare("UPDATE customer_addresses SET is_default=1 WHERE id=? AND user_id=?")
            ->execute([$addressId, $userId]);
    }

    private static function validate(array $d): void
    {
        if (mb_strlen(trim($d['province'] ?? '')) < 2) throw new RuntimeException('استان را وارد کنید');
        if (mb_strlen(trim($d['city'] ?? '')) < 2)     throw new RuntimeException('شهر را وارد کنید');
        if (mb_strlen(trim($d['address'] ?? '')) < 10) throw new RuntimeException('آدرس کامل را وارد کنید');
        if (!empty($d['postal_code']) && !preg_match('/^\d{10}$/', $d['postal_code']))
            throw new RuntimeException('کد پستی باید ۱۰ رقم باشد');
        if (!empty($d['receiver_mobile']) && !preg_match('/^09\d{9}$/', self::faToEn($d['receiver_mobile'])))
            throw new RuntimeException('موبایل گیرنده معتبر نیست');
    }

    public static function normalizeProvince(string $p): string
    {
        $p = trim(str_replace(['ي'], ['ی'], $p));
        return $p === self::TEHRAN || mb_strpos($p, 'تهران') === 0 ? self::TEHRAN : $p;
    }

    private static function faToEn(string $s): string
    {
        return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }
}
