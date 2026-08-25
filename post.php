<?php
define('APP_ACCESS', true);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 0);
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

$post_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$post_id) { header("Location: index.php"); exit; }

$stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
$stmt->execute([$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) { header("Location: index.php"); exit; }

try {
    $pdo->prepare("UPDATE posts SET views = views + 1, real_views = COALESCE(real_views, 0) + 1 WHERE id = ?")->execute([$post_id]);
} catch (PDOException $e) {
    $pdo->prepare("UPDATE posts SET views = views + 1 WHERE id = ?")->execute([$post_id]);
}

$sub_posts = [];
if ($post['parent_id'] == 0) {
    $stmt_sub = $pdo->prepare("SELECT * FROM posts WHERE parent_id = ? AND is_published = 1 ORDER BY id ASC");
    $stmt_sub->execute([$post_id]);
} else {
    $stmt_sub = $pdo->prepare("SELECT * FROM posts WHERE parent_id = ? AND is_published = 1 ORDER BY id ASC");
    $stmt_sub->execute([$post['parent_id']]);
}
$sub_posts = $stmt_sub->fetchAll(PDO::FETCH_ASSOC);

// Plakietka "Nowa relacja": wg daty publikacji etapu (nie zależy od przeglądarki/urządzenia czytelnika)
$new_stage_threshold_days = 3;
$new_stage_cutoff = time() - ($new_stage_threshold_days * 86400);

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

$cover = !empty($post['cover_image']) ? $post['cover_image'] : get_travel_fallback_photo($post['id'], $travel_fallback_photos);
$content = get_translated($post, 'content', $current_lang);

// --- OBLICZANIE CZASU CZYTANIA ---
$word_count = str_word_count(strip_tags($content));
$read_time_minutes = ceil($word_count / 200); 
if ($read_time_minutes < 1) $read_time_minutes = 1;

// --- SZUKANIE KOLEJNEGO ETAPU (DO BINGE-READINGU) ---
$next_stage = null;
if (!empty($sub_posts)) {
    $current_index = -1;
    foreach ($sub_posts as $index => $sub) {
        if ($sub['id'] == $post_id) {
            $current_index = $index;
            break;
        }
    }
    if ($current_index !== -1 && isset($sub_posts[$current_index + 1])) {
        $next_stage = $sub_posts[$current_index + 1];
    }
}

$ui = [
    'pl' => ['back_trip' => '🔙 Wróć do głównej wyprawy', 'back_home' => '🔙 Wróć do strony głównej', 'stages' => 'Kolejne etapy tej wyprawy', 'stages_desc' => 'To nie koniec — zobacz, co było dalej', 'gallery' => 'ZOBACZ PEŁNĄ GALERIĘ ZDJĘĆ Z TEGO ETAPU 📸', 'empty' => 'Treść w tym języku wkrótce się pojawi...', 'notif_title' => 'Nie przegap kolejnych wypraw!', 'notif_desc' => 'Wybierz swój kanał i bądź na bieżąco z nowymi relacjami.', 'notif_wa' => 'Kanał WhatsApp', 'notif_email' => 'E-mail Newsletter', 'notif_push' => 'Powiadomienia', 'notif_ph' => 'Twój adres e-mail', 'notif_btn' => 'Zapisz się', 'notif_spam' => 'Zero spamu. Zawsze możesz się wypisać jednym kliknięciem.', 'scroll_stages' => '👇 Zobacz etapy wyjazdu', 'read_time' => 'Czas czytania:', 'zen_on' => '👁️ Tryb kinowy', 'zen_off' => '☀️ Wyłącz tryb', 'next_in' => 'Kolejny etap za', 'cancel' => 'Anuluj', 'read_now' => 'Czytaj teraz', 'sidebar_title' => 'Bądź na bieżąco 🌎', 'sidebar_desc' => 'Powiadomienia o nowych wyjazdach:', 'new_stage' => 'Nowa relacja', 'cover_alt' => 'Okładka wpisu'],
    'en' => ['back_trip' => '🔙 Back to main trip', 'back_home' => '🔙 Back to home page', 'stages' => 'Next stages of this trip', 'stages_desc' => 'This isn\'t the end — see what happened next', 'gallery' => 'SEE FULL PHOTO GALLERY 📸', 'empty' => 'Content in this language will appear soon...', 'notif_title' => 'Don\'t miss next trips!', 'notif_desc' => 'Choose your channel and stay updated.', 'notif_wa' => 'WhatsApp', 'notif_email' => 'E-mail', 'notif_push' => 'Push', 'notif_ph' => 'Your e-mail', 'notif_btn' => 'Subscribe', 'notif_spam' => 'No spam. Unsubscribe anytime.', 'scroll_stages' => '👇 See trip stages', 'read_time' => 'Read time:', 'zen_on' => '👁️ Cinema mode', 'zen_off' => '☀️ Normal mode', 'next_in' => 'Next stage in', 'cancel' => 'Cancel', 'read_now' => 'Read now', 'sidebar_title' => 'Stay updated 🌎', 'sidebar_desc' => 'Notifications about new trips:', 'new_stage' => 'New report', 'cover_alt' => 'Post cover image'],
    'it' => ['back_trip' => '🔙 Torna al viaggio principale', 'back_home' => '🔙 Torna alla home page', 'stages' => 'Le prossime tappe di questo viaggio', 'stages_desc' => 'Non è la fine — scopri cosa è successo dopo', 'gallery' => 'GUARDA LA GALLERIA FOTOGRAFICA 📸', 'empty' => 'I contenuti in questa lingua saranno presto disponibili...', 'notif_title' => 'Non perdere i prossimi viaggi!', 'notif_desc' => 'Scegli il tuo canale.', 'notif_wa' => 'WhatsApp', 'notif_email' => 'E-mail', 'notif_push' => 'Notifiche', 'notif_ph' => 'Tua e-mail', 'notif_btn' => 'Iscriviti', 'notif_spam' => 'Zero spam.', 'scroll_stages' => '👇 Vedi tappe', 'read_time' => 'Tempo di lettura:', 'zen_on' => '👁️ Modalità cinema', 'zen_off' => '☀️ Esci', 'next_in' => 'Prossima tappa in', 'cancel' => 'Annulla', 'read_now' => 'Leggi ora', 'sidebar_title' => 'Resta aggiornato 🌎', 'sidebar_desc' => 'Notifiche sui nuovi viaggi:', 'new_stage' => 'Nuovo reportage', 'cover_alt' => 'Immagine di copertina'],
    'es' => ['back_trip' => '🔙 Volver al viaje', 'back_home' => '🔙 Volver al inicio', 'stages' => 'Las próximas etapas de este viaje', 'stages_desc' => 'Esto no es el final — mira qué pasó después', 'gallery' => 'VER GALERÍA DE FOTOS 📸', 'empty' => 'El contenido aparecerá pronto...', 'notif_title' => '¡No te pierdas los próximos viajes!', 'notif_desc' => 'Elige tu canal.', 'notif_wa' => 'WhatsApp', 'notif_email' => 'Boletín', 'notif_push' => 'Notificaciones', 'notif_ph' => 'Tu correo', 'notif_btn' => 'Suscribirse', 'notif_spam' => 'Cero spam.', 'scroll_stages' => '👇 Ver etapas', 'read_time' => 'Tiempo de lectura:', 'zen_on' => '👁️ Modo cine', 'zen_off' => '☀️ Salir', 'next_in' => 'Siguiente etapa en', 'cancel' => 'Cancelar', 'read_now' => 'Leer ahora', 'sidebar_title' => 'Mantente al día 🌎', 'sidebar_desc' => 'Notificaciones de nuevos viajes:', 'new_stage' => 'Nuevo informe', 'cover_alt' => 'Imagen de portada'],
    'de' => ['back_trip' => '🔙 Zurück zur Hauptreise', 'back_home' => '🔙 Zurück zur Startseite', 'stages' => 'Die nächsten Etappen dieser Reise', 'stages_desc' => 'Das ist noch nicht das Ende — sieh, was als Nächstes kam', 'gallery' => 'FOTOGALERIE ANSEHEN 📸', 'empty' => 'Inhalte in Kürze verfügbar...', 'notif_title' => 'Verpassen Sie keine neuen Reisen!', 'notif_desc' => 'Wählen Sie Ihren Kanal.', 'notif_wa' => 'WhatsApp', 'notif_email' => 'E-Mail', 'notif_push' => 'Benachrichtigungen', 'notif_ph' => 'Ihre E-Mail', 'notif_btn' => 'Abonnieren', 'notif_spam' => 'Kein Spam.', 'scroll_stages' => '👇 Etappen ansehen', 'read_time' => 'Lesezeit:', 'zen_on' => '👁️ Kino-Modus', 'zen_off' => '☀️ Beenden', 'next_in' => 'Nächste Etappe in', 'cancel' => 'Abbrechen', 'read_now' => 'Jetzt lesen', 'sidebar_title' => 'Bleib auf dem Laufenden 🌎', 'sidebar_desc' => 'Benachrichtigungen über neue Reisen:', 'new_stage' => 'Neuer Bericht', 'cover_alt' => 'Titelbild']
];

if ($post['parent_id'] > 0) {
    $back_url = "post.php?id=" . $post['parent_id'] . "&lang=" . $current_lang;
    $back_text = $ui[$current_lang]['back_trip'];
} else {
    $back_url = "index.php?lang=" . $current_lang;
    $back_text = $ui[$current_lang]['back_home'];
}

// --- META TAGI SEO / OPEN GRAPH / TWITTER CARD ---
if (!function_exists('generateMetaDescription')) {
    function generateMetaDescription($content, $maxLength = 155) {
        // Usuń tagi HTML i nadmiarowe białe znaki
        $text = strip_tags($content);
        $text = preg_replace('/\s+/', ' ', trim($text));

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        // Utnij do maxLength, ale nie w połowie słowa
        $truncated = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated . '...';
    }
}

if (!function_exists('getAbsoluteImageUrl')) {
    function getAbsoluteImageUrl($imagePath) {
        if (empty($imagePath)) {
            return 'https://www.travel24.me/uploads/default-og-image.jpg';
        }
        if (strpos($imagePath, 'http') === 0) {
            return $imagePath; // już jest pełny URL (np. Unsplash fallback)
        }
        return 'https://www.travel24.me/' . ltrim($imagePath, '/');
    }
}

// Tytuł i opis budowane z przetłumaczonych pól ($current_lang), nie z surowego $post
$metaTitle       = htmlspecialchars(get_translated($post, 'title', $current_lang)) . ' | TRAVEL24.me';
$metaDescription = htmlspecialchars(generateMetaDescription($content));
// $cover to już wyliczony wcześniej okładka posta (własna albo fallback Unsplash)
$metaImage       = getAbsoluteImageUrl($cover);
$metaUrl         = 'https://www.travel24.me/post.php?id=' . (int)$post['id'] . '&lang=' . $current_lang;
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $metaTitle; ?></title>
    <meta name="description" content="<?php echo $metaDescription; ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($metaUrl); ?>">
    <!-- Open Graph (Facebook, WhatsApp, LinkedIn) -->
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?php echo $metaTitle; ?>">
    <meta property="og:description" content="<?php echo $metaDescription; ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($metaImage); ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="<?php echo htmlspecialchars($metaUrl); ?>">
    <meta property="og:site_name" content="TRAVEL24.me">
    <meta property="og:locale" content="<?php echo $current_lang === 'pl' ? 'pl_PL' : $current_lang; ?>">
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo $metaTitle; ?>">
    <meta name="twitter:description" content="<?php echo $metaDescription; ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($metaImage); ?>">
    <!-- Hreflang — te same posty pod różnymi ?lang= -->
    <link rel="alternate" hreflang="pl" href="https://www.travel24.me/post.php?id=<?php echo (int)$post['id']; ?>&lang=pl">
    <link rel="alternate" hreflang="en" href="https://www.travel24.me/post.php?id=<?php echo (int)$post['id']; ?>&lang=en">
    <link rel="alternate" hreflang="it" href="https://www.travel24.me/post.php?id=<?php echo (int)$post['id']; ?>&lang=it">
    <link rel="alternate" hreflang="es" href="https://www.travel24.me/post.php?id=<?php echo (int)$post['id']; ?>&lang=es">
    <link rel="alternate" hreflang="de" href="https://www.travel24.me/post.php?id=<?php echo (int)$post['id']; ?>&lang=de">
    <link rel="alternate" hreflang="x-default" href="https://www.travel24.me/post.php?id=<?php echo (int)$post['id']; ?>&lang=pl">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="/fonts/fonts.css" rel="stylesheet">
    <style>
        html { scroll-behavior: smooth; }
        body { background-color: #f8fafc; color: #18181b; font-family: 'Montserrat', sans-serif; transition: background-color 0.5s ease; }
        .playfair { font-family: 'Playfair Display', serif; }
        .ql-editor img { max-width: 100%; height: auto; border-radius: 8px; margin: 20px 0; display: block; }
        .ql-editor h2, .ql-editor h3 { font-family: 'Playfair Display', serif; font-weight: bold; margin-top: 1.5em; margin-bottom: 0.5em; }
        .ql-editor p { margin-bottom: 1em; line-height: 1.8; }
        .slide-down { transition: max-height 0.3s ease-out, opacity 0.3s ease-out; overflow: hidden; max-height: 0; opacity: 0; }
        .slide-down.open { max-height: 150px; opacity: 1; margin-top: 1rem; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        @keyframes gentle-pulse {
            0%, 100% { transform: translateX(-50%) scale(1); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
            50% { transform: translateX(-50%) scale(1.04); box-shadow: 0 15px 25px -5px rgba(245, 158, 11, 0.5), 0 8px 10px -6px rgba(245, 158, 11, 0.3); }
        }
        .btn-floating-pulse {
            animation: gentle-pulse 2.5s infinite ease-in-out;
        }
    </style>

    <script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
    <script>
      window.OneSignalDeferred = window.OneSignalDeferred || [];
      OneSignalDeferred.push(async function(OneSignal) {
        await OneSignal.init({
          appId: "4dc4fd0c-4810-4b9e-9d04-22075da68048",
          notifyButton: { enable: false }
        });
      });
    </script>
</head>
<body id="main-body" class="flex flex-col min-h-screen relative">
    
    <div id="scroll-progress" class="fixed top-0 left-0 h-1 bg-amber-500 z-[9999] transition-all duration-75 ease-out" style="width: 0%;"></div>

    <?php if (file_exists('header.php')) include 'header.php'; ?>
    
    <main class="flex-grow max-w-7xl mx-auto px-4 py-8 mt-16 w-full flex flex-col lg:flex-row gap-10">
        
        <article id="main-article" class="lg:w-2/3 w-full transition-all duration-500">
            
            <a href="<?php echo $back_url; ?>" class="inline-flex items-center mb-6 px-4 py-2 bg-white border border-zinc-300 hover:bg-zinc-100 text-zinc-800 font-bold rounded shadow-sm transition">
                <?php echo $back_text; ?>
            </a>
            
            <img src="<?php echo htmlspecialchars($cover); ?>" class="w-full h-[30vh] md:h-[50vh] object-cover rounded-xl shadow-lg mb-8" alt="<?php echo htmlspecialchars($ui[$current_lang]['cover_alt']); ?>">
            <h1 class="text-4xl md:text-5xl font-bold playfair mb-4"><?php echo htmlspecialchars(get_translated($post, 'title', $current_lang)); ?></h1>
            
            <div class="flex flex-wrap items-center gap-4 mb-8">
                <p class="text-amber-600 font-bold uppercase tracking-wider text-sm md:text-base">📍 <?php echo htmlspecialchars(get_translated($post, 'location', $current_lang)); ?></p>
                <div class="flex items-center gap-2 text-zinc-500 text-sm font-bold bg-white px-3 py-1 rounded-full border border-zinc-200 shadow-sm">
                    👁️ <?php echo (int)$post['views'] + 1; ?>
                </div>
                
                <div class="flex items-center gap-2 text-zinc-500 text-sm font-bold bg-white px-3 py-1 rounded-full border border-zinc-200 shadow-sm">
                    ⏱️ <?php echo $ui[$current_lang]['read_time'] . ' ' . $read_time_minutes; ?> min
                </div>

                <button id="zen-mode-btn" class="ml-auto flex items-center gap-2 text-zinc-500 hover:text-amber-600 text-sm font-bold bg-white px-3 py-1 rounded-full border border-zinc-200 shadow-sm transition">
                    <?php echo $ui[$current_lang]['zen_on']; ?>
                </button>
            </div>

            <div class="prose max-w-none text-lg leading-relaxed ql-editor bg-white p-6 md:p-10 rounded-xl shadow-sm border border-zinc-200 transition-all duration-500">
                <?php 
                    if (empty(trim(strip_tags($content)))) {
                        echo "<p class='text-zinc-500 italic'>" . $ui[$current_lang]['empty'] . "</p>";
                    } else {
                        echo $content; 
                    }
                ?>
            </div>

            <?php if (!empty($post['linked_album_id']) && $post['linked_album_id'] > 0): ?>
                <div class="mt-16 text-center">
                    <a href="gallery.php?album_id=<?php echo $post['linked_album_id']; ?>&lang=<?php echo $current_lang; ?>" class="inline-block px-8 py-4 bg-amber-500 hover:bg-amber-600 text-white text-lg md:text-xl font-bold rounded-lg shadow-lg transition transform hover:-translate-y-1">
                        <?php echo $ui[$current_lang]['gallery']; ?>
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!empty($sub_posts)): ?>
            <div id="etapy-wyprawy" class="mt-16 scroll-mt-24">
                <div class="text-center mb-6">
                    <h2 class="text-3xl md:text-4xl font-bold playfair text-zinc-800 mb-2">🧭 <?php echo $ui[$current_lang]['stages']; ?></h2>
                    <p class="text-zinc-500"><?php echo $ui[$current_lang]['stages_desc']; ?></p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" id="stages-container">
                    <?php foreach($sub_posts as $sub):
                        $sub_cover = !empty($sub['cover_image']) ? $sub['cover_image'] : get_travel_fallback_photo($sub['id'], $travel_fallback_photos);
                        $is_active = ($sub['id'] == $post_id);
                        $active_classes = $is_active ? 'ring-4 ring-amber-500 ring-offset-2' : '';
                        $is_new_stage = !$is_active && !empty($sub['created_at']) && strtotime($sub['created_at']) >= $new_stage_cutoff;
                    ?>
                    <a href="post.php?id=<?php echo $sub['id']; ?>&lang=<?php echo $current_lang; ?>" class="stage-link group block relative h-40 md:h-48 rounded-xl overflow-hidden shadow-md transition-transform hover:-translate-y-1 <?php echo $active_classes; ?>" data-stage-id="<?php echo $sub['id']; ?>">

                        <?php if ($is_new_stage): ?>
                        <div class="new-stage-badge absolute top-3 right-3 bg-red-600 text-white text-[10px] md:text-xs font-bold px-3 py-1 rounded-full uppercase btn-floating-pulse shadow-lg z-20">
                            <?php echo $ui[$current_lang]['new_stage']; ?>
                        </div>
                        <?php endif; ?>

                        <img src="<?php echo htmlspecialchars($sub_cover); ?>" class="absolute inset-0 w-full h-full object-cover transition-transform duration-700 group-hover:scale-110" alt="">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>

                        <div class="absolute bottom-0 left-0 p-4 w-full">
                            <h3 class="text-white font-bold text-lg md:text-xl leading-tight shadow-sm playfair"><?php echo htmlspecialchars(get_translated($sub, 'title', $current_lang)); ?></h3>
                            <div class="flex justify-between items-center mt-2">
                                <p class="text-amber-400 text-xs font-bold uppercase tracking-wider shadow-sm">📍 <?php echo htmlspecialchars(get_translated($sub, 'location', $current_lang)); ?></p>
                                <span class="text-white text-xs font-bold bg-black/60 px-2 py-1 rounded-full backdrop-blur-sm shadow-sm">👁️ <?php echo (int)$sub['views']; ?></span>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="mt-16 bg-white border border-zinc-200 rounded-xl p-8 text-center shadow-sm relative overflow-hidden">
                <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-amber-400 to-[#25D366]"></div>
                <h3 class="text-2xl font-bold playfair text-zinc-800 mb-2"><?php echo $ui[$current_lang]['notif_title']; ?></h3>
                <p class="text-zinc-500 mb-8"><?php echo $ui[$current_lang]['notif_desc']; ?></p>
                
                <div class="flex flex-col sm:flex-row justify-center gap-4">
                    <a href="https://whatsapp.com/channel/0029VbCoWY2CsU9JJ4x2Ki27" target="_blank" class="flex items-center justify-center gap-2 px-6 py-3 bg-[#25D366] hover:bg-[#20bd5a] text-white font-bold rounded-lg transition transform hover:-translate-y-1 shadow-sm">
                        📱 <?php echo $ui[$current_lang]['notif_wa']; ?>
                    </a>
                    <button onclick="document.getElementById('emailFormMain').classList.toggle('open')" class="flex items-center justify-center gap-2 px-6 py-3 bg-zinc-800 hover:bg-zinc-700 text-white font-bold rounded-lg transition transform hover:-translate-y-1 shadow-sm">
                        ✉️ <?php echo $ui[$current_lang]['notif_email']; ?>
                    </button>
                    <button id="webPushBtnMain" class="flex items-center justify-center gap-2 px-6 py-3 border-2 border-zinc-300 hover:border-amber-500 hover:text-amber-600 text-zinc-600 font-bold rounded-lg transition transform hover:-translate-y-1">
                        🔔 <?php echo $ui[$current_lang]['notif_push']; ?>
                    </button>
                </div>
                
                <div id="emailFormMain" class="slide-down max-w-md mx-auto">
                    <form id="emailFormMainAction" class="flex gap-2">
                        <input type="email" id="emailInputMain" placeholder="<?php echo $ui[$current_lang]['notif_ph']; ?>" class="flex-1 px-4 py-3 border border-zinc-300 rounded-lg focus:outline-none focus:border-amber-500 bg-zinc-50" required>
                        <button type="submit" class="px-6 py-3 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-lg transition"><?php echo $ui[$current_lang]['notif_btn']; ?></button>
                    </form>
                    <p id="emailStatusMain" class="text-sm font-bold mt-2 hidden"></p>
                    <p class="text-xs text-zinc-400 mt-3"><?php echo $ui[$current_lang]['notif_spam']; ?></p>
                </div>
            </div>

            <div id="bottom-marker" class="mt-16 mb-8 text-center border-t border-zinc-200 pt-8">
                <a href="<?php echo $back_url; ?>" class="inline-block px-8 py-3 border-2 border-zinc-800 hover:bg-zinc-800 hover:text-white text-zinc-800 font-bold uppercase rounded transition">
                    <?php echo $back_text; ?>
                </a>
            </div>
        </article>

        <aside id="main-sidebar" class="lg:w-1/3 w-full relative z-10 transition-opacity duration-300">
            <div class="sticky top-24 flex flex-col gap-6 max-h-[calc(100vh-7rem)] overflow-y-auto no-scrollbar pb-6">
                
                <div class="bg-white p-6 rounded-xl shadow-sm border border-zinc-200">
                    <h3 class="text-xl font-bold playfair text-zinc-800 mb-2"><?php echo $ui[$current_lang]['sidebar_title']; ?></h3>
                    <p class="text-sm text-zinc-500 mb-5"><?php echo $ui[$current_lang]['sidebar_desc']; ?></p>

                    <div class="flex flex-col gap-3">
                        <a href="https://whatsapp.com/channel/0029VbCoWY2CsU9JJ4x2Ki27" target="_blank" class="flex items-center justify-center gap-2 w-full py-2 bg-[#25D366] hover:bg-[#20bd5a] text-white text-sm font-bold rounded-lg transition">
                            📱 <?php echo $ui[$current_lang]['notif_wa']; ?>
                        </a>
                        <button onclick="document.getElementById('emailFormSidebar').classList.toggle('open')" class="flex items-center justify-center gap-2 w-full py-2 bg-zinc-800 hover:bg-zinc-700 text-white text-sm font-bold rounded-lg transition">
                            ✉️ <?php echo $ui[$current_lang]['notif_email']; ?>
                        </button>
                        <button id="webPushBtnSidebar" class="flex items-center justify-center gap-2 w-full py-2 border border-zinc-300 hover:bg-zinc-50 text-zinc-600 text-sm font-bold rounded-lg transition">
                            🔔 <?php echo $ui[$current_lang]['notif_push']; ?>
                        </button>
                    </div>

                    <div id="emailFormSidebar" class="slide-down">
                        <form id="emailFormSidebarAction" class="flex flex-col gap-2">
                            <input type="email" id="emailInputSidebar" placeholder="<?php echo $ui[$current_lang]['notif_ph']; ?>" class="w-full px-3 py-2 border border-zinc-300 rounded-lg text-sm focus:outline-none focus:border-amber-500 bg-zinc-50" required>
                            <button type="submit" class="w-full py-2 bg-amber-500 hover:bg-amber-600 text-white text-sm font-bold rounded-lg transition"><?php echo $ui[$current_lang]['notif_btn']; ?></button>
                        </form>
                        <p id="emailStatusSidebar" class="text-xs font-bold mt-2 hidden"></p>
                    </div>
                </div>

            </div>
        </aside>

    </main>

    <?php if ($next_stage): ?>
    <div id="binge-banner" class="fixed bottom-0 left-0 w-full bg-white border-t-4 border-amber-500 shadow-[0_-20px_40px_rgba(0,0,0,0.15)] p-4 md:p-6 transform translate-y-full transition-transform duration-500 z-[100] flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="text-center md:text-left">
            <p class="text-sm text-zinc-500 font-bold uppercase tracking-wider mb-1">
                <?php echo $ui[$current_lang]['next_in']; ?> <span id="binge-timer" class="text-amber-500 text-xl font-black">5</span>s...
            </p>
            <h3 class="text-xl md:text-2xl playfair font-bold text-zinc-800">
                <?php echo htmlspecialchars(get_translated($next_stage, 'title', $current_lang)); ?>
            </h3>
        </div>
        <div class="flex gap-3 w-full md:w-auto">
            <button id="binge-cancel" class="flex-1 md:flex-none px-6 py-3 border border-zinc-300 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 font-bold rounded transition">
                <?php echo $ui[$current_lang]['cancel']; ?>
            </button>
            <a href="post.php?id=<?php echo $next_stage['id']; ?>&lang=<?php echo $current_lang; ?>" class="flex-1 md:flex-none text-center px-8 py-3 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded shadow-md transition uppercase tracking-wider">
                <?php echo $ui[$current_lang]['read_now']; ?>
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($sub_posts)): ?>
        <a href="#etapy-wyprawy" id="scroll-stages-btn" class="fixed bottom-24 left-1/2 z-[90] bg-amber-500 hover:bg-amber-600 text-white px-6 py-3 rounded-full font-bold flex items-center justify-center gap-2 lg:hidden btn-floating-pulse border-2 border-white text-sm whitespace-nowrap transition-colors">
            <?php echo $ui[$current_lang]['scroll_stages']; ?>
        </a>
    <?php endif; ?>

    <?php if (file_exists('footer.php')) include 'footer.php'; ?>
    
    <script>
    // ZNACZNIK NOWEJ RELACJI: serwer pokazuje plakietkę tylko dla etapów opublikowanych
    // w ostatnich $new_stage_threshold_days dniach (patrz PHP wyżej w tym pliku).
    // Dodatkowo w TEJ przeglądarce ukrywamy plakietkę dla etapów, które czytelnik już otworzył,
    // żeby po przeczytaniu nie pojawiała się ponownie przy kolejnych wizytach na tym samym urządzeniu/przeglądarce.
    document.addEventListener("DOMContentLoaded", function() {
        const currentPostId = <?php echo $post_id; ?>;

        let readStages = JSON.parse(localStorage.getItem('travel24_read_stages')) || [];

        if (!readStages.includes(currentPostId)) {
            readStages.push(currentPostId);
            localStorage.setItem('travel24_read_stages', JSON.stringify(readStages));
        }

        document.querySelectorAll('.stage-link').forEach(stage => {
            const stageId = parseInt(stage.getAttribute('data-stage-id'));
            if (readStages.includes(stageId)) {
                const badge = stage.querySelector('.new-stage-badge');
                if (badge) badge.classList.add('hidden');
            }
        });
    });

    window.addEventListener('scroll', () => {
        const winScroll = document.body.scrollTop || document.documentElement.scrollTop;
        const height = document.documentElement.scrollHeight - document.documentElement.clientHeight;
        const scrolled = (winScroll / height) * 100;
        document.getElementById('scroll-progress').style.width = scrolled + '%';
    });

    const zenBtn = document.getElementById('zen-mode-btn');
    const mainBody = document.getElementById('main-body');
    const mainArticle = document.getElementById('main-article');
    const mainSidebar = document.getElementById('main-sidebar');
    let isZen = false;

    if (zenBtn) {
        zenBtn.addEventListener('click', () => {
            isZen = !isZen;
            if (isZen) {
                mainSidebar.classList.add('hidden', 'opacity-0');
                mainArticle.classList.remove('lg:w-2/3');
                mainArticle.classList.add('max-w-4xl', 'mx-auto');
                mainBody.classList.remove('bg-[#f8fafc]');
                mainBody.classList.add('bg-zinc-900'); 
                zenBtn.innerHTML = '<?php echo $ui[$current_lang]['zen_off']; ?>';
            } else {
                mainSidebar.classList.remove('hidden', 'opacity-0');
                mainArticle.classList.remove('max-w-4xl', 'mx-auto');
                mainArticle.classList.add('lg:w-2/3');
                mainBody.classList.add('bg-[#f8fafc]');
                mainBody.classList.remove('bg-zinc-900');
                zenBtn.innerHTML = '<?php echo $ui[$current_lang]['zen_on']; ?>';
            }
        });
    }

    const scrollStagesBtn = document.getElementById('scroll-stages-btn');
    if (scrollStagesBtn) {
        scrollStagesBtn.addEventListener('click', function(e) {
            if (isZen) {
                e.preventDefault();
                zenBtn.click(); 
                setTimeout(() => {
                    document.getElementById('etapy-wyprawy').scrollIntoView({behavior: 'smooth'});
                }, 300);
            }
        });
    }

    const bingeBanner = document.getElementById('binge-banner');
    if(bingeBanner) {
        const bingeCancel = document.getElementById('binge-cancel');
        const bingeTimerEl = document.getElementById('binge-timer');
        let bingeTimer = 5;
        let bingeInterval;
        let isBingeCanceled = false;

        const observer = new IntersectionObserver((entries) => {
            if(entries[0].isIntersecting && !isBingeCanceled) {
                bingeBanner.classList.remove('translate-y-full'); 
                if(!bingeInterval) {
                    bingeInterval = setInterval(() => {
                        bingeTimer--;
                        bingeTimerEl.innerText = bingeTimer;
                        if(bingeTimer <= 0) {
                            clearInterval(bingeInterval);
                            window.location.href = bingeBanner.querySelector('a').href; 
                        }
                    }, 1000);
                }
            }
        }, { threshold: 0.1 });

        const bottomMarker = document.getElementById('bottom-marker');
        if(bottomMarker) observer.observe(bottomMarker);

        bingeCancel.addEventListener('click', () => {
            isBingeCanceled = true;
            clearInterval(bingeInterval);
            bingeBanner.classList.add('translate-y-full'); 
        });
    }

    function setupNewsletter(formId, inputId, statusId) {
        const form = document.getElementById(formId);
        if (!form) return;
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const email = document.getElementById(inputId).value;
            const statusEl = document.getElementById(statusId);
            const btn = form.querySelector('button[type="submit"]');
            btn.disabled = true; btn.innerHTML = '⏳...';
            statusEl.classList.remove('hidden', 'text-green-600', 'text-red-600');
            statusEl.classList.add('text-zinc-600'); statusEl.innerText = 'Wysyłanie...';
            const formData = new FormData(); formData.append('email', email);

            fetch('subscribe.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                statusEl.innerText = data.message;
                if(data.success) { statusEl.classList.add('text-green-600'); statusEl.classList.remove('text-zinc-600'); document.getElementById(inputId).value = ''; } 
                else { statusEl.classList.add('text-red-600'); statusEl.classList.remove('text-zinc-600'); }
            })
            .catch(error => { statusEl.innerText = 'Błąd połączenia.'; statusEl.classList.add('text-red-600'); })
            .finally(() => { btn.disabled = false; btn.innerHTML = '<?php echo $ui[$current_lang]['notif_btn']; ?>'; });
        });
    }
    setupNewsletter('emailFormMainAction', 'emailInputMain', 'emailStatusMain');
    setupNewsletter('emailFormSidebarAction', 'emailInputSidebar', 'emailStatusSidebar');
    function setupWebPush(btnId) {
        const btn = document.getElementById(btnId);
        if (!btn) return;
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            window.OneSignalDeferred.push(async function(OneSignal) {
                try { await OneSignal.Slidedown.promptPush(); } 
                catch (err) { if (window.Notification && Notification.permission !== "granted") { Notification.requestPermission(); } }
            });
        });
    }
    setupWebPush('webPushBtnMain');
    setupWebPush('webPushBtnSidebar');
    </script>
</body>
</html>
