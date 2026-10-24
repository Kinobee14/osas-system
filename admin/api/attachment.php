<?php
/**
 * api/attachment.php?type=requirement&id=12
 * api/attachment.php?type=photo&id=5
 *
 * The admin twin of public/api/attachment.php: streams any uploaded file to
 * a logged-in Vice Admin, so requirement documents and event pictures can be
 * viewed straight from the panel.
 *
 * Note this deliberately does NOT include _bootstrap.php — that file sets a
 * JSON content-type header on every request, which would corrupt an image
 * or PDF response. The session check is repeated here instead.
 */
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../../shared/uploads.php';

if (empty($_SESSION['admin'])) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Please log in.';
    exit;
}

$type = $_GET['type'] ?? 'requirement';
$id   = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if (!$id || !in_array($type, ['requirement', 'photo'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Bad request.';
    exit;
}

$pdo = db();

if ($type === 'requirement') {
    $stmt = $pdo->prepare('SELECT stored_name, original_name, mime_type FROM reservation_attachments WHERE id = ? LIMIT 1');
    $bucket = UPLOAD_BUCKET_REQUIREMENTS;
} else {
    $stmt = $pdo->prepare('SELECT stored_name, original_name, mime_type FROM post_event_photos WHERE id = ? LIMIT 1');
    $bucket = UPLOAD_BUCKET_POST_EVENTS;
}

$stmt->execute([$id]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'File not found.';
    exit;
}

stream_upload($bucket, $file['stored_name'], $file['original_name'], $file['mime_type']);
