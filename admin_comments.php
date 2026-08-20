<?php
/**
 * admin_comments.php
 *
 * POST action handlers for comment moderation: approve or delete a pending
 * comment. Included from inside admin.php's try/catch-wrapped POST
 * dispatcher, after CSRF has already been verified and $pdo is available.
 */

if (!defined('APP_ACCESS')) { http_response_code(403); exit('Brak dostępu.'); }

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
