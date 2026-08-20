<?php
/**
 * admin_albums.php
 *
 * POST action handlers for photo albums: create, update, delete (album +
 * its photos + files on disk), bulk photo upload into an album, and bulk
 * photo delete. Included from inside admin.php's try/catch-wrapped POST
 * dispatcher, after CSRF has already been verified and $pdo is available.
 */

if (!defined('APP_ACCESS')) { http_response_code(403); exit('Brak dostępu.'); }

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
            if (resize_and_save_image($tmp, $target, 1600, 80)) { $pdo->prepare("INSERT INTO album_photos (album_id, photo_url) VALUES (?, ?)")->execute([$album_id_for_upload, $target]); }
        }
    }
    $_SESSION['admin_msg'] = "<p style='color:green; font-weight:bold;'>Zdjęcia wgrane do albumu!</p>";
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
