<?php
/**
 * admin_posts.php
 *
 * POST action handlers for blog posts: bulk views/publish quick-edit,
 * create, update, delete, and per-post photo upload/delete. Included from
 * inside admin.php's try/catch-wrapped POST dispatcher, after CSRF has
 * already been verified and $pdo is available - each block here sets
 * $_SESSION['admin_msg'] and $should_redirect on success, matching the
 * pattern used by admin_pages.php / admin_albums.php / admin_comments.php.
 */

if (!defined('APP_ACCESS')) { http_response_code(403); exit('Brak dostępu.'); }

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
            // Dopuszczamy w src opcjonalny wiodący "/" - w bazie ścieżka może być
            // zapisana z "/" lub bez niego (np. starsze wpisy vs nowe), więc usuwamy
            // ewentualne "/" z obu stron porównania zamiast dopasowywać dokładny string.
            $normalized_url = ltrim($url, '/');
            $pattern = '/<img[^>]*src="\/?' . preg_quote($normalized_url, '/') . '"[^>]*>/i';
            $pdo->prepare("UPDATE posts SET content=?, content_en=?, content_it=?, content_es=?, content_de=? WHERE id=?")->execute([
                preg_replace($pattern, '', $post_data['content']), preg_replace($pattern, '', $post_data['content_en']), preg_replace($pattern, '', $post_data['content_it']), preg_replace($pattern, '', $post_data['content_es']), preg_replace($pattern, '', $post_data['content_de']), $post_id
            ]);
        }
        $_SESSION['admin_msg'] = "<p style='color:red; font-weight:bold;'>Zdjęcie usunięte.</p>";
    }
    $should_redirect = true;
}
