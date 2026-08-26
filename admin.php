<?php
define('APP_ACCESS', true);
require_once __DIR__ . '/config.php';

// --- ZLICZANIE SUBSKRYBENTÓW MAILERLITE ---
// API MailerLite (connect.mailerlite.com) używa kursorowej paginacji i nie zwraca już pola
// meta.total, więc liczbę aktywnych subskrybentów trzeba zliczyć samodzielnie po stronach.
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
$total_subscribers = count_mailerlite_active_subscribers();
// ----------------------------------------

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

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

// --- POBIERANIE KOPII ZAPASOWEJ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_latest_backup'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { die('Błąd CSRF'); }
    
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
// ----------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['login']) && !isset($_POST['download_latest_backup'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('<div style="max-width:600px;margin:100px auto;font-family:Arial;color:red;"><h2>Błąd bezpieczeństwa</h2><a href="' . htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')) . '">Powrót</a></div>');
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'deepl_translate') {
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
    }
}

require_once 'db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$msg = ''; $db_error = '';
if (isset($backup_error_msg)) { $msg = $backup_error_msg; }

try { $pdo->exec("ALTER TABLE posts ADD COLUMN real_views INT DEFAULT 0"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE posts ADD COLUMN is_published TINYINT(1) DEFAULT 1"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE albums ADD COLUMN is_published TINYINT(1) DEFAULT 1"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE album_photos ADD COLUMN is_published TINYINT(1) NOT NULL DEFAULT 1"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE posts ADD COLUMN location_en VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE posts ADD COLUMN location_it VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE posts ADD COLUMN location_es VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE posts ADD COLUMN location_de VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}

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

function send_onesignal_push($title, $post_id) {
    $onesignal_app_id = "4dc4fd0c-4810-4b9e-9d04-22075da68048";
    $onesignal_rest_api_key = 'os_v2_app_jxcp2dcicbfz5hieeidv3juajcxwaigscf4unuuraujxcatpkq5q4upqsjgu7ynbq67o5x4ab2g4csnarhm3zwur7zajqy6hghet5wi'; 
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['action']) && !isset($_POST['download_latest_backup'])) {
    $should_redirect = false;
    try {
        // --- SZYBKA AKTUALIZACJA Z SEKCJI 8 (W TYM UKRYWANIE) ---
        if (isset($_POST['update_all_views']) && !empty($_POST['post_views'])) {
            $stmt = $pdo->prepare("UPDATE posts SET views = ?, is_published = ? WHERE id = ?");
            foreach ($_POST['post_views'] as $pid => $v) { 
                $is_pub = isset($_POST['post_published'][$pid]) ? 1 : 0;
                $stmt->execute([(int)$v, $is_pub, (int)$pid]); 
            }
            $_SESSION['admin_msg'] = "<p style='color:green; font-weight:bold;'>Wyświetlenia i statusy publikacji zostały zaktualizowane!</p>";
            $should_redirect = true;
        }

        if (isset($_POST['add_post'])) {
            $parent_id = (int)($_POST['parent_id'] ?? 0); $linked_album_id = (int)($_POST['linked_album_id'] ?? 0);
            $is_published = isset($_POST['is_published']) ? 1 : 0; 
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $_POST['title_pl']), '-')) . '-' . substr(uniqid(), -5);
            $pdo->prepare("INSERT INTO posts (title, slug, location, cover_image, content, parent_id, linked_album_id, title_en, content_en, location_en, title_it, content_it, location_it, title_es, content_es, location_es, title_de, content_de, location_de, is_published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                $_POST['title_pl'], $slug, $_POST['location'], $_POST['cover_image'], $_POST['content_pl'], $parent_id, $linked_album_id, $_POST['title_en'] ?? '', $_POST['content_en'] ?? '', $_POST['location_en'] ?? '', $_POST['title_it'] ?? '', $_POST['content_it'] ?? '', $_POST['location_it'] ?? '', $_POST['title_es'] ?? '', $_POST['content_es'] ?? '', $_POST['location_es'] ?? '', $_POST['title_de'] ?? '', $_POST['content_de'] ?? '', $_POST['location_de'] ?? '', $is_published
            ]);
            $new_post_id = $pdo->lastInsertId();
            $_SESSION['admin_msg'] = $is_published ? "<p style='color:green; font-weight:bold;'>Wpis opublikowany na blogu!</p>" : "<p style='color:orange; font-weight:bold;'>Wpis zapisany jako SZKIC!</p>";
            if (isset($_POST['send_push']) && $_POST['send_push'] == '1' && $is_published) { send_onesignal_push($_POST['title_pl'], $new_post_id); $_SESSION['admin_msg'] .= "<p style='color:green; font-size:12px;'>Powiadomienie Push zostało wysłane.</p>"; }
            $should_redirect = true;
        }

        if (isset($_POST['update_post'])) {
            $post_id = (int)($_POST['post_id'] ?? 0);
            if ($post_id > 0) {
                $parent_id = (int)($_POST['parent_id'] ?? 0); 
                $linked_album_id = (int)($_POST['linked_album_id'] ?? 0);
                $is_published = isset($_POST['is_published']) ? 1 : 0;
                $title_pl = $_POST['title_pl'] ?? '';
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title_pl), '-')) . '-' . $post_id;
                
                $stmt = $pdo->prepare("UPDATE posts SET title=?, slug=?, location=?, cover_image=?, content=?, views=?, parent_id=?, linked_album_id=?, title_en=?, content_en=?, location_en=?, title_it=?, content_it=?, location_it=?, title_es=?, content_es=?, location_es=?, title_de=?, content_de=?, location_de=?, is_published=? WHERE id=?");
                $stmt->execute([
                    $title_pl,
                    $slug,
                    $_POST['location'] ?? '',
                    $_POST['cover_image'] ?? '',
                    $_POST['content_pl'] ?? '',
                    (int)($_POST['views'] ?? 0),
                    $parent_id,
                    $linked_album_id,
                    $_POST['title_en'] ?? '',
                    $_POST['content_en'] ?? '',
                    $_POST['location_en'] ?? '',
                    $_POST['title_it'] ?? '',
                    $_POST['content_it'] ?? '',
                    $_POST['location_it'] ?? '',
                    $_POST['title_es'] ?? '',
                    $_POST['content_es'] ?? '',
                    $_POST['location_es'] ?? '',
                    $_POST['title_de'] ?? '',
                    $_POST['content_de'] ?? '',
                    $_POST['location_de'] ?? '',
                    $is_published,
                    $post_id
                ]);
                
                $_SESSION['admin_msg'] = "<p style='color:green; font-weight:bold;'>Wpis zaktualizowany pomyślnie!</p>";
                if (isset($_POST['send_push']) && $_POST['send_push'] == '1' && $is_published) { 
                    send_onesignal_push($title_pl, $post_id); 
                    $_SESSION['admin_msg'] .= "<p style='color:orange; font-size:12px;'>Powiadomienie Push zostało WYSŁANE PONOWNIE.</p>"; 
                }
            } else {
                $_SESSION['admin_msg'] = "<p style='color:red; font-weight:bold;'>Błąd: Nie wybrano wpisu do aktualizacji!</p>";
            }
            $should_redirect = true;
        }

        if (isset($_POST['delete_post'])) {
            $pdo->prepare("UPDATE posts SET parent_id = 0 WHERE parent_id = ?")->execute([$_POST['post_id']]);
            $pdo->prepare("DELETE FROM posts WHERE id=?")->execute([$_POST['post_id']]);
            $_SESSION['admin_msg'] = "<p style='color:red; font-weight:bold;'>Wpis usunięty!</p>";
            $should_redirect = true;
        }

        if (isset($_POST['upload_post_photos']) && !empty($_POST['post_id_for_photos']) && !empty($_FILES['post_photos']['name'][0])) {
            $post_id_for_photos = (int)$_POST['post_id_for_photos']; $upload_dir = 'uploads/posts/'; if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            foreach ($_FILES['post_photos']['name'] as $key => $name) {
                $tmp = $_FILES['post_photos']['tmp_name'][$key];
                if ($_FILES['post_photos']['error'][$key] == 0) {
                    $target = $upload_dir . time() . '_' . rand(1000, 9999) . '.jpg';
                    if (resize_and_save_image($tmp, $target, 1400, 75)) { $pdo->prepare("INSERT INTO post_photos (post_id, image_url) VALUES (?, ?)")->execute([$post_id_for_photos, $target]); }
                }
            }
            $_SESSION['admin_msg'] = "<p style='color:green; font-weight:bold;'>Zdjęcia wpisu poprawnie wgrane!</p>";
            $should_redirect = true;
        }
        
        if (isset($_POST['delete_post_photo']) && !empty($_POST['post_photo_id'])) {
            $stmt = $pdo->prepare("SELECT post_id, image_url FROM post_photos WHERE id = ?"); $stmt->execute([(int)$_POST['post_photo_id']]);
            $photo = $stmt->fetch();
            if ($photo) {
                if (file_exists($photo['image_url'])) unlink($photo['image_url']);
                $pdo->prepare("DELETE FROM post_photos WHERE id = ?")->execute([(int)$_POST['post_photo_id']]);
                $post_id = $photo['post_id']; $url = $photo['image_url'];
                $stmt_post = $pdo->prepare("SELECT content, content_en, content_it, content_es, content_de FROM posts WHERE id = ?");
                $stmt_post->execute([$post_id]); $post_data = $stmt_post->fetch();
                if ($post_data) {
                    $pattern = '/<img[^>]*src="' . preg_quote($url, '/') . '"[^>]*>/i';
                    $pdo->prepare("UPDATE posts SET content=?, content_en=?, content_it=?, content_es=?, content_de=? WHERE id=?")->execute([
                        preg_replace($pattern, '', $post_data['content']), preg_replace($pattern, '', $post_data['content_en']), preg_replace($pattern, '', $post_data['content_it']), preg_replace($pattern, '', $post_data['content_es']), preg_replace($pattern, '', $post_data['content_de']), $post_id
                    ]);
                }
                $_SESSION['admin_msg'] = "<p style='color:red; font-weight:bold;'>Zdjęcie usunięte.</p>";
            }
            $should_redirect = true;
        }

        if (isset($_POST['move_post_photos']) && !empty($_POST['photo_ids']) && !empty($_POST['target_post_id'])) {
            $target_post_id = (int)$_POST['target_post_id'];
            $target_check = $pdo->prepare("SELECT id FROM posts WHERE id = ?");
            $target_check->execute([$target_post_id]);
            if ($target_check->fetch()) {
                $photo_ids = array_filter(array_map('intval', (array)$_POST['photo_ids']));
                if (!empty($photo_ids)) {
                    $placeholders = implode(',', array_fill(0, count($photo_ids), '?'));
                    $pdo->prepare("UPDATE post_photos SET post_id = ? WHERE id IN ($placeholders)")->execute(array_merge([$target_post_id], $photo_ids));
                    $_SESSION['admin_msg'] = "<p style='color:green; font-weight:bold;'>Przeniesiono " . count($photo_ids) . " zdjęć!</p>";
                }
            }
            $should_redirect = true;
        }

        if (isset($_POST['add_page'])) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $_POST['new_page_slug'] ?: $_POST['page_title_pl']), '-'));
            if ($slug === '') $slug = 'strona-' . substr(uniqid(), -6);
            $pdo->prepare("INSERT INTO pages (title, slug, content, title_en, content_en, title_it, content_it, title_es, content_es, title_de, content_de) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                $_POST['page_title_pl'], $slug, $_POST['page_content_pl'], $_POST['page_title_en'] ?? '', $_POST['page_content_en'] ?? '', $_POST['page_title_it'] ?? '', $_POST['page_content_it'] ?? '', $_POST['page_title_es'] ?? '', $_POST['page_content_es'] ?? '', $_POST['page_title_de'] ?? '', $_POST['page_content_de'] ?? ''
            ]);
            $_SESSION['admin_msg'] = "<p style='color:green; font-weight:bold;'>Zakładka utworzona!</p>";
            $should_redirect = true;
        }
        
        if (isset($_POST['save_page'])) {
            $pdo->prepare("UPDATE pages SET title=?, content=?, title_en=?, content_en=?, title_it=?, content_it=?, title_es=?, content_es=?, title_de=?, content_de=? WHERE slug=?")->execute([
                $_POST['page_title_pl'], $_POST['page_content_pl'], $_POST['page_title_en'] ?? '', $_POST['page_content_en'] ?? '', $_POST['page_title_it'] ?? '', $_POST['page_content_it'] ?? '', $_POST['page_title_es'] ?? '', $_POST['page_content_es'] ?? '', $_POST['page_title_de'] ?? '', $_POST['page_content_de'] ?? '', $_POST['page_slug']
            ]);
            $_SESSION['admin_msg'] = "<p style='color:green; font-weight:bold;'>Zakładka zaktualizowana!</p>";
            $should_redirect = true;
        }
        
        if (isset($_POST['delete_page'])) {
            $pdo->prepare("DELETE FROM pages WHERE slug = ?")->execute([$_POST['page_slug']]);
            $_SESSION['admin_msg'] = "<p style='color:red; font-weight:bold;'>Zakładka usunięta!</p>";
            $should_redirect = true;
        }
        
        if (isset($_POST['create_album'])) {
            $is_published = isset($_POST['is_published']) ? 1 : 0;
            $pdo->prepare("INSERT INTO albums (destination, title, description, title_en, description_en, title_it, description_it, title_es, description_es, title_de, description_de, is_published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                $_POST['destination'], $_POST['album_title_pl'], $_POST['album_desc_pl'], $_POST['album_title_en'] ?? '', $_POST['album_desc_en'] ?? '', $_POST['album_title_it'] ?? '', $_POST['album_desc_it'] ?? '', $_POST['album_title_es'] ?? '', $_POST['album_desc_es'] ?? '', $_POST['album_title_de'] ?? '', $_POST['album_desc_de'] ?? '', $is_published
            ]);
            $_SESSION['admin_msg'] = $is_published ? "<p style='color:green; font-weight:bold;'>Album utworzony i opublikowany!</p>" : "<p style='color:orange; font-weight:bold;'>Album zapisany jako SZKIC!</p>";
            $should_redirect = true;
        }

        if (isset($_POST['update_album']) && !empty($_POST['album_id'])) {
            $is_published = isset($_POST['is_published']) ? 1 : 0;
            $pdo->prepare("UPDATE albums SET destination=?, title=?, description=?, title_en=?, description_en=?, title_it=?, description_it=?, title_es=?, description_es=?, title_de=?, description_de=?, is_published=? WHERE id=?")->execute([
                $_POST['destination'], $_POST['album_title_pl'], $_POST['album_desc_pl'], $_POST['album_title_en'] ?? '', $_POST['album_desc_en'] ?? '', $_POST['album_title_it'] ?? '', $_POST['album_desc_it'] ?? '', $_POST['album_title_es'] ?? '', $_POST['album_desc_es'] ?? '', $_POST['album_title_de'] ?? '', $_POST['album_desc_de'] ?? '', $is_published, $_POST['album_id']
            ]);
            $_SESSION['admin_msg'] = "<p style='color:green; font-weight:bold;'>Album zaktualizowany!</p>";
            $should_redirect = true;
        }
        
        if (isset($_POST['delete_album']) && !empty($_POST['album_id'])) {
            $album_id = (int)$_POST['album_id'];
            $stmt = $pdo->prepare("SELECT photo_url FROM album_photos WHERE album_id = ?"); $stmt->execute([$album_id]);
            foreach ($stmt->fetchAll() as $photo) if (file_exists($photo['photo_url'])) unlink($photo['photo_url']);
            $pdo->prepare("DELETE FROM album_photos WHERE album_id = ?")->execute([$album_id]);
            $pdo->prepare("DELETE FROM albums WHERE id = ?")->execute([$album_id]);
            $_SESSION['admin_msg'] = "<p style='color:red; font-weight:bold;'>Album usunięty!</p>";
            $should_redirect = true;
        }
        
        if (isset($_POST['upload_photos']) && !empty($_FILES['photos']['name'][0])) {
            $album_id_for_upload = (int)$_POST['album_id']; $upload_dir = 'uploads/'; if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            foreach ($_FILES['photos']['name'] as $key => $name) {
                $tmp = $_FILES['photos']['tmp_name'][$key];
                if ($_FILES['photos']['error'][$key] == 0) {
                    $target = $upload_dir . time() . '_' . rand(100, 999) . '.jpg';
                    if (resize_and_save_image($tmp, $target, 1600, 80)) { $pdo->prepare("INSERT INTO album_photos (album_id, photo_url, is_published) VALUES (?, ?, 0)")->execute([$album_id_for_upload, $target]); }
                }
            }
            $_SESSION['admin_msg'] = "<p style='color:green; font-weight:bold;'>Zdjęcia wgrane do albumu! Pamiętaj, aby je opublikować w sekcji 7 — na razie są niewidoczne na froncie.</p>";
            $should_redirect = true;
        }

        if (isset($_POST['save_photo_published']) && !empty($_POST['all_photo_ids'])) {
            $stmt = $pdo->prepare("UPDATE album_photos SET is_published = ? WHERE id = ?");
            foreach ($_POST['all_photo_ids'] as $pid) {
                $pid = (int)$pid;
                $is_pub = isset($_POST['photo_published'][$pid]) ? 1 : 0;
                $stmt->execute([$is_pub, $pid]);
            }
            $_SESSION['admin_msg'] = "<p style='color:green; font-weight:bold;'>Statusy publikacji zdjęć zaktualizowane!</p>";
            $should_redirect = true;
        }

        if (isset($_POST['delete_photos']) && !empty($_POST['photo_ids'])) {
            foreach ($_POST['photo_ids'] as $photo_id) {
                $stmt = $pdo->prepare("SELECT photo_url FROM album_photos WHERE id = ?"); $stmt->execute([(int)$photo_id]);
                $photo = $stmt->fetch(); if ($photo && file_exists($photo['photo_url'])) unlink($photo['photo_url']);
                $pdo->prepare("DELETE FROM album_photos WHERE id = ?")->execute([$photo_id]);
            }
            $_SESSION['admin_msg'] = "<p style='color:red; font-weight:bold;'>Zaznaczone zdjęcia zostały usunięte!</p>";
            $should_redirect = true;
        }

        if (isset($_POST['approve_comment'])) {
            $pdo->prepare("UPDATE comments SET is_approved = 1 WHERE id = ?")->execute([$_POST['comment_id']]);
            $_SESSION['admin_msg'] = "<p style='color:green; font-weight:bold;'>Komentarz zatwierdzony!</p>";
            $should_redirect = true;
        }
        if (isset($_POST['delete_comment'])) {
            $pdo->prepare("DELETE FROM comments WHERE id = ?")->execute([$_POST['comment_id']]);
            $_SESSION['admin_msg'] = "<p style='color:red; font-weight:bold;'>Komentarz usunięty!</p>";
            $should_redirect = true;
        }

        if ($should_redirect) {
            header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
            exit;
        }
        
    } catch (PDOException $e) { 
        $_SESSION['admin_msg'] = "<p style='color:red; font-weight:bold;'>Błąd bazy: " . htmlspecialchars($e->getMessage()) . "</p>"; 
        header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
        exit;
    }
}

if (isset($_SESSION['admin_msg'])) {
    $msg .= $_SESSION['admin_msg'];
    unset($_SESSION['admin_msg']);
}

function safe_query($pdo, $sql, &$db_error) { try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (PDOException $e) { return []; } }

$albums = safe_query($pdo, "SELECT * FROM albums ORDER BY created_at DESC", $db_error);
$pages = safe_query($pdo, "SELECT * FROM pages", $db_error);
$posts = safe_query($pdo, "SELECT * FROM posts ORDER BY id DESC", $db_error);
$main_posts = safe_query($pdo, "SELECT id, title FROM posts WHERE parent_id = 0 ORDER BY id DESC", $db_error);
$post_photos = safe_query($pdo, "SELECT * FROM post_photos ORDER BY id ASC", $db_error);
$photos = safe_query($pdo, "SELECT p.id, p.album_id, p.photo_url, p.is_published, a.title FROM album_photos p JOIN albums a ON p.album_id = a.id ORDER BY p.id DESC", $db_error);
$pending_comments = safe_query($pdo, "SELECT c.*, p.title as post_title FROM comments c JOIN posts p ON c.post_id = p.id WHERE c.is_approved = 0 ORDER BY c.created_at DESC", $db_error);

$langs = ['pl' => '🇵🇱 PL', 'en' => '🇬🇧 EN', 'it' => '🇮🇹 IT', 'es' => '🇪🇸 ES', 'de' => '🇩🇪 DE'];
$albums_safe = array_map(function($p) { foreach ($p as $k => $v) if ($v === null) $p[$k] = ''; return $p; }, $albums);
$posts_safe = array_map(function($p) { foreach ($p as $k => $v) if ($v === null) $p[$k] = ''; return $p; }, $posts);
$pages_safe = array_map(function($p) { foreach ($p as $k => $v) if ($v === null) $p[$k] = ''; return $p; }, $pages);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Panel Administratora</title>
    <style>
        body{background:#f4f4f4; padding:20px; font-family:Arial, sans-serif; color:#333;} 
        .box{background:white; padding:20px; margin-bottom:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);} 
        input, select{width:100%; padding:10px; margin:5px 0 15px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;} 
        button{padding:10px 20px; background:#2c3e50; color:white; border:none; cursor:pointer; font-weight:bold; border-radius:4px; transition:0.3s;} 
        button:hover{opacity:0.9;}
        .btn-red{background:#e74c3c;} .btn-green{background:#27ae60;} .btn-orange{background:#f39c12;} .btn-blue{background:#2980b9;}
        .grid-2{display:grid; grid-template-columns: 1fr 1fr; gap:20px;} 
        @media(max-width:768px){.grid-2{grid-template-columns: 1fr;}}
        h2{border-bottom:2px solid #2c3e50; padding-bottom:10px; margin-top:0;}
        hr {border:0; border-top:1px solid #eee; margin: 20px 0;}
        
        .lang-tabs { display: flex; gap: 5px; margin-bottom: 15px; border-bottom: 2px solid #2c3e50; padding-bottom: 5px; overflow-x: auto; align-items:center; }
        .lang-btn { background: #eee; color: #555; padding: 8px 15px; border: 1px solid #ccc; cursor: pointer; border-radius: 4px 4px 0 0; border-bottom: none; font-weight: normal; }
        .lang-btn.active { background: #2c3e50; color: white; border-color: #2c3e50; font-weight: bold; }
        .lang-group { display: none; }
        .lang-group.active { display: block; }
        .highlight-select { border-left: 4px solid #2980b9; background: #f8fbff; }
        
        .main-editor { 
            width: 100%; 
            min-height: 400px; 
            padding: 15px; 
            margin-bottom: 15px; 
            border: 1px solid #ccc; 
            border-radius: 4px; 
            font-family: Arial, sans-serif; 
            font-size: 15px;
            line-height: 1.6;
            resize: vertical; 
            box-sizing: border-box;
        }

        #managePhotosGrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)) !important; gap: 15px; margin-bottom: 15px; }
        .photo-manage-wrapper { text-align: center; padding: 12px; background: #fafafa; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; flex-direction: column; justify-content: space-between; }
        .photo-manage-wrapper img { width: 100%; height: 130px; object-fit: cover; border-radius: 6px; }
        .photo-manage-wrapper p { font-size: 11px; color: #64748b; margin: 8px 0; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; }
        .photo-manage-wrapper label { display: inline-flex; align-items: center; justify-content: center; gap: 6px; margin-top: auto; padding: 6px 10px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: bold; font-size: 12px; color: #334155; cursor: pointer; width: 100%; box-sizing: border-box; }
        .photo-manage-wrapper input[type="checkbox"] { width: 16px; height: 16px; margin: 0; cursor: pointer; }
        
        #loaderOverlay { display:none; position:fixed; inset:0; background:rgba(9, 9, 11, 0.95); z-index:99999; flex-direction:column; align-items:center; justify-content:center; color:white; font-family:Arial, sans-serif; text-align:center; }
        .spinner { border:6px solid rgba(255,255,255,0.1); border-top:6px solid #f39c12; border-radius:50%; width:70px; height:70px; animation:spin 1s linear infinite; margin-bottom:25px; }
        @keyframes spin { 0% { transform:rotate(0deg); } 100% { transform:rotate(360deg); } }
        .progress-container { width: 80%; max-width: 450px; height: 24px; background: rgba(255,255,255,0.1); border-radius: 12px; margin-top: 25px; overflow: hidden; position: relative; border: 1px solid rgba(255,255,255,0.2); }
        .progress-bar { width: 0%; height: 100%; background: linear-gradient(90deg, #f39c12, #e67e22); transition: width 0.3s ease; }
        #progressText { margin-top: 15px; font-weight: bold; font-size: 22px; letter-spacing: 1px; color: #f39c12; }
    </style>
</head>
<body>
    <div id="loaderOverlay">
        <div class="spinner"></div>
        <h2 style="margin:0; font-size:26px; font-weight:bold; letter-spacing:1px;" id="loaderTitle">Wysyłanie plików na serwer...</h2>
        <p style="color:#aaa; margin-top:10px; font-size:15px;">Proszę czekać.</p>
        <div class="progress-container"><div class="progress-bar" id="progressBar"></div></div>
        <div id="progressText">0%</div>
    </div>

    <div class="box">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; flex-wrap:wrap; gap:15px;">
                <h1 style="margin:0;">Panel Administratora</h1>
                <div style="display:inline-block; background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; padding:8px 16px; border-radius:8px; font-weight:bold; font-size:14px;">✉️ Czytelnicy w bazie: <?php echo $total_subscribers; ?></div>
            </div>
            <a href="?logout=1" style="background:#e74c3c; color:white; padding:8px 16px; border-radius:4px; text-decoration:none; font-weight:bold;" onclick="return confirm('Wylogować się?');">Wyloguj</a>
        </div>
        <?php echo $msg; ?>
    </div>

    <?php if(count($pending_comments) > 0): ?>
    <div class="box" style="border: 2px solid #f39c12;">
        <h2 style="color: #d35400;">Poczekalnia Komentarzy</h2>
        <?php foreach($pending_comments as $c): ?>
            <div style="border-left: 4px solid #f39c12; padding-left: 15px; margin-bottom: 15px; background: #fafafa; padding: 10px;">
                <strong>Autor:</strong> <?php echo htmlspecialchars($c['author']); ?> <br>
                <strong>Wpis:</strong> <?php echo htmlspecialchars($c['post_title']); ?> <br>
                <strong>Data:</strong> <?php echo $c['created_at']; ?> <br>
                <p style="font-style: italic;">"<?php echo nl2br(htmlspecialchars($c['content'])); ?>"</p>
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" style="display:flex; gap:10px;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>"><input type="hidden" name="comment_id" value="<?php echo $c['id']; ?>"><button type="submit" name="approve_comment" class="btn-green">Zatwierdź</button><button type="submit" name="delete_comment" class="btn-red" onclick="return confirm('Usuń?');">Usuń</button></form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="grid-2">
        <div class="box" id="containerAddPost">
            <h2>1. Dodaj Nowy Wpis (Blog)</h2>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="addPostForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <div style="background: #eef2f5; padding: 10px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #2980b9;">
                    <label style="font-weight:bold; cursor:pointer; color: #2c3e50; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="is_published" value="1" style="width: 20px; height: 20px; margin: 0;"> 👁️ Opublikuj od razu (odznacz, aby zapisać jako SZKIC)
                    </label>
                </div>
                
                <div style="background: #fff3cd; border: 1px solid #ffeeba; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                    <label style="font-weight:bold; cursor:pointer; color: #856404; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="send_push" value="1" checked style="width: 20px; height: 20px; margin: 0;"> 🔔 Wyślij powiadomienie Push (tylko gdy opublikowane)
                    </label>
                </div>
                
                <div style="background: #eef2f5; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                    <label style="font-weight:bold; font-size:14px;">Struktura Wyprawy:</label>
                    <select name="parent_id" class="highlight-select"><option value="0">⭐ To jest główny wpis</option><?php foreach($main_posts as $mp): ?><option value="<?php echo $mp['id']; ?>">↳ Podepnij pod: <?php echo htmlspecialchars($mp['title']); ?></option><?php endforeach; ?></select>
                    <label style="font-weight:bold; font-size:14px;">Powiązany Album w Galerii:</label>
                    <select name="linked_album_id" class="highlight-select"><option value="0">-- Brak --</option><?php foreach($albums as $a): ?><option value="<?php echo $a['id']; ?>">🔗 <?php echo htmlspecialchars($a['title']); ?></option><?php endforeach; ?></select>
                </div>
                <input type="text" name="cover_image" placeholder="URL okładki (wspólny)">
                <div class="lang-tabs">
                    <?php foreach($langs as $code => $label): ?><button type="button" class="lang-btn <?php echo $code=='pl'?'active':''; ?>" onclick="switchLang('containerAddPost', '<?php echo $code; ?>')"><?php echo $label; ?></button><?php endforeach; ?>
                    <button type="button" class="btn-blue" style="margin-left:auto; font-size:12px; padding:5px 10px;" onclick="runAutoTranslate('add', event)">🤖 Tłumacz (DeepL)</button>
                </div>
                <?php foreach($langs as $code => $label): ?>
                <div class="lang-group lang-group-<?php echo $code; ?> <?php echo $code=='pl'?'active':''; ?>">
                    <input type="text" name="title_<?php echo $code; ?>" id="addPostTitle_<?php echo $code; ?>" placeholder="Tytuł wpisu" <?php echo $code=='pl'?'required':''; ?>>
                    <input type="text" name="<?php echo $code=='pl'?'location':'location_'.$code; ?>" id="addPostLocation_<?php echo $code; ?>" placeholder="Lokalizacja (np. Wielki Pałac Królewski)" <?php echo $code=='pl'?'required':''; ?>>
                    <textarea name="content_<?php echo $code; ?>" id="contentAddPost_<?php echo $code; ?>" class="main-editor" placeholder="Tu wpisz pełną treść relacji z wyprawy..."></textarea>
                </div>
                <?php endforeach; ?>
                <button type="submit" name="add_post" class="btn-green">Zapisz nowy wpis / szkic</button>
            </form>
        </div>

        <div class="box" id="containerEditPost">
            <h2>2. Edytuj Wpis / Szkic</h2>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="editPostForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <select name="post_id" id="postSelector" required><option value="">-- Wybierz wpis --</option><?php foreach($posts as $pt) echo "<option value='" . $pt['id'] . "'>" . ($pt['is_published'] == 0 ? "[SZKIC] " : "") . htmlspecialchars($pt['title']) . "</option>"; ?></select>
                
                <div style="background: #eef2f5; padding: 10px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #2980b9;">
                    <label style="font-weight:bold; cursor:pointer; color: #2c3e50; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="is_published" id="editPostIsPublished" value="1" style="width: 20px; height: 20px; margin: 0;"> 👁️ Opublikowany (widoczny na blogu)
                    </label>
                </div>
                
                <div style="background: #fff3cd; border: 1px solid #ffeeba; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                    <label style="font-weight:bold; cursor:pointer; color: #856404; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="send_push" id="editSendPush" value="1" style="width: 20px; height: 20px; margin: 0;"> 🔔 WYŚLIJ PONOWNIE powiadomienie Push
                    </label>
                </div>
                
                <div style="background: #eef2f5; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                    <label style="font-weight:bold; font-size:14px;">Struktura Wyprawy:</label>
                    <select name="parent_id" id="editPostParentId" class="highlight-select"><option value="0">⭐ To jest główny wpis</option><?php foreach($main_posts as $mp): ?><option value="<?php echo $mp['id']; ?>">↳ Podepnij pod: <?php echo htmlspecialchars($mp['title']); ?></option><?php endforeach; ?></select>
                    <label style="font-weight:bold; font-size:14px;">Powiązany Album w Galerii:</label>
                    <select name="linked_album_id" id="editPostLinkedAlbumId" class="highlight-select"><option value="0">-- Brak --</option><?php foreach($albums as $a): ?><option value="<?php echo $a['id']; ?>">🔗 <?php echo htmlspecialchars($a['title']); ?></option><?php endforeach; ?></select>
                </div>
                <input type="text" name="cover_image" id="editPostCover" placeholder="URL okładki">
                <input type="number" name="views" id="editPostViews" placeholder="Wyświetlenia" required style="display:none;" value="0">
                <div class="lang-tabs">
                    <?php foreach($langs as $code => $label): ?><button type="button" class="lang-btn <?php echo $code=='pl'?'active':''; ?>" onclick="switchLang('containerEditPost', '<?php echo $code; ?>')"><?php echo $label; ?></button><?php endforeach; ?>
                    <button type="button" class="btn-blue" style="margin-left:auto; font-size:12px; padding:5px 10px;" onclick="runAutoTranslate('edit', event)">🤖 Tłumacz</button>
                </div>
                <?php foreach($langs as $code => $label): ?>
                <div class="lang-group lang-group-<?php echo $code; ?> <?php echo $code=='pl'?'active':''; ?>">
                    <input type="text" name="title_<?php echo $code; ?>" id="editPostTitle_<?php echo $code; ?>" placeholder="Tytuł wpisu" <?php echo $code=='pl'?'required':''; ?>>
                    <input type="text" name="<?php echo $code=='pl'?'location':'location_'.$code; ?>" id="editPostLocation_<?php echo $code; ?>" placeholder="Lokalizacja" <?php echo $code=='pl'?'required':''; ?>>
                    <textarea name="content_<?php echo $code; ?>" id="contentEditPost_<?php echo $code; ?>" class="main-editor" placeholder="Tu wpisz pełną treść relacji z wyprawy..."></textarea>
                </div>
                <?php endforeach; ?>
                <div style="display:flex; gap:10px;"><button type="submit" name="update_post" style="flex:1;">Zaktualizuj wpis</button><button type="submit" name="delete_post" class="btn-red" style="flex:1;" formnovalidate onclick="return confirm('Usuń?');">Usuń</button></div>
            </form>
            <hr>
            <div id="postPhotosSection" style="display:none; margin-top:20px;">
                <h3 style="margin-top:0;">Zdjęcia tego wpisu</h3>
                <p style="font-size:12px; color:#666; margin-top:-8px;">💡 Wgraj wszystkie zdjęcia z wyprawy tutaj (np. do wpisu głównego), a potem zaznacz te, które należą do konkretnego etapu, i przenieś je poniższym przyciskiem.</p>
                <div style="display:flex; gap:10px; margin-bottom:10px;">
                    <button type="button" class="btn-blue" style="font-size:12px; padding:6px 10px;" onclick="toggleAllPostPhotos(true)">Zaznacz wszystkie</button>
                    <button type="button" class="btn-blue" style="font-size:12px; padding:6px 10px;" onclick="toggleAllPostPhotos(false)">Odznacz wszystkie</button>
                </div>
                <div id="postPhotosGrid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap:10px; margin-bottom:15px;"></div>
                <div style="background:#eef2f5; padding:10px; border-radius:4px; margin-bottom:15px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <label style="font-weight:bold; font-size:13px;">Przenieś zaznaczone zdjęcia do:</label>
                    <select id="movePhotosTargetPost" class="highlight-select" style="flex:1; min-width:200px;"></select>
                    <button type="button" class="btn-green" style="font-size:12px; padding:6px 10px;" onclick="movePostPhotos()">Przenieś zaznaczone</button>
                </div>
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="post_id_for_photos" id="postIdForPhotos" value="">
                    <input type="file" name="post_photos[]" multiple accept="image/jpeg,image/png,image/webp,image/gif" required>
                    <button type="submit" name="upload_post_photos" class="btn-green">Wgraj i zmniejsz zdjęcia wpisu</button>
                </form>
            </div>
        </div>
    </div>

    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="movePostPhotosForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="target_post_id" id="movePhotosTargetPostId" value="">
        <input type="hidden" name="move_post_photos" value="1">
    </form>

    <div class="box" id="containerPages">
        <h2>3. Zarządzaj Zakładkami</h2>
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="editPageForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <select name="page_slug" id="pageSelector" required><option value="">-- Wybierz zakładkę --</option><?php foreach($pages as $p) echo "<option value='" . htmlspecialchars($p['slug']) . "'>" . htmlspecialchars($p['title']) . "</option>"; ?></select>
            <div class="lang-tabs">
                <?php foreach($langs as $code => $label): ?><button type="button" class="lang-btn <?php echo $code=='pl'?'active':''; ?>" onclick="switchLang('editPageForm', '<?php echo $code; ?>')"><?php echo $label; ?></button><?php endforeach; ?>
                <button type="button" class="btn-blue" style="margin-left:auto; font-size:12px; padding:5px 10px;" onclick="runAutoTranslate('editPage', event)">🤖 Tłumacz</button>
            </div>
            <?php foreach($langs as $code => $label): ?>
            <div class="lang-group lang-group-<?php echo $code; ?> <?php echo $code=='pl'?'active':''; ?>">
                <input type="text" name="page_title_<?php echo $code; ?>" id="editPageTitle_<?php echo $code; ?>" placeholder="Tytuł" <?php echo $code=='pl'?'required':''; ?>>
                <textarea name="page_content_<?php echo $code; ?>" id="contentEditPage_<?php echo $code; ?>" class="main-editor" placeholder="Treść zakładki..."></textarea>
            </div>
            <?php endforeach; ?>
            <button type="submit" name="save_page">Zapisz zakładkę</button>
        </form>
    </div>

    <div class="grid-2">
        <div class="box" id="containerAddAlbum">
            <h2>4. Dodaj Nowy Album (Wielojęzyczny)</h2>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="addAlbumForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <div style="background: #eef2f5; padding: 10px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #2980b9;">
                    <label style="font-weight:bold; cursor:pointer; color: #2c3e50; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="is_published" value="1" style="width: 20px; height: 20px; margin: 0;"> 👁️ Opublikuj od razu (odznacz, aby zapisać jako SZKIC)
                    </label>
                </div>

                <input type="text" name="destination" placeholder="Kierunek (np. Włochy)" required>
                <div class="lang-tabs">
                    <?php foreach($langs as $code => $label): ?><button type="button" class="lang-btn <?php echo $code=='pl'?'active':''; ?>" onclick="switchLang('containerAddAlbum', '<?php echo $code; ?>')"><?php echo $label; ?></button><?php endforeach; ?>
                    <button type="button" class="btn-blue" style="margin-left:auto; font-size:12px; padding:5px 10px;" onclick="runAutoTranslate('addAlbum', event)">🤖 Tłumacz (DeepL)</button>
                </div>
                <?php foreach($langs as $code => $label): ?>
                <div class="lang-group lang-group-<?php echo $code; ?> <?php echo $code=='pl'?'active':''; ?>">
                    <input type="text" name="album_title_<?php echo $code; ?>" id="addAlbumTitle_<?php echo $code; ?>" placeholder="Tytuł albumu" <?php echo $code=='pl'?'required':''; ?>>
                    <textarea name="album_desc_<?php echo $code; ?>" id="addAlbumDesc_<?php echo $code; ?>" placeholder="Krótki opis albumu..." rows="3" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box; margin-top:5px; margin-bottom:15px;"></textarea>
                </div>
                <?php endforeach; ?>
                <button type="submit" name="create_album" class="btn-green">Utwórz album / szkic</button>
            </form>
        </div>

        <div class="box" id="containerEditAlbum">
            <h2>5. Edytuj Istniejący Album</h2>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="editAlbumForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <select name="album_id" id="albumSelector" required><option value="">-- Wybierz album --</option><?php foreach($albums as $a) echo "<option value='" . $a['id'] . "'>" . ($a['is_published'] == 0 ? "[SZKIC] " : "") . htmlspecialchars($a['title']) . "</option>"; ?></select>
                
                <div style="background: #eef2f5; padding: 10px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #2980b9;">
                    <label style="font-weight:bold; cursor:pointer; color: #2c3e50; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="is_published" id="editAlbumIsPublished" value="1" style="width: 20px; height: 20px; margin: 0;"> 👁️ Opublikowany (widoczny w galerii)
                    </label>
                </div>

                <input type="text" name="destination" id="editAlbumDest" placeholder="Kierunek" required>
                <div class="lang-tabs">
                    <?php foreach($langs as $code => $label): ?><button type="button" class="lang-btn <?php echo $code=='pl'?'active':''; ?>" onclick="switchLang('containerEditAlbum', '<?php echo $code; ?>')"><?php echo $label; ?></button><?php endforeach; ?>
                    <button type="button" class="btn-blue" style="margin-left:auto; font-size:12px; padding:5px 10px;" onclick="runAutoTranslate('editAlbum', event)">🤖 Tłumacz</button>
                </div>
                <?php foreach($langs as $code => $label): ?>
                <div class="lang-group lang-group-<?php echo $code; ?> <?php echo $code=='pl'?'active':''; ?>">
                    <input type="text" name="album_title_<?php echo $code; ?>" id="editAlbumTitle_<?php echo $code; ?>" placeholder="Tytuł albumu" <?php echo $code=='pl'?'required':''; ?>>
                    <textarea name="album_desc_<?php echo $code; ?>" id="editAlbumDesc_<?php echo $code; ?>" placeholder="Opis albumu..." rows="3" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box; margin-top:5px; margin-bottom:15px;"></textarea>
                </div>
                <?php endforeach; ?>
                <div style="display:flex; gap:10px;"><button type="submit" name="update_album" style="flex:1;">Zaktualizuj album</button><button type="submit" name="delete_album" class="btn-red" style="flex:1;" formnovalidate onclick="return confirm('Usuń cały album i zdjęcia?');">Usuń</button></div>
            </form>
        </div>
    </div>

    <div class="box">
        <h2>6. Wgraj Zdjęcia do Albumu w Galerii</h2>
        <?php if (count($albums) > 0): ?>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <select name="album_id" id="uploadAlbumSelector" required><option value="">-- Wybierz album --</option><?php foreach($albums as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo ($a['is_published'] == 0 ? "[SZKIC] " : "") . htmlspecialchars($a['title']); ?></option><?php endforeach; ?></select>
                <div id="uploadPreviewContainer" style="display:none; margin-top:10px; margin-bottom:15px; padding:15px; background:#f8fbff; border:1px solid #cce5ff; border-radius:4px;">
                    <p style="font-size:13px; font-weight:bold; color:#004085; margin-top:0; margin-bottom:10px;">📸 Zdjęcia aktualnie znajdujące się w tym albumie:</p>
                    <div id="uploadPreviewGrid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap:10px;"></div>
                </div>
                <input type="file" name="photos[]" multiple accept="image/jpeg" required>
                <button type="submit" name="upload_photos" class="btn-green">Wgraj zdjęcia</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>7. Zarządzaj Zdjęciami w Albumach (Filtrowanie i Czyszczenie)</h2>
        <select id="filterPhotosSelector" style="margin-bottom:15px; font-weight:bold; border:2px solid #2980b9;">
            <option value="none" selected>-- Wybierz album, aby załadować zdjęcia --</option>
            <option value="all">👁️ Pokaż wszystkie zdjęcia ze wszystkich albumów</option>
            <?php foreach($albums as $a): ?><option value="<?php echo $a['id']; ?>">📂 Filtruj tylko: <?php echo htmlspecialchars($a['title']); ?></option><?php endforeach; ?></select>
        <p style="font-size: 12px; color: #666; margin: -10px 0 15px;">💡 Nowo wgrane zdjęcia są domyślnie <strong>niewidoczne na froncie</strong> ("Do opublikowania"). Zaznacz "Opublikowany" i zapisz, żeby pokazały się w galerii — w każdej chwili możesz to cofnąć.</p>
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" onsubmit="return (event.submitter && event.submitter.name === 'delete_photos') ? confirm('Czy na pewno chcesz trwale usunąć zaznaczone zdjęcia?') : true;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div id="managePhotosGrid" style="display: none; margin-bottom: 15px;">
                <div style="grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; background: #eef2f5; padding: 10px 15px; border-radius: 6px; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; gap: 10px;">
                        <button type="button" id="selectAllPhotosBtn" class="btn-blue" style="padding: 6px 12px; font-size: 13px;">✅ Zaznacz wszystkie widoczne</button>
                        <button type="button" id="deselectAllPhotosBtn" style="padding: 6px 12px; font-size: 13px; background: #95a5a6;">❌ Odznacz wszystkie</button>
                    </div>
                    <div style="font-weight: bold; font-size: 13px; color: #334155;" id="selectedCountText">Zaznaczono: 0 zdjęć</div>
                </div>
                <?php foreach($photos as $ph): ?>
                    <div class="photo-manage-wrapper" data-album-id="<?php echo $ph['album_id']; ?>">
                        <img src="<?php echo htmlspecialchars($ph['photo_url']); ?>">
                        <p title="<?php echo htmlspecialchars($ph['title']); ?>"><?php echo htmlspecialchars($ph['title']); ?></p>
                        <input type="hidden" name="all_photo_ids[]" value="<?php echo (int)$ph['id']; ?>">
                        <label class="photo-publish-toggle" style="cursor:pointer; display:flex; align-items:center; gap:4px; font-size:11px; font-weight:bold; margin-top:4px; padding:4px 6px; border-radius:4px; background:<?php echo $ph['is_published'] ? '#d1fae5' : '#fee2e2'; ?>; color:<?php echo $ph['is_published'] ? '#065f46' : '#991b1b'; ?>; border:1px solid <?php echo $ph['is_published'] ? '#a7f3d0' : '#fecaca'; ?>;">
                            <input type="checkbox" name="photo_published[<?php echo (int)$ph['id']; ?>]" class="photo-publish-checkbox" value="1" <?php echo $ph['is_published'] ? 'checked' : ''; ?>>
                            <span class="publish-label-text"><?php echo $ph['is_published'] ? 'Opublikowany' : 'Do opublikowania'; ?></span>
                        </label>
                        <label><input type="checkbox" name="photo_ids[]" class="photo-checkbox" value="<?php echo (int)$ph['id']; ?>"> Zaznacz do usunięcia</label>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" name="save_photo_published" class="btn-green" style="display: none;" id="savePublishedBtn">💾 Zapisz statusy publikacji</button>
                <button type="submit" name="delete_photos" id="deletePhotosBtn" class="btn-red" style="display: none;">🗑️ Usuń zaznaczone zdjęcia</button>
            </div>
        </form>
    </div>
    
    <div class="box">
        <h2>8. Statystyki, Szybka Edycja i Publikacja (Odkryj/Ukryj)</h2>
        <p style="font-size: 13px; color: #666; margin-bottom: 15px;">Możesz tu błyskawicznie "zaptaszkować" wpisy, które mają być publiczne lub odznaczyć, by stały się szkicami. Zmień statusy lub wyświetlenia i kliknij Zapisz!</p>
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div style="display:flex; flex-direction:column; gap: 8px; max-height: 500px; overflow-y: auto; padding-right: 10px; margin-bottom: 15px;">
                <?php foreach($posts as $p): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fbff; padding:10px 15px; border:1px solid #cce5ff; border-radius:6px; flex-wrap:wrap; gap:10px;">
                        <div style="font-weight:bold; color:#004085; font-size:14px; display:flex; align-items:center;">
                            <?php if($p['parent_id'] == 0) echo '⭐ Główna: '; else echo '↳ Etap: '; ?>
                            <?php echo htmlspecialchars($p['title']); ?>
                            
                            <?php if(isset($p['is_published']) && $p['is_published'] == 0): ?>
                                <span style="color:red; font-size:11px; border:1px solid red; padding:2px 4px; border-radius:3px; margin-left:8px;">SZKIC</span>
                            <?php endif; ?>

                            <?php 
                                $wa_text = "🚐 *Nowa relacja już na blogu!* 🌍\n\nCześć! Na blogu pojawił się właśnie nowy wpis z mojej podróży. Przygotowałem dla Was świeżą relację pełną ciekawych miejsc i wrażeń.\n\n👉 *Przeczytaj cały wpis tutaj:*\nhttps://www.travel24.me/post.php?id=" . $p['id'] . "&lang=pl\n\nWpadajcie, czytajcie i dajcie znać w komentarzu, jak Wam się podoba! 👇"; 
                            ?>
                            <a href="https://wa.me/?text=<?php echo rawurlencode($wa_text); ?>" target="_blank" title="Udostępnij na WhatsApp" style="font-size:18px; margin-left:12px; text-decoration:none;">📱</a>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <!-- NOWY MAGiczny Przycisk Z Boku do Szybkiej Publikacji -->
                            <label style="cursor:pointer; display:flex; align-items:center; gap:4px; font-size:11px; font-weight:bold; background: <?php echo (isset($p['is_published']) && $p['is_published'] == 1) ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo (isset($p['is_published']) && $p['is_published'] == 1) ? '#065f46' : '#991b1b'; ?>; padding: 4px 8px; border: 1px solid <?php echo (isset($p['is_published']) && $p['is_published'] == 1) ? '#a7f3d0' : '#fecaca'; ?>; border-radius: 4px; transition:0.3s;">
                                <input type="checkbox" name="post_published[<?php echo $p['id']; ?>]" value="1" <?php echo (isset($p['is_published']) && $p['is_published'] == 1) ? 'checked' : ''; ?>>
                                Widoczny
                            </label>

                            <span title="Prawdziwe, organiczne wejścia czytelników (widzisz to tylko Ty)" style="font-size:12px; color:#475569; background:#e2e8f0; padding:6px 10px; border-radius:4px; font-weight:bold; cursor:help;">
                                Prawdziwe: <?php echo (int)($p['real_views'] ?? 0); ?>
                            </span>
                            <span title="Wyświetlenia widoczne na blogu dla czytelników" style="font-size:18px; margin-left:5px; cursor:help;">👁️</span>
                            <input type="number" name="post_views[<?php echo $p['id']; ?>]" value="<?php echo (int)$p['views']; ?>" style="width:70px; margin:0; padding:6px; border:1px solid #b8daff; text-align:center; font-weight:bold; border-radius:4px;">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="update_all_views" class="btn-green" style="width:100%; font-size:16px;">💾 Zapisz wszystkie zmiany na tej liście</button>
        </form>
    </div>

    <div class="box" style="border: 2px solid #2980b9;">
        <h2 style="color: #2980b9;">9. Kopia Zapasowa (Baza Danych)</h2>
        <p style="font-size: 14px; color: #333; margin-bottom: 15px;">Możesz w każdej chwili jednym kliknięciem pobrać najnowszą, utworzoną przez Crona kopię całej swojej bazy (teksty, tłumaczenia, ustawienia). Aby wymusić wykonanie zupełnie nowej kopii w tej sekundzie, <a href="cron_backup.php?token=arek123" target="_blank" style="color:#2980b9; font-weight:bold; text-decoration:underline;">kliknij tutaj</a> <i>(zmień hasło w linku, jeśli je zmieniłeś)</i>.</p>
        
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button type="submit" name="download_latest_backup" class="btn-blue" style="width:100%; font-size:16px; display:flex; justify-content:center; align-items:center; gap:10px;">
                <span style="font-size:20px;">⬇️</span> Pobierz najnowszą kopię bazy na dysk komputera (.sql)
            </button>
        </form>
    </div>

    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="deletePostPhotoForm" style="display:none;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>"><input type="hidden" name="post_photo_id" id="deletePostPhotoId" value=""><input type="hidden" name="delete_post_photo" value="1"></form>

    <script>
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (!form || form.getAttribute('enctype') !== 'multipart/form-data') return;

            const fileInputs = form.querySelectorAll('input[type="file"]');
            let hasFiles = false;
            fileInputs.forEach(input => { if(input.files.length > 0) hasFiles = true; });
            if (!hasFiles) return; 

            e.preventDefault(); 
            const formData = new FormData(form);
            const submitter = e.submitter;
            if (submitter && submitter.name) formData.append(submitter.name, submitter.value || '1');

            const overlay = document.getElementById('loaderOverlay');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            const loaderTitle = document.getElementById('loaderTitle');
            
            overlay.style.display = 'flex';
            progressBar.style.width = '0%';
            progressText.textContent = '0%';
            progressText.style.color = '#f39c12';
            loaderTitle.textContent = "Wysyłanie plików na serwer...";

            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.pathname, true); 

            xhr.upload.addEventListener('progress', function(event) {
                if (event.lengthComputable) {
                    const percent = Math.round((event.loaded / event.total) * 100);
                    progressBar.style.width = percent + '%';
                    progressText.textContent = percent + '%';
                    if (percent === 100) {
                        progressText.textContent = "100% - Trwa optymalizacja i obracanie...";
                        progressText.style.color = "#27ae60";
                        progressBar.style.background = "#27ae60";
                        loaderTitle.textContent = "Przetwarzanie na serwerze...";
                    }
                }
            });

            xhr.onload = function() {
                if (xhr.status === 200) {
                    document.open(); document.write(xhr.responseText); document.close();
                } else {
                    alert('Błąd serwera. Kod błędu: ' + xhr.status); overlay.style.display = 'none';
                }
            };
            xhr.onerror = function() { alert('Błąd sieci.'); overlay.style.display = 'none'; };
            xhr.send(formData);
        });

        document.getElementById('editPostForm')?.addEventListener('submit', function(e) {
            if (e.submitter && e.submitter.name === 'delete_post') return;
            const pushCheckbox = document.getElementById('editSendPush');
            if (pushCheckbox && pushCheckbox.checked) { if (!confirm('UWAGA!\n\nPowiadomienie Push zostało już kiedyś wysłane.\n\nCzy na pewno chcesz wysłać je PONOWNIE?')) { e.preventDefault(); } }
        });

        function switchLang(containerId, lang) {
            const container = document.getElementById(containerId); if (!container) return;
            container.querySelectorAll('.lang-btn').forEach(btn => btn.classList.toggle('active', btn.getAttribute('onclick').includes("'" + lang + "'")));
            container.querySelectorAll('.lang-group').forEach(group => { group.classList.remove('active'); if (group.classList.contains('lang-group-' + lang)) group.classList.add('active'); });
        }

        const langsCode = ['pl', 'en', 'it', 'es', 'de'];
        const postsData = <?php echo json_encode($posts_safe, JSON_HEX_TAG | JSON_HEX_APOS); ?>;
        
        document.getElementById('postSelector')?.addEventListener('change', function() {
            const post = postsData.find(p => parseInt(p.id) === parseInt(this.value));
            if(post) {
                document.getElementById('editPostParentId').value = post.parent_id || 0;
                document.getElementById('editPostLinkedAlbumId').value = post.linked_album_id || 0;
                document.getElementById('editPostCover').value = post.cover_image || '';
                document.getElementById('editPostViews').value = post.views || 0;

                const pubCb = document.getElementById('editPostIsPublished');
                if (pubCb) { pubCb.checked = (post.is_published === undefined || post.is_published == 1); }

                langsCode.forEach(lang => {
                    const titleField = document.getElementById('editPostTitle_' + lang);
                    const locationField = document.getElementById('editPostLocation_' + lang);
                    const contentField = document.getElementById('contentEditPost_' + lang);
                    const keyTitle = (lang === 'pl') ? 'title' : 'title_' + lang;
                    const keyLocation = (lang === 'pl') ? 'location' : 'location_' + lang;
                    const keyContent = (lang === 'pl') ? 'content' : 'content_' + lang;

                    if (titleField) {
                        titleField.value = post[keyTitle] || '';
                    }
                    if (locationField) {
                        locationField.value = post[keyLocation] || '';
                    }
                    if (contentField) {
                        contentField.value = post[keyContent] || '';
                    }
                });
                renderPostPhotos(post.id);
            } else {
                renderPostPhotos(null);
                document.getElementById('editPostParentId').value = 0;
                document.getElementById('editPostLinkedAlbumId').value = 0;
                document.getElementById('editPostCover').value = '';
                document.getElementById('editPostViews').value = 0;
                const pubCb = document.getElementById('editPostIsPublished');
                if (pubCb) pubCb.checked = false;

                langsCode.forEach(lang => {
                    const titleField = document.getElementById('editPostTitle_' + lang);
                    const locationField = document.getElementById('editPostLocation_' + lang);
                    const contentField = document.getElementById('contentEditPost_' + lang);
                    if (titleField) titleField.value = '';
                    if (locationField) locationField.value = '';
                    if (contentField) contentField.value = '';
                });
            }
        });
        
        const postPhotosData = <?php echo json_encode($post_photos, JSON_HEX_TAG | JSON_HEX_APOS); ?>;
        function toggleAllPostPhotos(checked) {
            document.querySelectorAll('#postPhotosGrid .post-photo-check').forEach(cb => cb.checked = checked);
        }
        function movePostPhotos() {
            const ids = Array.from(document.querySelectorAll('#postPhotosGrid .post-photo-check:checked')).map(cb => cb.value);
            const targetId = document.getElementById('movePhotosTargetPost').value;
            if (ids.length === 0) { alert('Zaznacz przynajmniej jedno zdjęcie.'); return; }
            if (!targetId) { alert('Wybierz wpis docelowy.'); return; }
            const form = document.getElementById('movePostPhotosForm');
            form.querySelectorAll('input[name="photo_ids[]"]').forEach(el => el.remove());
            ids.forEach(id => {
                const input = document.createElement('input'); input.type = 'hidden'; input.name = 'photo_ids[]'; input.value = id;
                form.appendChild(input);
            });
            document.getElementById('movePhotosTargetPostId').value = targetId;
            form.submit();
        }
        function populateMoveTargetSelect(currentPostId) {
            const sel = document.getElementById('movePhotosTargetPost'); if (!sel) return;
            sel.innerHTML = '';
            postsData.filter(p => String(p.id) !== String(currentPostId)).forEach(p => {
                const opt = document.createElement('option'); opt.value = p.id;
                opt.textContent = (String(p.parent_id) === '0' ? '⭐ ' : '↳ ') + p.title;
                sel.appendChild(opt);
            });
        }
        function renderPostPhotos(postId) {
            const grid = document.getElementById('postPhotosGrid'); const section = document.getElementById('postPhotosSection');
            if(!grid || !section) return; document.getElementById('postIdForPhotos').value = postId || '';
            section.style.display = postId ? 'block' : 'none'; grid.innerHTML = ''; if (!postId) return;
            populateMoveTargetSelect(postId);
            postPhotosData.filter(ph => String(ph.post_id) === String(postId)).forEach(ph => {
                const wrap = document.createElement('div'); wrap.style.textAlign = 'center';
                const check = document.createElement('input'); check.type = 'checkbox'; check.className = 'post-photo-check'; check.value = ph.id; check.style.cssText = 'width:18px; height:18px; margin-bottom:4px;'; wrap.appendChild(check); wrap.appendChild(document.createElement('br'));
                const img = document.createElement('img'); img.src = ph.image_url; img.style.width = '100%'; img.style.height = '80px'; img.style.objectFit = 'cover'; img.style.borderRadius = '4px'; wrap.appendChild(img);

                const insertBtn = document.createElement('button'); insertBtn.type = 'button'; insertBtn.textContent = 'Wstaw do treści'; insertBtn.style.cssText = 'font-size:11px; padding:4px 6px; margin-top:4px; width:100%; background:#27ae60; border:none; color:white; border-radius:3px; cursor:pointer;';
                insertBtn.onclick = () => { 
                    langsCode.forEach(lang => { 
                        const txt = document.getElementById('contentEditPost_' + lang);
                        if(txt) txt.value += '\n<img src="' + ph.image_url + '" alt="Zdjęcie wpisu">\n';
                    }); 
                    alert('Wstawiono kod zdjęcia do pola tekstowego!'); 
                }; wrap.appendChild(insertBtn);

                const setCoverBtn = document.createElement('button'); setCoverBtn.type = 'button'; setCoverBtn.textContent = 'Na okładkę'; setCoverBtn.className = 'btn-blue'; setCoverBtn.style.cssText = 'font-size:11px; padding:4px 6px; margin-top:4px; width:100%; border:none; color:white; border-radius:3px; cursor:pointer;';
                setCoverBtn.onclick = () => { document.getElementById('editPostCover').value = ph.image_url; alert('Ustawiono na okładkę!'); }; wrap.appendChild(setCoverBtn);

                const delBtn = document.createElement('button'); delBtn.type = 'button'; delBtn.textContent = 'Trwale Usuń'; delBtn.className = 'btn-red'; delBtn.style.cssText = 'font-size:11px; padding:4px 6px; margin-top:4px; width:100%; border:none; color:white; border-radius:3px; cursor:pointer;';
                delBtn.onclick = () => { if(confirm('Usunąć plik serwera?')) { document.getElementById('deletePostPhotoId').value = ph.id; document.getElementById('deletePostPhotoForm').submit(); } }; wrap.appendChild(delBtn);
                grid.appendChild(wrap);
            });
        }

        const pagesData = <?php echo json_encode($pages_safe, JSON_HEX_TAG | JSON_HEX_APOS); ?>;
        document.getElementById('pageSelector')?.addEventListener('change', function() {
            const page = pagesData.find(p => p.slug === this.value);
            if(page) { 
                langsCode.forEach(lang => { 
                    const titleField = document.getElementById('editPageTitle_' + lang);
                    const contentField = document.getElementById('contentEditPage_' + lang);
                    const keyTitle = (lang === 'pl') ? 'title' : 'title_' + lang;
                    const keyContent = (lang === 'pl') ? 'content' : 'content_' + lang;
                    
                    if (titleField) titleField.value = page[keyTitle] || '';
                    if (contentField) contentField.value = page[keyContent] || '';
                }); 
            }
        });

        const albumsData = <?php echo json_encode($albums_safe, JSON_HEX_TAG | JSON_HEX_APOS); ?>;
        document.getElementById('albumSelector')?.addEventListener('change', function() {
            const album = albumsData.find(p => parseInt(p.id) === parseInt(this.value));
            if(album) { 
                document.getElementById('editAlbumDest').value = album.destination || ''; 
                
                const pubCb = document.getElementById('editAlbumIsPublished');
                if (pubCb) { pubCb.checked = (album.is_published === undefined || album.is_published == 1); }

                langsCode.forEach(lang => { 
                    const keyTitle = (lang === 'pl') ? 'title' : 'title_' + lang;
                    const keyDesc = (lang === 'pl') ? 'description' : 'description_' + lang;
                    
                    const titleField = document.getElementById('editAlbumTitle_' + lang);
                    const descField = document.getElementById('editAlbumDesc_' + lang);
                    
                    if(titleField) titleField.value = album[keyTitle] || ''; 
                    if(descField) descField.value = album[keyDesc] || ''; 
                }); 
            }
        });

        const allAlbumPhotos = <?php echo json_encode($photos, JSON_HEX_TAG | JSON_HEX_APOS); ?>;
        document.getElementById('uploadAlbumSelector')?.addEventListener('change', function() {
            const albumId = parseInt(this.value); const container = document.getElementById('uploadPreviewContainer'); const grid = document.getElementById('uploadPreviewGrid');
            if (!albumId) { container.style.display = 'none'; return; }
            const filtered = allAlbumPhotos.filter(p => parseInt(p.album_id) === albumId);
            if (filtered.length > 0) { grid.innerHTML = filtered.map(p => `<img src="${p.photo_url}" style="width:100%; height:60px; object-fit:cover; border-radius:4px; border:1px solid #b8daff;" title="ID: ${p.id}">`).join(''); } 
            else { grid.innerHTML = '<p style="font-size:12px; color:#666; grid-column: 1 / -1;">Jeszcze brak zdjęć w tym albumie.</p>'; }
            container.style.display = 'block';
        });

        const filterPhotosSelector = document.getElementById('filterPhotosSelector');
        const managePhotosGrid = document.getElementById('managePhotosGrid');
        const deletePhotosBtn = document.getElementById('deletePhotosBtn');
        const savePublishedBtn = document.getElementById('savePublishedBtn');
        const selectAllPhotosBtn = document.getElementById('selectAllPhotosBtn');
        const deselectAllPhotosBtn = document.getElementById('deselectAllPhotosBtn');
        const selectedCountText = document.getElementById('selectedCountText');

        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('photo-publish-checkbox')) {
                const label = e.target.closest('.photo-publish-toggle');
                const text = label.querySelector('.publish-label-text');
                if (e.target.checked) {
                    text.textContent = 'Opublikowany'; label.style.background = '#d1fae5'; label.style.color = '#065f46'; label.style.borderColor = '#a7f3d0';
                } else {
                    text.textContent = 'Do opublikowania'; label.style.background = '#fee2e2'; label.style.color = '#991b1b'; label.style.borderColor = '#fecaca';
                }
            }
        });

        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.photo-checkbox');
            let count = 0;
            checkboxes.forEach(cb => {
                const wrapper = cb.closest('.photo-manage-wrapper');
                if (wrapper && wrapper.style.display !== 'none' && cb.checked) {
                    count++;
                }
            });
            selectedCountText.textContent = 'Zaznaczono: ' + count + ' zdjęć';
        }

        filterPhotosSelector?.addEventListener('change', function() {
            const filterId = this.value;
            const photoWrappers = document.querySelectorAll('.photo-manage-wrapper');
            
            if (filterId === 'none') {
                managePhotosGrid.style.display = 'none';
                deletePhotosBtn.style.display = 'none';
                savePublishedBtn.style.display = 'none';
            } else {
                managePhotosGrid.style.display = 'grid';
                deletePhotosBtn.style.display = 'inline-block';
                savePublishedBtn.style.display = 'inline-block';
                photoWrappers.forEach(w => {
                    if (filterId === 'all' || w.getAttribute('data-album-id') === filterId) {
                        w.style.display = 'flex';
                    } else {
                        w.style.display = 'none';
                        const cb = w.querySelector('.photo-checkbox');
                        if(cb) cb.checked = false;
                    }
                });
                updateSelectedCount();
            }
        });

        selectAllPhotosBtn?.addEventListener('click', function() {
            document.querySelectorAll('.photo-manage-wrapper').forEach(w => {
                if (w.style.display !== 'none') {
                    const cb = w.querySelector('.photo-checkbox');
                    if(cb) cb.checked = true;
                }
            });
            updateSelectedCount();
        });

        deselectAllPhotosBtn?.addEventListener('click', function() {
            document.querySelectorAll('.photo-checkbox').forEach(cb => {
                cb.checked = false;
            });
            updateSelectedCount();
        });

        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('photo-checkbox')) {
                updateSelectedCount();
            }
        });

        async function runAutoTranslate(mode, event) {
            event.preventDefault(); const apiKey = localStorage.getItem('deepl_api_key') || prompt("Wklej swój klucz API z DeepL:");
            if (!apiKey) return; localStorage.setItem('deepl_api_key', apiKey.trim());

            let titlePl, contentPl, titleTarget, contentTarget, locationPl, locationTarget;
            if (mode === 'add') {
                titlePl = document.getElementById('addPostTitle_pl').value;
                contentPl = document.getElementById('contentAddPost_pl').value;
                titleTarget = (lang) => document.getElementById('addPostTitle_' + lang);
                contentTarget = (lang) => document.getElementById('contentAddPost_' + lang);
                locationPl = document.getElementById('addPostLocation_pl').value;
                locationTarget = (lang) => document.getElementById('addPostLocation_' + lang);
            } else if (mode === 'edit') {
                titlePl = document.getElementById('editPostTitle_pl').value;
                contentPl = document.getElementById('contentEditPost_pl').value;
                titleTarget = (lang) => document.getElementById('editPostTitle_' + lang);
                contentTarget = (lang) => document.getElementById('contentEditPost_' + lang);
                locationPl = document.getElementById('editPostLocation_pl').value;
                locationTarget = (lang) => document.getElementById('editPostLocation_' + lang);
            } else if (mode === 'editPage') {
                titlePl = document.getElementById('editPageTitle_pl').value; 
                contentPl = document.getElementById('contentEditPage_pl').value; 
                titleTarget = (lang) => document.getElementById('editPageTitle_' + lang); 
                contentTarget = (lang) => document.getElementById('contentEditPage_' + lang); 
            } else if (mode === 'addAlbum') { 
                titlePl = document.getElementById('addAlbumTitle_pl').value; 
                contentPl = document.getElementById('addAlbumDesc_pl').value; 
                titleTarget = (lang) => document.getElementById('addAlbumTitle_' + lang); 
                contentTarget = (lang) => document.getElementById('addAlbumDesc_' + lang); 
            } else if (mode === 'editAlbum') { 
                titlePl = document.getElementById('editAlbumTitle_pl').value; 
                contentPl = document.getElementById('editAlbumDesc_pl').value; 
                titleTarget = (lang) => document.getElementById('editAlbumTitle_' + lang); 
                contentTarget = (lang) => document.getElementById('editAlbumDesc_' + lang); 
            }

            if (!titlePl && (contentPl === '<p><br></p>' || !contentPl)) { alert('Wpisz polski tytuł i treść!'); return; }
            const csrfToken = document.querySelector('input[name="csrf_token"]').value; const langs = {'en': 'EN-GB', 'it': 'IT', 'es': 'ES', 'de': 'DE'};
            const btn = event.target; const ogTxt = btn.innerText; btn.innerText = '⏳ Tłumaczenie...'; btn.disabled = true;

            const t = async (txt, tgt, html) => {
                const fd = new FormData(); fd.append('csrf_token', csrfToken); fd.append('action', 'deepl_translate'); fd.append('api_key', apiKey); fd.append('text', txt); fd.append('target_lang', tgt); if(html) fd.append('is_html', '1');
                const r = await fetch(window.location.href, { method: 'POST', body: fd }); const d = await r.json();
                if (!r.ok) throw new Error(d.details || "Nieznany błąd"); return d.translations[0].text;
            };

            try {
                for (const [lang, dLang] of Object.entries(langs)) {
                    if (titlePl) {
                        const translatedTitle = await t(titlePl, dLang, false);
                        if (titleTarget(lang)) titleTarget(lang).value = translatedTitle;
                    }
                    if (locationPl && locationTarget) {
                        const translatedLocation = await t(locationPl, dLang, false);
                        if (locationTarget(lang)) locationTarget(lang).value = translatedLocation;
                    }
                    if (contentPl && contentPl !== '<p><br></p>') {
                        const translatedContent = await t(contentPl, dLang, true);
                        if (contentTarget(lang)) contentTarget(lang).value = translatedContent;
                    }
                }
                alert('✅ Tytuł i treść zostały przetłumaczone!');
            } catch (e) { alert('❌ Błąd API! Szczegóły: ' + e.message); localStorage.removeItem('deepl_api_key'); } 
            finally { btn.innerText = ogTxt; btn.disabled = false; }
        }
    </script>
</body>
</html>