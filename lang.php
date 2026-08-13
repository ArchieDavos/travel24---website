<?php
$ui_lang = [
    // Menu nawigacyjne
    'menu_trips' => ['pl' => 'Wyprawy', 'en' => 'Trips', 'it' => 'Viaggi', 'es' => 'Viajes', 'de' => 'Reisen'],
    'menu_gallery' => ['pl' => 'Galeria', 'en' => 'Gallery', 'it' => 'Galleria', 'es' => 'Galería', 'de' => 'Galerie'],
    'about_me' => ['pl' => 'O mnie', 'en' => 'About me', 'it' => 'Su di me', 'es' => 'Sobre mí', 'de' => 'Über mich'],

    // Banner główny
    'hero_subtitle' => ['pl' => 'Wyprawy, zdjęcia, pasja.', 'en' => 'Expeditions, photos, passion.', 'it' => 'Spedizioni, foto, passione.', 'es' => 'Expediciones, fotos, pasión.', 'de' => 'Expeditionen, Fotos, Leidenschaft.'],

    // Galeria zdjęć (NOWE)
    'gallery_title' => ['pl' => 'Galeria Zdjęć', 'en' => 'Photo Gallery', 'it' => 'Galleria Fotografica', 'es' => 'Galería de Fotos', 'de' => 'Fotogalerie'],
    'gallery_subtitle' => ['pl' => 'Świat zatrzymany w kadrach', 'en' => 'The world captured in frames', 'it' => 'Il mondo catturato in fotogrammi', 'es' => 'El mundo capturado en fotogramas', 'de' => 'Die in Bildern festgehaltene Welt'],
    'no_albums' => ['pl' => 'Brak albumów do wyświetlenia. Wróć tu wkrótce!', 'en' => 'No albums to display. Check back soon!', 'it' => 'Nessun album da visualizzare. Torna presto!', 'es' => 'No hay álbumes para mostrar. ¡Vuelve pronto!', 'de' => 'Keine Alben anzuzeigen. Schau bald wieder vorbei!'],

    // index.php
    'latest_posts' => ['pl' => 'Najnowsze wpisy', 'en' => 'Latest posts', 'it' => 'Ultimi post', 'es' => 'Últimos posts', 'de' => 'Neueste Beiträge'],
    'read_more' => ['pl' => 'Czytaj', 'en' => 'Read', 'it' => 'Leggi', 'es' => 'Leer', 'de' => 'Lesen'],
    'no_posts' => ['pl' => 'Brak wpisów do wyświetlenia. Wróć tu wkrótce!', 'en' => 'No posts to display. Check back soon!', 'it' => 'Nessun post da visualizzare. Torna presto!', 'es' => 'No hay posts para mostrar. ¡Vuelve pronto!', 'de' => 'Keine Beiträge anzuzeigen. Schau bald wieder vorbei!'],
    
    // post.php
    'views' => ['pl' => 'Wyświetleń', 'en' => 'Views', 'it' => 'Visualizzazioni', 'es' => 'Vistas', 'de' => 'Aufrufe'],
    'comments' => ['pl' => 'Komentarze', 'en' => 'Comments', 'it' => 'Commenti', 'es' => 'Comentarios', 'de' => 'Kommentare'],
    'leave_comment' => ['pl' => 'Zostaw ślad po sobie', 'en' => 'Leave a comment', 'it' => 'Lascia un commento', 'es' => 'Deja un comentario', 'de' => 'Hinterlasse einen Kommentar'],
    'name' => ['pl' => 'Imię / Nick', 'en' => 'Name / Nickname', 'it' => 'Nome / Nickname', 'es' => 'Nombre / Apodo', 'de' => 'Name / Spitzname'],
    'comment_text' => ['pl' => 'Komentarz', 'en' => 'Comment', 'it' => 'Commento', 'es' => 'Comentario', 'de' => 'Kommentar'],
    'send_comment' => ['pl' => 'Wyślij komentarz', 'en' => 'Send comment', 'it' => 'Invia commento', 'es' => 'Enviar comentario', 'de' => 'Kommentar senden'],
    'gallery' => ['pl' => 'Galeria &rarr;', 'en' => 'Gallery &rarr;', 'it' => 'Galleria &rarr;', 'es' => 'Galería &rarr;', 'de' => 'Galerie &rarr;'],
    'thanks_comment' => ['pl' => 'Dziękujemy! Twój komentarz czeka na akceptację moderatora.', 'en' => 'Thank you! Your comment is awaiting moderation.', 'it' => 'Grazie! Il tuo commento è in attesa di approvazione.', 'es' => '¡Gracias! Tu comentario está pendiente de moderación.', 'de' => 'Danke! Dein Kommentar wartet auf Freigabe.'],
    
    // Stopka i Zakładki
    'all_rights' => ['pl' => 'Wszelkie prawa zastrzeżone.', 'en' => 'All rights reserved.', 'it' => 'Tutti i diritti riservati.', 'es' => 'Todos los derechos reservados.', 'de' => 'Alle Rechte vorbehalten.'],
    'privacy_policy' => ['pl' => 'Polityka prywatności', 'en' => 'Privacy Policy', 'it' => 'Informativa sulla privacy', 'es' => 'Política de privacidad', 'de' => 'Datenschutzerklärung']
];

// Funkcja tłumacząca, z której korzysta cała strona
if (!function_exists('__')) {
    function __($key) {
        global $ui_lang;
        $lang = $_SESSION['lang'] ?? 'pl';
        return $ui_lang[$key][$lang] ?? ($ui_lang[$key]['pl'] ?? $key);
    }
}
?>