<?php
/**
 * admin_auth.php
 *
 * Session hardening, logout, login form + rate-limited login handling,
 * and CSRF token issuance for the admin panel.
 *
 * Must be required AFTER config.php (needs ADMIN_USER / ADMIN_PASS_HASH)
 * and BEFORE any POST-handling code that trusts $_SESSION['loggedin'] or
 * checks $csrf_token. On success this file simply falls through; if the
 * visitor is not authenticated it prints the login form and exits, so
 * nothing below it in admin.php ever executes for anonymous requests.
 */

if (!defined('APP_ACCESS')) { http_response_code(403); exit('Brak dostępu.'); }

// TWARDA WERYFIKACJA SESJI I PRZEKIEROWANIA
$is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https');
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'secure' => $is_secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Oczyszczanie URL z ?logout=1 żeby zapobiec ponownemu wylogowaniu przy odświeżeniu/zapisie
$current_url = $_SERVER['REQUEST_URI'];
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($current_url, '?'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
    $max_size = ini_get('post_max_size');
    die('<div style="max-width:600px;margin:100px auto;font-family:Arial;text-align:center;color:red;background:white;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);"><h2>Błąd serwera</h2><p>Przesyłane zdjęcia przekraczają limit nałożony przez Twój serwer.</p><a href="' . htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')) . '" style="display:inline-block;padding:12px 24px;background:#2c3e50;color:white;text-decoration:none;border-radius:4px;font-weight:bold;">Wróć do panelu</a></div>');
}

$admin_user = ADMIN_USER;
$admin_pass_hash = ADMIN_PASS_HASH;

if (!isset($_SESSION['loggedin'])) {
    if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
    if (!isset($_SESSION['login_locked_until'])) $_SESSION['login_locked_until'] = 0;
    $locked = time() < $_SESSION['login_locked_until'];
    if (!$locked && isset($_POST['login'])) {
        if ($_POST['user'] === $admin_user && password_verify($_POST['pass'], $admin_pass_hash)) {
            session_regenerate_id(true); $_SESSION['loggedin'] = true; $_SESSION['login_attempts'] = 0;
            header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF']));
            exit;
        } else {
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] >= 5) { $_SESSION['login_locked_until'] = time() + 300; $_SESSION['login_attempts'] = 0; }
        }
    }
    if (!isset($_SESSION['loggedin'])) {
        $login_error = '';
        if ($locked) { $login_error = '<p style="color:red;">Zbyt wiele nieudanych prób.</p>'; }
        elseif (isset($_POST['login'])) { $login_error = '<p style="color:red;">Błędny login lub hasło.</p>'; }
        echo '<div style="max-width:400px; margin:100px auto; font-family:Arial; text-align:center;"><h2>Logowanie Travel24.me</h2>' . $login_error . '<form method="POST" action="'.htmlspecialchars($_SERVER['PHP_SELF']).'"><input type="text" name="user" placeholder="Login" style="width:100%; padding:10px; margin-bottom:10px;"><br><input type="password" name="pass" placeholder="Hasło" style="width:100%; padding:10px; margin-bottom:10px;"><br><button type="submit" name="login" style="width:100%; padding:10px; background:#2c3e50; color:white; border:none; border-radius:4px; font-weight:bold;">Zaloguj</button></form></div>';
        exit;
    }
}

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];
