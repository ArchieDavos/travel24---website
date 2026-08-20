<?php
/**
 * admin_functions.php
 *
 * Stand-alone helper functions used by the admin panel (admin.php).
 * No side effects on include - safe to require at any point, in any order,
 * relative to session/db bootstrap. Requires APP_ACCESS like every other
 * panel file (defined by whichever entry file includes this one).
 */

if (!defined('APP_ACCESS')) { http_response_code(403); exit('Brak dostępu.'); }

/**
 * Zlicza aktywnych subskrybentów MailerLite.
 *
 * API MailerLite (connect.mailerlite.com) używa kursorowej paginacji i nie zwraca już pola
 * meta.total, więc liczbę aktywnych subskrybentów trzeba zliczyć samodzielnie po stronach.
 *
 * @return int Liczba aktywnych subskrybentów.
 */
if (!function_exists('count_mailerlite_active_subscribers')) {
    function count_mailerlite_active_subscribers() {
        $total = 0;
        $cursor = null;
        $max_pages = 50; // zabezpieczenie przed nieskończoną pętlą (do 50 000 subskrybentów przy limit=1000)
        for ($i = 0; $i < $max_pages; $i++) {
            $url = 'https://connect.mailerlite.com/api/subscribers?filter[status]=active&limit=1000';
            if ($cursor) { $url .= '&cursor=' . urlencode($cursor); }
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Authorization: Bearer ' . MAILERLITE_TOKEN
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($response, true);
            if (!isset($data['data']) || !is_array($data['data'])) break;
            $total += count($data['data']);
            $cursor = $data['meta']['next_cursor'] ?? null;
            if (!$cursor) break;
        }
        return $total;
    }
}

/**
 * Zamienia ścieżkę zapisaną w bazie (np. "uploads/xyz.jpg", ewentualnie już z wiodącym "/")
 * na bezwzględną ścieżkę od katalogu głównego domeny (np. "/uploads/xyz.jpg").
 * Dzięki temu miniatury ładują się poprawnie niezależnie od tego, z jakiego
 * adresu/podkatalogu jest aktualnie serwowany admin.php.
 */
if (!function_exists('normalize_media_path')) {
    function normalize_media_path(string $path): string {
        return '/' . ltrim($path, '/');
    }
}

/**
 * Wgrywa obraz: koryguje orientację EXIF (dla JPEG), skaluje do max_dimension
 * i zapisuje jako JPEG o podanej jakości. Zwraca false przy jakimkolwiek błędzie.
 */
if (!function_exists('resize_and_save_image')) {
    function resize_and_save_image($tmp_path, $target_path, $max_dimension = 1400, $quality = 75) {
        $info = @getimagesize($tmp_path); if ($info === false) return false; list($width, $height, $type) = $info;
        switch ($type) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($tmp_path); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($tmp_path); break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($tmp_path); break;
            case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp_path) : false; break;
            default: return false;
        }
        if ($src === false) return false;
        if ($type == IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp_path);
            if (!empty($exif['Orientation'])) {
                switch ($exif['Orientation']) { case 3: $src = imagerotate($src, 180, 0); break; case 6: $src = imagerotate($src, 270, 0); break; case 8: $src = imagerotate($src, 90, 0); break; }
            }
        }
        $current_width = imagesx($src); $current_height = imagesy($src);
        $ratio = min(1, $max_dimension / max($current_width, $current_height));
        $new_w = max(1, (int)round($current_width * $ratio)); $new_h = max(1, (int)round($current_height * $ratio));
        $dst = imagecreatetruecolor($new_w, $new_h); $white = imagecolorallocate($dst, 255, 255, 255); imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $current_width, $current_height);
        $ok = imagejpeg($dst, $target_path, $quality); imagedestroy($src); imagedestroy($dst); return $ok;
    }
}

/** Wysyła powiadomienie push (OneSignal) o nowym/zaktualizowanym wpisie. */
if (!function_exists('send_onesignal_push')) {
    function send_onesignal_push($title, $post_id) {
        $onesignal_app_id = defined('ONESIGNAL_APP_ID') ? ONESIGNAL_APP_ID : '';
        $onesignal_rest_api_key = defined('ONESIGNAL_REST_API_KEY') ? ONESIGNAL_REST_API_KEY : '';
        if (!empty($onesignal_rest_api_key)) {
            $content = array("en" => "Nowa relacja już na blogu: " . $title, "pl" => "Nowa relacja już na blogu: " . $title);
            $fields = array('app_id' => $onesignal_app_id, 'included_segments' => array('Subscribed Users'), 'contents' => $content, 'headings' => array("en" => "TRAVEL24.me", "pl" => "TRAVEL24.me"), 'url' => 'https://www.travel24.me/post.php?id=' . $post_id);
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8', 'Authorization: Key ' . $onesignal_rest_api_key));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE); curl_setopt($ch, CURLOPT_HEADER, FALSE); curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields)); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_exec($ch); curl_close($ch);
        }
    }
}

/** Wykonuje SELECT i zwraca wynik jako tablicę; pustą tablicę przy błędzie PDO. */
if (!function_exists('safe_query')) {
    function safe_query($pdo, $sql, &$db_error) {
        try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
        catch (PDOException $e) { return []; }
    }
}
