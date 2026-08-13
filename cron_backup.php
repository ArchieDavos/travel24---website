<?php
// OTO NASZA PRZEPUSTKA DO BAZY DANYCH (żeby db.php nie blokował dostępu)
define('APP_ACCESS', true);
require_once __DIR__ . '/config.php';

// Tajne hasło zabezpieczające - patrz config.php (CRON_BACKUP_TOKEN)
$secret_token = CRON_BACKUP_TOKEN;

// Sprawdzanie uprawnień (czy odpalane z przeglądarki z dobrym hasłem, czy z serwera)
if (!isset($_GET['token'])) {
    if (php_sapi_name() !== 'cli') {
        die('<div style="font-family:sans-serif; padding:20px; color:#721c24; background:#f8d7da; border:1px solid #f5c6cb; border-radius:5px; max-width:600px; margin:20px auto;"><h3>Błąd: Brak autoryzacji!</h3><p>Dostęp zabroniony.</p></div>');
    }
} elseif ($_GET['token'] !== $secret_token) {
    if (php_sapi_name() !== 'cli') {
        die('<div style="font-family:sans-serif; padding:20px; color:#721c24; background:#f8d7da; border:1px solid #f5c6cb; border-radius:5px; max-width:600px; margin:20px auto;"><h3>Błąd: Odmowa dostępu!</h3><p>Podano nieprawidłowe hasło.</p></div>');
    }
}

// Podłączenie do bazy danych
require_once 'db.php'; 

// Folder na kopie zapasowe (ukryty)
$backup_dir = __DIR__ . '/backups/';

// Jeśli folder nie istnieje, stwórz go
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Zabezpieczenie folderu "backups" przed przeglądaniem plików w internecie
if (!file_exists($backup_dir . '.htaccess')) {
    file_put_contents($backup_dir . '.htaccess', "Order allow,deny\nDeny from all");
}

// Ustalenie nazwy pliku z dzisiejszą datą i godziną
$filename = $backup_dir . 'db_backup_' . date('Y-m-d_H-i-s') . '.sql';

try {
    // 1. POBIERANIE WSZYSTKICH TABEL Z BAZY
    $tables = [];
    $query = $pdo->query('SHOW TABLES');
    while ($row = $query->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    // 2. GENEROWANIE ZRZUTU BAZY (DUMP)
    $sql_dump = "-- Kopia zapasowa bazy danych TRAVEL24.me\n";
    $sql_dump .= "-- Wygenerowano: " . date('Y-m-d H:i:s') . "\n\n";
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $query = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $query->fetch(PDO::FETCH_NUM);
        
        $sql_dump .= "-- Struktura i dane dla tabeli `$table`\n";
        $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql_dump .= $row[1] . ";\n\n";

        $query = $pdo->query("SELECT * FROM `$table`");
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $keys = array_keys($row);
            $keys = array_map(function($key) { return "`$key`"; }, $keys);
            
            $values = array_values($row);
            $values = array_map(function($value) use ($pdo) {
                if ($value === null) return 'NULL';
                return $pdo->quote($value); // Bezpieczne cytowanie (escapowanie) znaków
            }, $values);

            $sql_dump .= "INSERT INTO `$table` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $values) . ");\n";
        }
        $sql_dump .= "\n\n";
    }

    $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    // 3. ZAPISYWANIE DO PLIKU .SQL
    file_put_contents($filename, $sql_dump);

    // 4. ROTACJA: ZOSTAW TYLKO 2 NAJNOWSZE ARCHIWA, RESZTĘ USUŃ
    $files = glob($backup_dir . '*.sql');
    
    // Sortuj pliki po dacie modyfikacji (najnowsze na początku)
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $deleted = 0;
    // Jeśli jest więcej niż 2 pliki, kasujemy najstarsze (od trzeciego w dół)
    if (count($files) > 2) {
        for ($i = 2; $i < count($files); $i++) {
            unlink($files[$i]);
            $deleted++;
        }
    }

    // 5. KOMUNIKATY O SUKCESIE
    echo '<div style="font-family:sans-serif; padding:20px; color:#155724; background:#d4edda; border:1px solid #c3e6cb; border-radius:5px; max-width:600px; margin:50px auto;">';
    echo '<h2 style="margin-top:0;">✅ Kopia zapasowa gotowa!</h2>';
    echo '<strong>Utworzono plik:</strong> ' . basename($filename) . '<br><br>';
    echo '<strong>Usunięto starych kopii:</strong> ' . $deleted . '<br>';
    echo '<strong>W bezpiecznym folderze są teraz:</strong> ' . min(count($files), 2) . ' kopie.';
    echo '</div>';

} catch (Exception $e) {
    echo '<div style="font-family:sans-serif; padding:20px; color:#721c24; background:#f8d7da; border:1px solid #f5c6cb; border-radius:5px; max-width:600px; margin:50px auto;">';
    echo '<h3>❌ Wystąpił błąd podczas tworzenia kopii:</h3>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}
?>