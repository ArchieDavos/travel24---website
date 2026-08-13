<?php
define('APP_ACCESS', true);
require_once 'db.php';
header('Content-Type: application/xml; charset=utf-8');

$base = 'https://travel24.me';

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Strony statyczne
echo "  <url><loc>$base/index.php</loc><changefreq>daily</changefreq><priority>1.0</priority></url>\n";
echo "  <url><loc>$base/gallery.php</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>\n";

// Wpisy na blogu
try {
    $posts = $pdo->query("SELECT id FROM posts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($posts as $p) {
        echo "  <url><loc>$base/post.php?id=" . (int)$p['id'] . "</loc><changefreq>monthly</changefreq><priority>0.7</priority></url>\n";
    }
} catch (PDOException $e) {
    error_log("Błąd sitemap.php (posts): " . $e->getMessage());
}

// Albumy ze zdjęciami
try {
    $albums = $pdo->query("SELECT id FROM albums ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($albums as $a) {
        echo "  <url><loc>$base/album.php?id=" . (int)$a['id'] . "</loc><changefreq>monthly</changefreq><priority>0.6</priority></url>\n";
    }
} catch (PDOException $e) {
    error_log("Błąd sitemap.php (albums): " . $e->getMessage());
}

// Zakładki (poza "wyprawy" i "galeria", które i tak są wyżej jako index/gallery)
try {
    $pages = $pdo->query("SELECT slug FROM pages ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pages as $pg) {
        if (in_array(strtolower($pg['slug']), ['wyprawy', 'galeria'])) continue;
        echo "  <url><loc>$base/page.php?slug=" . urlencode($pg['slug']) . "</loc><changefreq>monthly</changefreq><priority>0.5</priority></url>\n";
    }
} catch (PDOException $e) {
    error_log("Błąd sitemap.php (pages): " . $e->getMessage());
}

echo '</urlset>';
