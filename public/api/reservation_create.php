<?php
/**
 * api/reservation_create.php
 * Handles the "New Reservation" form submit from student.php.
 *
 * The form now posts as multipart/form-data because a reservation can't be
 * sent without at least one requirement file attached (letter of request,
 * concept paper, layout, pictures...). Those files are validated and moved
 * into public/uploads/requirements/ BEFORE the DB transaction opens, and are
 * deleted again if that transaction later rolls back — so the disk and the
 * database never drift apart.
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
require_once __DIR__ . '/../../shared/uploads.php';
require_once __DIR__ . '/../../shared/scheduling.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

/** Reads php.ini's post_max_size as a plain byte count. */
function post_max_size_bytes(): int {
    $value = trim((string) ini_get('post_max_size'));
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $num  = (int) $value;
    if ($unit === 'g') return $num * 1073741824;
    if ($unit === 'm') return $num * 1048576;
    if ($unit === 'k') return $num * 1024;
    return $num;
}

/** Sends a JSON error and stops. */
function fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$eventName = trim($_POST['event'] ?? '');
$venueName = trim($_POST['venue'] ?? '');
$date      = trim($_POST['date'] ?? '');
$start     = trim($_POST['start'] ?? '');
$end       = trim($_POST['end'] ?? '');
$purpose   = trim($_POST['purpose'] ?? '');   // "Objectives of the Activity"

// --- Fields of the OSAS "Permission to Conduct Activity / Program" form ---
$scope         = trim($_POST['scope'] ?? '');            // 'on' | 'off'
$campusInput   = mb_substr(trim($_POST['campus'] ?? ''), 0, 100);
$natureInput   = $_POST['nature'] ?? '[]';
$natureOther   = mb_substr(trim($_POST['nature_other'] ?? ''), 0, 150);
$participants  = filter_var($_POST['participants'] ?? null, FILTER_VALIDATE_INT);
$sponsoringOrg = mb_substr(trim($_POST['sponsoring_org'] ?? ''), 0, 150);
$sourceOfFund  = mb_substr(trim($_POST['source_of_fund'] ?? ''), 0, 150);
$adviserName   = mb_substr(trim($_POST['adviser_name'] ?? ''), 0, 150);
$recommending  = mb_substr(trim($_POST['recommending_name'] ?? ''), 0, 150);
$remarks       = mb_substr(trim($_POST['remarks'] ?? ''), 0, 255);
$checklistRaw  = $_POST['attachments_checklist'] ?? '[]';

$allowedNature = ['Seminar / Convention','Educational Tour','Immersion','Community Extension','Academic Requirements','Others'];
$allowedChecklist = ['List of Students','Project Proposal','Budget Proposal',"Instructor's Acceptance of Responsibility",'Proposed Program / Invitation','Parent Consent'];

$natureList = array_values(array_intersect($allowedNature, (array) (json_decode($natureInput, true) ?: [])));
$checklist  = array_values(array_intersect($allowedChecklist, (array) (json_decode($checklistRaw, true) ?: [])));

// Resources the student chose to borrow, e.g. [{"id":"1","quantity":20}, ...].
// Sent as a JSON string alongside the rest of the form fields.
$resourcesInput = [];
$rawResources = $_POST['resources'] ?? '';
if ($rawResources !== '') {
    $decoded = json_decode($rawResources, true);
    if (is_array($decoded)) $resourcesInput = $decoded;
}

// Optional per-file labels in the same order as the files themselves,
// e.g. ["Letter of Request", "Concept Paper", ""].
$labels = [];
$rawLabels = $_POST['requirement_labels'] ?? '';
if ($rawLabels !== '') {
    $decodedLabels = json_decode($rawLabels, true);
    if (is_array($decodedLabels)) $labels = $decodedLabels;
}

if ($eventName === '' || $venueName === '' || $date === '' || $start === '' || $end === '') {
    fail(422, 'Please fill in all required fields.');
}

if (!in_array($scope, ['on','off'], true)) {
    fail(422, 'Please tick whether the activity is On-Campus or Off-Campus.');
}
if (count($natureList) !== 1) {
    fail(422, 'Please check only one Nature of Activity.');
}
if (in_array('Others', $natureList, true) && $natureOther === '') {
    fail(422, 'Please specify the "Others" nature of activity.');
}
if (!in_array($participants, [100,200,300,400,500,600,700,800,900,1000], true)) {
    fail(422, 'Please choose the number of participants from the list (100 to 1000).');
}
if ($sponsoringOrg === '' || $sourceOfFund === '' || $purpose === '') {
    fail(422, 'Please fill in the Sponsoring Organization / Class, Source of Fund and Objectives of the Activity.');
}

// Date/time sanity (valid date, not in the past — campus-local time —, end after start).
$windowError = sched_validate_window($date, $start, $end);
if ($windowError !== null) {
    fail(422, $windowError);
}
$start = sched_norm_time($start);
$end   = sched_norm_time($end);

// --- Requirement files: mandatory. A request with nothing attached can't be
// evaluated by OSAS, so it's turned away here before anything is written. ---
$incoming = normalize_files($_FILES['requirements'] ?? null);

if (!$incoming) {
    // Nothing arrived: either no picture was chosen, or the body blew past
    // post_max_size (which PHP discards silently).
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = post_max_size_bytes();
    if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
        fail(413, 'Your picture is too large for this server (limit: ' . format_bytes($postMax) . '). Please upload a smaller one.');
    }
    fail(422, 'Please upload a picture. You cannot submit the request without one.');
}

$storedFiles = [];
try {
    // Exactly one picture, images only.
    $storedFiles = store_uploads($incoming, UPLOAD_BUCKET_REQUIREMENTS, [
        'images_only' => true,
        'min' => 1,
        'max' => 1,
    ]);
} catch (RuntimeException $e) {
    fail(422, $e->getMessage());
} catch (Throwable $e) {
    fail(500, 'Your picture could not be saved. Please try again.');
}

// Pair each stored file with whatever label the student typed for it.
foreach ($storedFiles as $i => $ignored) {
    $storedFiles[$i]['label'] = mb_substr(trim((string) ($labels[$i] ?? '')), 0, 120);
}

$pdo = get_db();
$userId = $_SESSION['user_id'];

/** Everything below here can fail — make sure the files we just wrote go with it. */
$cleanupFiles = function () use ($storedFiles) {
    delete_uploads($storedFiles, UPLOAD_BUCKET_REQUIREMENTS);
};

$userStmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$user = $userStmt->fetch();
if (!$user) {
    $cleanupFiles();
    fail(401, 'Please sign in again.');
}
$requestedBy = trim($user['first_name'] . ' ' . $user['last_name']);

// Look up the venue row (for campus + venue_id); fall back gracefully
// if the venue isn't in the venues table yet.
$venueStmt = $pdo->prepare('SELECT id, campus, status FROM venues WHERE name = ? LIMIT 1');
$venueStmt->execute([$venueName]);
$venue = $venueStmt->fetch();

if ($venue && $venue['status'] === 'maintenance') {
    $cleanupFiles();
    fail(422, 'That venue is currently under maintenance and cannot be booked.');
}

/** 409 with the overlapping times and automatic suggestions of free ones. */
$failConflict = function (array $conflicts) use ($pdo, $venue, $venueName, $date, $start, $end, $userId, $cleanupFiles) {
    $cleanupFiles();
    $suggestions = sched_suggest($pdo, [
        'venue_id' => $venue['id'] ?? null, 'venue_name' => $venueName,
        'date' => $date, 'start' => $start, 'end' => $end, 'other_venues' => false, 'later_only' => true,
    ]);
    http_response_code(409);
    echo json_encode([
        'success'     => false,
        'conflict'    => true,
        'error'       => 'That venue is already booked for an overlapping time (' . sched_conflict_text($conflicts)
                         . '). Please pick another time or venue — a few free times are suggested below.',
        'conflicts'   => sched_public_conflicts($conflicts, (int) $userId),
        'suggestions' => $suggestions,
    ]);
    exit;
};

// Quick early check (the authoritative one repeats inside the transaction below).
$earlyConflicts = sched_find_conflicts($pdo, $venue ? (int) $venue['id'] : null, $venueName, $date, $start, $end);
if ($earlyConflicts) {
    $failConflict($earlyConflicts);
}

$refNo = generate_ref_no($pdo);

$pdo->beginTransaction();
try {
    // Serialise bookings for this venue, then re-check for overlaps. Without
    // this, two students submitting the same slot at the same moment could
    // both pass the early check above.
    sched_lock_venue($pdo, $venue ? (int) $venue['id'] : null);
    $lockedConflicts = sched_find_conflicts($pdo, $venue ? (int) $venue['id'] : null, $venueName, $date, $start, $end);
    if ($lockedConflicts) {
        throw new SchedConflictException($lockedConflicts);
    }

    // Re-check every requested resource's stock here (never trust the
    // quantities the browser sent) and lock the rows so two students
    // submitting at the same moment can't both over-borrow the same item.
    $resourcesToInsert = []; // [id, name, quantity]
    foreach ($resourcesInput as $item) {
        $resourceId = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
        $quantity   = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$resourceId || !$quantity || $quantity < 1) continue;

        $lockStmt = $pdo->prepare('SELECT id, name, stock FROM resources WHERE id = ? FOR UPDATE');
        $lockStmt->execute([$resourceId]);
        $resource = $lockStmt->fetch();

        if (!$resource) {
            throw new RuntimeException('One of the selected resources no longer exists. Please refresh and try again.');
        }
        if ($quantity > (int) $resource['stock']) {
            throw new RuntimeException("Only {$resource['stock']} \"{$resource['name']}\" left in stock — please lower the quantity and try again.");
        }

        $resourcesToInsert[] = [
            'id'       => (int) $resource['id'],
            'name'     => $resource['name'],
            'quantity' => $quantity,
        ];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO reservations
            (ref_no, event_name, venue_id, venue_name, campus, activity_scope, date_prepared, nature_of_activity, nature_other,
             event_date, start_time, end_time, participants, sponsoring_org, source_of_fund, purpose,
             adviser_name, recommending_name, remarks, attachments_checklist, requested_by, student_id, status)
         VALUES
            (:ref_no, :event_name, :venue_id, :venue_name, :campus, :scope, CURDATE(), :nature, :nature_other,
             :event_date, :start_time, :end_time, :participants, :sponsoring_org, :source_of_fund, :purpose,
             :adviser, :recommending, :remarks, :checklist, :requested_by, :student_id, 'pending')"
    );
    $stmt->execute([
        ':ref_no'       => $refNo,
        ':event_name'   => $eventName,
        ':venue_id'     => $venue['id'] ?? null,
        ':venue_name'   => $venueName,
        ':campus'       => $campusInput !== '' ? $campusInput : ($venue['campus'] ?? 'Main Campus'),
        ':scope'        => $scope,
        ':nature'       => implode(', ', $natureList),
        ':nature_other' => in_array('Others', $natureList, true) ? $natureOther : null,
        ':participants' => $participants,
        ':sponsoring_org' => $sponsoringOrg,
        ':source_of_fund' => $sourceOfFund,
        ':adviser'      => $adviserName !== '' ? $adviserName : null,
        ':recommending' => $recommending !== '' ? $recommending : null,
        ':remarks'      => $remarks !== '' ? $remarks : null,
        ':checklist'    => $checklist ? implode(', ', $checklist) : null,
        ':event_date'   => $date,
        ':start_time'   => $start,
        ':end_time'     => $end,
        ':purpose'      => $purpose,
        ':requested_by' => $requestedBy,
        ':student_id'   => $userId,
    ]);
    $reservationId = (int) $pdo->lastInsertId();

    // Requirement files — metadata only; the bytes are already on disk.
    $insertFile = $pdo->prepare(
        'INSERT INTO reservation_attachments
            (reservation_id, kind, label, original_name, stored_name, mime_type, size_bytes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($storedFiles as $f) {
        $insertFile->execute([
            $reservationId, $f['kind'], $f['label'],
            $f['original_name'], $f['stored_name'], $f['mime_type'], $f['size_bytes'],
        ]);
    }

    if ($resourcesToInsert) {
        $insertRes = $pdo->prepare(
            'INSERT INTO reservation_resources (reservation_id, resource_id, resource_name, quantity) VALUES (?, ?, ?, ?)'
        );
        $deductStmt = $pdo->prepare('UPDATE resources SET stock = stock - ? WHERE id = ? AND stock >= ?');
        foreach ($resourcesToInsert as $r) {
            $insertRes->execute([$reservationId, $r['id'], $r['name'], $r['quantity']]);
            $deductStmt->execute([$r['quantity'], $r['id'], $r['quantity']]);
            if ($deductStmt->rowCount() === 0) {
                // Someone else grabbed the remaining stock between our lock and here — bail out.
                throw new RuntimeException("\"{$r['name']}\" is no longer available in the quantity you requested. Please try again.");
            }
        }
    }

    // Let the student know right away that their request went through — a
    // separate notification arrives later once OSAS decides on it (see
    // admin/api/reservations.php, which notifies on approve/decline/complete).
    $fileCount = count($storedFiles);
    $plural = $fileCount === 1 ? '' : 's';
    $withFiles = $fileCount ? " with {$fileCount} attachment{$plural}" : '';
    notify_student($pdo, $userId, "Your reservation request \"{$eventName}\" ({$refNo}) has been submitted{$withFiles} and is pending OSAS approval.");

    $pdo->commit();
} catch (SchedConflictException $e) {
    $pdo->rollBack();
    $failConflict($e->conflicts);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    $cleanupFiles();
    fail(422, $e->getMessage());
} catch (Throwable $e) {
    $pdo->rollBack();
    $cleanupFiles();
    fail(500, 'Something went wrong while saving your reservation. Please try again.');
}

echo json_encode(['success' => true, 'ref_no' => $refNo, 'files' => count($storedFiles)]);