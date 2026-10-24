<?php
/**
 * api/post_event.php
 *
 *   GET  ?reservation_id=12   → what the submit/resubmit form needs: the
 *                               event's details, any resources borrowed, and
 *                               the student's own report if one already
 *                               exists (so a resubmission can be pre-filled).
 *   POST (multipart/form-data) → submit a new report, or resubmit one that
 *                               OSAS sent back for revision.
 *
 * Everything here is scoped to reservations owned by the logged-in student —
 * there's no id-based lookup that isn't joined through `student_id = ?`, so
 * one student can never see or touch another's report.
 */
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in again.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../shared/events.php';
require_once __DIR__ . '/../../shared/uploads.php';

$pdo = get_db();
$userId = (int) $_SESSION['user_id'];

/** Sends a JSON error and stops. */
function fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

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

/* ======================================================================
   GET — data for the submit / resubmit form
   ====================================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $reservationId = filter_var($_GET['reservation_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$reservationId) fail(422, 'Invalid reservation.');

    $st = $pdo->prepare(
        "SELECT id, ref_no, event_name, venue_name, campus, event_date,
                start_time, end_time, purpose, status
           FROM reservations
          WHERE id = ? AND student_id = ?"
    );
    $st->execute([$reservationId, $userId]);
    $reservation = $st->fetch();
    if (!$reservation) fail(404, 'Reservation not found.');

    $rs = $pdo->prepare('SELECT resource_name, quantity FROM reservation_resources WHERE reservation_id = ? ORDER BY resource_name');
    $rs->execute([$reservationId]);

    $rp = $pdo->prepare('SELECT * FROM post_event_reports WHERE reservation_id = ?');
    $rp->execute([$reservationId]);
    $existing = $rp->fetch();

    $existingPayload = null;
    if ($existing) {
        $ph = $pdo->prepare('SELECT * FROM post_event_photos WHERE report_id = ? ORDER BY id');
        $ph->execute([(int) $existing['id']]);

        $existingPayload = [
            'id'                  => (int) $existing['id'],
            'report_no'           => $existing['report_no'],
            'attendees_count'     => (int) $existing['attendees_count'],
            'summary'             => $existing['summary'],
            'issues'              => $existing['issues'],
            'recommendations'     => $existing['recommendations'],
            'resources_condition' => $existing['resources_condition'],
            'venue_condition'     => $existing['venue_condition'],
            'prepared_by'         => $existing['prepared_by'],
            'review_status'       => $existing['review_status'],
            'review_remarks'      => $existing['review_remarks'],
            'photos'              => array_map('photo_payload', $ph->fetchAll()),
        ];
    }

    echo json_encode(['success' => true, 'data' => [
        'reservation'         => $reservation,
        'resources'           => $rs->fetchAll(),
        'report'              => $existingPayload,
        'resource_conditions' => resource_condition_labels(),
        'venue_conditions'    => venue_condition_labels(),
    ]]);
    exit;
}

/* ======================================================================
   POST — submit or resubmit
   ====================================================================== */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed.');
}

$reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
if (!$reservationId) fail(422, 'Invalid reservation.');

$st = $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND student_id = ?');
$st->execute([$reservationId, $userId]);
$reservation = $st->fetch();
if (!$reservation) fail(404, 'Reservation not found.');

if ($reservation['status'] !== 'completed') {
    fail(422, 'You can only submit a post-event report once the event has finished.');
}

$rp = $pdo->prepare('SELECT * FROM post_event_reports WHERE reservation_id = ?');
$rp->execute([$reservationId]);
$existing = $rp->fetch();

if ($existing && $existing['review_status'] === 'approved') {
    fail(409, 'This report has already been approved by OSAS and can no longer be edited.');
}
if ($existing && $existing['review_status'] === 'pending') {
    fail(409, 'This report is already awaiting review. Please wait for OSAS to respond before resubmitting.');
}
// The only way to reach here with $existing set is review_status === 'needs_revision'.

$summary    = trim((string) ($_POST['summary'] ?? ''));
$preparedBy = trim((string) ($_POST['prepared_by'] ?? ''));
$attendees  = filter_var($_POST['attendees_count'] ?? null, FILTER_VALIDATE_INT);
$issues     = trim((string) ($_POST['issues'] ?? ''));
$recommend  = trim((string) ($_POST['recommendations'] ?? ''));
$resCond    = (string) ($_POST['resources_condition'] ?? 'all_returned');
$venueCond  = (string) ($_POST['venue_condition'] ?? 'good');

if ($summary === '')    fail(422, 'Please write a short summary of how the event went.');
if ($preparedBy === '') fail(422, 'Please fill in who prepared this report.');
if ($attendees === false || $attendees < 0) {
    fail(422, 'Attendance must be a whole number (use 0 if it was not counted).');
}
if (!isset(resource_condition_labels()[$resCond])) $resCond = 'all_returned';
if (!isset(venue_condition_labels()[$venueCond]))  $venueCond = 'good';

// --- Event photos. Required only on a first-ever submission for this
// reservation; a resubmission may add more but doesn't have to re-upload
// what's already on file, since the earlier photos are simply kept. ---
$incoming = normalize_files($_FILES['photos'] ?? null);
$existingPhotoCount = 0;
if ($existing) {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM post_event_photos WHERE report_id = ?');
    $countStmt->execute([(int) $existing['id']]);
    $existingPhotoCount = (int) $countStmt->fetchColumn();
}

if (!$incoming && $existingPhotoCount === 0) {
    fail(422, 'Please attach at least one picture of the event.');
}
if (count($incoming) + $existingPhotoCount > UPLOAD_MAX_FILES) {
    fail(422, 'You can have at most ' . UPLOAD_MAX_FILES . ' photos on one report. Remove some before adding more.');
}

$storedPhotos = [];
if ($incoming) {
    try {
        $storedPhotos = store_uploads($incoming, UPLOAD_BUCKET_POST_EVENTS, [
            'images_only' => true,
            'min'         => 0,
            'max'         => UPLOAD_MAX_FILES,
        ]);
    } catch (RuntimeException $e) {
        fail(422, $e->getMessage());
    }
}

$captions = [];
if (!empty($_POST['captions'])) {
    $decoded = json_decode((string) $_POST['captions'], true);
    if (is_array($decoded)) $captions = $decoded;
}

$cleanupNewPhotos = function () use ($storedPhotos) {
    delete_uploads($storedPhotos, UPLOAD_BUCKET_POST_EVENTS);
};

$pdo->beginTransaction();
try {
    if ($existing) {
        // Resubmission: update the write-up in place, clear the previous
        // remarks, and drop it back into the review queue. The report's
        // report_no and id stay the same — it's the same report, corrected.
        $reportId = (int) $existing['id'];
        $reportNo = $existing['report_no'];

        $pdo->prepare(
            "UPDATE post_event_reports SET
                attendees_count = ?, summary = ?, issues = ?, recommendations = ?,
                resources_condition = ?, venue_condition = ?, prepared_by = ?,
                review_status = 'pending', review_remarks = NULL,
                reviewed_by = NULL, reviewed_at = NULL
             WHERE id = ?"
        )->execute([
            $attendees, $summary,
            $issues !== '' ? $issues : null,
            $recommend !== '' ? $recommend : null,
            $resCond, $venueCond, $preparedBy, $reportId,
        ]);
    } else {
        $reportNo = generate_report_no($pdo);
        $pdo->prepare(
            "INSERT INTO post_event_reports
                (report_no, reservation_id, attendees_count, summary, issues, recommendations,
                 resources_condition, venue_condition, prepared_by, review_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([
            $reportNo, $reservationId, $attendees, $summary,
            $issues !== '' ? $issues : null,
            $recommend !== '' ? $recommend : null,
            $resCond, $venueCond, $preparedBy,
        ]);
        $reportId = (int) $pdo->lastInsertId();
    }

    if ($storedPhotos) {
        $insPhoto = $pdo->prepare(
            'INSERT INTO post_event_photos (report_id, caption, original_name, stored_name, mime_type, size_bytes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($storedPhotos as $i => $p) {
            $insPhoto->execute([
                $reportId,
                mb_substr(trim((string) ($captions[$i] ?? '')), 0, 160),
                $p['original_name'], $p['stored_name'], $p['mime_type'], $p['size_bytes'],
            ]);
        }
    }

    notify_student($pdo, $userId, $existing
        ? "Your revised post-event report for \"{$reservation['event_name']}\" ({$reservation['ref_no']}) has been resubmitted and is awaiting review."
        : "Your post-event report for \"{$reservation['event_name']}\" ({$reservation['ref_no']}) has been submitted as $reportNo and is awaiting OSAS review."
    );

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $cleanupNewPhotos();
    fail(500, 'Something went wrong while saving your report. Please try again.');
}

echo json_encode(['success' => true, 'id' => $reportId, 'report_no' => $reportNo]);
