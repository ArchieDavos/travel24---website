<?php
define('APP_ACCESS', true);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 0);
require_once 'db.php';
$slug = isset($_GET['slug']) ? $_GET['slug'] : '';

// Ładujemy nasz słownik tłumaczeń dla awaryjnych tytułów
if (file_exists('lang.php')) {
    require_once 'lang.php';
}

// --- SYSTEM WIELOJĘZYCZNOŚCI (Wersja SEO) ---
$allowed_langs = ['pl', 'en', 'it', 'es', 'de'];

if (isset($_GET['lang']) && in_array($_GET['lang'], $allowed_langs)) {
    $_SESSION['lang'] = $_GET['lang'];
    $current_lang = $_GET['lang'];
} else {
    $current_lang = $_SESSION['lang'] ?? 'pl';
}

// Funkcja wyciągająca właściwe tłumaczenie
if (!function_exists('get_translated')) {
    function get_translated($item, $field, $lang) {
        if ($lang === 'pl') return $item[$field];
        $lang_field = $field . '_' . $lang;
        return (!empty($item[$lang_field])) ? $item[$lang_field] : $item[$field];
    }
}
// ---------------------------------

try {
    $stmt = $pdo->prepare("SELECT * FROM pages WHERE slug = ?");
    $stmt->execute([$slug]);
    $page_data = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Błąd pobierania zakładki '$slug' (page.php): " . $e->getMessage());
    $page_data = false;
}

if (!$page_data) { header("Location: index.php"); exit; }

// Wstępne pobranie tytułów z bazy
$translated_title = get_translated($page_data, 'title', $current_lang);
$translated_content = get_translated($page_data, 'content', $current_lang);

// INTELIGENTNY BYPASS DLA BRAKUJĄCYCH TYTUŁÓW W BAZIE
$slug_lower = strtolower($page_data['slug']);
if (strpos($slug_lower, 'o-mnie') !== false) {
    $translated_title = function_exists('__') ? __('about_me') : $translated_title;
} elseif (strpos($slug_lower, 'polityka') !== false || strpos($slug_lower, 'strona-9dce9c') !== false) {
    $translated_title = function_exists('__') ? __('privacy_policy') : $translated_title;
}

$page_url = 'https://travel24.me/page.php?slug=' . urlencode($page_data['slug']);
$page_description = trim(preg_replace('/\s+/', ' ', strip_tags($translated_content)));
$page_description = mb_substr($page_description, 0, 155);
if ($page_description === '') {
    $page_description = htmlspecialchars($translated_title) . ' - TRAVEL24.me';
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>TRAVEL24.me | <?php echo htmlspecialchars($translated_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($page_url); ?>">

    <!-- Tagi hreflang dla zakładki -->
    <link rel="alternate" hreflang="pl" href="https://travel24.me/page.php?slug=<?php echo urlencode($slug); ?>&lang=pl" />
    <link rel="alternate" hreflang="en" href="https://travel24.me/page.php?slug=<?php echo urlencode($slug); ?>&lang=en" />
    <link rel="alternate" hreflang="it" href="https://travel24.me/page.php?slug=<?php echo urlencode($slug); ?>&lang=it" />
    <link rel="alternate" hreflang="es" href="https://travel24.me/page.php?slug=<?php echo urlencode($slug); ?>&lang=es" />
    <link rel="alternate" hreflang="de" href="https://travel24.me/page.php?slug=<?php echo urlencode($slug); ?>&lang=de" />
    <link class="alternate" hreflang="x-default" href="https://travel24.me/page.php?slug=<?php echo urlencode($slug); ?>" />

    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo htmlspecialchars($translated_title); ?> | TRAVEL24.me">
    <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta property="og:image" content="https://travel24.me/hero-travel24.jpg">
    <meta property="og:url" content="<?php echo htmlspecialchars($page_url); ?>">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#f59e0b">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="/fonts/fonts.css" rel="stylesheet">
    <style>
        body, h1, h2, h3, h4, p, a, button, select, textarea { font-family: 'Montserrat', sans-serif !important; }
        .playfair { font-family: 'Playfair Display', serif !important; }
        body { background-color: #f8fafc; color: #18181b; overflow-x: hidden; }
        html, body { width: 100%; margin: 0; padding: 0; overflow-x: hidden; }

        .page-content h1, .page-content h2, .page-content h3, .page-content h4 { line-height: 1.4 !important; margin-top: 0.6em; margin-bottom: 0.6em; }
        .page-content p { margin-bottom: 1em; }
    </style>
</head>
<body class="flex flex-col min-h-screen">
    <?php include 'header.php'; ?>
    
    <!-- PŁYWAJĄCY PRZEŁĄCZNIK JĘZYKÓW Z PAMIĘCIĄ SLUGA ZAKŁADKI -->
    <div class="fixed bottom-6 right-6 z-50 bg-white/90 backdrop-blur-sm shadow-xl rounded-full px-4 py-2 flex gap-3 border border-zinc-200 transition-all">
        <a href="?slug=<?php echo urlencode($slug); ?>&lang=pl" class="text-2xl transition-opacity <?php echo $current_lang=='pl'?'opacity-100 scale-110':'opacity-50 hover:opacity-100'; ?>" title="Polski">🇵🇱</a>
        <a href="?slug=<?php echo urlencode($slug); ?>&lang=en" class="text-2xl transition-opacity <?php echo $current_lang=='en'?'opacity-100 scale-110':'opacity-50 hover:opacity-100'; ?>" title="English">🇬🇧</a>
        <a href="?slug=<?php echo urlencode($slug); ?>&lang=it" class="text-2xl transition-opacity <?php echo $current_lang=='it'?'opacity-100 scale-110':'opacity-50 hover:opacity-100'; ?>" title="Italiano">🇮🇹</a>
        <a href="?slug=<?php echo urlencode($slug); ?>&lang=es" class="text-2xl transition-opacity <?php echo $current_lang=='es'?'opacity-100 scale-110':'opacity-50 hover:opacity-100'; ?>" title="Español">🇪🇸</a>
        <a href="?slug=<?php echo urlencode($slug); ?>&lang=de" class="text-2xl transition-opacity <?php echo $current_lang=='de'?'opacity-100 scale-110':'opacity-50 hover:opacity-100'; ?>" title="Deutsch">🇩🇪</a>
    </div>

    <header class="relative w-full h-[45vh] flex items-center justify-center mt-16 overflow-hidden">
        <img src="https://images.unsplash.com/photo-1503220317375-aaad61436b1b?w=1600" class="absolute inset-0 w-full h-full object-cover z-0">
        <div class="absolute inset-0 bg-black/50 z-10"></div> 
        <div class="relative z-20 text-center px-4 max-w-full">
            <h1 class="playfair text-4xl md:text-6xl font-bold text-white drop-shadow-lg break-words"><?php echo htmlspecialchars($translated_title); ?></h1>
        </div>
    </header>
    <main class="flex-grow px-4 py-16 max-w-4xl mx-auto w-full">
        <div class="page-content bg-white p-6 md:p-12 rounded-2xl border border-zinc-200 shadow-lg text-zinc-700 leading-relaxed text-base md:text-lg">
            <?php echo nl2br($translated_content); ?>
        </div>
    </main>
    <?php include 'footer.php'; ?>
</body>
</html>