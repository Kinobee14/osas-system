<?php
// Shared helpers for every API endpoint
require_once __DIR__ . '/../config/db.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (Throwable $e) {
    $code = ($e instanceof PDOException && $e->getCode() == 23000) ? 409 : 500;
    $msg  = $code === 409 ? 'That record already exists.' : 'Server error: ' . $e->getMessage();
    respond(false, null, $msg, $code);
});

function respond(bool $ok, $data = null, string $message = '', int $status = 200): void {
    http_response_code($status);
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $message]);
    exit;
}

function body(): array {
    $json = json_decode(file_get_contents('php://input'), true);
    return is_array($json) ? $json : $_POST;
}

function method(): string {
    return $_SERVER['REQUEST_METHOD'];
}

function require_auth(): array {
    if (empty($_SESSION['admin'])) {
        respond(false, null, 'Please log in.', 401);
    }
    return $_SESSION['admin'];
}

/** Stops anyone who is not the OSAS (super admin) account — Administration and Academic Affairs get a 403. */
function require_osas(array $admin): array {
    require_once __DIR__ . '/../../shared/approval_flow.php';
    if (!admin_is_osas($admin)) respond(false, null, 'Only the OSAS account can do this.', 403);
    return $admin;
}

function required(array $data, array $fields): void {
    foreach ($fields as $f) {
        if (!isset($data[$f]) || trim((string)$data[$f]) === '') {
            respond(false, null, "Field '$f' is required.", 422);
        }
    }
}

function int_id($value): int {
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if (!$id) respond(false, null, 'Invalid id.', 422);
    return $id;
}

/**
 * Writes a row to `notifications` so the affected student sees it on the
 * "Notification" tab of the public site. Silently does nothing if
 * $studentId is null (e.g. a legacy/manual reservation with no linked
 * student account) — there's nobody to notify.
 */
function notify_student(?int $studentId, string $message): void {
    if (!$studentId) return;
    db()->prepare('INSERT INTO notifications (student_id, message) VALUES (?, ?)')
        ->execute([$studentId, $message]);
}
