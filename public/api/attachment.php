<?php
/**
 * api/attachment.php?type=requirement&id=12
 * api/attachment.php?type=photo&id=5
 *
 * Streams one uploaded file back to the logged-in student — but only if the
 * file belongs to one of THEIR OWN reservations. Everything under
 * public/uploads/ is blocked from direct URL access by its .htaccess, so
 * this endpoint (and its admin twin) is the only way in, which is what makes
 * "student A can't read student B's letter of request" actually true rather
 * than just unlikely.
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Please sign in again.';
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../shared/uploads.php';

$type = $_GET['type'] ?? 'requirement';
$id   = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if (!$id || !in_array($type, ['requirement', 'photo'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Bad request.';
    exit;
}

$pdo = get_db();
$userId = (int) $_SESSION['user_id'];

if ($type === 'requirement') {
    // Join through to reservations so ownership is part of the lookup itself
    // rather than a second check somebody could forget to write.
    $stmt = $pdo->prepare(
        'SELECT a.stored_name, a.original_name, a.mime_type
           FROM reservation_attachments a
           JOIN reservations r ON r.id = a.reservation_id
          WHERE a.id = ? AND r.student_id = ?
          LIMIT 1'
    );
    $bucket = UPLOAD_BUCKET_REQUIREMENTS;
} else {
    $stmt = $pdo->prepare(
        'SELECT p.stored_name, p.original_name, p.mime_type
           FROM post_event_photos p
           JOIN post_event_reports pr ON pr.id = p.report_id
           JOIN reservations r ON r.id = pr.reservation_id
          WHERE p.id = ? AND r.student_id = ?
          LIMIT 1'
    );
    $bucket = UPLOAD_BUCKET_POST_EVENTS;
}

$stmt->execute([$id, $userId]);
$file = $stmt->fetch();

if (!$file) {
    // Deliberately the same response whether the row is missing or simply
    // isn't theirs — otherwise this doubles as a way to probe what exists.
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'File not found.';
    exit;
}

stream_upload($bucket, $file['stored_name'], $file['original_name'], $file['mime_type']);
