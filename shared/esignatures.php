<?php
/**
 * shared/esignatures.php
 *
 * Drawn e-signatures for the admin side.
 *  - Which admin account signs which slot of the Permission form
 *    (keyed on the non-editable username, like clearance_access.php).
 *  - Storing / reading signatures (PNG data URLs).
 *  - Turning a signature into a JPEG array SimplePdf::drawImage() understands
 *    (used by the Activity Clearance PDF).
 *
 * Everything degrades gracefully if database/migration_esignatures.sql has not
 * been imported yet: the helpers just report "no signature".
 */

/** username => slot on the form. */
function esig_slot_map(): array {
    return ['osas' => 'approver1', 'academic' => 'approver2', 'admin' => 'noted'];
}
function esig_slot_for_admin(?array $admin): ?string {
    if (!$admin) return null;
    $byUser = esig_slot_map()[strtolower((string) ($admin['username'] ?? ''))] ?? null;
    if ($byUser) return $byUser;
    // Usernames differ on some installs: fall back to the office shown in the sidebar ("Vice OSAS", ...).
    $o = strtolower(trim((string) ($admin['office'] ?? '')));
    if ($o === '') return null;
    if (str_contains($o, 'osas') || str_contains($o, 'student affairs')) return 'approver1';
    if (str_contains($o, 'academic')) return 'approver2';
    if ($o === 'admin' || str_contains($o, 'administration')) return 'noted';
    return null;
}
function esig_slot_label(string $slot): string {
    return ['approver1' => 'Approved by (1)', 'approver2' => 'Approved by (2)', 'noted' => 'Noted by'][$slot] ?? $slot;
}

/** Creates the e-signature tables if they are missing, so the feature works even before the .sql is imported. */
function esig_ensure_tables(PDO $pdo): void {
    static $tried = false;
    if ($tried) return;
    $tried = true;
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_signatures (
            admin_id INT(11) NOT NULL, signature MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (admin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS reservation_signatures (
            id INT(11) NOT NULL AUTO_INCREMENT, reservation_id INT(11) NOT NULL, slot VARCHAR(12) NOT NULL,
            admin_id INT(11) DEFAULT NULL, signature MEDIUMTEXT NOT NULL, signed_at DATETIME NOT NULL, code VARCHAR(12) NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_ressig_slot (reservation_id, slot)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS signatory_signatures (
            slot VARCHAR(12) NOT NULL, signature MEDIUMTEXT NOT NULL, updated_by INT(11) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (slot)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (Throwable $e) { /* no CREATE permission: the checks below report "not ready" */ }
}

function esig_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    esig_ensure_tables($pdo);
    try {
        $pdo->query('SELECT 1 FROM admin_signatures LIMIT 1');
        $pdo->query('SELECT 1 FROM reservation_signatures LIMIT 1');
        $ok = true;
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

function esig_slots_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    esig_ensure_tables($pdo);
    try { $pdo->query('SELECT 1 FROM signatory_signatures LIMIT 1'); $ok = true; } catch (Throwable $e) { $ok = false; }
    return $ok;
}
function esig_valid_slot(string $slot): bool { return in_array($slot, ['approver1', 'approver2', 'noted'], true); }

/** slot => PNG data URL for the signatories drawn in Settings. */
function esig_slots_all(PDO $pdo): array {
    if (!esig_slots_ready($pdo)) return [];
    $out = [];
    foreach ($pdo->query('SELECT slot, signature FROM signatory_signatures')->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['slot']] = $r['signature'];
    return $out;
}
function esig_slot_get(PDO $pdo, string $slot): ?string { return esig_slots_all($pdo)[$slot] ?? null; }
function esig_slot_set(PDO $pdo, string $slot, string $dataUrl, int $adminId): void {
    $pdo->prepare('INSERT INTO signatory_signatures (slot, signature, updated_by) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE signature = VALUES(signature), updated_by = VALUES(updated_by)')->execute([$slot, $dataUrl, $adminId]);
}
function esig_slot_delete(PDO $pdo, string $slot): void {
    $pdo->prepare('DELETE FROM signatory_signatures WHERE slot = ?')->execute([$slot]);
}

/** Validates a PNG data URL from the browser. Returns the clean data URL, or null. */
function esig_clean_png(string $dataUrl): ?string {
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $m)) return null;
    $raw = base64_decode($m[1], true);
    if ($raw === false || strlen($raw) < 100 || strlen($raw) > 400 * 1024) return null;
    $info = @getimagesizefromstring($raw);
    if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] > 1400 || $info[1] > 700 || $info[0] < 10 || $info[1] < 10) return null;
    return 'data:image/png;base64,' . base64_encode($raw);
}

function esig_get_admin(PDO $pdo, int $adminId): ?string {
    if (!esig_ready($pdo)) return null;
    $st = $pdo->prepare('SELECT signature FROM admin_signatures WHERE admin_id = ?');
    $st->execute([$adminId]);
    $v = $st->fetchColumn();
    return $v ? (string) $v : null;
}
function esig_set_admin(PDO $pdo, int $adminId, string $dataUrl): void {
    $pdo->prepare('INSERT INTO admin_signatures (admin_id, signature) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE signature = VALUES(signature)')->execute([$adminId, $dataUrl]);
}
function esig_delete_admin(PDO $pdo, int $adminId): void {
    $pdo->prepare('DELETE FROM admin_signatures WHERE admin_id = ?')->execute([$adminId]);
}

function esig_code(int $reservationId, string $slot, int $adminId, string $signedAt): string {
    return strtoupper(substr(hash('sha256', $reservationId . '|' . $slot . '|' . $adminId . '|' . $signedAt), 0, 8));
}

/**
 * Keeps a reservation's form signatures in step with the Approval Queue votes:
 *  - an admin who APPROVED gets their signature on their line (dated when they voted),
 *  - an admin who undid the vote / declined has it taken off again.
 * The signature used is the one drawn for that line in Settings (or the admin's own saved one).
 * Runs whenever the signatures are read (admin form view, clearance, student PDF), so approvals
 * made before this feature existed are picked up too.
 */
function esig_sync_from_votes(PDO $pdo, int $reservationId): void {
    static $done = [];
    if (isset($done[$reservationId])) return;
    $done[$reservationId] = true;
    try {
        $v = $pdo->prepare("SELECT v.admin_id, v.voted_at, a.username, a.office
                              FROM reservation_votes v JOIN admins a ON a.id = v.admin_id
                             WHERE v.reservation_id = ? AND v.vote = 'approve' ORDER BY v.voted_at, v.id");
        $v->execute([$reservationId]);
        $voters = $v->fetchAll(PDO::FETCH_ASSOC);
        $approverIds = array_map('intval', array_column($voters, 'admin_id'));

        $c = $pdo->prepare('SELECT slot, admin_id FROM reservation_signatures WHERE reservation_id = ?');
        $c->execute([$reservationId]);
        $cur = [];
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $cur[$r['slot']] = (int) $r['admin_id'];

        foreach ($cur as $slot => $aid) {                      // vote withdrawn -> signature off
            if (!in_array($aid, $approverIds, true)) { esig_unsign_reservation($pdo, $reservationId, $slot); unset($cur[$slot]); }
        }
        $slots = ['approver1', 'approver2', 'noted'];
        foreach ($voters as $vt) {
            $aid = (int) $vt['admin_id'];
            if (in_array($aid, $cur, true)) continue;          // already signed
            $slot = esig_slot_for_admin($vt);
            if ($slot === null) {                              // unrecognised account: first free line
                foreach ($slots as $k) if (!isset($cur[$k])) { $slot = $k; break; }
            }
            if ($slot === null || isset($cur[$slot])) continue;
            $img = esig_slot_get($pdo, $slot) ?: ($slot === 'noted' ? null : esig_get_admin($pdo, $aid));   // "Noted by" is only what OSAS drew in Settings
            if (!$img) continue;                               // nothing drawn yet: appears once one is drawn
            $at = (string) $vt['voted_at'];
            $pdo->prepare('INSERT INTO reservation_signatures (reservation_id, slot, admin_id, signature, signed_at, code)
                           VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$reservationId, $slot, $aid, $img, $at, esig_code($reservationId, $slot, $aid, $at)]);
            $cur[$slot] = $aid;
        }
    } catch (Throwable $e) { /* signatures are optional */ }
}

/** slot => [signature, signed_at (UTC), code, admin_id] for one reservation. */
function esig_for_reservation(PDO $pdo, int $reservationId): array {
    if (!esig_ready($pdo)) return [];
    esig_sync_from_votes($pdo, $reservationId);
    $st = $pdo->prepare('SELECT slot, signature, signed_at, code, admin_id FROM reservation_signatures WHERE reservation_id = ?');
    $st->execute([$reservationId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['slot']] = $r;
    return $out;
}

function esig_sign_reservation(PDO $pdo, int $reservationId, string $slot, int $adminId, string $dataUrl): array {
    $now = gmdate('Y-m-d H:i:s');
    $code = esig_code($reservationId, $slot, $adminId, $now);
    $pdo->prepare('INSERT INTO reservation_signatures (reservation_id, slot, admin_id, signature, signed_at, code)
                   VALUES (?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id), signature = VALUES(signature),
                                           signed_at = VALUES(signed_at), code = VALUES(code)')
        ->execute([$reservationId, $slot, $adminId, $dataUrl, $now, $code]);
    return ['signed_at' => $now, 'code' => $code];
}
function esig_unsign_reservation(PDO $pdo, int $reservationId, string $slot): void {
    $pdo->prepare('DELETE FROM reservation_signatures WHERE reservation_id = ? AND slot = ?')->execute([$reservationId, $slot]);
}

/** UTC "Y-m-d H:i:s" -> "October 24, 2026" in campus time (falls back to UTC if the helper isn't loaded). */
function esig_format_date(?string $utc): string {
    if (!$utc) return '';
    try {
        $tz = function_exists('app_timezone') ? app_timezone() : 'Asia/Manila';
        return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('F j, Y');
    } catch (Throwable $e) { return ''; }
}

/**
 * Decodes an 8-bit RGB / RGBA, non-interlaced PNG in plain PHP (no GD needed) and flattens it onto white.
 * Returns an image array for SimplePdf::drawImage() (Flate-compressed RGB), or null if the PNG is of another kind.
 */
function esig_png_flatten(string $raw): ?array {
    if (substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n" || !function_exists('gzuncompress') || !function_exists('gzcompress')) return null;
    $len = strlen($raw); $pos = 8; $idat = ''; $w = $h = $bd = $ct = $il = null;
    while ($pos + 8 <= $len) {
        $n = unpack('N', substr($raw, $pos, 4))[1];
        $type = substr($raw, $pos + 4, 4);
        $data = substr($raw, $pos + 8, $n);
        $pos += 12 + $n;
        if ($type === 'IHDR') {
            $u = unpack('Nw/Nh/Cbd/Cct/Ccm/Cfm/Cil', $data);
            $w = (int) $u['w']; $h = (int) $u['h']; $bd = (int) $u['bd']; $ct = (int) $u['ct']; $il = (int) $u['il'];
        } elseif ($type === 'IDAT') { $idat .= $data; }
        elseif ($type === 'IEND') { break; }
    }
    if (!$w || !$h || $bd !== 8 || $il !== 0 || ($ct !== 6 && $ct !== 2) || $w > 2000 || $h > 2000) return null;
    $bpp = $ct === 6 ? 4 : 3;
    $stride = $w * $bpp;
    $inf = @gzuncompress($idat);
    if ($inf === false || strlen($inf) < ($stride + 1) * $h) return null;

    $rgb = '';
    $prev = array_fill(0, $stride, 0);
    for ($y = 0; $y < $h; $y++) {
        $off = $y * ($stride + 1);
        $ft = ord($inf[$off]);
        $line = array_values(unpack('C*', substr($inf, $off + 1, $stride)));
        for ($i = 0; $i < $stride; $i++) {
            $a = $i >= $bpp ? $line[$i - $bpp] : 0;
            $b = $prev[$i];
            $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
            switch ($ft) {
                case 1: $line[$i] = ($line[$i] + $a) & 255; break;
                case 2: $line[$i] = ($line[$i] + $b) & 255; break;
                case 3: $line[$i] = ($line[$i] + (($a + $b) >> 1)) & 255; break;
                case 4:
                    $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                    $pr = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                    $line[$i] = ($line[$i] + $pr) & 255;
                    break;
                default: break; // 0 = none
            }
        }
        for ($x = 0; $x < $w; $x++) {
            $j = $x * $bpp;
            if ($bpp === 4) {
                $al = $line[$j + 3];
                $r = intdiv($line[$j] * $al + 255 * (255 - $al) + 127, 255);
                $g = intdiv($line[$j + 1] * $al + 255 * (255 - $al) + 127, 255);
                $bl = intdiv($line[$j + 2] * $al + 255 * (255 - $al) + 127, 255);
            } else { $r = $line[$j]; $g = $line[$j + 1]; $bl = $line[$j + 2]; }
            $rgb .= chr($r) . chr($g) . chr($bl);
        }
        $prev = $line;
    }
    return ['data' => gzcompress($rgb, 6), 'w' => $w, 'h' => $h, 'filter' => 'FlateDecode'];
}

/**
 * PNG data URL -> image array for SimplePdf::drawImage(), flattened onto white.
 * Pure-PHP decoder first (works without the GD extension), GD as the fallback.
 */
function esig_pdf_image(?string $dataUrl): ?array {
    if (!$dataUrl || !preg_match('#^data:image/png;base64,(.+)$#s', $dataUrl, $m)) return null;
    $raw = base64_decode($m[1], true);
    if ($raw === false || $raw === '') return null;

    $img = null;
    try { $img = esig_png_flatten($raw); } catch (Throwable $e) { $img = null; }
    if ($img) return $img;

    if (!function_exists('imagecreatefromstring')) return null;
    $src = @imagecreatefromstring($raw);
    if (!$src) return null;
    $w = imagesx($src); $h = imagesy($src);
    $dst = imagecreatetruecolor($w, $h);
    imagefilledrectangle($dst, 0, 0, $w, $h, imagecolorallocate($dst, 255, 255, 255));
    imagealphablending($dst, true);
    imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
    ob_start(); imagejpeg($dst, null, 92); $jpg = ob_get_clean();
    return $jpg ? ['data' => $jpg, 'w' => $w, 'h' => $h] : null;
}

/**
 * The signature image to print on the clearance for a form slot:
 * the one signed on that reservation's form, else that office's saved signature.
 */
function esig_clearance_image(PDO $pdo, int $reservationId, string $slot): ?array {
    if (!esig_ready($pdo)) return null;
    $sigs = esig_for_reservation($pdo, $reservationId);
    if (!empty($sigs[$slot]['signature'])) return esig_pdf_image($sigs[$slot]['signature']);
    $slotSig = esig_slot_get($pdo, $slot);   // drawn in Settings for this signatory
    if ($slotSig) return esig_pdf_image($slotSig);
    $user = array_search($slot, esig_slot_map(), true);
    if ($user === false) return null;
    $st = $pdo->prepare('SELECT s.signature FROM admin_signatures s JOIN admins a ON a.id = s.admin_id WHERE a.username = ?');
    $st->execute([$user]);
    return esig_pdf_image($st->fetchColumn() ?: null);
}
/** The saved signature of a specific admin (used for the clearance's "Reviewed by"). */
function esig_admin_image(PDO $pdo, ?int $adminId): ?array {
    return $adminId ? esig_pdf_image(esig_get_admin($pdo, $adminId)) : null;
}
