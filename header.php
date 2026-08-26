<?php
define('APP_ACCESS', true);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

// Ładujemy nasz słownik tłumaczeń
if (file_exists('lang.php')) {
    require_once 'lang.php';
}

try {
    $menu_pages = $pdo->query("SELECT * FROM pages ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Błąd pobierania menu (header.php): " . $e->getMessage());
    $menu_pages = [];
}

// Zabezpieczenie funkcji get_translated, gdybyśmy byli w pliku, który jej jeszcze nie wywołał
if (!function_exists('get_translated')) {
    function get_translated($item, $field, $lang) {
        if ($lang === 'pl') return $item[$field];
        $lang_field = $field . '_' . $lang;
        return (!empty($item[$lang_field])) ? $item[$lang_field] : $item[$field];
    }
}

$current_lang = $_GET['lang'] ?? ($_SESSION['lang'] ?? 'pl');

// TŁUMACZENIA LOKALNE DLA GŁÓWNEGO MENU
$ui_menu = [
    'pl' => [
        'trips' => 'Wyprawy',
        'gallery' => 'Galeria',
        'about' => 'O mnie',
        'privacy' => 'Polityka prywatności'
    ],
    'en' => [
        'trips' => 'Expeditions',
        'gallery' => 'Gallery',
        'about' => 'About me',
        'privacy' => 'Privacy Policy'
    ],
    'it' => [
        'trips' => 'Spedizioni',
        'gallery' => 'Galleria',
        'about' => 'Chi sono',
        'privacy' => 'Informativa sulla privacy'
    ],
    'es' => [
        'trips' => 'Expediciones',
        'gallery' => 'Galería',
        'about' => 'Sobre mí',
        'privacy' => 'Política de privacidad'
    ],
    'de' => [
        'trips' => 'Expeditionen',
        'gallery' => 'Galerie',
        'about' => 'Über mich',
        'privacy' => 'Datenschutzerklärung'
    ]
];

// Przycisk "Galeria" jest zawsze widoczny przy logo (nie chowamy go w menu) i jest
// kontekstowy: czytając wpis/etap prowadzi do galerii zdjęć powiązanej z TĄ wyprawą
// (album przypięty do wpisu, a jak go brak - do zbiorczej galerii całej wyprawy z etapami),
// wszędzie indziej (strona główna, itd.) prowadzi do listy wszystkich wypraw.
$gallery_href = 'gallery.php?lang=' . $current_lang;
if (isset($post) && is_array($post)) {
    if (!empty($post['linked_album_id'])) {
        $gallery_href = 'gallery.php?album_id=' . (int)$post['linked_album_id'] . '&lang=' . $current_lang;
    } else {
        $gallery_trip_id = ((int)$post['parent_id'] > 0) ? (int)$post['parent_id'] : (int)$post['id'];
        $gallery_href = 'gallery.php?trip_id=' . $gallery_trip_id . '&lang=' . $current_lang;
    }
}
?>
<nav class="fixed top-0 left-0 right-0 z-50 bg-white/95 backdrop-blur-md border-b border-zinc-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center gap-3">
        <div class="flex items-center gap-3 min-w-0">
            <!-- Logo odsyła na główną stronę trzymając bieżący język -->
            <a href="index.php?lang=<?php echo $current_lang; ?>" class="font-bold text-lg tracking-widest text-zinc-900 hover:text-amber-600 transition-colors shrink-0">TRAVEL24.me</a>

            <!-- Guzik Galerii - zawsze widoczny (nie chowamy go w menu mobilnym) -->
            <a href="<?php echo htmlspecialchars($gallery_href, ENT_QUOTES, 'UTF-8'); ?>" class="flex items-center gap-1 px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-xs md:text-sm font-bold uppercase tracking-wide rounded-md shadow-sm transition-colors shrink-0">
                📸 <?php echo $ui_menu[$current_lang]['gallery']; ?>
            </a>
        </div>

        <button id="menu-btn" class="md:hidden text-zinc-900 focus:outline-none"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg></button>

        <div id="menu" class="hidden md:flex flex-col md:flex-row absolute md:relative top-16 md:top-0 left-0 w-full md:w-auto bg-white/95 md:bg-transparent p-6 md:p-0 space-y-4 md:space-y-0 md:space-x-6 text-sm font-bold uppercase tracking-wider">
            <!-- Linki z zaktualizowanym słownikiem i przekazywaniem flagi językowej -->
            <a href="index.php?lang=<?php echo $current_lang; ?>" class="text-zinc-600 hover:text-amber-600 transition-colors"><?php echo $ui_menu[$current_lang]['trips']; ?></a>

            <?php foreach($menu_pages as $page): ?>
                <?php 
                $slug_lower = strtolower($page['slug']);
                $title_lower = strtolower($page['title']);
                
                if ($slug_lower == 'wyprawy' || $slug_lower == 'galeria') continue; 
                
                // Domyślnie bierzemy tytuł z bazy przetłumaczony na wybrany język
                $display_title = htmlspecialchars(get_translated($page, 'title', $current_lang));
                
                // Wymuszamy tłumaczenie z niezawodnego słownika dla dwóch głównych zakładek
                if (strpos($slug_lower, 'o-mnie') !== false || strpos($title_lower, 'mnie') !== false) {
                    $display_title = $ui_menu[$current_lang]['about'];
                }
                if (strpos($slug_lower, 'polityka') !== false || strpos($slug_lower, 'strona-9dce9c') !== false || strpos($title_lower, 'polityka') !== false) {
                    $display_title = $ui_menu[$current_lang]['privacy'];
                }
                ?>
                <a href="page.php?slug=<?php echo htmlspecialchars($page['slug']); ?>&lang=<?php echo $current_lang; ?>" class="text-zinc-600 hover:text-amber-600 transition-colors"><?php echo $display_title; ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</nav>

<script>
    document.getElementById('menu-btn').addEventListener('click', function(){ 
        document.getElementById('menu').classList.toggle('hidden'); 
    });
</script>

<!-- GŁÓWNY SKRYPT ONESIGNAL -->
<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
<script>
  window.OneSignalDeferred = window.OneSignalDeferred || [];
  OneSignalDeferred.push(function(OneSignal) {
    OneSignal.init({
      appId: "4dc4fd0c-4810-4b9e-9d04-22075da68048",
    });
  });
</script>