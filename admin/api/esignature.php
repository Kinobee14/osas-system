<?php
// E-signatures for the admin side (OSAS, Academic Affairs and Admin accounts).
// GET  ?action=mine                       -> { signature, slot, slot_label, can_sign }
// POST ?action=save   {signature}         -> save/replace MY signature (PNG data URL from the pad)
// POST ?action=remove                     -> delete MY saved signature
// GET  ?action=form&reservation_id=12     -> signatures placed on that reservation's form
// GET  ?action=slots | POST ?action=save_slot {slot, signature} | ?action=remove_slot {slot}  -> one signature per signatory (Settings)
// POST ?action=sign   {reservation_id}    -> put MY saved signature on the form (my slot only)
// POST ?action=unsign {reservation_id}    -> take MY signature off the form
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../shared/events.php';
require_once __DIR__ . '/../../shared/esignatures.php';
require_once __DIR__ . '/../../shared/approval_flow.php';
$admin = require_auth();
$pdo = db();
$slot = esig_slot_for_admin($admin);
$action = $_GET['action'] ?? '';

if (!esig_ready($pdo)) {
    respond(false, null, 'E-signatures are not set up yet. Import database/migration_esignatures.sql in phpMyAdmin first.', 503);
}

if ($action === 'mine' && method() === 'GET') {
    respond(true, [
        'signature'  => esig_get_admin($pdo, (int) $admin['id']),
        'slot'       => $slot,
        'slot_label' => $slot ? esig_slot_label($slot) : null,
        'slot_signature' => $slot ? esig_slot_get($pdo, $slot) : null,
        'can_sign'   => true,
    ]);
}

if ($action === 'slots' && method() === 'GET') {
    respond(true, ['ready' => esig_slots_ready($pdo), 'slots' => esig_slots_all($pdo)]);
}

if ($action === 'form' && method() === 'GET') {
    $rid = int_id($_GET['reservation_id'] ?? null);
    $out = [];
    foreach (esig_for_reservation($pdo, $rid) as $s => $r) {
        $out[$s] = [
            'signature' => $r['signature'],
            'date'      => esig_format_date($r['signed_at']),
            'code'      => $r['code'],
            'mine'      => (int) $r['admin_id'] === (int) $admin['id'],
        ];
    }
    respond(true, ['signatures' => $out, 'slot' => $slot, 'can_sign' => true]);
}

if (method() !== 'POST') respond(false, null, 'Method not allowed', 405);
$in = body();

if ($action === 'save') {
    $png = esig_clean_png((string) ($in['signature'] ?? ''));
    if (!$png) respond(false, null, 'That signature could not be read. Please draw it again.', 422);
    esig_set_admin($pdo, (int) $admin['id'], $png);
    respond(true, ['signature' => $png], 'Your e-signature was saved.');
}

// The "Noted by" signature belongs to OSAS alone; Administration / Academic may only manage Approved by (1) and (2).
$isOsas = admin_is_osas($admin);
if (!$isOsas && in_array($action, ['save_slot', 'remove_slot'], true) && ($in['slot'] ?? '') === 'noted') {
    respond(false, null, 'Only the OSAS account can change the "Noted by" e-signature.', 403);
}
if (!$isOsas && in_array($action, ['save', 'remove', 'sign', 'unsign'], true) && ($slot === 'noted' || ($in['slot'] ?? '') === 'noted')) {
    respond(false, null, 'Only the OSAS account can change the "Noted by" e-signature.', 403);
}

if ($action === 'save_slot' || $action === 'remove_slot') {
    if (!esig_slots_ready($pdo)) respond(false, null, 'Import database/migration_esignatures.sql again (it now includes the signatory table).', 503);
    $which = (string) ($in['slot'] ?? '');
    if (!esig_valid_slot($which)) respond(false, null, 'Unknown signatory.', 422);
    if ($action === 'remove_slot') {
        esig_slot_delete($pdo, $which);
        respond(true, null, esig_slot_label($which) . ' e-signature removed.');
    }
    $png = esig_clean_png((string) ($in['signature'] ?? ''));
    if (!$png) respond(false, null, 'That signature could not be read. Please draw it again.', 422);
    esig_slot_set($pdo, $which, $png, (int) $admin['id']);
    respond(true, ['signature' => $png], esig_slot_label($which) . ' e-signature saved.');
}

if ($action === 'remove') {
    esig_delete_admin($pdo, (int) $admin['id']);
    respond(true, null, 'Your saved e-signature was removed.');
}

if ($action === 'sign' || $action === 'unsign') {
    // Accounts that match a line (OSAS / Academic Affairs / Admin) always sign their own line;
    // any other admin account picks the line it signs.
    $slot = $slot ?? (string) ($in['slot'] ?? '');
    if (!esig_valid_slot((string) $slot)) respond(false, null, 'Choose which signature line you are signing.', 422);
    $rid = int_id($in['reservation_id'] ?? null);
    $st = $pdo->prepare('SELECT status FROM reservations WHERE id = ?');
    $st->execute([$rid]);
    $status = $st->fetchColumn();
    if ($status === false) respond(false, null, 'Reservation not found.', 404);

    if ($action === 'unsign') {
        esig_unsign_reservation($pdo, $rid, $slot);
        respond(true, null, 'Your signature was removed from the form.');
    }
    if (in_array($status, ['declined', 'cancelled'], true)) {
        respond(false, null, 'This reservation is ' . $status . ' — it cannot be signed.', 409);
    }
    $mine = esig_slot_get($pdo, $slot) ?: esig_get_admin($pdo, (int) $admin['id']);
    if (!$mine) respond(false, ['need_signature' => true], 'Draw your e-signature first.', 422);
    $res = esig_sign_reservation($pdo, $rid, $slot, (int) $admin['id'], $mine);
    respond(true, ['date' => esig_format_date($res['signed_at']), 'code' => $res['code']], 'Form signed — your e-signature now appears on it.');
}

respond(false, null, 'Unknown action', 404);
