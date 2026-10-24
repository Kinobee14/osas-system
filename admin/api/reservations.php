<?php
// GET  ?status=pending|approved|declined|completed  (optional)
// GET  ?month=2026-08                               (calendar)
// POST ?action=approve|decline {id, reason}  cast YOUR one vote (to change it, Undo first, then vote again)
// POST ?action=undo {id}              take back YOUR vote (can reopen an approved/declined request until the event ends)
// POST ?action=complete {id}          mark an approved event as done early
// (rows also carry reschedule_count + orig_date/orig_start/orig_end once a reschedule was approved)
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../shared/events.php';
require_once __DIR__ . '/../../shared/scheduling.php';
require_once __DIR__ . '/../../shared/votes.php';
$admin = require_auth();
$pdo = db();

// Anything whose end time has already gone by becomes "completed" on its own —
// see shared/events.php. Running it here means the Approval Queue, the All
// Reservation table and the calendar are all looking at fresh statuses, and
// the green post-event button shows up the moment an event is genuinely over.
auto_complete_due_reservations($pdo);

/** Kept for the GET payload: approvals needed to approve (see shared/votes.php). */
function votes_needed(PDO $pdo): int { return approvals_needed($pdo); }

if (method() === 'GET') {
    $where = [];
    $args = [$admin['id']];
    if (!empty($_GET['status'])) {
        $where[] = 'r.status = ?';
        $args[] = $_GET['status'];
    }
    if (!empty($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) {
        $where[] = "DATE_FORMAT(r.event_date, '%Y-%m') = ?";
        $args[] = $_GET['month'];
    }
    // Schedule-history columns only exist after migration_reschedule_history.sql.
    $histSelect = '';
    if (sched_tables_ready($pdo)) {
        $histSelect = ",
                (SELECT COUNT(*) FROM reservation_schedule_history h WHERE h.reservation_id = r.id) AS reschedule_count,
                (SELECT h.old_date  FROM reservation_schedule_history h WHERE h.reservation_id = r.id ORDER BY h.id ASC LIMIT 1) AS orig_date,
                (SELECT h.old_start FROM reservation_schedule_history h WHERE h.reservation_id = r.id ORDER BY h.id ASC LIMIT 1) AS orig_start,
                (SELECT h.old_end   FROM reservation_schedule_history h WHERE h.reservation_id = r.id ORDER BY h.id ASC LIMIT 1) AS orig_end";
    }
    $sql = "SELECT r.*, v.name AS venue,
                (SELECT COUNT(*) FROM reservation_votes x WHERE x.reservation_id = r.id AND x.vote = 'approve') AS approve_count,
                (SELECT COUNT(*) FROM reservation_votes x WHERE x.reservation_id = r.id AND x.vote = 'decline') AS decline_count,
                (SELECT x.vote FROM reservation_votes x WHERE x.reservation_id = r.id AND x.admin_id = ?) AS my_vote,
                (SELECT COUNT(*) FROM reservation_attachments a WHERE a.reservation_id = r.id) AS file_count,
                (SELECT COUNT(*) FROM reservation_attachments a WHERE a.reservation_id = r.id AND a.kind = 'image') AS image_count,
                (SELECT pr.id FROM post_event_reports pr WHERE pr.reservation_id = r.id) AS report_id,
                (SELECT pr.report_no FROM post_event_reports pr WHERE pr.reservation_id = r.id) AS report_no,
                (SELECT pr.review_status FROM post_event_reports pr WHERE pr.reservation_id = r.id) AS report_review_status{$histSelect}
            FROM reservations r LEFT JOIN venues v ON v.id = r.venue_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY r.event_date DESC';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();

    // Attach who voted what (for the tooltip / voter list)
    $nowLocalVotes = app_now();
    if ($rows) {
        $ids = array_column($rows, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $vs = $pdo->prepare("SELECT x.reservation_id, x.vote, x.reason, x.voted_at, a.display_name, a.office
                             FROM reservation_votes x JOIN admins a ON a.id = x.admin_id
                             WHERE x.reservation_id IN ($in) ORDER BY x.voted_at, x.id");
        $vs->execute($ids);
        $voters = [];
        foreach ($vs->fetchAll() as $v) {
            $voters[$v['reservation_id']][] = [
                'name' => $v['office'] && $v['office'] !== 'Admin' ? 'Vice ' . $v['office'] : $v['display_name'],
                'vote' => $v['vote'],
                'reason' => $v['reason'],
                // voted_at is stored in UTC; show it in campus time
                'at' => (new DateTime($v['voted_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(app_timezone()))->format('M j, Y g:i A'),
            ];
        }
        foreach ($rows as &$r) {
            $r['approve_count'] = (int)$r['approve_count'];
            $r['decline_count'] = (int)$r['decline_count'];
            $r['file_count']    = (int)$r['file_count'];
            $r['image_count']   = (int)$r['image_count'];
            $r['report_id']     = $r['report_id'] ? (int)$r['report_id'] : null;
            $r['voters'] = $voters[$r['id']] ?? [];
            // Can THIS admin take their vote back? (not once the event is over / cancelled / completed)
            $r['can_undo'] = !empty($r['my_vote']) && in_array($r['status'], ['pending', 'approved', 'declined'], true)
                && ($r['status'] === 'pending' || ($r['event_date'] . ' ' . $r['end_time']) > $nowLocalVotes);
        }
        unset($r); // break the reference before it can bite the next foreach
    }
    respond(true, ['rows' => $rows, 'votes_needed' => approvals_needed($pdo), 'approvals_needed' => approvals_needed($pdo), 'declines_needed' => declines_needed($pdo)]);
}

if (method() === 'POST') {
    $in = body();
    $id = int_id($in['id'] ?? null);
    $action = $_GET['action'] ?? '';

    $st = $pdo->prepare('SELECT * FROM reservations WHERE id = ?');
    $st->execute([$id]);
    $res = $st->fetch();
    if (!$res) respond(false, null, 'Reservation not found.', 404);

    if ($action === 'complete') {
        if ($res['status'] !== 'approved') respond(false, null, 'Only approved reservations can be completed.', 422);
        $pdo->prepare("UPDATE reservations SET status='completed' WHERE id=?")->execute([$id]);
        notify_student($res['student_id'], "Your reservation \"{$res['event_name']}\" ({$res['ref_no']}) has been marked completed.");
        respond(true, null, 'Reservation marked as completed.');
    }

    if ($action === 'undo') {
        if (!in_array($res['status'], ['pending', 'approved', 'declined'], true)) {
            respond(false, null, 'This reservation is already ' . $res['status'] . ' – votes can no longer be undone.', 409);
        }
        if ($res['status'] !== 'pending' && ($res['event_date'] . ' ' . $res['end_time']) <= app_now()) {
            respond(false, null, 'The event has already ended – the decision is locked.', 409);
        }
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM reservations WHERE id = ? FOR UPDATE')->execute([$id]);
        $cur = $pdo->prepare('SELECT status FROM reservations WHERE id = ?');
        $cur->execute([$id]);
        $curStatus = $cur->fetchColumn();
        if (!in_array($curStatus, ['pending', 'approved', 'declined'], true)) {
            $pdo->rollBack();
            respond(false, null, 'This reservation just changed to ' . $curStatus . ' – votes can no longer be undone.', 409);
        }
        $mine = $pdo->prepare('SELECT vote FROM reservation_votes WHERE reservation_id = ? AND admin_id = ?');
        $mine->execute([$id, $admin['id']]);
        $myVote = $mine->fetchColumn();
        if (!$myVote) {
            $pdo->rollBack();
            respond(false, null, 'You have no vote to undo on this reservation.', 404);
        }
        $pdo->prepare('DELETE FROM reservation_votes WHERE reservation_id = ? AND admin_id = ?')->execute([$id, $admin['id']]);

        $t = $pdo->prepare("SELECT SUM(vote='approve') AS a, SUM(vote='decline') AS d FROM reservation_votes WHERE reservation_id = ?");
        $t->execute([$id]);
        $tally = $t->fetch();
        $approve = (int)$tally['a'];
        $decline = (int)$tally['d'];
        $needed  = approvals_needed($pdo);
        $newStatus = $approve >= $needed ? 'approved' : ($decline >= declines_needed($pdo) ? 'declined' : 'pending');

        // Only touch the reservation when its status really changes (or a declined one lost a reason).
        if ($newStatus !== $curStatus || $newStatus === 'declined') {
            $pdo->prepare('UPDATE reservations SET status = ?, decline_reason = ? WHERE id = ?')
                ->execute([$newStatus, $newStatus === 'declined' ? decline_reason_text($pdo, (int)$id) : null, $id]);
        }

        $who   = ($admin['office'] ?? '') && $admin['office'] !== 'Admin' ? $admin['office'] : ($admin['display_name'] ?? 'Admin');
        $label = "Your reservation \"{$res['event_name']}\" ({$res['ref_no']})";
        $msg   = "{$who} withdrew their " . ($myVote === 'approve' ? 'approval' : 'decline') . " on {$label}.";
        if ($newStatus !== $curStatus) $msg .= ' The reservation is now ' . strtoupper($newStatus) . ($newStatus === 'pending' ? ' again (back under review).' : '.');
        notify_student($res['student_id'], $msg);
        $pdo->commit();

        respond(true, ['status' => $newStatus, 'approve' => $approve, 'decline' => $decline, 'needed' => $needed],
            $newStatus !== $curStatus ? "Vote undone – reservation is now $newStatus." : 'Your vote was undone.');
    }

    if (!in_array($action, ['approve', 'decline'], true)) respond(false, null, 'Unknown action', 404);
    if ($res['status'] !== 'pending') respond(false, null, 'Voting is closed – this reservation is already ' . $res['status'] . '.', 409);

    // A decline must come with a reason — the student gets to read it.
    // An approval may carry an optional comment / instructions for the student.
    $reason = trim((string)($in['reason'] ?? ''));
    if ($action === 'decline' && $reason === '') respond(false, null, 'Please type the reason for declining this reservation.', 422);
    $reason = $reason === '' ? null : mb_substr($reason, 0, 500);

    $pdo->beginTransaction();
    // Lock the reservation row so two admins voting at once can't double-decide
    $pdo->prepare('SELECT id FROM reservations WHERE id = ? FOR UPDATE')->execute([$id]);

    // Re-check under the lock: someone may have just closed the voting.
    $cur = $pdo->prepare('SELECT status FROM reservations WHERE id = ?');
    $cur->execute([$id]);
    if ($cur->fetchColumn() !== 'pending') {
        $pdo->rollBack();
        respond(false, null, 'Voting is closed – this reservation was just decided.', 409);
    }

    // Backend double-booking guard: never approve a reservation whose time
    // overlaps one that is already approved at the same venue.
    if ($action === 'approve') {
        $venueIdForLock = $res['venue_id'] ? (int)$res['venue_id'] : null;
        sched_lock_venue($pdo, $venueIdForLock);
        $clash = sched_find_conflicts(
            $pdo, $venueIdForLock, $res['venue_name'], $res['event_date'], $res['start_time'], $res['end_time'],
            (int)$id, ['approved']
        );
        if ($clash) {
            $pdo->rollBack();
            respond(false, null, 'Cannot approve: this overlaps an already approved booking at the same venue (' . sched_conflict_text($clash) . '). Decline it, or ask the student to request a reschedule.', 409);
        }
    }

    // Is this a new vote or a changed one? (Re-sending the same vote isn't announced again.)
    $prev = $pdo->prepare('SELECT vote FROM reservation_votes WHERE reservation_id = ? AND admin_id = ?');
    $prev->execute([$id, $admin['id']]);
    $previousVote = $prev->fetchColumn() ?: null;
    if ($previousVote !== null) {
        $pdo->rollBack();
        respond(false, null, 'You already voted (' . $previousVote . ') on this reservation. Each admin has one vote – use Undo first if you want to change it.', 409);
    }

    // One vote per admin (the unique key on reservation_id + admin_id is the last safety net)
    $pdo->prepare("INSERT INTO reservation_votes (reservation_id, admin_id, vote, reason) VALUES (?, ?, ?, ?)")
        ->execute([$id, $admin['id'], $action, $reason]);

    $t = $pdo->prepare("SELECT SUM(vote='approve') AS a, SUM(vote='decline') AS d FROM reservation_votes WHERE reservation_id = ?");
    $t->execute([$id]);
    $tally = $t->fetch();
    $approve = (int)$tally['a'];
    $decline = (int)$tally['d'];
    $needed = approvals_needed($pdo);
    $dNeeded = declines_needed($pdo);

    $final = null;
    if ($approve >= $needed) $final = 'approved';
    elseif ($decline >= $dNeeded) $final = 'declined';

    // "OSAS", "Academic Affairs", ... — the office that cast this vote.
    $who = ($admin['office'] ?? '') && $admin['office'] !== 'Admin' ? $admin['office'] : ($admin['display_name'] ?? 'Admin');
    $label = "Your reservation \"{$res['event_name']}\" ({$res['ref_no']})";

    // Every declining admin's reason, gathered for the student (used once the decision is final).
    $declineReason = null;
    if ($final === 'declined') {
        $rs = $pdo->prepare("SELECT a.office, a.display_name, x.reason
                               FROM reservation_votes x JOIN admins a ON a.id = x.admin_id
                              WHERE x.reservation_id = ? AND x.vote = 'decline' ORDER BY x.voted_at");
        $rs->execute([$id]);
        $parts = [];
        foreach ($rs->fetchAll() as $row) {
            $office = $row['office'] && $row['office'] !== 'Admin' ? $row['office'] : $row['display_name'];
            $parts[] = $office . ': ' . ($row['reason'] ?: 'No reason given');
        }
        $declineReason = implode("\n", $parts);
    }

    if ($final) {
        $pdo->prepare('UPDATE reservations SET status = ?, decline_reason = ? WHERE id = ?')
            ->execute([$final, $declineReason, $id]);
    }

    // ---- Tell the student about THIS admin's vote (and the final result, if there is one) ----
    if ($previousVote !== $action) {
        if ($action === 'approve') {
            $msg = "{$who} approved {$label}. ($approve of $needed approvals needed)";
            if ($reason) $msg .= " Comment: {$reason}";
            if ($final === 'approved') $msg .= " All {$needed} approvals reached — your reservation is now APPROVED.";
            notify_student($res['student_id'], $msg);
        } else {
            $msg = "{$who} declined {$label}. Reason: {$reason}";
            if ($final === 'declined') $msg .= " Majority reached — your reservation is now DECLINED.";
            notify_student($res['student_id'], $msg);
        }
    } elseif ($final) {
        // Same vote re-sent but it happened to tip the result — still announce the outcome.
        notify_student($res['student_id'], $final === 'approved'
            ? "Good news! {$label} has been approved ({$needed} approvals reached)."
            : "{$label} has been declined. Reason: {$declineReason}");
    }
    $pdo->commit();

    $message = $final
        ? "Decision reached ($approve approve / $decline decline) – reservation $final."
        : "Your vote was recorded ($approve of $needed approvals, $decline of $dNeeded declines).";
    respond(true, ['status' => $final ?? 'pending', 'approve' => $approve, 'decline' => $decline, 'needed' => $needed, 'declines_needed' => $dNeeded], $message);
}

respond(false, null, 'Method not allowed', 405);