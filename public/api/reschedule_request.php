<?php
/**
 * api/reschedule_request.php  (POST, JSON body)
 *
 * The student's side of rescheduling. NOTHING here moves a reservation on
 * its own except accept_suggestion, which only works after an admin has
 * personally proposed that exact time.
 *
 *   action=create             {reservation_id, date, start, end, reason}
 *   action=withdraw           {request_id}
 *   action=accept_suggestion  {request_id}   (admin suggested a time; student agrees)
 *   action=decline_suggestion {request_id}
 */
session_start();
header('Content-Type: application/json');

function rs_out(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}
function rs_fail(int $status, string $message, array $extra = []): void {
    rs_out(array_merge(['success' => false, 'error' => $message], $extra), $status);
}

if (!isset($_SESSION['user_id'])) rs_fail(401, 'Please sign in again.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rs_fail(405, 'Method not allowed.');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../shared/scheduling.php';

$pdo    = get_db();
$userId = (int) $_SESSION['user_id'];

if (!sched_tables_ready($pdo)) {
    rs_fail(503, 'Rescheduling is not set up yet. Ask the administrator to import database/migration_reschedule_history.sql.');
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$action = (string) ($in['action'] ?? '');

/** Loads one of THIS student's reservations (ownership is part of the query). */
function rs_own_reservation(PDO $pdo, int $id, int $userId, bool $lock = false): array {
    $st = $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND student_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id, $userId]);
    $r = $st->fetch();
    if (!$r) rs_fail(404, 'Reservation not found.');
    return $r;
}

function rs_check_reschedulable(array $res): void {
    if (!in_array($res['status'], ['pending', 'approved'], true)) {
        rs_fail(422, 'Only pending or approved reservations can be rescheduled.');
    }
    if (($res['event_date'] . ' ' . $res['end_time']) <= app_now()) {
        rs_fail(422, 'This event has already finished, so it can no longer be rescheduled.');
    }
}

function rs_own_request(PDO $pdo, int $id, int $userId, bool $lock = false): array {
    $st = $pdo->prepare('SELECT * FROM reservation_reschedule_requests WHERE id = ? AND student_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id, $userId]);
    $r = $st->fetch();
    if (!$r) rs_fail(404, 'Reschedule request not found.');
    return $r;
}

/* ------------------------------------------------------------------ create */
if ($action === 'create') {
    $resId  = filter_var($in['reservation_id'] ?? null, FILTER_VALIDATE_INT);
    $date   = trim((string) ($in['date'] ?? ''));
    $start  = trim((string) ($in['start'] ?? ''));
    $end    = trim((string) ($in['end'] ?? ''));
    $reason = trim((string) ($in['reason'] ?? ''));
    if (!$resId) rs_fail(422, 'Missing reservation.');

    $err = sched_validate_window($date, $start, $end);
    if ($err !== null) rs_fail(422, $err);
    $start = sched_norm_time($start);
    $end   = sched_norm_time($end);
    if (mb_strlen($reason) < 5) rs_fail(422, 'Please tell us why you need to reschedule (a short sentence is enough).');
    $reason = mb_substr($reason, 0, 1000);

    $conflicts = [];
    $pdo->beginTransaction();
    try {
        $res = rs_own_reservation($pdo, (int) $resId, $userId, true);
        rs_check_reschedulable($res);

        if ($res['event_date'] === $date && $res['start_time'] === $start && $res['end_time'] === $end) {
            throw new RuntimeException('That is the schedule you already have. Pick a different date or time.');
        }

        $open = $pdo->prepare("SELECT 1 FROM reservation_reschedule_requests WHERE reservation_id = ? AND status IN ('pending','suggested') LIMIT 1");
        $open->execute([$res['id']]);
        if ($open->fetch()) {
            throw new RuntimeException('You already have an open reschedule request for this reservation. Withdraw it first if you want to send a new one.');
        }

        // Is the wanted time free right now? A conflict does NOT block the
        // request (the student may ask anyway); the admin can only approve
        // it if the time frees up, otherwise they reject or suggest another.
        $conflicts = sched_find_conflicts(
            $pdo, $res['venue_id'] ? (int) $res['venue_id'] : null, $res['venue_name'],
            $date, $start, $end, (int) $res['id']
        );

        $pdo->prepare(
            'INSERT INTO reservation_reschedule_requests
                (reservation_id, student_id, venue_id, orig_date, orig_start, orig_end,
                 new_date, new_start, new_end, reason, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\')'
        )->execute([
            $res['id'], $userId, $res['venue_id'],
            $res['event_date'], $res['start_time'], $res['end_time'],
            $date, $start, $end, $reason,
        ]);

        $label = "\"{$res['event_name']}\" ({$res['ref_no']})";
        $msg = "Your reschedule request for {$label} to " . sched_describe($date, $start, $end)
             . ' was sent to OSAS. Your current schedule stays the same until it is approved.';
        if ($conflicts) {
            $msg .= ' Note: that time is currently booked, so OSAS may suggest another schedule.';
        }
        notify_student($pdo, $userId, $msg);
        $pdo->commit();
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        rs_fail(422, $e->getMessage());
    }

    rs_out([
        'success'  => true,
        'conflict' => (bool) $conflicts,
        'message'  => $conflicts
            ? 'Request sent. That time is currently booked, so OSAS may suggest another schedule.'
            : 'Reschedule request sent. OSAS will approve, reject or suggest another time.',
    ]);
}

/* ---------------------------------------------------------------- withdraw */
if ($action === 'withdraw' || $action === 'decline_suggestion') {
    $reqId = filter_var($in['request_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$reqId) rs_fail(422, 'Missing request.');
    $pdo->beginTransaction();
    $rq = rs_own_request($pdo, (int) $reqId, $userId, true);
    $okStatuses = $action === 'withdraw' ? ['pending', 'suggested'] : ['suggested'];
    if (!in_array($rq['status'], $okStatuses, true)) {
        $pdo->rollBack();
        rs_fail(409, 'This request can no longer be changed (it is already ' . $rq['status'] . ').');
    }
    $pdo->prepare("UPDATE reservation_reschedule_requests SET status = 'withdrawn' WHERE id = ?")->execute([$rq['id']]);
    $pdo->commit();
    rs_out(['success' => true, 'message' => $action === 'withdraw' ? 'Reschedule request withdrawn.' : 'Suggestion declined. Your original schedule is unchanged.']);
}

/* ------------------------------------------------------- accept_suggestion */
if ($action === 'accept_suggestion') {
    $reqId = filter_var($in['request_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$reqId) rs_fail(422, 'Missing request.');

    $pdo->beginTransaction();
    try {
        $rq = rs_own_request($pdo, (int) $reqId, $userId, true);
        if ($rq['status'] !== 'suggested' || !$rq['suggested_date']) {
            throw new RuntimeException('There is no suggested schedule to accept on this request.');
        }
        $res = rs_own_reservation($pdo, (int) $rq['reservation_id'], $userId, true);
        rs_check_reschedulable($res);

        $d = $rq['suggested_date'];
        $s = $rq['suggested_start'];
        $e = $rq['suggested_end'];
        $err = sched_validate_window($d, $s, $e);
        if ($err !== null) throw new RuntimeException('The suggested time is no longer valid (' . $err . ') Please send a new request.');

        $venueId = $res['venue_id'] ? (int) $res['venue_id'] : null;
        sched_lock_venue($pdo, $venueId);
        $conflicts = sched_find_conflicts($pdo, $venueId, $res['venue_name'], $d, $s, $e, (int) $res['id']);
        if ($conflicts) throw new SchedConflictException($conflicts, 'Someone just booked the suggested time.');

        sched_apply_reschedule($pdo, $res, $d, $s, $e, (int) $rq['id'], $rq['admin_id'] ? (int) $rq['admin_id'] : null, $rq['reason']);
        $pdo->prepare("UPDATE reservation_reschedule_requests SET status = 'approved', responded_at = ? WHERE id = ?")
            ->execute([app_now(), $rq['id']]);

        notify_student($pdo, $userId,
            "Your reservation \"{$res['event_name']}\" ({$res['ref_no']}) has been rescheduled to "
            . sched_describe($d, $s, $e) . '. The original schedule is kept in your history.');
        $pdo->commit();
    } catch (SchedConflictException $e) {
        $pdo->rollBack();
        rs_fail(409, 'Someone just booked the suggested time. Please send a new request.', ['conflict' => true]);
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        rs_fail(422, $e->getMessage());
    }
    rs_out(['success' => true, 'message' => 'Reservation rescheduled. Your original schedule is kept in history.']);
}

rs_fail(404, 'Unknown action.');
