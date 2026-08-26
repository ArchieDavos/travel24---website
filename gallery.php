<?php
define('APP_ACCESS', true);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

if (file_exists('lang.php')) {
    require_once 'lang.php';
}

$allowed_langs = ['pl', 'en', 'it', 'es', 'de'];
$current_lang = $_GET['lang'] ?? ($_SESSION['lang'] ?? 'pl');
if (!in_array($current_lang, $allowed_langs)) $current_lang = 'pl';
$_SESSION['lang'] = $current_lang;

if (!function_exists('get_translated')) {
    function get_translated($item, $field, $lang) {
        if ($lang === 'pl') return $item[$field];
        $lang_field = $field . '_' . $lang;
        return (!empty($item[$lang_field])) ? $item[$lang_field] : $item[$field];
    }
}

// Funkcja do zdjęć ratunkowych
$travel_fallback_photos = [
    'https://images.unsplash.com/photo-1503220317375-aaad61436b1b?w=1600',
    'https://images.unsplash.com/photo-1476514525535-07fb3b4ae5f1?w=1600',
    'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=1600',
    'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?w=1600',
];

if (!function_exists('get_travel_fallback_photo')) {
    function get_travel_fallback_photo($item_id, $photos) {
        $index = ((int)$item_id) % count($photos);
        return $photos[$index];
    }
}

// PARAMETRY ODCZYTYWANE Z ADRESU URL
$album_id = isset($_GET['album_id']) ? (int)$_GET['album_id'] : 0;
$trip_id  = isset($_GET['trip_id'])  ? (int)$_GET['trip_id']  : 0;

$view_mode = 'home';
if ($album_id > 0) {
    $view_mode = 'album';
} elseif ($trip_id > 0) {
    $view_mode = 'trip';
}

$ui = [
    'pl' => ['back_home' => '🔙 Wróć na stronę główną', 'back_gallery' => '🔙 Wróć do listy wyjazdów', 'back_dest' => '🔙 Wróć do tego wyjazdu', 'empty' => 'Brak zdjęć w tej galerii.', 'gallery_title' => 'Główne Wyprawy', 'view_dest' => 'Zobacz galerię wyjazdu', 'no_albums' => 'Brak dostępnych wyjazdów.'],
    'en' => ['back_home' => '🔙 Back to home', 'back_gallery' => '🔙 Back to trips list', 'back_dest' => '🔙 Back to trip', 'empty' => 'No photos in this gallery.', 'gallery_title' => 'Main Trips', 'view_dest' => 'View trip gallery', 'no_albums' => 'No trips available.'],
    'it' => ['back_home' => '🔙 Torna alla home', 'back_gallery' => '🔙 Torna alla lista viaggi', 'back_dest' => '🔙 Torna al viaggio', 'empty' => 'Nessuna foto in questa galleria.', 'gallery_title' => 'Viaggi Principali', 'view_dest' => 'Vedi galleria viaggio', 'no_albums' => 'Nessun viaggio disponibile.'],
    'es' => ['back_home' => '🔙 Volver al inicio', 'back_gallery' => '🔙 Volver a la lista de viajes', 'back_dest' => '🔙 Volver al viaje', 'empty' => 'No hay fotos en esta galería.', 'gallery_title' => 'Viajes Principales', 'view_dest' => 'Ver galería de viaje', 'no_albums' => 'No hay viajes disponibles.'],
    'de' => ['back_home' => '🔙 Zurück zur Startseite', 'back_gallery' => '🔙 Zurück zur Reiseliste', 'back_dest' => '🔙 Zurück zur Reise', 'empty' => 'Keine Fotos in dieser Galerie.', 'gallery_title' => 'Hauptreisen', 'view_dest' => 'Reisegalerie ansehen', 'no_albums' => 'Keine Reisen verfügbar.']
];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($current_lang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TRAVEL24.me | Galeria</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css" />
    <style>
        body { background-color: #f8fafc; color: #18181b; font-family: 'Montserrat', sans-serif; }
        .playfair { font-family: 'Playfair Display', serif; }
    </style>
</head>
<body class="flex flex-col min-h-screen">

    <?php if (file_exists('header.php')) include 'header.php'; ?>

    <main class="flex-grow max-w-7xl mx-auto px-4 py-8 mt-16 w-full">

        <?php if ($view_mode === 'trip'): ?>
            <!-- ========================================== -->
            <!-- WIDOK 2: KONKRETNA WYPRAWA Z PODZIAŁEM NA ETAPY -->
            <!-- ========================================== -->
            <?php 
                $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ? AND parent_id = 0");
                $stmt->execute([$trip_id]);
                $main_trip = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$main_trip) {
                    echo "<p class='text-center mt-10'>Nie znaleziono wyprawy.</p>";
                } else {
                    $stmt_subs = $pdo->prepare("SELECT * FROM posts WHERE parent_id = ? ORDER BY id ASC");
                    $stmt_subs->execute([$trip_id]);
                    $sub_posts = $stmt_subs->fetchAll(PDO::FETCH_ASSOC);

                    // Łączymy główny wpis i pod-wpisy, aby wyświetlić je po kolei
                    $all_stages = array_merge([$main_trip], $sub_posts);
            ?>

            <a href="gallery.php?lang=<?php echo htmlspecialchars($current_lang, ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center mb-8 px-4 py-2 bg-white border border-zinc-300 hover:bg-zinc-100 text-zinc-800 font-bold rounded shadow-sm transition">
                <?php echo htmlspecialchars($ui[$current_lang]['back_gallery'], ENT_QUOTES, 'UTF-8'); ?>
            </a>

            <div class="mb-12 text-center">
                <h1 class="text-4xl md:text-5xl font-bold playfair mb-4 text-amber-600">
                    <?php echo htmlspecialchars(get_translated($main_trip, 'title', $current_lang), ENT_QUOTES, 'UTF-8'); ?>
                </h1>
            </div>

            <?php 
                $has_photos = false;
                foreach ($all_stages as $stage): 
                    if (!empty($stage['linked_album_id'])):
                        $stmt_photos = $pdo->prepare("SELECT * FROM album_photos WHERE album_id = ? AND is_published = 1 ORDER BY id ASC");
                        $stmt_photos->execute([$stage['linked_album_id']]);
                        $photos = $stmt_photos->fetchAll(PDO::FETCH_ASSOC);

                        if (!empty($photos)): 
                            $has_photos = true;
            ?>
                            <div class="mb-12 bg-white p-6 md:p-10 rounded-2xl shadow-sm border border-zinc-200">
                                <h2 class="text-2xl md:text-3xl font-bold playfair mb-6 border-l-4 border-amber-500 pl-4">
                                    <?php echo htmlspecialchars(get_translated($stage, 'title', $current_lang), ENT_QUOTES, 'UTF-8'); ?>
                                </h2>
                                
                                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                                    <?php foreach($photos as $p): ?>
                                        <div class="overflow-hidden rounded-xl shadow-sm border border-zinc-200 group">
                                            <!-- data-gallery używa ID wpisu, więc zdjęcia z różnych etapów się nie zmieszają -->
                                            <a href="<?php echo htmlspecialchars($p['photo_url'], ENT_QUOTES, 'UTF-8'); ?>" data-gallery="stage-<?php echo $stage['id']; ?>" class="glightbox block overflow-hidden aspect-square">
                                                <img src="<?php echo htmlspecialchars($p['photo_url'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110 group-hover:brightness-110" alt="Zdjęcie z galerii">
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
            <?php 
                        endif;
                    endif;
                endforeach; 

                if (!$has_photos) {
                    echo "<p class='text-center text-zinc-500 italic py-12'>".$ui[$current_lang]['empty']."</p>";
                }
            } // end if main_trip
            ?>

        <?php elseif ($view_mode === 'album'): ?>
            <!-- ========================================== -->
            <!-- WIDOK 3: POJEDYNCZY ALBUM (Gdy ktoś wchodzi prosto z przycisku we wpisie) -->
            <!-- ========================================== -->
            <?php 
                $stmt = $pdo->prepare("SELECT * FROM albums WHERE id = ?");
                $stmt->execute([$album_id]);
                $album = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($album) {
                    $stmt_photos = $pdo->prepare("SELECT * FROM album_photos WHERE album_id = ? AND is_published = 1 ORDER BY id ASC");
                    $stmt_photos->execute([$album_id]);
                    $photos = $stmt_photos->fetchAll(PDO::FETCH_ASSOC);
                }
            ?>
            <a href="gallery.php?lang=<?php echo htmlspecialchars($current_lang, ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center mb-8 px-4 py-2 bg-white border border-zinc-300 hover:bg-zinc-100 text-zinc-800 font-bold rounded shadow-sm transition">
                <?php echo htmlspecialchars($ui[$current_lang]['back_gallery'], ENT_QUOTES, 'UTF-8'); ?>
            </a>

            <div class="mb-12 text-center">
                <h1 class="text-4xl md:text-5xl font-bold playfair mb-4"><?php echo htmlspecialchars(get_translated($album, 'title', $current_lang), ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>

            <?php if (empty($photos)): ?>
                <p class="text-center text-zinc-500 italic py-12"><?php echo htmlspecialchars($ui[$current_lang]['empty'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php else: ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    <?php foreach($photos as $p): ?>
                        <div class="bg-white rounded-xl overflow-hidden shadow-sm border border-zinc-200 group">
                            <a href="<?php echo htmlspecialchars($p['photo_url'], ENT_QUOTES, 'UTF-8'); ?>" data-gallery="single-album" class="glightbox block overflow-hidden aspect-square">
                                <img src="<?php echo htmlspecialchars($p['photo_url'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110 group-hover:brightness-110" alt="Zdjęcie z galerii">
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- ========================================== -->
            <!-- WIDOK 1: STRONA GŁÓWNA GALERII (Kafelki z Głównymi Wyprawami) -->
            <!-- ========================================== -->
            <?php 
                // Pobieramy wszystkie główne wpisy (wyprawy)
                $stmt_main = $pdo->query("SELECT * FROM posts WHERE parent_id = 0 ORDER BY id DESC");
                $main_trips = $stmt_main->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <div class="mb-12 text-center">
                <h1 class="text-4xl md:text-5xl font-bold playfair mb-4"><?php echo htmlspecialchars($ui[$current_lang]['gallery_title'], ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>

            <?php if (empty($main_trips)): ?>
                <p class="text-center text-zinc-500 italic py-12"><?php echo htmlspecialchars($ui[$current_lang]['no_albums'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8">
                    <?php foreach($main_trips as $trip): ?>
                        <?php 
                            // Okładka to cover_image z głównego wpisu lub fallback
                            $cover_url = !empty($trip['cover_image']) ? $trip['cover_image'] : get_travel_fallback_photo($trip['id'], $travel_fallback_photos);
                        ?>
                        <div class="bg-white rounded-xl overflow-hidden shadow-md border border-zinc-200 flex flex-col justify-between group">
                            
                            <a href="gallery.php?trip_id=<?php echo (int)$trip['id']; ?>&lang=<?php echo htmlspecialchars($current_lang, ENT_QUOTES, 'UTF-8'); ?>" class="block relative h-56 overflow-hidden">
                                <img src="<?php echo htmlspecialchars($cover_url, ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105" alt="Okładka wyjazdu">
                                <div class="absolute inset-0 bg-black/20 group-hover:bg-transparent transition-colors duration-300"></div>
                            </a>
                            
                            <div class="p-6 flex-grow text-center">
                                <h3 class="text-2xl font-bold playfair uppercase tracking-widest text-amber-600">
                                    <?php echo htmlspecialchars(get_translated($trip, 'title', $current_lang), ENT_QUOTES, 'UTF-8'); ?>
                                </h3>
                                <p class="text-sm font-bold text-zinc-500 mt-2 uppercase tracking-wider">📍 <?php echo htmlspecialchars(get_translated($trip, 'location', $current_lang), ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            
                            <div class="p-6 pt-0">
                                <a href="gallery.php?trip_id=<?php echo (int)$trip['id']; ?>&lang=<?php echo htmlspecialchars($current_lang, ENT_QUOTES, 'UTF-8'); ?>" class="inline-block w-full text-center border border-amber-500 px-4 py-3 bg-amber-50 hover:bg-amber-500 hover:text-white transition-colors font-bold uppercase text-sm rounded text-amber-600">
                                    <?php echo htmlspecialchars($ui[$current_lang]['view_dest'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <?php if (file_exists('footer.php')) include 'footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/gh/mcstudios/glightbox/dist/js/glightbox.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const lightbox = GLightbox({
                selector: '.glightbox',
                touchNavigation: true,
                loop: true,
                openEffect: 'zoom',
                closeEffect: 'fade'
            });
        });
    </script>
</body>
</html>

