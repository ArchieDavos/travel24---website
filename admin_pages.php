<?php
/**
 * admin_pages.php
 *
 * POST action handlers for static CMS pages (About/Privacy/etc, `pages`
 * table, keyed by slug): create, save (update), delete. Included from
 * inside admin.php's try/catch-wrapped POST dispatcher, after CSRF has
 * already been verified and $pdo is available.
 */

if (!defined('APP_ACCESS')) { http_response_code(403); exit('Brak dostępu.'); }

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
