<?php
/** api/clearance_pdf.php?report_id=4 — admin downloads the clearance for an approved post-event report. */
session_start();
if (empty($_SESSION['admin'])) {
    http_response_code(401); header('Content-Type: text/plain'); echo 'Please log in to the admin panel first.'; exit;
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../../shared/clearance.php';
require_once __DIR__ . '/../../shared/clearance_access.php';

if (!admin_can_download_clearance($_SESSION['admin'])) {
    http_response_code(403); header('Content-Type: text/plain');
    echo 'Your account is not allowed to download the clearance.'; exit;
}

$pdo = db();
$rid = filter_var($_GET['report_id'] ?? null, FILTER_VALIDATE_INT);
$st = $pdo->prepare('SELECT reservation_id FROM post_event_reports WHERE id = ?');
$st->execute([(int) $rid]);
$resId = (int) $st->fetchColumn();

$c = $resId ? load_clearance($pdo, $resId) : null;
if (!$c) {
    http_response_code(403); header('Content-Type: text/plain');
    echo 'The clearance is generated once the post-event report is approved.'; exit;
}
render_clearance_pdf($pdo, $c);