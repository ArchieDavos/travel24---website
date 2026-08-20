<?php
/**
 * admin.php
 *
 * Entry point / orchestrator for the Travel24.me admin panel, and the
 * dashboard view itself. All actual logic lives in the admin_*.php files
 * next to this one (see below); this file wires them together in the
 * exact order the panel depends on, then renders the dashboard HTML.
 *
 * File map:
 *  - admin_functions.php  Stand-alone helpers (image resize, push, MailerLite count, safe_query...)
 *  - admin_auth.php       Session hardening, login form + rate limiting, logout, CSRF token
 *  - admin_backup.php     "Download latest DB backup" POST action
 *  - admin_deepl.php      DeepL translation proxy POST action
 *  - admin_schema.php     Idempotent ALTER TABLE migrations
 *  - admin_posts.php      Post CRUD, post photo upload/delete, quick views/publish edit
 *  - admin_pages.php      Static CMS page CRUD
 *  - admin_albums.php     Album CRUD, album photo upload/delete
 *  - admin_comments.php   Comment moderation (approve/delete)
 *
 * Load order matters and mirrors the pre-refactor single-file version:
 * config -> functions -> MailerLite count -> auth/session/CSRF -> backup
 * action -> CSRF-checked POST / DeepL action -> db connection -> schema
 * migrations -> POST action dispatch -> data fetch -> HTML view.
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin_functions.php';

// Liczba aktywnych subskrybentów MailerLite, wyświetlana w nagłówku panelu.
$total_subscribers = count_mailerlite_active_subscribers();

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

// Sesja, logowanie (z blokadą po nieudanych próbach), wylogowanie, token CSRF.
// Jeśli użytkownik nie jest zalogowany, ten plik wypisuje formularz logowania i kończy wykonanie (exit).
require_once __DIR__ . '/admin_auth.php';

// Pobranie najnowszej kopii zapasowej bazy (jeśli o to poproszono) - kończy się exit()/readfile() przy sukcesie.
require_once __DIR__ . '/admin_backup.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['login']) && !isset($_POST['download_latest_backup'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('<div style="max-width:600px;margin:100px auto;font-family:Arial;color:red;"><h2>Błąd bezpieczeństwa</h2><a href="' . htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')) . '">Powrót</a></div>');
    }

    if (isset($_POST['action']) && $_POST['action'] === 'deepl_translate') {
        require_once __DIR__ . '/admin_deepl.php'; // kończy się exit()
    }
}

require_once __DIR__ . '/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$msg = ''; $db_error = '';
if (isset($backup_error_msg)) { $msg = $backup_error_msg; }

require_once __DIR__ . '/admin_schema.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['action']) && !isset($_POST['download_latest_backup'])) {
    $should_redirect = false;
    try {
        require __DIR__ . '/admin_posts.php';
        require __DIR__ . '/admin_pages.php';
        require __DIR__ . '/admin_albums.php';
        require __DIR__ . '/admin_comments.php';

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

$albums = safe_query($pdo, "SELECT * FROM albums ORDER BY created_at DESC", $db_error);
$pages = safe_query($pdo, "SELECT * FROM pages", $db_error);
$posts = safe_query($pdo, "SELECT * FROM posts ORDER BY id DESC", $db_error);
$main_posts = safe_query($pdo, "SELECT id, title FROM posts WHERE parent_id = 0 ORDER BY id DESC", $db_error);
$post_photos = safe_query($pdo, "SELECT * FROM post_photos ORDER BY id ASC", $db_error);
$photos = safe_query($pdo, "SELECT p.id, p.album_id, p.photo_url, a.title FROM album_photos p JOIN albums a ON p.album_id = a.id ORDER BY p.id DESC", $db_error);
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
                <div id="postPhotosGrid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap:10px; margin-bottom:15px;"></div>
                <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="post_id_for_photos" id="postIdForPhotos" value="">
                    <input type="file" name="post_photos[]" multiple accept="image/jpeg,image/png,image/webp,image/gif" required>
                    <button type="submit" name="upload_post_photos" class="btn-green">Wgraj i zmniejsz zdjęcia wpisu</button>
                </form>
            </div>
        </div>
    </div>

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
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" onsubmit="return confirm('Czy na pewno chcesz trwale usunąć zaznaczone zdjęcia?');">
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
                        <img src="<?php echo htmlspecialchars(normalize_media_path($ph['photo_url'])); ?>" loading="lazy">
                        <p title="<?php echo htmlspecialchars($ph['title']); ?>"><?php echo htmlspecialchars($ph['title']); ?></p>
                        <label><input type="checkbox" name="photo_ids[]" class="photo-checkbox" value="<?php echo (int)$ph['id']; ?>"> Zaznacz</label>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="delete_photos" id="deletePhotosBtn" class="btn-red" style="display: none;">🗑️ Usuń zaznaczone zdjęcia</button>
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
        <p style="font-size: 14px; color: #333; margin-bottom: 15px;">Możesz w każdej chwili jednym kliknięciem pobrać najnowszą, utworzoną przez Crona kopię całej swojej bazy (teksty, tłumaczenia, ustawienia).</p>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <button type="submit" name="download_latest_backup" class="btn-blue" style="width:100%; font-size:16px; display:flex; justify-content:center; align-items:center; gap:10px;">
                <span style="font-size:20px;">⬇️</span> Pobierz najnowszą kopię bazy na dysk komputera (.sql)
            </button>
        </form>
    </div>

    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="deletePostPhotoForm" style="display:none;"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>"><input type="hidden" name="post_photo_id" id="deletePostPhotoId" value=""><input type="hidden" name="delete_post_photo" value="1"></form>

    <script>
        // Odpowiednik PHP-owego normalize_media_path() - ścieżki w bazie bywają zapisane
        // z lub bez wiodącego "/", więc wszędzie w JS renderujemy je przez tę funkcję.
        function normalizeMediaUrl(path) {
            return '/' + String(path || '').replace(/^\/+/, '');
        }

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
        function renderPostPhotos(postId) {
            const grid = document.getElementById('postPhotosGrid'); const section = document.getElementById('postPhotosSection');
            if(!grid || !section) return; document.getElementById('postIdForPhotos').value = postId || '';
            section.style.display = postId ? 'block' : 'none'; grid.innerHTML = ''; if (!postId) return;
            postPhotosData.filter(ph => String(ph.post_id) === String(postId)).forEach(ph => {
                const wrap = document.createElement('div'); wrap.style.textAlign = 'center';
                const img = document.createElement('img'); img.src = normalizeMediaUrl(ph.image_url); img.style.width = '100%'; img.style.height = '80px'; img.style.objectFit = 'cover'; img.style.borderRadius = '4px'; wrap.appendChild(img);

                const insertBtn = document.createElement('button'); insertBtn.type = 'button'; insertBtn.textContent = 'Wstaw do treści'; insertBtn.style.cssText = 'font-size:11px; padding:4px 6px; margin-top:4px; width:100%; background:#27ae60; border:none; color:white; border-radius:3px; cursor:pointer;';
                insertBtn.onclick = () => {
                    langsCode.forEach(lang => {
                        const txt = document.getElementById('contentEditPost_' + lang);
                        if(txt) txt.value += '\n<img src="' + normalizeMediaUrl(ph.image_url) + '" alt="Zdjęcie wpisu">\n';
                    });
                    alert('Wstawiono kod zdjęcia do pola tekstowego!');
                }; wrap.appendChild(insertBtn);

                const setCoverBtn = document.createElement('button'); setCoverBtn.type = 'button'; setCoverBtn.textContent = 'Na okładkę'; setCoverBtn.className = 'btn-blue'; setCoverBtn.style.cssText = 'font-size:11px; padding:4px 6px; margin-top:4px; width:100%; border:none; color:white; border-radius:3px; cursor:pointer;';
                setCoverBtn.onclick = () => { document.getElementById('editPostCover').value = normalizeMediaUrl(ph.image_url); alert('Ustawiono na okładkę!'); }; wrap.appendChild(setCoverBtn);

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
            if (filtered.length > 0) { grid.innerHTML = filtered.map(p => `<img src="${normalizeMediaUrl(p.photo_url)}" style="width:100%; height:60px; object-fit:cover; border-radius:4px; border:1px solid #b8daff;" title="ID: ${p.id}">`).join(''); }
            else { grid.innerHTML = '<p style="font-size:12px; color:#666; grid-column: 1 / -1;">Jeszcze brak zdjęć w tym albumie.</p>'; }
            container.style.display = 'block';
        });

        const filterPhotosSelector = document.getElementById('filterPhotosSelector');
        const managePhotosGrid = document.getElementById('managePhotosGrid');
        const deletePhotosBtn = document.getElementById('deletePhotosBtn');
        const selectAllPhotosBtn = document.getElementById('selectAllPhotosBtn');
        const deselectAllPhotosBtn = document.getElementById('deselectAllPhotosBtn');
        const selectedCountText = document.getElementById('selectedCountText');

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
            } else {
                managePhotosGrid.style.display = 'grid';
                deletePhotosBtn.style.display = 'inline-block';
                photoWrappers.forEach(w => {
                    if (filterId === 'all' || w.getAttribute('data-album-id') === filterId) {
                        w.style.display = 'flex';
                    } else {
                        w.style.display = 'none';
                        const cb = w.querySelector('input[type="checkbox"]');
                        if(cb) cb.checked = false;
                    }
                });
                updateSelectedCount();
            }
        });

        selectAllPhotosBtn?.addEventListener('click', function() {
            document.querySelectorAll('.photo-manage-wrapper').forEach(w => {
                if (w.style.display !== 'none') {
                    const cb = w.querySelector('input[type="checkbox"]');
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
