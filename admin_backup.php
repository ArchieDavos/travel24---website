<?php
/**
 * admin_backup.php
 *
 * Handles the "download latest DB backup" action from the admin panel.
 * Requires a valid session (admin_auth.php already ran) and a valid CSRF
 * token. Streams the newest backups/*.sql file (created by cron_backup.php)
 * straight to the browser and exits; on failure it sets $backup_error_msg
 * for admin.php to fold into the flash message.
 */

if (!defined('APP_ACCESS')) { http_response_code(403); exit('Brak dostępu.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_latest_backup'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { die('Błąd CSRF'); }

    $backup_dir = __DIR__ . '/backups/';
    $files = glob($backup_dir . '*.sql');

    if (!empty($files)) {
        usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
        $latest_backup = $files[0];

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($latest_backup).'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($latest_backup));
        readfile($latest_backup);
        exit;
    } else {
        $backup_error_msg = "<p style='color:red; font-weight:bold;'>Brak kopii do pobrania. Najpierw utwórz kopię!</p>";
    }
}
