<?php
// Reschedule requests sent by students.
//
// GET                          list  (?status=pending|suggested|approved|rejected|withdrawn, optional)
// GET  ?action=suggest&id=     automatic free-time suggestions for one request
// POST ?action=approve {id, note?}               move the reservation (original schedule kept in history)
// POST ?action=reject  {id, note}                keep the schedule; tell the student why
// POST ?action=suggest {id, date, start, end, note?}   offer a different available schedule
//
// An admin decision is always required — nothing is ever rescheduled automatically.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../shared/scheduling.php';
$admin = require_auth();
$pdo = db();

if (!sched_tables_ready($pdo)) {
    respond(false, null, 'Rescheduling is not set up yet. Import database/migration_reschedule_history.sql in phpMyAdmin.', 503);
}
auto_complete_due_reservations($pdo);

function who(array $admin): string {
    return ($admin['office'] ?? '') && $admin['office'] !== 'Admin' ? 'Vice ' . $admin['office'] : ($admin['display_name'] ?? 'Admin');
}

/** Request joined with its reservation, optionally locked. Responds 404 if missing. */
function load_request(PDO $pdo, int $id, bool $lock = false): array {
    $st = $pdo->prepare(
        'SELECT q.*, r.ref_no, r.event_name, r.venue_name, r.venue_id AS res_venue_id, r.status AS res_status,
                r.event_date AS cur_date, r.start_time AS cur_start, r.end_time AS cur_end, r.student_id AS res_student
           FROM reservation_reschedule_requests q
           JOIN reservations r ON r.id = q.reservation_id
          WHERE q.id = ?' . ($lock ? ' FOR UPDATE' : '')
    );
    $st->execute([$id]);
    $q = $st->fetch();
    if (!$q) respond(false, null, 'Reschedule request not found.', 404);
    return $q;
}

if (method() === 'GET') {
    if (($_GET['action'] ?? '') === 'suggest') {
        $q = load_request($pdo, int_id($_GET['id'] ?? null));
        $vid = $q['res_venue_id'] ? (int)$q['res_venue_id'] : null;
        // Ask for the same length as the requested window, around the requested start.
        $sug = sched_suggest($pdo, [
            'venue_id' => $vid, 'venue_name' => $q['venue_name'],
            'date' => $q['new_date'], 'start' => $q['new_start'], 'end' => $q['new_end'],
            'exclude_id' => (int)$q['reservation_id'], 'other_venues' => false,
        ], 6);
        respond(true, $sug);
    }

    $where = '';
    $args = [];
    if (!empty($_GET['status'])) { $where = 'WHERE q.status = ?'; $args[] = $_GET['status']; }
    $st = $pdo->prepare(
        "SELECT q.*, r.ref_no, r.event_name, r.requested_by, r.venue_name, r.venue_id AS res_venue_id,
                r.status AS res_status, r.event_date AS cur_date, r.start_time AS cur_start, r.end_time AS cur_end,
                a.display_name AS admin_name, a.office AS admin_office
           FROM reservation_reschedule_requests q
           JOIN reservations r ON r.id = q.reservation_id
           LEFT JOIN admins a ON a.id = q.admin_id
           $where
          ORDER BY FIELD(q.status,'pending','suggested','approved','rejected','withdrawn'), q.created_at DESC"
    );
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$q) {
        $q['conflicts'] = [];
        // For requests still waiting, check live whether the wanted time is free.
        if (in_array($q['status'], ['pending', 'suggested'], true)) {
            $c = sched_find_conflicts(
                $pdo, $q['res_venue_id'] ? (int)$q['res_venue_id'] : null, $q['venue_name'],
                $q['new_date'], $q['new_start'], $q['new_end'], (int)$q['reservation_id']
            );
            $q['conflicts'] = sched_public_conflicts($c);
            $q['conflict_text'] = $c ? sched_conflict_text($c) : '';
        }
        $q['admin_label'] = $q['admin_name']
            ? (($q['admin_office'] && $q['admin_office'] !== 'Admin') ? 'Vice ' . $q['admin_office'] : $q['admin_name'])
            : null;
    }
    unset($q);
    respond(true, $rows);
}

if (method() !== 'POST') respond(false, null, 'Method not allowed', 405);

$in = body();
$id = int_id($in['id'] ?? null);
$action = $_GET['action'] ?? '';
if (!in_array($action, ['approve', 'reject', 'suggest'], true)) respond(false, null, 'Unknown action', 404);
$note = mb_substr(trim((string)($in['note'] ?? '')), 0, 500);

$pdo->beginTransaction();
$q = load_request($pdo, $id, true);
if (!in_array($q['status'], ['pending', 'suggested'], true)) {
    $pdo->rollBack();
    respond(false, null, 'This request was already ' . $q['status'] . '.', 409);
}
$rs = $pdo->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
$rs->execute([$q['reservation_id']]);
$res = $rs->fetch();
$label = "\"{$res['event_name']}\" ({$res['ref_no']})";

// The reservation may have been cancelled / finished since the request was made.
if (!in_array($res['status'], ['pending', 'approved'], true) || ($res['event_date'] . ' ' . $res['end_time']) <= app_now()) {
    $pdo->prepare("UPDATE reservation_reschedule_requests SET status = 'withdrawn' WHERE id = ?")->execute([$id]);
    $pdo->commit();
    respond(false, null, 'That reservation is no longer open for rescheduling (cancelled or already finished). The request was closed.', 409);
}

$venueId = $res['venue_id'] ? (int)$res['venue_id'] : null;

if ($action === 'reject') {
    if ($note === '') { $pdo->rollBack(); respond(false, null, 'Please type the reason for rejecting this request. The student will see it.', 422); }
    $pdo->prepare("UPDATE reservation_reschedule_requests SET status='rejected', admin_id=?, admin_note=?, responded_at=? WHERE id=?")
        ->execute([$admin['id'], $note, app_now(), $id]);
    notify_student($res['student_id'] ? (int)$res['student_id'] : null,
        who($admin) . " rejected your reschedule request for {$label}. Reason: {$note} Your current schedule is unchanged.");
    $pdo->commit();
    respond(true, null, 'Request rejected. The student was notified.');
}

if ($action === 'approve') {
    $err = sched_validate_window($q['new_date'], $q['new_start'], $q['new_end']);
    if ($err !== null) { $pdo->rollBack(); respond(false, null, 'Cannot approve: ' . $err . ' Use "Suggest" to offer another time.', 422); }

    $vs = $pdo->prepare('SELECT status FROM venues WHERE id = ?');
    $vs->execute([$venueId]);
    if ($vs->fetchColumn() === 'maintenance') {
        $pdo->rollBack();
        respond(false, null, 'Cannot approve: that venue is under maintenance.', 409);
    }

    sched_lock_venue($pdo, $venueId);
    $conflicts = sched_find_conflicts($pdo, $venueId, $res['venue_name'], $q['new_date'], $q['new_start'], $q['new_end'], (int)$res['id']);
    if ($conflicts) {
        $pdo->rollBack();
        respond(false, null, 'Cannot approve: the requested time overlaps another booking (' . sched_conflict_text($conflicts)
            . '). Use "Suggest" to offer an available time, or reject the request.', 409);
    }

    sched_apply_reschedule($pdo, $res, $q['new_date'], $q['new_start'], $q['new_end'], $id, (int)$admin['id'], $q['reason']);
    $pdo->prepare("UPDATE reservation_reschedule_requests SET status='approved', admin_id=?, admin_note=?, responded_at=? WHERE id=?")
        ->execute([$admin['id'], $note !== '' ? $note : null, app_now(), $id]);
    notify_student($res['student_id'] ? (int)$res['student_id'] : null,
        who($admin) . " approved your reschedule request. {$label} is now on "
        . sched_describe($q['new_date'], $q['new_start'], $q['new_end']) . '. Your original schedule is kept in your history.');
    $pdo->commit();
    respond(true, null, 'Approved. The reservation was moved and the original schedule is kept in history.');
}

// action === 'suggest'
$d = trim((string)($in['date'] ?? ''));
$s = trim((string)($in['start'] ?? ''));
$e = trim((string)($in['end'] ?? ''));
$err = sched_validate_window($d, $s, $e);
if ($err !== null) { $pdo->rollBack(); respond(false, null, $err, 422); }
$s = sched_norm_time($s);
$e = sched_norm_time($e);

sched_lock_venue($pdo, $venueId);
$conflicts = sched_find_conflicts($pdo, $venueId, $res['venue_name'], $d, $s, $e, (int)$res['id']);
if ($conflicts) {
    $pdo->rollBack();
    respond(false, null, 'That suggested time is not available (' . sched_conflict_text($conflicts) . '). Pick a free slot.', 409);
}

$pdo->prepare("UPDATE reservation_reschedule_requests
                  SET status='suggested', admin_id=?, admin_note=?, suggested_date=?, suggested_start=?, suggested_end=?, responded_at=?
                WHERE id=?")
    ->execute([$admin['id'], $note !== '' ? $note : null, $d, $s, $e, app_now(), $id]);
notify_student($res['student_id'] ? (int)$res['student_id'] : null,
    who($admin) . " suggested a different schedule for {$label}: " . sched_describe($d, $s, $e)
    . '. Open Upcoming Events to accept it or decline. Your current schedule is unchanged until you accept.');
$pdo->commit();
respond(true, null, 'Suggestion sent to the student. Nothing changes until they accept it.');
