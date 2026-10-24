<?php
// Pending account approvals — now backed directly by users.status
// (pending/active/rejected) instead of a separate account_requests table,
// since students already create their row in users when they sign up
// and verify their email on the public site.
//
// GET                         list (?status=pending|active|rejected|all)
// POST ?action=approve {id}   sets status='active' (student can now log in)
// POST ?action=reject  {id}   sets status='rejected'
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../public/includes/mailer.php'; // Gmail SMTP sender
require_auth();
$pdo = db();
$STATUSES = ['pending', 'active', 'rejected'];

// Emails the student's Gmail/inbox. A mail failure never blocks the approval.
function notify_email(array $user, bool $approved): bool {
    if (empty($user['email'])) return false;
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Student';
    $mailer = 'send_account_decision_email';
    return function_exists($mailer) && (bool) $mailer($user['email'], $name, $approved);
}

if (method() === 'GET') {
    $status = $_GET['status'] ?? 'pending';
    if ($status === 'all') {
        $rows = $pdo->query(
            "SELECT id, first_name, last_name, email, campus, status, created_at
             FROM users WHERE role = 'Student' ORDER BY created_at DESC"
        )->fetchAll();
    } else {
        if (!in_array($status, $STATUSES, true)) respond(false, null, 'Invalid status.', 422);
        $st = $pdo->prepare(
            "SELECT id, first_name, last_name, email, campus, status, created_at
             FROM users WHERE role = 'Student' AND status = ? ORDER BY created_at DESC"
        );
        $st->execute([$status]);
        $rows = $st->fetchAll();
    }
    respond(true, $rows);
}

if (method() === 'POST') {
    $in = body();
    $id = int_id($in['id'] ?? null);
    $action = $_GET['action'] ?? '';

    $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'Student'");
    $st->execute([$id]);
    $user = $st->fetch();
    if (!$user) respond(false, null, 'Account request not found.', 404);
    if ($user['status'] !== 'pending') respond(false, null, 'This request was already decided.', 409);

    if ($action === 'approve') {
        $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
        notify_student($id, 'Your account has been approved. You can now sign in.');
        $mailed = notify_email($user, true);
        respond(true, ['emailSent' => $mailed],
            $mailed ? 'Account approved. Confirmation email sent to the student.'
                    : 'Account approved, but the email could not be sent (check MAIL_USER / MAIL_PASS).');
    }

    if ($action === 'reject') {
        $pdo->prepare("UPDATE users SET status = 'rejected' WHERE id = ?")->execute([$id]);
        notify_student($id, 'Your account request was not approved. Contact OSAS for more information.');
        $mailed = notify_email($user, false);
        respond(true, ['emailSent' => $mailed],
            $mailed ? 'Account rejected. Notification email sent to the student.'
                    : 'Account rejected, but the email could not be sent (check MAIL_USER / MAIL_PASS).');
    }

    respond(false, null, 'Unknown action', 404);
}

respond(false, null, 'Method not allowed', 405);