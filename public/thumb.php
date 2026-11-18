<?php

/**
 * RABETI — thumb.php  v4  — بندانگشتی‌ساز آنی با کش «اتمیک»
 * ════════════════════════════════════════════════════════════════
 * قواعد طلایی:
 *   ۱) هر شکستی → «فایل اصلی» سرو می‌شود (عکس‌ها هرگز نمی‌شکنند).
 *   ۲) نوشتن کش «اتمیک» است (tmp + rename) → هرگز webp ناقص سرو نمی‌شود.
 *   ۳) قبل از سروِ کش، اعتبارسنجی می‌شود (getimagesize) → فایل خراب دوباره ساخته می‌شود.
 *   ۴) گارد هم‌زمانی: اگر همین thumb در حال ساخت است، فایل اصلی سرو می‌شود.
 *   ۵) ⭐ فایل مبدأ در «دو قالب مسیر» جستجو می‌شود — باگ دوبله‌شدن uploads (v3) رفع شد:
 *        f=products/x.webp            →  uploads/products/x.webp
 *        f=uploads/products/x.webp    →  uploads/products/x.webp   (قالب DB شما)
 *   ۶) شکست‌ها در uploads/thumbs/rabeti-thumb.log ثبت می‌شوند:
 *          http://localhost/thumb.php?debug=1
 *
 * نصب:  D:\projects\sock-b2b\public\thumb.php   (کنار index.php)
 * نمونه URL:  /thumb.php?f=uploads/products/xxx.webp&w=340&h=340&q=82
 */

declare(strict_types=1);

/* ───────── تنظیمات ───────── */
$SITE_ROOT   = __DIR__;                                        // = پوشه‌ی public
$UPLOAD_ROOT = $SITE_ROOT . DIRECTORY_SEPARATOR . 'uploads';   // فقط زیر همین‌ها مجاز است
$CACHE_DIR   = $UPLOAD_ROOT . DIRECTORY_SEPARATOR . 'thumbs';
$LOG_FILE    = $CACHE_DIR . DIRECTORY_SEPARATOR . 'rabeti-thumb.log';
$ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$MAX_DIM     = 1600;   // سقف امنیت برای w و h

/* ───────── ورودی ───────── */
$f     = (string)($_GET['f'] ?? '');
$w     = (int)($_GET['w'] ?? 0);
$h     = (int)($_GET['h'] ?? 0);   // اختیاری: اگر داده شود، برش مرکزی دقیق w×h
$q     = min(95, max(40, (int)($_GET['q'] ?? 82)));
$debug = false;
if (isset($_GET['debug'])) {
    foreach ([__DIR__ . '/../sock-b2b/config/bootstrap.php', __DIR__ . '/../config/bootstrap.php'] as $__b) {
        if (is_file($__b)) {
            require_once $__b;
            break;
        }
    }
    unset($__b);
    if (defined('ROOT_PATH') && is_file(ROOT_PATH . '/config/auth.php')) {
        require_once ROOT_PATH . '/config/auth.php';
        $debug = is_admin_logged_in();
    }
}

/* اندازه و کیفیت به چند مقدار ثابت گرد می‌شوند تا کش با پارامترهای دلخواه پر نشود */
$w = $w > 0 ? min($MAX_DIM, (int)ceil($w / 20) * 20) : 0;
$h = $h > 0 ? min($MAX_DIM, (int)ceil($h / 20) * 20) : 0;
$q = $q >= 86 ? 90 : ($q >= 76 ? 82 : ($q >= 65 ? 70 : 60));

$f = str_replace('\\', '/', $f);
$f = ltrim($f, '/');

/* ⭐ دیباگ موقت: بررسی مسیر */
if ($debug && $f !== '') {
    error_log('[thumb-debug] f=' . $f . ' realpath=' . var_export(realpath($UPLOAD_ROOT . DIRECTORY_SEPARATOR . $f), true));
}

/* ───────── ابزارهای کمکی ───────── */

/** ثبت رویدادهای خطا برای عیب‌یابی (بی‌صدا؛ اگر نشد مهم نیست) */
function r6_log(string $reason, string $detail = ''): void
{
    global $LOG_FILE;
    $dir = dirname($LOG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(
        $LOG_FILE,
        date('Y-m-d H:i:s') . ' | ' . $reason . ($detail !== '' ? ' | ' . $detail : '') . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/**
 * مسیر امن و واقعیِ فایل مبدأ را برمی‌گرداند؛ null یعنی فایل نیست.
 * ⭐ v4: چون DB مسیر را «uploads/products/x.webp» ذخیره می‌کند و UPLOAD_ROOT خودش
 * پوشه‌ی uploads است، در v3 مسیر دوبله (uploads/uploads/…) 404 می‌شد.
 * حالا هر دو قالب امتحان می‌شوند و مسیر پیدا شده باید زیر یکی از ریشه‌های مجاز باشد.
 */
function r6_orig_path(string $f, string $uploadRoot, string $siteRoot): ?string
{
    if ($f === '' || strpos($f, '..') !== false) {
        return null;
    }
    if (preg_match('#(^|[/\\\\])(receipts|custom-orders)([/\\\\]|$)#i', $f)) {
        return null;
    }
    $candidates = [
        $uploadRoot . DIRECTORY_SEPARATOR . $f,   /* f = products/x.webp یا uploads/products/x.webp */
        $siteRoot   . DIRECTORY_SEPARATOR . $f,   /* f = uploads/products/x.webp یا Image/1.webp   */
    ];
    foreach ($candidates as $cand) {
        $p = realpath($cand);
        if ($p === false) {
            $p = $cand; // fallback: مسیر خام
        }
        if (!is_file($p)) {
            continue;
        }
        $roots = [realpath($uploadRoot), realpath($siteRoot)];
        foreach ($roots as $root) {
            if ($root !== false && strpos($p, $root) === 0) {
                return $p;
            }
        }
    }
    return null;
}

/** خروجی یک فایل موجود (اصل یا کش) — همیشه موفق */
function r6_emit_file(string $path, ?string $mime = null, string $cacheCtl = 'public, max-age=86400'): void
{
    if ($mime === null) {
        $d    = @getimagesize($path);
        $mime = (is_array($d) && !empty($d['mime'])) ? $d['mime'] : 'application/octet-stream';
    }
    header('Content-Type: ' . $mime);
    header('Cache-Control: ' . $cacheCtl);
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

/** 404 واقعی — فقط وقتی فایل مبدأ وجود ندارد */
function r6_not_found(string $f): void
{
    r6_log('source-missing-404', $f);
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('not found');
}

/* ───────── حالت عیب‌یابی:  /thumb.php?debug=1  ───────── */
if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "RABETI thumb.php v4 — diagnosis\n";
    echo str_repeat('=', 56) . "\n";
    echo "PHP version        : " . PHP_VERSION . "\n";
    echo "thumb.php is in    : " . __DIR__ . "\n";
    echo "uploads dir        : " . (is_dir($UPLOAD_ROOT) ? 'OK' : 'MISSING !!') . "  ({$UPLOAD_ROOT})\n";
    if (!is_dir($CACHE_DIR) && is_dir($UPLOAD_ROOT)) {
        @mkdir($CACHE_DIR, 0775, true);
    }
    echo "cache dir          : " . (is_dir($CACHE_DIR) ? 'OK' : 'MISSING !!') . "  ({$CACHE_DIR})\n";
    echo "cache writable     : " . (is_dir($CACHE_DIR) && is_writable($CACHE_DIR) ? 'yes' : 'NO !! (thumbs work without caching)') . "\n";
    echo "GD extension       : " . (extension_loaded('gd') ? 'yes' : 'NO !! (original files will be served)') . "\n";
    echo "GD WebP decode     : " . (function_exists('imagecreatefromwebp') ? 'yes' : 'NO  (original files will be served)') . "\n";
    echo "GD WebP encode     : " . (function_exists('imagewebp') ? 'yes' : 'NO  (original files will be served)') . "\n";
    echo "cached thumbs      : " . ((is_dir($CACHE_DIR) && ($n = count((array)@glob($CACHE_DIR . '/*.webp')))) ? (string)$n : '0') . "\n";

    /* پاکسازی کامل کش:  /thumb.php?debug=1&purge=1 */
    if (isset($_GET['purge']) && is_dir($CACHE_DIR)) {
        $purged = 0;
        foreach ((array)@glob($CACHE_DIR . '/*.webp') as $cf) {
            if (@unlink($cf)) {
                $purged++;
            }
        }
        foreach ((array)@glob($CACHE_DIR . '/*.tmp')  as $cf) {
            @unlink($cf);
        }
        r6_log('cache-purged-manually', $purged . ' files');
        echo "PURGE: {$purged} cached thumb(s) deleted — اولین بازدید هر عکس دوباره resize می‌شود.\n\n";
    }

    echo "\nRecent log (rabeti-thumb.log — last 20):\n";
    echo str_repeat('-', 56) . "\n";
    $lines = @file($LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines) && count($lines) > 0) {
        foreach (array_slice($lines, -20) as $ln) {
            echo "  " . $ln . "\n";
        }
    } else {
        echo "  (empty — no events logged)\n";
    }

    echo "\nSample test URLs (first file found per folder):\n";
    $found = 0;
    foreach (['products', 'categories', 'brand', 'banners'] as $sub) {
        if ($found >= 4) {
            break;
        }
        $files = @glob($UPLOAD_ROOT . '/' . $sub . '/*.webp');
        if (!$files) {
            $files = @glob($UPLOAD_ROOT . '/' . $sub . '/*.jpg');
        }
        if (!$files || empty($files[0])) {
            continue;
        }
        $rel = 'uploads/' . $sub . '/' . basename($files[0]);
        echo "  http://localhost/thumb.php?f=" . rawurlencode($rel) . "&w=340&h=340\n";
        $found++;
    }
    if ($found === 0) {
        echo "  (no image found under uploads/products|categories|brand|banners)\n";
    }
    exit;
}

/* ───────── اعتبارسنجی ───────── */
if ($w < 20 || $w > $MAX_DIM || $h > $MAX_DIM) {
    r6_not_found($f);
}

$srcPath = r6_orig_path($f, $UPLOAD_ROOT, $SITE_ROOT);
if ($srcPath === null) {
    r6_not_found($f);   /* فایل مبدأ واقعاً وجود ندارد — 404 درست است */
}

$ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
if (!in_array($ext, $ALLOWED_EXT, true)) {
    r6_emit_file($srcPath);   /* پسوند عجیب؟ همان اصلی بده، سایت نشکند */
}

$dim = @getimagesize($srcPath);
$srcMime = (is_array($dim) && !empty($dim['mime'])) ? $dim['mime'] : null;

/* اگر تصویر اصلی کوچک‌تر از w است، بزرگ‌نمایی بی‌معناست → همان اصلی */
if ($dim && $dim[0] <= $w) {
    r6_emit_file($srcPath, $srcMime);
}

/* ───────── کش ───────── */
$cacheOk = is_dir($CACHE_DIR) || @mkdir($CACHE_DIR, 0775, true);
$cacheOk = $cacheOk && is_writable($CACHE_DIR);

$cacheName = $w . 'x' . $h . 'q' . $q . '_' . md5($f) . '.webp';
$cachePath = $CACHE_DIR . DIRECTORY_SEPARATOR . $cacheName;

if ($cacheOk && is_file($cachePath)) {
    /* اعتبارسنجی قبل از سرو: فقط فایل کشِ سالم سرو می‌شود (self-healing) */
    $cdim = @getimagesize($cachePath);
    if ($cdim && (int)$cdim[0] >= $w && @filemtime($cachePath) >= @filemtime($srcPath)) {
        r6_emit_file($cachePath, 'image/webp', 'public, max-age=31536000, immutable');
    }
    /* فایل کش خراب/ناقص است → حذف و ساخت مجدد */
    if ($cdim === false || (int)$cdim[0] < $w) {
        r6_log('cache-corrupt-regenerate', $cacheName . ' (' . filesize($cachePath) . ' bytes)');
        @unlink($cachePath);
    }
}

/* گارد هم‌زمانی: اگر ساختِ همین thumb در جریان است، منتظر نمان — اصلی را بده */
if ($cacheOk) {
    $inFlight = @glob($cachePath . '.*.tmp');
    if (is_array($inFlight)) {
        foreach ($inFlight as $tmp) {
            $age = time() - (int)@filemtime($tmp);
            if ($age < 0) {
                $age = 0;
            }
            if ($age < 15) {
                r6_log('in-flight-serve-original', $cacheName);
                r6_emit_file($srcPath, $srcMime);
            } else {
                @unlink($tmp);   /* بقایای درخواست کرش‌شده */
            }
        }
    }
}

/* ───────── decode (بدون GD / بدون WebP → فایل اصلی) ───────── */
$img = false;
if (function_exists('imagecreatetruecolor')) {
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $img = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($srcPath) : false;
            break;
        case 'png':
            $img = function_exists('imagecreatefrompng')  ? @imagecreatefrompng($srcPath)  : false;
            break;
        case 'gif':
            $img = function_exists('imagecreatefromgif')  ? @imagecreatefromgif($srcPath)  : false;
            break;
        case 'webp':
            $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false;
            break;
    }
}

/* GD یا فرمت پشتیبانی نشد → همان فایل اصلی بده تا سایت نشکند */
if (!$img) {
    r6_log('decode-failed-serve-original', $f . ' [' . $ext . ']');
    r6_emit_file($srcPath, $srcMime);
}

/* ───────── resize (+ برش مرکزی اگر h داده شده باشد) ───────── */
$srcW = imagesx($img);
$srcH = imagesy($img);

$cropX = 0;
$cropY = 0;
$cropW = $srcW;
$cropH = $srcH;

if ($h > 0) {
    /* برش مرکزی به نسبت دقیق w:h (حل audit تصویر با نسبت اشتباه در هیرو) */
    $targetRatio = $w / $h;
    $srcRatio    = $srcW / $srcH;

    if ($srcRatio > $targetRatio) {
        $cropW = (int)round($srcH * $targetRatio);
        $cropX = (int)round(($srcW - $cropW) / 2);
    } else {
        $cropH = (int)round($srcW / $targetRatio);
        $cropY = (int)round(($srcH - $cropH) / 2);
    }
    $newW = $w;
    $newH = $h;
} else {
    $newW = $w;
    $newH = (int)round($srcH * ($w / $srcW));
    if ($newH < 1) {
        $newH = 1;
    }
}

$out = imagecreatetruecolor($newW, $newH);

/* حفظ شفافیت PNG/WebP */
imagealphablending($out, false);
imagesavealpha($out, true);
$transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
imagefilledrectangle($out, 0, 0, $newW, $newH, $transparent);

imagecopyresampled($out, $img, 0, 0, $cropX, $cropY, $newW, $newH, $cropW, $cropH);
imagedestroy($img);

/* ───────── encode + نوشتن اتمیک کش (هر شکست → فایل اصلی) ───────── */
if (!function_exists('imagewebp')) {
    imagedestroy($out);
    r6_log('encode-func-missing-serve-original', $f);
    r6_emit_file($srcPath, $srcMime);
}

if ($cacheOk) {
    /* نوشتن اتمیک: اول tmp، بعد rename → هیچ‌وقت فایل ناقص سرو نمی‌شود */
    $tmpPath = $cachePath . '.' . getmypid() . '.tmp';
    $ok = @imagewebp($out, $tmpPath, $q);
    imagedestroy($out);

    if ($ok && is_file($tmpPath) && filesize($tmpPath) > 100) {
        if (is_file($cachePath)) {
            @unlink($cachePath);
        }
        if (@rename($tmpPath, $cachePath)) {
            r6_emit_file($cachePath, 'image/webp', 'public, max-age=31536000, immutable');
        }
        /* rename نشد (قفل ویندوز و…) → همان tmp را مستقیم بده؛ tmp بعداً پاک می‌شود */
        r6_log('rename-failed-emit-tmp', $cacheName);
        r6_emit_file($tmpPath, 'image/webp', 'public, max-age=3600');
    }

    @unlink($tmpPath);
    r6_log('write-failed-serve-original', $f . ' [' . $ext . ' ' . $srcW . 'x' . $srcH . ']');
    r6_emit_file($srcPath, $srcMime);
}

/* کش روی دیسک ممکن نیست → بدون کش، مستقیم خروجی بده */
header('Content-Type: image/webp');
header('Cache-Control: public, max-age=86400');
$direct = @imagewebp($out, null, $q);
imagedestroy($out);
if ($direct) {
    exit;
}

r6_log('stream-encode-failed-serve-original', $f);
r6_emit_file($srcPath, $srcMime);
