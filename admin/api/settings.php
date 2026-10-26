<?php
// PUT ?action=profile  {display_name, office}
// PUT ?action=password {current_password, new_password}
// GET ?action=signatories → the "Approved by" / "Noted by" names + positions on the reservation form
// PUT ?action=signatories {approver1_name, approver1_title, approver2_name, approver2_title, noted_name, noted_title}
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../shared/signatories.php';
$admin = require_auth();
$pdo = db();

if (method() === 'GET' && ($_GET['action'] ?? '') === 'signatories') {
    respond(true, get_signatories($pdo));
}
if (method() !== 'PUT') respond(false, null, 'Method not allowed', 405);

$in = body();
$action = $_GET['action'] ?? '';

if ($action === 'profile') {
    required($in, ['display_name', 'office']);
    $pdo->prepare('UPDATE admins SET display_name=?, office=? WHERE id=?')
        ->execute([trim($in['display_name']), trim($in['office']), $admin['id']]);
    $_SESSION['admin']['display_name'] = trim($in['display_name']);
    $_SESSION['admin']['office'] = trim($in['office']);
    respond(true, $_SESSION['admin'], 'Profile updated.');
}

if ($action === 'signatories') {
    require_once __DIR__ . '/../../shared/approval_flow.php';
    if (!admin_is_osas($admin)) {
        // "Noted by" belongs to OSAS alone: keep whatever is saved, ignore anything sent for it.
        $cur = get_signatories($pdo);
        $in['noted_name']  = $cur['noted_name'];
        $in['noted_title'] = $cur['noted_title'];
    }
    required($in, ['approver1_name', 'approver1_title', 'approver2_name', 'approver2_title', 'noted_name', 'noted_title']);
    try {
        save_signatories($pdo, $in);
    } catch (PDOException $e) {
        respond(false, null, 'Could not save. Import database/migration_form_signatories.sql first.', 500);
    }
    respond(true, get_signatories($pdo), 'Approved by / Noted by updated. Students will now see these on the reservation form.');
}

if ($action === 'password') {
    required($in, ['current_password', 'new_password']);
    if (strlen($in['new_password']) < 6) respond(false, null, 'New password must be at least 6 characters.', 422);
    $st = $pdo->prepare('SELECT password FROM admins WHERE id=?');
    $st->execute([$admin['id']]);
    if (!password_verify($in['current_password'], $st->fetchColumn())) {
        respond(false, null, 'Current password is incorrect.', 422);
    }
    $pdo->prepare('UPDATE admins SET password=? WHERE id=?')
        ->execute([password_hash($in['new_password'], PASSWORD_DEFAULT), $admin['id']]);
    respond(true, null, 'Password changed.');
}

respond(false, null, 'Unknown action', 404);
