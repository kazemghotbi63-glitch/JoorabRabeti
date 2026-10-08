<?php
/* sitemap پویا — از طریق /sitemap.xml (RewriteRule در .htaccess) */
require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';

/* [مسیر، priority، changefreq] */
$urls = [
    ['/',             '1.0', 'daily'],
    ['/products',     '0.9', 'daily'],
    ['/custom-order', '0.7', 'monthly'],
    ['/about',        '0.5', 'monthly'],
    ['/contact',      '0.5', 'monthly'],
];

try {
    $categories = db()->query("
        SELECT slug
        FROM categories
        WHERE is_active = 1
        ORDER BY id
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($categories as $slug) {
        $urls[] = ['/products?cat=' . rawurlencode($slug), '0.8', 'weekly'];
    }

    $products = db()->query("
        SELECT p.slug
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE p.is_active = 1
        ORDER BY p.id
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($products as $slug) {
        /* فقط slugهایی که RewriteRule مسیر /product/... می‌پذیرد */
        if (preg_match('/^[a-zA-Z0-9\-]+$/', (string)$slug)) {
            $urls[] = ['/product/' . $slug, '0.8', 'weekly'];
        }
    }
} catch (Throwable $e) {
    error_log('sitemap: ' . $e->getMessage());
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";

foreach ($urls as [$path, $priority, $changefreq]) {
    echo '    <url><loc>',
        htmlspecialchars(SITE_URL . $path, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        '</loc><changefreq>', $changefreq,
        '</changefreq><priority>', $priority,
        '</priority></url>', "\n";
}

echo '</urlset>', "\n";
