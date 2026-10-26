<?php
/**
 * api/reservation_update.php  (POST, multipart/form-data)
 *
 * Lets a student EDIT one of their own reservations while it is still
 * "pending" (OSAS hasn't decided yet) or after OSAS "declined" it.
 * Same fields as reservation_create.php, plus `reservation_id`.
 *
 *  - Pictures are OPTIONAL here: the pictures already on file are kept.
 *    A new picture uploaded for a checklist item replaces the old picture
 *    with the same label; new labels are simply added.
 *  - Borrowed resources are re-checked against current stock, but nothing is
 *    deducted: stock is only taken out when the reservation is approved.
 *  - Saving sends the request back to OSAS: status becomes 'pending', the
 *    old decline reason and admin votes are cleared, so it is reviewed fresh.
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

function ru_fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$EDITABLE = ['pending', 'declined'];

$resId     = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
$eventName = trim($_POST['event'] ?? '');
$venueName = trim($_POST['venue'] ?? '');
$date      = trim($_POST['date'] ?? '');
$start     = trim($_POST['start'] ?? '');
$end       = trim($_POST['end'] ?? '');
$purpose   = trim($_POST['purpose'] ?? '');

$scope         = trim($_POST['scope'] ?? '');
$campusInput   = mb_substr(trim($_POST['campus'] ?? ''), 0, 100);
$natureInput   = $_POST['nature'] ?? '[]';
$natureOther   = mb_substr(trim($_POST['nature_other'] ?? ''), 0, 150);
$participants  = filter_var($_POST['participants'] ?? null, FILTER_VALIDATE_INT);
$sponsoringOrg = mb_substr(trim($_POST['sponsoring_org'] ?? ''), 0, 150);
$sourceOfFund  = mb_substr(trim($_POST['source_of_fund'] ?? ''), 0, 150);
$adviserName   = mb_substr(trim($_POST['adviser_name'] ?? ''), 0, 150);
$recommending  = mb_substr(trim($_POST['recommending_name'] ?? ''), 0, 150);
require_once __DIR__ . '/../../shared/form_signatures.php';
$adviserSig    = fs_clean($_POST['adviser_signature'] ?? '');
$recSig        = fs_clean($_POST['recommending_signature'] ?? '');
$remarks       = mb_substr(trim($_POST['remarks'] ?? ''), 0, 255);
$checklistRaw  = $_POST['attachments_checklist'] ?? '[]';

$allowedNature = ['Seminar / Convention','Educational Tour','Immersion','Community Extension','Academic Requirements','Others'];
$allowedChecklist = ['List of Students','Project Proposal','Budget Proposal',"Instructor's Acceptance of Responsibility",'Proposed Program / Invitation','Parent Consent'];
$natureList = array_values(array_intersect($allowedNature, (array) (json_decode($natureInput, true) ?: [])));
$checklist  = array_values(array_intersect($allowedChecklist, (array) (json_decode($checklistRaw, true) ?: [])));

$resourcesInput = json_decode($_POST['resources'] ?? '[]', true);
if (!is_array($resourcesInput)) $resourcesInput = [];
$labels = json_decode($_POST['requirement_labels'] ?? '[]', true);
if (!is_array($labels)) $labels = [];

if (!$resId) ru_fail(422, 'Missing reservation.');
if (!$adviserSig || !$recSig || $adviserName === '' || $recommending === '') ru_fail(422, 'Please add the signature and printed name for both the Club / Organization / Adviser and the Recommending Approval.');
if ($eventName === '' || $venueName === '' || $date === '' || $start === '' || $end === '') {
    ru_fail(422, 'Please fill in all required fields.');
}
if (!in_array($scope, ['on','off'], true)) ru_fail(422, 'Please tick whether the activity is On-Campus or Off-Campus.');
if (count($natureList) !== 1) ru_fail(422, 'Please check only one Nature of Activity.');
if (in_array('Others', $natureList, true) && $natureOther === '') ru_fail(422, 'Please specify the "Others" nature of activity.');
if (!in_array($participants, [100,200,300,400,500,600,700,800,900,1000], true)) {
    ru_fail(422, 'Please choose the number of participants from the list (100 to 1000).');
}
if ($sponsoringOrg === '' || $sourceOfFund === '' || $purpose === '') {
    ru_fail(422, 'Please fill in the Sponsoring Organization / Class, Source of Fund and Objectives of the Activity.');
}
$windowError = sched_validate_window($date, $start, $end);
if ($windowError !== null) ru_fail(422, $windowError);
$start = sched_norm_time($start);
$end   = sched_norm_time($end);

$pdo    = get_db();
$userId = (int) $_SESSION['user_id'];

// Quick ownership/status check before touching any files.
$chk = $pdo->prepare('SELECT id, status FROM reservations WHERE id = ? AND student_id = ?');
$chk->execute([$resId, $userId]);
$current = $chk->fetch();
if (!$current) ru_fail(404, 'Reservation not found.');
if (!in_array($current['status'], $EDITABLE, true)) {
    ru_fail(409, 'Only pending or declined reservations can be edited (this one is ' . $current['status'] . ').');
}

// Optional new pictures (images only, one per checklist item).
$storedFiles = [];
$incoming = normalize_files($_FILES['requirements'] ?? null);
if ($incoming) {
    try {
        $storedFiles = store_uploads($incoming, UPLOAD_BUCKET_REQUIREMENTS, [
            'images_only' => true, 'min' => 1, 'max' => count($allowedChecklist),
        ]);
    } catch (RuntimeException $e) {
        ru_fail(422, $e->getMessage());
    } catch (Throwable $e) {
        ru_fail(500, 'Your picture could not be saved. Please try again.');
    }
    foreach ($storedFiles as $i => $ignored) {
        $storedFiles[$i]['label'] = mb_substr(trim((string) ($labels[$i] ?? '')), 0, 120);
    }
} elseif ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST)) {
    ru_fail(413, 'Your picture is too large for this server. Please upload a smaller one.');
}
$cleanupNew = function () use ($storedFiles) { delete_uploads($storedFiles, UPLOAD_BUCKET_REQUIREMENTS); };

$venueStmt = $pdo->prepare('SELECT id, campus, status FROM venues WHERE name = ? LIMIT 1');
$venueStmt->execute([$venueName]);
$venue = $venueStmt->fetch();
if ($venue && $venue['status'] === 'maintenance') {
    $cleanupNew();
    ru_fail(422, 'That venue is currently under maintenance and cannot be booked.');
}
$venueId = $venue ? (int) $venue['id'] : null;

$failConflict = function (array $conflicts) use ($pdo, $venueId, $venueName, $date, $start, $end, $userId, $cleanupNew) {
    $cleanupNew();
    http_response_code(409);
    echo json_encode([
        'success'     => false,
        'conflict'    => true,
        'error'       => 'That venue is already booked for an overlapping time (' . sched_conflict_text($conflicts)
                         . '). Please pick another time or venue.',
        'conflicts'   => sched_public_conflicts($conflicts, $userId),
        'suggestions' => sched_suggest($pdo, [
            'venue_id' => $venueId, 'venue_name' => $venueName,
            'date' => $date, 'start' => $start, 'end' => $end, 'other_venues' => false, 'later_only' => true,
        ]),
    ]);
    exit;
};

$oldFilesToDelete = [];
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND student_id = ? FOR UPDATE');
    $st->execute([$resId, $userId]);
    $res = $st->fetch();
    if (!$res || !in_array($res['status'], $EDITABLE, true)) {
        throw new RuntimeException('This reservation can no longer be edited. Please refresh the page.');
    }
    $wasDeclined = $res['status'] === 'declined';

    sched_lock_venue($pdo, $venueId);
    $conflicts = sched_find_conflicts($pdo, $venueId, $venueName, $date, $start, $end, (int) $resId);
    if ($conflicts) throw new SchedConflictException($conflicts);

    // --- Resources: pending/declined reservations hold no stock, so just replace the list. ---
    $pdo->prepare('DELETE FROM reservation_resources WHERE reservation_id = ?')->execute([$resId]);

    $insertRes = $pdo->prepare('INSERT INTO reservation_resources (reservation_id, resource_id, resource_name, quantity) VALUES (?, ?, ?, ?)');
    $lockRes   = $pdo->prepare('SELECT id, name, stock FROM resources WHERE id = ? FOR UPDATE');
    foreach ($resourcesInput as $item) {
        $rid = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
        $qty = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$rid || !$qty || $qty < 1) continue;
        $lockRes->execute([$rid]);
        $resource = $lockRes->fetch();
        if (!$resource) throw new RuntimeException('One of the selected resources no longer exists. Please refresh and try again.');
        if ($qty > (int) $resource['stock']) {
            throw new RuntimeException("Only {$resource['stock']} \"{$resource['name']}\" left in stock — please lower the quantity and try again.");
        }
        $insertRes->execute([$resId, (int) $resource['id'], $resource['name'], $qty]);
    }

    // --- Pictures: a new picture replaces the old one with the same label. ---
    if ($storedFiles) {
        $newLabels = array_values(array_filter(array_column($storedFiles, 'label'), fn($l) => $l !== ''));
        if ($newLabels) {
            $in = implode(',', array_fill(0, count($newLabels), '?'));
            $q = $pdo->prepare("SELECT id, stored_name FROM reservation_attachments WHERE reservation_id = ? AND label IN ($in)");
            $q->execute(array_merge([$resId], $newLabels));
            $oldFilesToDelete = $q->fetchAll();
            if ($oldFilesToDelete) {
                $ids = array_column($oldFilesToDelete, 'id');
                $pdo->prepare('DELETE FROM reservation_attachments WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
            }
        }
        $insertFile = $pdo->prepare(
            'INSERT INTO reservation_attachments (reservation_id, kind, label, original_name, stored_name, mime_type, size_bytes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($storedFiles as $f) {
            $insertFile->execute([$resId, $f['kind'], $f['label'], $f['original_name'], $f['stored_name'], $f['mime_type'], $f['size_bytes']]);
        }
    }

    // A reservation must still have at least one picture.
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM reservation_attachments WHERE reservation_id = ?');
    $cnt->execute([$resId]);
    if ((int) $cnt->fetchColumn() === 0) {
        throw new RuntimeException('Please upload a picture. A reservation needs at least one.');
    }

    if (fs_ensure_columns($pdo)) {
        $pdo->prepare('UPDATE reservations SET adviser_signature = ?, recommending_signature = ? WHERE id = ?')
            ->execute([$adviserSig, $recSig, $resId]);
    }

    // --- Save the form and send it back to OSAS for a fresh review. ---
    $pdo->prepare(
        "UPDATE reservations SET
            event_name = :event_name, venue_id = :venue_id, venue_name = :venue_name, campus = :campus,
            activity_scope = :scope, nature_of_activity = :nature, nature_other = :nature_other,
            event_date = :event_date, start_time = :start_time, end_time = :end_time,
            participants = :participants, sponsoring_org = :sponsoring_org, source_of_fund = :source_of_fund,
            purpose = :purpose, adviser_name = :adviser, recommending_name = :recommending, remarks = :remarks,
            attachments_checklist = :checklist,
            status = 'pending', decline_reason = NULL,
            approver1_status = 'pending', approver1_date = NULL, approver1_notation = NULL,
            approver2_status = 'pending', approver2_date = NULL, approver2_notation = NULL
         WHERE id = :id"
    )->execute([
        ':event_name'   => $eventName,
        ':venue_id'     => $venueId,
        ':venue_name'   => $venueName,
        ':campus'       => $campusInput !== '' ? $campusInput : ($venue['campus'] ?? 'Main Campus'),
        ':scope'        => $scope,
        ':nature'       => implode(', ', $natureList),
        ':nature_other' => in_array('Others', $natureList, true) ? $natureOther : null,
        ':event_date'   => $date,
        ':start_time'   => $start,
        ':end_time'     => $end,
        ':participants' => $participants,
        ':sponsoring_org' => $sponsoringOrg,
        ':source_of_fund' => $sourceOfFund,
        ':purpose'      => $purpose,
        ':adviser'      => $adviserName !== '' ? $adviserName : null,
        ':recommending' => $recommending !== '' ? $recommending : null,
        ':remarks'      => $remarks !== '' ? $remarks : null,
        ':checklist'    => $checklist ? implode(', ', $checklist) : null,
        ':id'           => $resId,
    ]);

    // Old admin votes no longer apply to the edited request.
    $pdo->prepare('DELETE FROM reservation_votes WHERE reservation_id = ?')->execute([$resId]);

    // Any open reschedule request is replaced by this edit.
    try {
        $pdo->prepare("UPDATE reservation_reschedule_requests SET status = 'withdrawn' WHERE reservation_id = ? AND status IN ('pending','suggested')")
            ->execute([$resId]);
    } catch (Throwable $ignored) { /* table may not exist before the migration */ }

    notify_student($pdo, $userId, $wasDeclined
        ? "Your reservation \"{$eventName}\" ({$res['ref_no']}) was updated and resubmitted. It is pending approval again (Administration and Academic Affairs first, then OSAS)."
        : "Your reservation \"{$eventName}\" ({$res['ref_no']}) was updated and is still pending approval.");

    $pdo->commit();
} catch (SchedConflictException $e) {
    $pdo->rollBack();
    $failConflict($e->conflicts);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    $cleanupNew();
    ru_fail(422, $e->getMessage());
} catch (Throwable $e) {
    $pdo->rollBack();
    $cleanupNew();
    ru_fail(500, 'Something went wrong while saving your changes. Please try again.');
}

// Old pictures that were replaced: remove their bytes only after the commit.
if ($oldFilesToDelete) delete_uploads($oldFilesToDelete, UPLOAD_BUCKET_REQUIREMENTS);

echo json_encode(['success' => true, 'ref_no' => $res['ref_no'], 'resubmitted' => $wasDeclined]);
