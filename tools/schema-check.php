<?php

/**
 * schema-check.php — ابزار یک‌باره برای پیدا کردن ستون‌های گمشده
 * اجرا: php tools/schema-check.php > schema-report.txt
 * یا در مرورگر: /tools/schema-check.php
 *
 * ⚠️ بعد از استفاده، این فایل را پاک کن (یا از دسترس خارج کن)
 */

declare(strict_types=1);

/* ─── bootstrap ─── */
$__bp = [
    __DIR__ . '/../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../config/bootstrap.php',
];
foreach ($__bp as $__b) {
    if (is_file($__b)) {
        require_once $__b;
        break;
    }
}
unset($__bp, $__b);
require_once ROOT_PATH . '/config/database.php';

$db = db();
$root = ROOT_PATH;

/* ─── ۱. لیست جدول‌ها و ستون‌ها ─── */
$tables = [];
$stmt = $db->query('SHOW TABLES');
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $tables[$row[0]] = [];
}
foreach ($tables as $t => $_) {
    $cols = $db->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        $tables[$t][] = $c['Field'];
    }
}

/* ─── ۲. پیمایش فایل‌های PHP ─── */
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$missing = [];   // [table => [col => [files...]]]
$usedCols = [];  // [table => [col => [files...]]]

foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
    if (str_contains($path, DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR)) continue;

    $src = file_get_contents($path);
    if ($src === false) continue;

    /* استخراج تمام رشته‌های داخل "..." و '...' که شبیه SQL هستند */
    $patterns = [
        // SELECT ... FROM table ... WHERE col
        '/\b(FROM|JOIN|UPDATE|INTO)\s+`?([a-z_][a-z0-9_]*)`?/i',
    ];

    /* همه SQL های احتمالی را پیدا کن */
    if (!preg_match_all('/["\']([^"\']{20,})["\']/s', $src, $m)) continue;

    foreach ($m[1] as $sql) {
        if (!preg_match('/\b(SELECT|UPDATE|INSERT|DELETE)\b/i', $sql)) continue;

        /* جدول‌های استفاده‌شده در این کوئری */
        $foundTables = [];
        if (preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO)\s+`?([a-z_][a-z0-9_]*)`?(?:\s+(?:AS\s+)?([a-z_][a-z0-9_]*))?/i', $sql, $tm, PREG_SET_ORDER)) {
            foreach ($tm as $t) {
                $tbl = $t[1];
                $alias = $t[2] ?? $tbl;
                if (isset($tables[$tbl])) {
                    $foundTables[$alias] = $tbl;
                }
            }
        }
        if (!$foundTables) continue;

        /* ستون‌هایی که با alias. یا مستقیم استفاده شده‌اند */
        // alias.column
        if (preg_match_all('/\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/i', $sql, $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) {
                $alias = strtolower($c[1]);
                $col   = strtolower($c[2]);
                if (isset($foundTables[$alias])) {
                    $tbl = $foundTables[$alias];
                    $usedCols[$tbl][$col][] = basename($path);
                    if (!in_array($col, array_map('strtolower', $tables[$tbl]), true)) {
                        $missing[$tbl][$col][] = basename($path);
                    }
                }
            }
        }
    }
}

/* ─── ۳. گزارش ─── */
header('Content-Type: text/plain; charset=utf-8');
echo "═══ گزارش ستون‌های گمشده ═══\n\n";

if (!$missing) {
    echo "✅ هیچ ستون گمشده‌ای پیدا نشد.\n";
    exit;
}

foreach ($missing as $tbl => $cols) {
    echo "📁 جدول: $tbl\n";
    foreach ($cols as $col => $files) {
        $files = array_unique($files);
        echo "   ❌ $col   (استفاده در: " . implode(', ', array_slice($files, 0, 5)) . ")\n";
    }
    echo "\n   ستون‌های موجود در DB: " . implode(', ', $tables[$tbl]) . "\n\n";
}

echo "─── خلاصه ───\n";
foreach ($missing as $tbl => $cols) {
    echo "ALTER TABLE `$tbl` ADD COLUMN ... ;  // " . count($cols) . " ستون\n";
}
