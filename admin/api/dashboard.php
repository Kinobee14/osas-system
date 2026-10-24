<?php
// GET – summary cards + latest reservations
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../shared/events.php';
require_auth();

$pdo = db();

// Bring any finished events up to date before counting anything, so the
// cards and the report queues agree with the reservation tables.
auto_complete_due_reservations($pdo);

$stats = [
    'upcoming' => (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status='approved' AND event_date >= CURDATE()")->fetchColumn(),
    'total_events' => (int)$pdo->query("SELECT COUNT(*) FROM reservations")->fetchColumn(),
    'total_users' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn(),
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status='pending'")->fetchColumn(),
    'pending_accounts' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn(),
    // Finished events the student hasn't submitted a report for yet — nothing
    // for OSAS to do here except wait.
    'awaiting_submission' => (int)$pdo->query(
        "SELECT COUNT(*) FROM reservations r
          WHERE r.status='completed'
            AND NOT EXISTS (SELECT 1 FROM post_event_reports pr WHERE pr.reservation_id = r.id)"
    )->fetchColumn(),
    // Reports the student HAS submitted and that are sitting in the review
    // queue — this is the number that actually asks something of OSAS.
    'pending_review' => (int)$pdo->query(
        "SELECT COUNT(*) FROM post_event_reports WHERE review_status = 'pending'"
    )->fetchColumn(),
];

$recent = $pdo->query(
    "SELECT r.id, r.ref_no, r.event_name, r.event_date, r.status, v.name AS venue,
            (SELECT COUNT(*) FROM reservation_attachments a WHERE a.reservation_id = r.id) AS file_count,
            (SELECT COUNT(*) FROM reservation_attachments a WHERE a.reservation_id = r.id AND a.kind='image') AS image_count,
            (SELECT pr.id FROM post_event_reports pr WHERE pr.reservation_id = r.id) AS report_id,
            (SELECT pr.report_no FROM post_event_reports pr WHERE pr.reservation_id = r.id) AS report_no,
            (SELECT pr.review_status FROM post_event_reports pr WHERE pr.reservation_id = r.id) AS report_review_status
     FROM reservations r LEFT JOIN venues v ON v.id = r.venue_id
     ORDER BY r.event_date DESC LIMIT 8"
)->fetchAll();

foreach ($recent as &$row) {
    $row['file_count']  = (int)$row['file_count'];
    $row['image_count'] = (int)$row['image_count'];
    $row['report_id']   = $row['report_id'] ? (int)$row['report_id'] : null;
}
unset($row);

respond(true, ['stats' => $stats, 'recent' => $recent]);
