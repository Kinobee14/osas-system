<?php
// GET  ?id=4       → one report in full, with its photos (the review/view modal)
// GET               → every submitted report (the Reports page register)
// POST ?action=approve         {id}              → accept the report as final
// POST ?action=request_revision {id, remarks}    → send it back to the student
//
// The student writes and (re)submits the report from their own portal
// (public/api/post_event.php); this file is read-only plus the two review
// actions a Vice Admin can take on a report that's awaiting review.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../shared/events.php';
require_once __DIR__ . '/../../shared/uploads.php';
$admin = require_auth();
$pdo = db();

/** Shapes one photo row for the browser. */
function photo_payload(array $p): array {
    return [
        'id'      => (int) $p['id'],
        'caption' => $p['caption'],
        'name'    => $p['original_name'],
        'size'    => format_bytes((int) $p['size_bytes']),
        'url'     => 'api/attachment.php?type=photo&id=' . (int) $p['id'],
    ];
}

/** Loads one report + its reservation + its photos, or null. */
function load_report(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare(
        "SELECT pr.*, r.ref_no, r.event_name, r.venue_name, r.campus, r.event_date,
                r.start_time, r.end_time, r.purpose, r.requested_by, r.student_id,
                a.display_name AS reviewer_name, a.office AS reviewer_office
           FROM post_event_reports pr
           JOIN reservations r ON r.id = pr.reservation_id
           LEFT JOIN admins a ON a.id = pr.reviewed_by
          WHERE pr.id = ?"
    );
    $st->execute([$id]);
    $report = $st->fetch();
    if (!$report) return null;

    $ph = $pdo->prepare('SELECT * FROM post_event_photos WHERE report_id = ? ORDER BY id');
    $ph->execute([$id]);
    $report['photos'] = array_map('photo_payload', $ph->fetchAll());

    $rs = $pdo->prepare('SELECT resource_name, quantity FROM reservation_resources WHERE reservation_id = ? ORDER BY resource_name');
    $rs->execute([(int) $report['reservation_id']]);
    $report['resources'] = $rs->fetchAll();

    $report['attendees_count'] = (int) $report['attendees_count'];
    $report['resource_condition_label'] = resource_condition_labels()[$report['resources_condition']] ?? $report['resources_condition'];
    $report['venue_condition_label']    = venue_condition_labels()[$report['venue_condition']] ?? $report['venue_condition'];
    $report['review_status_label']      = review_status_labels()[$report['review_status']] ?? $report['review_status'];

    return $report;
}

if (method() === 'GET') {

    // --- One full report (the review / view modal) ---
    if (!empty($_GET['id'])) {
        $report = load_report($pdo, int_id($_GET['id']));
        if (!$report) respond(false, null, 'Report not found.', 404);
        respond(true, $report);
    }

    // --- Every submitted report (the Reports page register) ---
    $days    = isset($_GET['days']) ? (int) $_GET['days'] : 0;
    $venueId = (int) ($_GET['venue_id'] ?? 0);

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

    $st = $pdo->prepare(
        "SELECT pr.id, pr.report_no, pr.attendees_count, pr.prepared_by, pr.created_at,
                pr.resources_condition, pr.venue_condition, pr.review_status,
                r.ref_no, r.event_name, r.venue_name, r.event_date, r.requested_by,
                (SELECT COUNT(*) FROM post_event_photos p WHERE p.report_id = pr.id) AS photo_count
           FROM post_event_reports pr
           JOIN reservations r ON r.id = pr.reservation_id
          WHERE $w
          ORDER BY pr.created_at DESC"
    );
    $st->execute($args);
    respond(true, ['rows' => $st->fetchAll()]);
}

if (method() === 'POST') {
    $in = body();
    $id = int_id($in['id'] ?? null);
    $action = $_GET['action'] ?? '';

    $st = $pdo->prepare(
        "SELECT pr.*, r.event_name, r.ref_no, r.student_id
           FROM post_event_reports pr
           JOIN reservations r ON r.id = pr.reservation_id
          WHERE pr.id = ?"
    );
    $st->execute([$id]);
    $report = $st->fetch();
    if (!$report) respond(false, null, 'Report not found.', 404);

    if ($report['review_status'] !== 'pending') {
        respond(false, null, 'This report has already been reviewed (' . review_status_labels()[$report['review_status']] . ').', 409);
    }

    if ($action === 'approve') {
        $pdo->prepare(
            "UPDATE post_event_reports
                SET review_status = 'approved', review_remarks = NULL,
                    reviewed_by = ?, reviewed_at = NOW()
              WHERE id = ?"
        )->execute([$admin['id'], $id]);

        notify_student($report['student_id'] ? (int) $report['student_id'] : null,
            "Your post-event report for \"{$report['event_name']}\" ({$report['ref_no']}) has been approved by OSAS. Your Activity Clearance (with e-signatures) is now ready to download from My Reservations.");

        respond(true, null, 'Report approved.');
    }

    if ($action === 'request_revision') {
        $remarks = trim((string) ($in['remarks'] ?? ''));
        if ($remarks === '') {
            respond(false, null, 'Please explain what needs to be changed before sending this back.', 422);
        }

        $pdo->prepare(
            "UPDATE post_event_reports
                SET review_status = 'needs_revision', review_remarks = ?,
                    reviewed_by = ?, reviewed_at = NOW()
              WHERE id = ?"
        )->execute([$remarks, $admin['id'], $id]);

        notify_student($report['student_id'] ? (int) $report['student_id'] : null,
            "OSAS has requested changes to your post-event report for \"{$report['event_name']}\" ({$report['ref_no']}): \"$remarks\". Please revise and resubmit it.");

        respond(true, null, 'Sent back to the student for revision.');
    }

    respond(false, null, 'Unknown action', 404);
}

respond(false, null, 'Method not allowed', 405);
