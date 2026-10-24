<?php
// GET ?days=30|90|365|0 (0 = all time) &venue_id=
//
// The Reports page shows the post-event report register. Reports are now
// submitted by students and reviewed by OSAS, so this endpoint returns:
//   summary            – headline counts, including how many submitted
//                        reports still need OSAS to look at them (the
//                        number that actually needs action)
//   by_venue           – reports per venue, for the bar chart
//   rows               – the register itself
//   awaiting_submission – finished events the student hasn't reported on yet
//                        (nothing to do here but wait)
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../shared/events.php';
require_auth();
$pdo = db();

// Keep statuses current, so "awaiting submission" counts an event that
// ended ten minutes ago rather than waiting for someone to open another page.
auto_complete_due_reservations($pdo);

$days = (int)($_GET['days'] ?? 90);
$venueId = (int)($_GET['venue_id'] ?? 0);

// Shared filter, applied to the *event* date rather than the filing date —
// "last 90 days" should mean events that happened then, not paperwork that
// happened to be typed up then.
$where = ['1=1'];
$args = [];
if ($days > 0) {
    $where[] = 'r.event_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
    $args[] = $days;
}
if ($venueId > 0) {
    $where[] = 'r.venue_id = ?';
    $args[] = $venueId;
}
$w = implode(' AND ', $where);

// ---- Headline numbers -------------------------------------------------
$st = $pdo->prepare(
    "SELECT COUNT(*) AS submitted,
            COALESCE(SUM(pr.review_status = 'approved'), 0) AS approved,
            COALESCE(SUM(pr.review_status = 'pending'), 0) AS pending_review,
            COALESCE(SUM(pr.review_status = 'needs_revision'), 0) AS needs_revision,
            COALESCE(SUM(pr.attendees_count), 0) AS attendees,
            COALESCE(SUM(pr.venue_condition <> 'good'), 0) AS venue_issues
       FROM post_event_reports pr
       JOIN reservations r ON r.id = pr.reservation_id
      WHERE $w"
);
$st->execute($args);
$agg = $st->fetch();

$st = $pdo->prepare(
    "SELECT COUNT(*) FROM reservations r
      WHERE r.status = 'completed' AND $w"
);
$st->execute($args);
$finished = (int) $st->fetchColumn();

$st = $pdo->prepare(
    "SELECT COUNT(*) FROM post_event_photos p
       JOIN post_event_reports pr ON pr.id = p.report_id
       JOIN reservations r ON r.id = pr.reservation_id
      WHERE $w"
);
$st->execute($args);
$photos = (int) $st->fetchColumn();

$submitted = (int) $agg['submitted'];
$approved = (int) $agg['approved'];
$summary = [
    'submitted'           => $submitted,
    'approved'            => $approved,
    'pending_review'      => (int) $agg['pending_review'],
    'needs_revision'      => (int) $agg['needs_revision'],
    'finished_events'     => $finished,
    'awaiting_submission' => max(0, $finished - $submitted),
    'attendees'           => (int) $agg['attendees'],
    'photos'              => $photos,
    'venue_issues'        => (int) $agg['venue_issues'],
    // Completion means "approved", not just "submitted" — a report stuck
    // in revision hasn't actually documented the event yet as far as the
    // record is concerned.
    'completion_rate'     => $finished > 0 ? (int) round($approved / $finished * 100) : 0,
];

// ---- Reports per venue (bar chart; counts every submission, any status) ---
$st = $pdo->prepare(
    "SELECT COALESCE(v.name, r.venue_name, '(No venue)') AS label,
            COUNT(*) AS value,
            COALESCE(SUM(pr.attendees_count), 0) AS attendees
       FROM post_event_reports pr
       JOIN reservations r ON r.id = pr.reservation_id
       LEFT JOIN venues v ON v.id = r.venue_id
      WHERE $w
      GROUP BY label
      ORDER BY value DESC, label"
);
$st->execute($args);
$byVenue = $st->fetchAll();

// ---- The register -----------------------------------------------------
$st = $pdo->prepare(
    "SELECT pr.id, pr.report_no, pr.attendees_count, pr.prepared_by, pr.created_at,
            pr.resources_condition, pr.venue_condition, pr.review_status,
            r.ref_no, r.event_name, r.requested_by, r.event_date,
            COALESCE(v.name, r.venue_name) AS venue,
            (SELECT COUNT(*) FROM post_event_photos p WHERE p.report_id = pr.id) AS photo_count
       FROM post_event_reports pr
       JOIN reservations r ON r.id = pr.reservation_id
       LEFT JOIN venues v ON v.id = r.venue_id
      WHERE $w
      ORDER BY r.event_date DESC, pr.id DESC"
);
$st->execute($args);
$rows = $st->fetchAll();

// ---- Finished events the student hasn't reported on yet ---------------
$st = $pdo->prepare(
    "SELECT r.id, r.ref_no, r.event_name, r.requested_by, r.event_date,
            COALESCE(v.name, r.venue_name) AS venue,
            DATEDIFF(CURDATE(), r.event_date) AS days_since
       FROM reservations r
       LEFT JOIN venues v ON v.id = r.venue_id
      WHERE r.status = 'completed'
        AND NOT EXISTS (SELECT 1 FROM post_event_reports pr WHERE pr.reservation_id = r.id)
        AND $w
      ORDER BY r.event_date DESC"
);
$st->execute($args);
$awaitingSubmission = $st->fetchAll();

respond(true, [
    'summary'             => $summary,
    'by_venue'            => $byVenue,
    'rows'                => $rows,
    'awaiting_submission' => $awaitingSubmission,
]);
