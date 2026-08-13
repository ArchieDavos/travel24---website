<?php
// Bezpieczne pobranie aktualnego języka
$curr_l = $_SESSION['lang'] ?? 'pl';

// Funkcja generująca linki, która nie "gubi" id wpisu ani galerii
if (!function_exists('get_lang_url')) {
    function get_lang_url($lang) {
        $params = $_GET;
        $params['lang'] = $lang;
        return '?' . http_build_query($params);
    }
}
?>

<!-- GLOBALNY KONTENER PŁYWAJĄCY W FLEXBOX (ZAPOBIEGA NAKŁADANIU) -->
<div class="fixed bottom-4 right-4 md:bottom-6 md:right-6 z-[9999] flex flex-col md:flex-row items-end gap-3 pointer-events-none">
    
    <!-- 
    UWAGA: Aby przycisk "Zobacz etapy wyjazdu" nie nakładał się na flagi, 
    jego kod HTML (który prawdopodobnie masz w post.php) musi zostać wklejony TUTAJ.
    
    Pamiętaj tylko, aby do klas CSS przycisku etapów dodać 'pointer-events-auto', 
    żeby pozostał klikalny!
    -->

    <!-- PŁYWAJĄCY PRZEŁĄCZNIK JĘZYKÓW (GLOBALNY DLA CAŁEJ STRONY) -->
    <div class="pointer-events-auto bg-white/90 backdrop-blur-sm shadow-xl rounded-full px-4 py-2 flex gap-3 border border-zinc-200 transition-all">
        <a href="<?php echo get_lang_url('pl'); ?>" class="text-2xl transition-opacity <?php echo $curr_l=='pl'?'opacity-100 scale-110':'opacity-50 hover:opacity-100'; ?>" title="Polski">🇵🇱</a>
        <a href="<?php echo get_lang_url('en'); ?>" class="text-2xl transition-opacity <?php echo $curr_l=='en'?'opacity-100 scale-110':'opacity-50 hover:opacity-100'; ?>" title="English">🇬🇧</a>
        <a href="<?php echo get_lang_url('it'); ?>" class="text-2xl transition-opacity <?php echo $curr_l=='it'?'opacity-100 scale-110':'opacity-50 hover:opacity-100'; ?>" title="Italiano">🇮🇹</a>
        <a href="<?php echo get_lang_url('es'); ?>" class="text-2xl transition-opacity <?php echo $curr_l=='es'?'opacity-100 scale-110':'opacity-50 hover:opacity-100'; ?>" title="Español">🇪🇸</a>
        <a href="<?php echo get_lang_url('de'); ?>" class="text-2xl transition-opacity <?php echo $curr_l=='de'?'opacity-100 scale-110':'opacity-50 hover:opacity-100'; ?>" title="Deutsch">🇩🇪</a>
    </div>
</div>

<!-- GŁÓWNA STOPKA -->
<footer class="bg-zinc-900 text-zinc-400 py-8 text-center w-full mt-auto relative z-10">
    <p>&copy; <?php echo date('Y'); ?> TRAVEL24.me - Wyprawy, zdjęcia, pasja. Wszystkie prawa zastrzeżone.</p>
</footer>

<!-- ======================================================= -->
<!-- SILNIK ANIMACJI PRZEJŚĆ: "MIĘKKI WYBUCH" I FADE-IN      -->
<!-- ======================================================= -->
<style>
    /* 1. Animacja startowa: Miękkie wejście nowej strony (Poprawiona) */
    @keyframes pageFadeIn {
        0% { opacity: 0; transform: scale(0.98); }
        99% { opacity: 1; transform: scale(1); }
        100% { opacity: 1; transform: none; } /* Zwalnia blokadę position: fixed na końcu! */
    }
    
    .page-enter-active {
        animation: pageFadeIn 0.6s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
    }

    /* 2. Animacja wyjścia: Zniknięcie reszty strony */
    .page-leave-active {
        opacity: 0 !important;
        transition: opacity 0.4s ease-out !important;
    }

    /* 3. Efekt "Miękkiego Wybuchu" dla klikniętego kafelka */
    .card-explode {
        transform: scale(1.15) translateY(-5px) !important;
        opacity: 0 !important;
        filter: blur(4px) !important;
        box-shadow: 0 40px 80px rgba(0,0,0,0.5) !important;
        z-index: 9999 !important;
        position: relative;
        transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1) !important;
        pointer-events: none; 
    }
</style>

<script>
document.addEventListener("DOMContentLoaded", () => {
    // Odpalamy miękkie wejście
    document.body.classList.add('page-enter-active');

    // KLUCZOWA POPRAWKA: Po 650 milisekundach (gdy wjazd się skończy) zdejmujemy klasę.
    // Dzięki temu Twoja galeria z powiększeniami zdjęć (tzw. Lightbox) znów działa idealnie na środku ekranu!
    setTimeout(() => {
        document.body.classList.remove('page-enter-active');
    }, 650);

    const transitionLinks = document.querySelectorAll('a.group');

    transitionLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // Bezpiecznik dla nowych kart
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1 || this.target === '_blank') {
                return;
            }

            e.preventDefault(); 
            const targetUrl = this.href;
            
            // Odpalamy miękki wybuch
            this.classList.add('card-explode');

            setTimeout(() => {
                const mainArea = document.querySelector('main');
                if (mainArea) mainArea.classList.add('page-leave-active');
            }, 120);

            setTimeout(() => {
                window.location.href = targetUrl;
            }, 500);
        });
    });
});
</script>
