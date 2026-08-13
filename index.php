<?php
define('APP_ACCESS', true);
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 0);
require_once 'db.php';

// Ładowanie słownika z tłumaczeniami interfejsu
if (file_exists('lang.php')) {
    require_once 'lang.php';
}

// --- SYSTEM WIELOJĘZYCZNOŚCI Z AUTOMATYCZNYM WYKRYWANIEM ---
$allowed_langs = ['pl', 'en', 'it', 'es', 'de'];

if (isset($_GET['lang']) && in_array($_GET['lang'], $allowed_langs)) {
    // 1. Użytkownik ręcznie kliknął flagę (wymuszenie języka)
    $_SESSION['lang'] = $_GET['lang'];
    $current_lang = $_GET['lang'];
} elseif (isset($_SESSION['lang']) && in_array($_SESSION['lang'], $allowed_langs)) {
    // 2. Użytkownik ma już przypisany język w trwającej sesji
    $current_lang = $_SESSION['lang'];
} else {
    // 3. Pierwsze wejście na stronę - AUTOMATYCZNE WYKRYWANIE z przeglądarki
    $current_lang = 'pl'; // Język domyślny, gdyby coś poszło nie tak
    
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        // Wyciągamy pierwsze 2 litery z języka przeglądarki (np. "de-DE,de;..." -> "de")
        $browser_lang = strtolower(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2));
        
        // Jeśli język przeglądarki jest na naszej liście, ustawiamy go
        if (in_array($browser_lang, $allowed_langs)) {
            $current_lang = $browser_lang;
        }
    }
    // Zapisujemy wykryty język w pamięci, żeby działał na podstronach
    $_SESSION['lang'] = $current_lang;
}

// Funkcja wyciągająca właściwe tłumaczenie
if (!function_exists('get_translated')) {
    function get_translated($item, $field, $lang) {
        if ($lang === 'pl') return $item[$field];
        $lang_field = $field . '_' . $lang;
        return (!empty($item[$lang_field])) ? $item[$lang_field] : $item[$field];
    }
}

// Komunikaty formularza newslettera (tłumaczone, bo $ui jest definiowane niżej)
$newsletter_ui = [
    'pl' => ['ok' => 'Dziękuję! Twój e-mail został zapisany.', 'err' => 'Wystąpił problem techniczny. Spróbuj ponownie później.', 'invalid' => 'Podaj poprawny adres e-mail.'],
    'en' => ['ok' => 'Thank you! Your e-mail has been saved.', 'err' => 'A technical problem occurred. Please try again later.', 'invalid' => 'Please enter a valid e-mail address.'],
    'it' => ['ok' => 'Grazie! La tua email è stata salvata.', 'err' => 'Si è verificato un problema tecnico. Riprova più tardi.', 'invalid' => 'Inserisci un indirizzo email valido.'],
    'es' => ['ok' => '¡Gracias! Tu correo ha sido guardado.', 'err' => 'Se produjo un problema técnico. Inténtalo de nuevo más tarde.', 'invalid' => 'Introduce una dirección de correo válida.'],
    'de' => ['ok' => 'Danke! Ihre E-Mail wurde gespeichert.', 'err' => 'Es ist ein technisches Problem aufgetreten. Bitte versuchen Sie es später erneut.', 'invalid' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.']
];
$nl_t = $newsletter_ui[$current_lang] ?? $newsletter_ui['pl'];

// --- OBSŁUGA ZAPISU DO NEWSLETTERA (MAILERLITE) ---
$newsletter_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe_email'])) {
    $email = filter_var($_POST['subscribe_email'], FILTER_SANITIZE_EMAIL);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $post_data = json_encode([
            'email' => $email,
            'groups' => ['195318408642823817']
        ]);
        
        $ch = curl_init('https://connect.mailerlite.com/api/subscribers');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . MAILERLITE_TOKEN
        ]);
        
        // Zabezpieczenie przed błędem SSL na niektórych hostingach
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpcode == 200 || $httpcode == 201) {
            $newsletter_msg = '<p class="text-green-600 font-bold mt-4">' . htmlspecialchars($nl_t['ok']) . '</p>';
        } else {
            $newsletter_msg = '<p class="text-red-600 font-bold mt-4">' . htmlspecialchars($nl_t['err']) . '</p>';
        }
    } else {
        $newsletter_msg = '<p class="text-red-600 font-bold mt-4">' . htmlspecialchars($nl_t['invalid']) . '</p>';
    }
}
// ---------------------------------

// TŁUMACZENIA ZWROTÓW NA STRONIE GŁÓWNEJ
$ui = [
    'pl' => [
        'hero_subtitle' => 'Wyprawy, zdjęcia, pasja.',
        'latest_posts' => 'Najnowsze wyprawy',
        'read_more' => 'Czytaj relację',
        'no_posts' => 'Brak wpisów do wyświetlenia. Wróć tu wkrótce!',
        'notif_title' => 'Nie przegap kolejnych wypraw!',
        'notif_desc' => 'Wybierz swój ulubiony kanał komunikacji i bądź na bieżąco z nowymi relacjami.',
        'wa_channel' => '📱 Kanał WhatsApp',
        'push_btn' => '🔔 Powiadomienia Push',
        'or_email' => 'LUB PODAJ E-MAIL',
        'email_placeholder' => 'Twój adres e-mail',
        'subscribe_btn' => 'Zapisz się',
        'no_spam' => 'Zero spamu. Zawsze możesz się wypisać jednym kliknięciem.'
    ],
    'en' => [
        'hero_subtitle' => 'Expeditions, photos, passion.',
        'latest_posts' => 'Latest Expeditions',
        'read_more' => 'Read Report',
        'no_posts' => 'No posts to display. Check back soon!',
        'notif_title' => 'Don\'t miss the next trips!',
        'notif_desc' => 'Choose your favorite channel and stay up to date with new reports.',
        'wa_channel' => '📱 WhatsApp Channel',
        'push_btn' => '🔔 Push Notifications',
        'or_email' => 'OR ENTER YOUR EMAIL',
        'email_placeholder' => 'Your email address',
        'subscribe_btn' => 'Subscribe',
        'no_spam' => 'No spam. You can unsubscribe anytime with one click.'
    ],
    'it' => [
        'hero_subtitle' => 'Spedizioni, foto, passione.',
        'latest_posts' => 'Ultime Spedizioni',
        'read_more' => 'Leggi il resoconto',
        'no_posts' => 'Nessun post da visualizzare. Torna presto!',
        'notif_title' => 'Non perdere i prossimi viaggi!',
        'notif_desc' => 'Scegli il tuo canale preferito e resta aggiornato sui nuovi reportage.',
        'wa_channel' => '📱 Canale WhatsApp',
        'push_btn' => '🔔 Notifiche Push',
        'or_email' => 'OPPURE INSERISCI LA TUA EMAIL',
        'email_placeholder' => 'Il tuo indirizzo email',
        'subscribe_btn' => 'Iscriviti',
        'no_spam' => 'Zero spam. Puoi annullare l\'iscrizione in qualsiasi momento con un clic.'
    ],
    'es' => [
        'hero_subtitle' => 'Expediciones, fotos, pasión.',
        'latest_posts' => 'Últimas Expediciones',
        'read_more' => 'Leer informe',
        'no_posts' => 'No hay posts para mostrar. ¡Vuelve pronto!',
        'notif_title' => '¡No te pierdas los próximos viajes!',
        'notif_desc' => 'Elige tu canal de comunicación favorito y mantente al día con los nuevos reportajes.',
        'wa_channel' => '📱 Canal de WhatsApp',
        'push_btn' => '🔔 Notificaciones Push',
        'or_email' => 'O INTRODUCE TU EMAIL',
        'email_placeholder' => 'Tu dirección de correo',
        'subscribe_btn' => 'Suscribirse',
        'no_spam' => 'Cero spam. Puedes darte de baja en cualquier momento con un clic.'
    ],
    'de' => [
        'hero_subtitle' => 'Expeditionen, Fotos, Leidenschaft.',
        'latest_posts' => 'Neueste Expeditionen',
        'read_more' => 'Bericht lesen',
        'no_posts' => 'Keine Beiträge anzuzeigen. Komm bald wieder!',
        'notif_title' => 'Verpassen Sie keine neuen Reisen!',
        'notif_desc' => 'Wählen Sie Ihren bevorzugten Kanal und bleiben Sie über neue Berichte auf dem Laufenden.',
        'wa_channel' => '📱 WhatsApp-Kanal',
        'push_btn' => '🔔 Push-Benachrichtigungen',
        'or_email' => 'ODER E-MAIL ANGEBEN',
        'email_placeholder' => 'Ihre E-Mail-Adresse',
        'subscribe_btn' => 'Abonnieren',
        'no_spam' => 'Kein Spam. Sie können sich jederzeit mit einem Klick abmelden.'
    ]
];

try {
    // ZMIANA: Pobieramy z bazy tylko główne wpisy, które mają flagę is_published = 1
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE parent_id = 0 AND is_published = 1 ORDER BY id DESC");
    $stmt->execute();
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Błąd pobierania wpisów (index.php): " . $e->getMessage());
    $posts = [];
}

$travel_fallback_photos = [
    'https://images.unsplash.com/photo-1503220317375-aaad61436b1b?w=800',
    'https://images.unsplash.com/photo-1476514525535-07fb3b4ae5f1?w=800',
    'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=800',
    'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?w=800',
];

function get_travel_fallback_photo($post_id, $photos) {
    $index = ((int)$post_id) % count($photos);
    return $photos[$index];
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>TRAVEL24.me | Moje Wyprawy</title>
    <meta name="description" content="Blog podróżniczy TRAVEL24.me — relacje z wypraw, zdjęcia i inspiracje na kolejne podróże.">
    <link rel="canonical" href="https://travel24.me/index.php">

    <link rel="alternate" hreflang="pl" href="https://travel24.me/index.php?lang=pl" />
    <link rel="alternate" hreflang="en" href="https://travel24.me/index.php?lang=en" />
    <link rel="alternate" hreflang="it" href="https://travel24.me/index.php?lang=it" />
    <link rel="alternate" hreflang="es" href="https://travel24.me/index.php?lang=es" />
    <link rel="alternate" hreflang="de" href="https://travel24.me/index.php?lang=de" />
    <link rel="alternate" hreflang="x-default" href="https://travel24.me/index.php" />

    <meta property="og:type" content="website">
    <meta property="og:title" content="TRAVEL24.me | Moje Wyprawy">
    <meta property="og:description" content="Blog podróżniczy TRAVEL24.me — relacje z wypraw, zdjęcia i inspiracje na kolejne podróże.">
    <meta property="og:url" content="https://travel24.me/index.php">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="/fonts/fonts.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; color: #18181b; font-family: 'Montserrat', sans-serif; }
        .playfair { font-family: 'Playfair Display', serif; }
    </style>
</head>
<body class="flex flex-col min-h-screen">
    <?php if (file_exists('header.php')) include 'header.php'; ?>

    <header class="relative w-full h-[50vh] md:h-[70vh] flex items-center justify-center mt-16 overflow-hidden">
        <div class="absolute inset-0 bg-black/30 z-10"></div>
        <img src="https://images.unsplash.com/photo-1503220317375-aaad61436b1b?w=1600" class="absolute inset-0 w-full h-full object-cover">
        <div class="relative z-20 text-center px-4">
            <h1 class="playfair text-4xl md:text-8xl font-bold text-white drop-shadow-lg">TRAVEL24.me</h1>
            <p class="mt-4 text-white font-semibold uppercase tracking-widest"><?php echo $ui[$current_lang]['hero_subtitle']; ?></p>
        </div>
    </header>
    
    <main class="flex-grow max-w-6xl mx-auto px-4 py-16 w-full">
        <h2 class="text-3xl font-bold mb-12 playfair border-l-4 border-amber-500 pl-4"><?php echo $ui[$current_lang]['latest_posts']; ?></h2>

        <?php if (empty($posts)): ?>
            <p class="text-zinc-500"><?php echo $ui[$current_lang]['no_posts']; ?></p>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php foreach($posts as $post): ?>
                <?php
                    $cover = !empty($post['cover_image'])
                        ? $post['cover_image']
                        : get_travel_fallback_photo($post['id'], $travel_fallback_photos);
                ?>
                <div class="bg-white border border-zinc-200 rounded-xl overflow-hidden shadow-md">
                    <img src="<?php echo htmlspecialchars($cover); ?>" class="w-full h-48 object-cover" loading="lazy" alt="<?php echo htmlspecialchars(get_translated($post, 'title', $current_lang)); ?>">
                    <div class="p-6">
                        <div class="text-amber-600 text-xs font-bold uppercase mb-2">📍 <?php echo htmlspecialchars(get_translated($post, 'location', $current_lang)); ?></div>
                        <h3 class="text-xl font-bold mb-4 playfair"><?php echo htmlspecialchars(get_translated($post, 'title', $current_lang)); ?></h3>
                        <a href="post.php?id=<?php echo (int)$post['id']; ?>&lang=<?php echo $current_lang; ?>" class="inline-block border border-zinc-300 px-4 py-2 hover:bg-amber-500 hover:text-white transition-colors font-bold uppercase text-sm"><?php echo $ui[$current_lang]['read_more']; ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- KOMPLETNY PANEL POWIADOMIEŃ I NEWSLETTERA -->
        <div class="mt-16 mb-8 text-center bg-white p-8 rounded-xl border border-zinc-200 shadow-sm max-w-3xl mx-auto" id="newsletter">
            <h3 class="text-2xl font-bold playfair mb-2"><?php echo $ui[$current_lang]['notif_title']; ?></h3>
            <p class="text-zinc-600 mb-6"><?php echo $ui[$current_lang]['notif_desc']; ?></p>

            <div class="flex flex-wrap justify-center gap-4 mb-8">
                <!-- WhatsApp -->
                <a href="https://whatsapp.com/channel/0029VbCoWY2CsU9JJ4x2Ki27" target="_blank" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-green-500 hover:bg-green-600 text-white font-bold rounded shadow-md transition-colors text-sm">
                    <?php echo $ui[$current_lang]['wa_channel']; ?>
                </a>
                <!-- Powiadomienia Push OneSignal -->
                <button id="custom-push-button" type="button" class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-zinc-800 hover:bg-zinc-700 text-white font-bold rounded shadow-md transition-colors text-sm">
                    <?php echo $ui[$current_lang]['push_btn']; ?>
                </button>
            </div>

            <div class="relative flex py-5 items-center">
                <div class="flex-grow border-t border-zinc-200"></div>
                <span class="flex-shrink-0 mx-4 text-zinc-400 text-sm font-semibold uppercase"><?php echo $ui[$current_lang]['or_email']; ?></span>
                <div class="flex-grow border-t border-zinc-200"></div>
            </div>

            <!-- Formularz Newslettera (łączy się z MailerLite) -->
            <form method="POST" action="index.php#newsletter" class="flex flex-col sm:flex-row justify-center items-center gap-3 max-w-md mx-auto mt-4">
                <input type="email" name="subscribe_email" placeholder="<?php echo htmlspecialchars($ui[$current_lang]['email_placeholder']); ?>" required class="w-full sm:w-auto flex-grow px-4 py-3 border border-zinc-300 rounded focus:outline-none focus:border-amber-500 bg-zinc-50">
                <button type="submit" class="w-full sm:w-auto px-8 py-3 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded shadow-md transition-colors uppercase text-sm tracking-wider">
                    <?php echo $ui[$current_lang]['subscribe_btn']; ?>
                </button>
            </form>

            <!-- Wyświetlanie komunikatu o powodzeniu/błędzie zapisu -->
            <?php echo $newsletter_msg; ?>

            <p class="text-xs text-zinc-400 mt-6"><?php echo $ui[$current_lang]['no_spam']; ?></p>
        </div>

    </main>
    
    <?php if (file_exists('footer.php')) include 'footer.php'; ?>

    <!-- SKRYPT OBSŁUGUJĄCY PRZYCISK POWIADOMIEŃ PUSH -->
    <script>
        const pushButton = document.getElementById('custom-push-button');
        if(pushButton) {
            pushButton.addEventListener('click', function() {
                if (typeof OneSignalDeferred !== 'undefined') {
                    OneSignalDeferred.push(function(OneSignal) {
                        OneSignal.Notifications.requestPermission();
                    });
                }
            });
        }
    </script>
</body>
</html>