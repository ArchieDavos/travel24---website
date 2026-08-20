<?php
/**
 * admin_schema.php
 *
 * Ad-hoc, idempotent schema migrations. There is no migrations directory or
 * tool in this project (see CLAUDE.md) - each ALTER TABLE is wrapped in its
 * own try/catch so it's a no-op once the column already exists. Runs on
 * every admin.php load, right after the PDO connection is opened.
 *
 * To add a new column: append another try/catch block here, don't create a
 * separate migrations mechanism.
 */

if (!defined('APP_ACCESS')) { http_response_code(403); exit('Brak dostępu.'); }

try { $pdo->exec("ALTER TABLE posts ADD COLUMN real_views INT DEFAULT 0"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE posts ADD COLUMN is_published TINYINT(1) DEFAULT 1"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE albums ADD COLUMN is_published TINYINT(1) DEFAULT 1"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE posts ADD COLUMN location_en VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE posts ADD COLUMN location_it VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE posts ADD COLUMN location_es VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE posts ADD COLUMN location_de VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}
