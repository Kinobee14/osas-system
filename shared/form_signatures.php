<?php
/**
 * Hand-drawn signatures the student places on the "Permission to Conduct
 * Activity / Program" form: one over the Club / Organization / Adviser printed
 * name and one over the Recommending Approval printed name.
 *
 * Stored on `reservations` as PNG data URLs. The columns are created on first
 * use, so importing database/migration_form_signatures.sql is optional.
 */

const FS_MAX_BYTES = 400000;   // size of the base64 data URL

function fs_ensure_columns(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT adviser_signature, recommending_signature FROM reservations LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) { /* columns missing: try to add them */ }
    try {
        $pdo->exec('ALTER TABLE reservations
            ADD COLUMN adviser_signature MEDIUMTEXT DEFAULT NULL,
            ADD COLUMN recommending_signature MEDIUMTEXT DEFAULT NULL');
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}

/** Returns a safe PNG data URL, or null when empty / not a valid PNG. */
function fs_clean($v): ?string {
    if (!is_string($v)) return null;
    $v = trim($v);
    if ($v === '' || strlen($v) > FS_MAX_BYTES) return null;
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $v, $m)) return null;
    $raw = base64_decode($m[1], true);
    if ($raw === false || strncmp($raw, "\x89PNG\r\n\x1a\n", 8) !== 0) return null;
    return $v;
}
