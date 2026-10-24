<?php
/**
 * includes/db.php
 * Shared database bootstrap. Reuses the same $pdo connection that
 * config.php already sets up, so there's only ever one place the
 * DB credentials live. Files that used to expect a get_db() function
 * (student.php, api/reservation_create.php) get it from here.
 */

require_once __DIR__ . '/../config.php';

/**
 * Returns the shared PDO connection created in config.php.
 */
function get_db(): PDO {
    global $pdo;
    return $pdo;
}
