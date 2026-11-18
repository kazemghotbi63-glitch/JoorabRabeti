<?php
/**
 * ═══════════════════════════════════════════════════════════════
 *  optimize-images.php — بهینه‌ساز یکجای تصاویر (فرمت + رزولوشن)
 *  ─────────────────────────────────────────────────────────────
 *  کاری که می‌کند:
 *   ۱) همه JPG/PNG/WebP داخل پوشه uploads/ را پیدا می‌کند
 *   ۲) اگر بزرگ‌تر از حد مجاز باشد، Resize می‌کند (پیش‌فرض ۱۰۰۰px)
 *   ۳) با فرمت WebP کیفیت ۸۰ ذخیره می‌کند (هم‌نام، پسوند .webp)
 *   ۴) یک نسخه کوچک -400.webp هم برای کارت‌ها می‌سازد
 *   ۵) فایل اصلی را در uploads/_originals/ بایگانی می‌کند
 *   ۶) مسیرها را در دیتابیس آپدیت می‌کند (اختیاری — پیش‌فرض فعال)
 *
 *  اجرا (در ریشه پروژه):
 *      php scripts/optimize-images.php --dry-run        ← فقط گزارش، بدون تغییر
 *      php scripts/optimize-images.php                  ← اجرای کامل
 *      php scripts/optimize-images.php --no-db          ← بدون آپدیت دیتابیس
 *      php scripts/optimize-images.php --max=1000 --quality=80 --sm=400
 *
 *  ⚠️  قبل از اجرا: بکاپ کامل uploads/ و دیتابیس بگیرید.
 *  بعد از اجرا: فایل را حذف کنید.
 * ═══════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') { exit("فقط از خط فرمان اجرا شود.\n"); }

/* ── پارامترها ─────────────────────────────────────────────── */
$opts = getopt('', ['dry-run', 'no-db', 'max::', 'quality::', 'sm::']);
$DRY_RUN = isset($opts['dry-run']);
$SKIP_DB = isset($opts['no-db']);
$MAX_W   = (int)($opts['max'] ?? 1000);      // حداکثر ضلع بزرگ تصویر اصلی
$SM_W    = (int)($opts['sm'] ?? 400);        // عرض نسخه کوچک برای کارت‌ها
$QUALITY = (int)($opts['quality'] ?? 80);

if (!function_exists('imagewebp')) {
    exit("GD با پشتیبانی WebP موجود نیست. PHP: " . PHP_VERSION . "\n");
}

$ROOT = is_dir(dirname(__DIR__, 2) . '/public_html')
    ? dirname(__DIR__, 2) . '/public_html'
    : dirname(__DIR__) . '/public';
$UPLOADS = $ROOT . '/uploads';
$BACKUP  = $UPLOADS . '/_originals';

echo "═══ بهینه‌ساز تصاویر ═══\n";
echo "max={$MAX_W}px  small={$SM_W}px  quality={$QUALITY}" . ($DRY_RUN ? "  [DRY-RUN]" : "") . "\n\n";

/* ── اتصال دیتابیس (همان الگوی bootstrap پروژه) ────────────── */
$hasDb = false;
if (!$SKIP_DB) {
    foreach ([dirname(__DIR__) . '/config/bootstrap.php'] as $bp) {
        if (is_file($bp)) { require_once $bp; break; }
    }
    if (defined('ROOT_PATH') && is_file(ROOT_PATH . '/config/database.php')) {
        require_once ROOT_PATH . '/config/database.php';
        $hasDb = function_exists('db');
    }
    if (!$hasDb) echo "⚠️  دیتابیس وصل نشد — فقط فایل‌ها پردازش می‌شوند.\n\n";
}

/* ستون‌هایی که مسیر تصویر ذخیره می‌کنند: [table => [columns]] */
$DB_MAP = [
    'product_images'  => ['image_path'],
    'categories'      => ['image_path'],
    'banners'         => ['image_path', 'mobile_image_path'],
    'instagram_posts' => ['image_path'],
    'products'        => ['image_path'],   // اگر ستون ندارد، خودکار رد می‌شود
];
$DB_SETTINGS_KEYS = ['site_logo', 'site_favicon', 'og_default_image', 'custom_order_image'];

$updatedRows = 0;

/* ── پردازش بازگشتی ────────────────────────────────────────── */
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($UPLOADS, FilesystemIterator::SKIP_DOTS)
);

$stats = ['converted' => 0, 'resized' => 0, 'skipped' => 0, 'failed' => 0, 'bytes_before' => 0, 'bytes_after' => 0];

foreach ($rii as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) continue;

    $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($UPLOADS))), '/');
    if (str_starts_with($rel, '_originals/')) continue;

    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) { $stats['skipped']++; continue; }

    // نسخه‌های کوچک قبلی را دست نزن
    if (preg_match('/-\d+\.webp$/', $rel)) { $stats['skipped']++; continue; }

    $srcPath = $file->getPathname();
    $oldSize = filesize($srcPath);
    $stats['bytes_before'] += $oldSize;

    /* باز کردن تصویر */
    switch ($ext) {
        case 'jpg': case 'jpeg': $img = @imagecreatefromjpeg($srcPath); break;
        case 'png':              $img = @imagecreatefrompng($srcPath);  break;
        case 'webp':             $img = @imagecreatefromwebp($srcPath); break;
    }
    if (!$img) { echo "FAIL (باز نشد): {$rel}\n"; $stats['failed']++; continue; }
    imagepalettetotruecolor($img);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    $w = imagesx($img); $h = imagesy($img);
    $info = pathinfo($srcPath);
    $baseWebp = $info['dirname'] . '/' . $info['filename'] . '.webp';
    $relWebp  = preg_replace('/\.' . $ext . '$/i', '.webp', $rel);
    $changed  = false;

    /* ۱) ریسایز + تبدیل به WebP (اگر از حد بزرگ‌تر است یا فرمت قدیمی) */
    if ($ext !== 'webp' || max($w, $h) > $MAX_W) {
        $ratio  = min(1.0, $MAX_W / max($w, $h));
        $nw = max(1, (int)round($w * $ratio));
        $nh = max(1, (int)round($h * $ratio));
        if ($ratio < 1.0) $stats['resized']++;

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, true);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        if (!$DRY_RUN) {
            if (!is_dir($info['dirname'])) mkdir($info['dirname'], 0775, true);
            $ok = imagewebp($dst, $baseWebp, $QUALITY);
            if ($ok) $changed = true;
        } else { $changed = true; }
        imagedestroy($dst);
    } elseif ($ext !== 'webp') {
        if (!$DRY_RUN) $changed = (bool)imagewebp($img, $baseWebp, $QUALITY);
        else $changed = true;
    }
    imagedestroy($img);

    if ($changed) {
        /* ۲) نسخه کوچک -{SM}.webp برای کارت‌ها */
        if (max($w, $h) > $SM_W && !$DRY_RUN) {
            $img2 = @imagecreatefromwebp($baseWebp) ?: null;
            if ($img2) {
                $r2  = min(1.0, $SM_W / max($w, $h));
                $nw2 = max(1, (int)round($w * $r2));
                $nh2 = max(1, (int)round($h * $r2));
                $dst2 = imagecreatetruecolor($nw2, $nh2);
                imagealphablending($dst2, true);
                imagesavealpha($dst2, true);
                imagecopyresampled($dst2, $img2, 0, 0, 0, 0, $nw2, $nh2, $w, $h);
                imagewebp($dst2, $info['dirname'] . '/' . $info['filename'] . "-{$SM_W}.webp", $QUALITY);
                imagedestroy($dst2);
                imagedestroy($img2);
            }
        }

        /* ۳) بایگانی فایل اصلی */
        if (!$DRY_RUN) {
            $bk = $BACKUP . '/' . $rel;
            @mkdir(dirname($bk), 0775, true);
            @rename($srcPath, $bk);
        }

        $newSize = is_file($baseWebp) ? filesize($baseWebp) : 0;
        $stats['bytes_after'] += $newSize ?: $oldSize;
        $stats['converted']++;
        $saved = $oldSize ? round((1 - ($newSize ?: $oldSize) / $oldSize) * 100, 1) : 0;
        printf("OK  %-58s %8.1fKB → %8.1fKB  (-%s%%)\n", $rel, $oldSize / 1024, ($newSize ?: $oldSize) / 1024, $saved);

        /* ۴) آپدیت دیتابیس */
        if ($hasDb && !$DRY_RUN) {
            $oldDb = '/uploads/' . $rel;
            $newDb = '/uploads/' . $relWebp;
            foreach ($DB_MAP as $table => $cols) {
                foreach ($cols as $col) {
                    try {
                        $st = db()->prepare("UPDATE {$table} SET {$col} = ? WHERE {$col} = ?");
                        $st->execute([$newDb, $oldDb]);
                        $updatedRows += $st->rowCount();
                    } catch (Throwable $e) { /* ستون/جدول ندارد — رد شو */ }
                }
            }
            try {
                $st = db()->prepare("UPDATE settings SET value = ? WHERE name = ? AND value = ?");
                $st->execute([$newDb, 'x', $oldDb]); // الگو؛ پایین به‌صورت داینامیک اجرا می‌شود
            } catch (Throwable $e) {}
        }
    } else {
        $stats['bytes_after'] += $oldSize;
        $stats['skipped']++;
    }
}

/* آپدیت جدول settings (اسم کلید در value) */
if ($hasDb && !$DRY_RUN) {
    try {
        $rows = db()->query("SELECT name, value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $v = (string)$r['value'];
            if (in_array($r['name'], $DB_SETTINGS_KEYS, true)
                && str_starts_with($v, '/uploads/')
                && preg_match('/\.(jpe?g|png|webp)$/i', $v)
                && !preg_match('/-\d+\.webp$/i', $v)) {
                $nv = preg_replace('/\.(jpe?g|png|webp)$/i', '.webp', $v);
                if (is_file($ROOT . $nv)) {
                    $st = db()->prepare("UPDATE settings SET value = ? WHERE name = ?");
                    $st->execute([$nv, $r['name']]);
                    $updatedRows += $st->rowCount();
                }
            }
        }
    } catch (Throwable $e) { echo "settings: " . $e->getMessage() . "\n"; }
}

/* ── گزارش نهایی ───────────────────────────────────────────── */
echo "\n═══ خلاصه ═══\n";
echo "تبدیل شده: {$stats['converted']} | ریسایز: {$stats['resized']} | رد شده: {$stats['skipped']} | خطا: {$stats['failed']}\n";
printf("حجم: %.1f MB → %.1f MB  (صرفه‌جویی %.1f%%)\n",
    $stats['bytes_before'] / 1048576,
    $stats['bytes_after'] / 1048576,
    $stats['bytes_before'] ? (1 - $stats['bytes_after'] / $stats['bytes_before']) * 100 : 0
);
if ($hasDb && !$DRY_RUN) echo "سطرهای دیتابیس آپدیت شده: {$updatedRows}\n";
if ($DRY_RUN) echo "\n[DRY-RUN] هیچ فایلی تغییر نکرد. برای اجرای واقعی دستور را بدون --dry-run بزن.\n";
