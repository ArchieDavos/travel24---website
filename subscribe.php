<?php
define('APP_ACCESS', true);
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

// Sprawdzamy czy żądanie przyszło z naszego formularza
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['email'])) {
    echo json_encode(['success' => false, 'message' => 'Brak adresu e-mail.']);
    exit;
}

$email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Podaj poprawny adres e-mail.']);
    exit;
}

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

// Ignorowanie ostrzeżeń SSL, by zapobiec błędom na serwerze
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpcode == 200 || $httpcode == 201) {
    echo json_encode(['success' => true, 'message' => 'Dziękuję! Twój e-mail został zapisany.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Błąd techniczny lub e-mail jest już na liście.']);
}
?>