<?php
require_once 'db.php';

$queries = [
    "ALTER TABLE posts 
     ADD COLUMN title_en VARCHAR(255), ADD COLUMN content_en TEXT, 
     ADD COLUMN title_it VARCHAR(255), ADD COLUMN content_it TEXT, 
     ADD COLUMN title_es VARCHAR(255), ADD COLUMN content_es TEXT, 
     ADD COLUMN title_de VARCHAR(255), ADD COLUMN content_de TEXT",
     
    "ALTER TABLE pages 
     ADD COLUMN title_en VARCHAR(255), ADD COLUMN content_en TEXT, 
     ADD COLUMN title_it VARCHAR(255), ADD COLUMN content_it TEXT, 
     ADD COLUMN title_es VARCHAR(255), ADD COLUMN content_es TEXT, 
     ADD COLUMN title_de VARCHAR(255), ADD COLUMN content_de TEXT",
     
    "ALTER TABLE albums 
     ADD COLUMN title_en VARCHAR(255), ADD COLUMN description_en TEXT, 
     ADD COLUMN title_it VARCHAR(255), ADD COLUMN description_it TEXT, 
     ADD COLUMN title_es VARCHAR(255), ADD COLUMN description_es TEXT, 
     ADD COLUMN title_de VARCHAR(255), ADD COLUMN description_de TEXT"
];

foreach($queries as $q) {
    try {
        $pdo->exec($q);
        echo "Kolumny dodane pomyślnie!<br>";
    } catch(PDOException $e) {
        echo "Info: Prawdopodobnie kolumny już istnieją lub wystąpił błąd: " . $e->getMessage() . "<br>";
    }
}
echo "Gotowe!";
?>
