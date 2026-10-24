<?php
/** api/clearance_pdf.php?id=12 — student downloads the clearance for their own reservation. */
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); header('Content-Type: text/plain'); echo 'Please sign in again.'; exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../shared/clearance.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id) { http_response_code(400); header('Content-Type: text/plain'); echo 'Bad request.'; exit; }

$c = load_clearance(get_db(), (int) $id, (int) $_SESSION['user_id']);
if (!$c) {
    http_response_code(403); header('Content-Type: text/plain');
    echo 'The clearance is available once OSAS approves your post-event report.'; exit;
}
render_clearance_pdf(get_db(), $c);
