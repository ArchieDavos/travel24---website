<?php
// Plik: upload.php
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title = $_POST['title'];
    $location = $_POST['location'];
    $content = $_POST['content'];
    
    // Prosta funkcja tworząca tzw. przyjazny link (slug) z tytułu
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));

    // Tworzenie katalogu na zdjęcia, jeśli jeszcze nie istnieje
    $target_dir = "uploads/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    // Bezpieczna zmiana nazwy pliku na unikalną (czas + oryginalna nazwa)
    $file_name = time() . '_' . basename($_FILES["cover"]["name"]);
    $target_file = $target_dir . $file_name;

    // Przenoszenie pliku z pamięci tymczasowej do folderu docelowego
    if (move_uploaded_file($_FILES["cover"]["tmp_name"], $target_file)) {
        // Zapis do bazy danych
        $stmt = $pdo->prepare("INSERT INTO posts (title, slug, content, location, cover_image) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $slug, $content, $location, $target_file]);
        
        // Powrót na stronę główną po sukcesie
        header("Location: index.php");
        exit;
    } else {
        echo "Wystąpił błąd podczas wgrywania zdjęcia na serwer.";
    }
}
?>