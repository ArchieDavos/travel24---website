<?php
/**
 * admin_deepl.php
 *
 * Proxies a single DeepL translation request from the admin UI's "Tłumacz"
 * buttons. The DeepL API key is supplied per-request by the browser (stored
 * client-side in localStorage, never persisted server-side) - this file
 * only relays it to DeepL over HTTPS. Runs inside the already CSRF-checked
 * POST block in admin.php; always exits (JSON response or error).
 */

if (!defined('APP_ACCESS')) { http_response_code(403); exit('Brak dostępu.'); }

header('Content-Type: application/json');
$api_key = trim($_POST['api_key'] ?? '');
$text = $_POST['text'] ?? '';
$target_lang = $_POST['target_lang'] ?? 'EN-GB';
$is_html = $_POST['is_html'] ?? '0';

$url = strpos($api_key, ':fx') !== false ? 'https://api-free.deepl.com/v2/translate' : 'https://api-deepl.com/v2/translate';

$post_params = [
    'text' => $text,
    'target_lang' => $target_lang
];
if ($is_html === '1') {
    $post_params['tag_handling'] = 'html';
}

$post_data = http_build_query($post_params);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: DeepL-Auth-Key ' . $api_key,
    'Content-Type: application/x-www-form-urlencoded'
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpcode == 200) {
    echo $response;
} else {
    http_response_code($httpcode);
    echo json_encode(['error' => 'Błąd API', 'details' => $response]);
}
exit;
