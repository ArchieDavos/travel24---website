<?php
define('APP_ACCESS', true);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 0);
require_once 'db.php';

// Ładowanie słownika z tłumaczeniami interfejsu
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

if (!function_exists('get_translated')) {
    function get_translated($item, $field, $lang) {
        if ($lang === 'pl') return $item[$field];
        $lang_field = $field . '_' . $lang;
        return (!empty($item[$lang_field])) ? $item[$lang_field] : $item[$field];
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    $stmt = $pdo->prepare("SELECT * FROM albums WHERE id = ?");
    $stmt->execute([$id]);
    $album = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $album = false;
}

if (!$album) { header("Location: gallery.php?lang=" . $current_lang); exit; }

try {
    $stmt_photos = $pdo->prepare("SELECT * FROM album_photos WHERE album_id = ? AND is_published = 1 ORDER BY id DESC");
    $stmt_photos->execute([$id]);
    $photos = $stmt_photos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $photos = [];
}

// TŁUMACZENIA ZWROTÓW DLA ALBUMU
$ui = [
    'pl' => [ 'no_photos' => 'Brak zdjęć w tym albumie.', 'back' => '🔙 Wróć do galerii', 'desc_prefix' => 'Zdjęcia z albumu' ],
    'en' => [ 'no_photos' => 'No photos in this album.', 'back' => '🔙 Back to gallery', 'desc_prefix' => 'Photos from album' ],
    'it' => [ 'no_photos' => 'Nessuna foto in questo album.', 'back' => '🔙 Torna alla galleria', 'desc_prefix' => 'Foto dall\'album' ],
    'es' => [ 'no_photos' => 'No hay fotos en este álbum.', 'back' => '🔙 Volver a la galería', 'desc_prefix' => 'Fotos del álbum' ],
    'de' => [ 'no_photos' => 'Keine Fotos in diesem Album.', 'back' => '🔙 Zurück zur Galerie', 'desc_prefix' => 'Fotos aus dem Album' ]
];

// Wyciągamy wielojęzyczny tytuł i opis
$album_title = htmlspecialchars(get_translated($album, 'title', $current_lang));
$raw_desc = get_translated($album, 'description', $current_lang);

$album_url = 'https://travel24.me/album.php?id=' . (int)$album['id'];
$album_description = !empty($raw_desc)
    ? mb_substr(trim(strip_tags($raw_desc)), 0, 155)
    : $ui[$current_lang]['desc_prefix'] . ' ' . $album_title . ' (' . htmlspecialchars($album['destination']) . ') - TRAVEL24.me.';
$album_cover = !empty($photos) ? $photos[0]['photo_url'] : 'https://travel24.me/hero-travel24.jpg';
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $album_title; ?> | TRAVEL24.me</title>
    <meta name="description" content="<?php echo htmlspecialchars($album_description); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($album_url); ?>">

    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo $album_title; ?> | TRAVEL24.me">
    <meta property="og:description" content="<?php echo htmlspecialchars($album_description); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($album_cover); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($album_url); ?>">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="/fonts/fonts.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: 'Montserrat', sans-serif; }
        .album-photo-grid img { cursor: zoom-in; transition: opacity 0.2s; }
        .album-photo-grid img:hover { opacity: 0.9; }
        #albumLightboxOverlay { display: none; position: fixed; inset: 0; background: rgba(9, 9, 11, 0.94); z-index: 9999; align-items: center; justify-content: center; flex-direction: column; }
        #albumLightboxOverlay.active { display: flex; }
        #albumLightboxOverlay img { max-width: 90vw; max-height: 85vh; object-fit: contain; border-radius: 0.75rem; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .album-lightbox-close { position: absolute; top: 20px; right: 30px; color: #fff; font-size: 40px; line-height: 1; cursor: pointer; background: none; border: none; font-weight: 300; }
        .album-lightbox-close:hover { color: #f59e0b; }
        .album-lightbox-arrow { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.12); color: #fff; border: none; font-size: 26px; width: 54px; height: 54px; border-radius: 9999px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.2s; }
        .album-lightbox-arrow:hover { background: #f59e0b; color: #18181b; }
        .album-lightbox-prev { left: 20px; }
        .album-lightbox-next { right: 20px; }
        .album-lightbox-counter { color: rgba(255,255,255,0.7); margin-top: 14px; font-size: 13px; letter-spacing: 0.05em; }
    </style>
</head>
<body class="flex flex-col min-h-screen">
    <?php if (file_exists('header.php')) include 'header.php'; ?>
    <header class="relative w-full h-[45vh] flex items-center justify-center mt-16 overflow-hidden">
        <img src="https://images.unsplash.com/photo-1503220317375-aaad61436b1b?w=1600" class="absolute inset-0 w-full h-full object-cover">
        <div class="absolute inset-0 bg-black/40"></div>
        <div class="relative z-10 text-center px-4 text-white">
            <h1 class="text-3xl md:text-6xl font-bold"><?php echo $album_title; ?></h1>
        </div>
    </header>
    <main class="flex-grow max-w-7xl mx-auto px-4 py-8">
        
        <a href="gallery.php?lang=<?php echo $current_lang; ?>" class="inline-flex items-center mb-8 px-4 py-2 bg-white border border-zinc-300 hover:bg-zinc-100 text-zinc-800 font-bold rounded shadow-sm transition">
            <?php echo $ui[$current_lang]['back']; ?>
        </a>

        <?php if (empty($photos)): ?>
            <p class="text-zinc-500 text-center"><?php echo $ui[$current_lang]['no_photos']; ?></p>
        <?php else: ?>
        <div class="album-photo-grid grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
            <?php foreach($photos as $photo): ?>
                <div class="rounded-xl overflow-hidden shadow-md aspect-square">
                    <img src="<?php echo htmlspecialchars($photo['photo_url']); ?>" class="w-full h-full object-cover" loading="lazy" alt="<?php echo $album_title; ?>">
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <?php if (file_exists('footer.php')) include 'footer.php'; ?>

    <div id="albumLightboxOverlay">
        <button class="album-lightbox-close" aria-label="Zamknij">&times;</button>
        <button class="album-lightbox-arrow album-lightbox-prev" aria-label="Poprzednie">&#10094;</button>
        <img id="albumLightboxImg" src="" alt="">
        <button class="album-lightbox-arrow album-lightbox-next" aria-label="Następne">&#10095;</button>
        <div class="album-lightbox-counter" id="albumLightboxCounter"></div>
    </div>

    <script>
        (function () {
            const overlay = document.getElementById('albumLightboxOverlay');
            const overlayImg = document.getElementById('albumLightboxImg');
            const counter = document.getElementById('albumLightboxCounter');
            const closeBtn = overlay.querySelector('.album-lightbox-close');
            const prevBtn = overlay.querySelector('.album-lightbox-prev');
            const nextBtn = overlay.querySelector('.album-lightbox-next');

            let images = [];
            let currentIndex = 0;

            function collectImages() {
                images = Array.from(document.querySelectorAll('.album-photo-grid img'));
            }

            function openLightbox(index) {
                collectImages();
                if (images.length === 0) return;
                currentIndex = index;
                show();
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function closeLightbox() {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }

            function show() {
                overlayImg.src = images[currentIndex].src;
                counter.textContent = (currentIndex + 1) + ' / ' + images.length;
                const multi = images.length > 1;
                prevBtn.style.display = multi ? 'flex' : 'none';
                nextBtn.style.display = multi ? 'flex' : 'none';
            }

            function next() { currentIndex = (currentIndex + 1) % images.length; show(); }
            function prev() { currentIndex = (currentIndex - 1 + images.length) % images.length; show(); }

            document.addEventListener('click', function (e) {
                if (e.target.matches('.album-photo-grid img')) {
                    collectImages();
                    const idx = images.indexOf(e.target);
                    openLightbox(idx >= 0 ? idx : 0);
                }
            });

            closeBtn.addEventListener('click', closeLightbox);
            nextBtn.addEventListener('click', next);
            prevBtn.addEventListener('click', prev);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) closeLightbox(); });

            document.addEventListener('keydown', function (e) {
                if (!overlay.classList.contains('active')) return;
                if (e.key === 'Escape') closeLightbox();
                if (e.key === 'ArrowRight') next();
                if (e.key === 'ArrowLeft') prev();
            });
        })();
    </script>
</body>
</html>