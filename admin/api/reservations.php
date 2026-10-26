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
require_once __DIR__ . '/../../shared/approval_flow.php';
$admin = require_auth();
$pdo = db();
$myRole = flow_role($admin);   // 'admin' | 'academic' | 'osas' | null

// Anything whose end time has already gone by becomes "completed" on its own —
// see shared/events.php. Running it here means the Approval Queue, the All
// Reservation table and the calendar are all looking at fresh statuses, and
// the green post-event button shows up the moment an event is genuinely over.
auto_complete_due_reservations($pdo);

/** Take a reservation's borrowed items out of stock (called when it becomes approved). Throws if short. */
function deduct_reservation_stock(PDO $pdo, int $reservationId): void {
    $items = $pdo->prepare('SELECT resource_id, resource_name, quantity FROM reservation_resources WHERE reservation_id = ? AND resource_id IS NOT NULL');
    $items->execute([$reservationId]);
    $lock   = $pdo->prepare('SELECT name, stock FROM resources WHERE id = ? FOR UPDATE');
    $deduct = $pdo->prepare('UPDATE resources SET stock = stock - ? WHERE id = ? AND stock >= ?');
    foreach ($items->fetchAll() as $it) {
        $qty = (int)$it['quantity'];
        $lock->execute([(int)$it['resource_id']]);
        $row = $lock->fetch();
        if (!$row) throw new RuntimeException('"' . $it['resource_name'] . '" no longer exists in the inventory.');
        $deduct->execute([$qty, (int)$it['resource_id'], $qty]);
        if ($deduct->rowCount() === 0) {
            throw new RuntimeException('Not enough "' . $row['name'] . '" in stock (' . (int)$row['stock'] . ' left, ' . $qty . ' requested).');
        }
    }
}

/** Put a reservation's borrowed items back in stock (an approval was undone). */
function restore_reservation_stock(PDO $pdo, int $reservationId): void {
    $items = $pdo->prepare('SELECT resource_id, quantity FROM reservation_resources WHERE reservation_id = ? AND resource_id IS NOT NULL');
    $items->execute([$reservationId]);
    $restock = $pdo->prepare('UPDATE resources SET stock = stock + ? WHERE id = ?');
    foreach ($items->fetchAll() as $it) $restock->execute([(int)$it['quantity'], (int)$it['resource_id']]);
}

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
        . (!empty($_GET['queue']) ? ' ORDER BY r.created_at ASC, r.id ASC' : ' ORDER BY r.event_date DESC');   // approval queue: first come, first served
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
            // When the student submitted the form (stored in UTC) in campus time.
            if (!empty($r['created_at'])) {
                $sub = (new DateTime($r['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(app_timezone()));
                $r['submitted_date'] = $sub->format('M j, Y');
                $r['submitted_time'] = $sub->format('g:i A');
            }
            // Approval sequence: Administration + Academic first, then OSAS.
            $fl = flow_state($pdo, (int)$r['id']);
            $r['flow'] = $fl['steps'];
            foreach ($r['flow'] as $k => &$stp) { $stp['label'] = FLOW_LABEL[$k]; $stp['at'] = $stp['at'] ? (new DateTime($stp['at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(app_timezone()))->format('M j, Y g:i A') : null; }
            unset($stp);
            $r['flow_stage']   = $fl['stage'];
            $r['waiting_for']  = $fl['waiting_for'];
            $r['my_role']      = $myRole;
            $r['my_turn']      = $r['status'] === 'pending' && flow_can_act($myRole, $fl);
            // Can THIS admin take their vote back? (not once the event is over / cancelled / completed)
            $r['can_undo'] = !empty($r['my_vote']) && in_array($r['status'], ['pending', 'approved', 'declined'], true)
                && ($r['status'] === 'pending' || ($r['event_date'] . ' ' . $r['end_time']) > $nowLocalVotes);
        }
        unset($r); // break the reference before it can bite the next foreach
    }
    // OSAS (the super admin) only sees a pending request once Administration and Academic Affairs both approved it.
    if (!empty($_GET['queue']) && $myRole === 'osas') {
        $rows = array_values(array_filter($rows, fn($r) => $r['status'] !== 'pending' || $r['flow_stage'] === 'osas'));
    }
    respond(true, ['rows' => $rows, 'my_role' => $myRole, 'votes_needed' => approvals_needed($pdo), 'approvals_needed' => approvals_needed($pdo), 'declines_needed' => declines_needed($pdo)]);
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
        require_osas($admin);   // only OSAS can mark an event as done
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
        // Sequence rule: Administration / Academic can't take their approval back once OSAS has acted on it.
        if ($myRole !== 'osas') {
            $osasVoted = flow_state($pdo, (int)$id)['steps']['osas']['state'];
            if (in_array($osasVoted, ['approved', 'declined'], true)) {
                $pdo->rollBack();
                respond(false, null, 'OSAS has already acted on this reservation. OSAS must undo first before you can change your decision.', 409);
            }
        }
        $pdo->prepare('DELETE FROM reservation_votes WHERE reservation_id = ? AND admin_id = ?')->execute([$id, $admin['id']]);

        $newStatus = flow_status(flow_state($pdo, (int)$id));
        $approve = $decline = $needed = 0;

        // Stock follows approval: give it back if an approval was withdrawn, take it if undo tipped it to approved.
        if ($curStatus === 'approved' && $newStatus !== 'approved') {
            restore_reservation_stock($pdo, (int)$id);
        } elseif ($curStatus !== 'approved' && $newStatus === 'approved') {
            try { deduct_reservation_stock($pdo, (int)$id); }
            catch (RuntimeException $e) { $pdo->rollBack(); respond(false, null, 'Cannot approve: ' . $e->getMessage(), 409); }
        }

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
    if ($res['status'] !== 'pending') respond(false, null, 'This reservation is already ' . $res['status'] . '.', 409);
    if (!$myRole) respond(false, null, 'Your account is not part of the approval sequence (Administration, Academic Affairs, OSAS).', 403);
    $flowNow = flow_state($pdo, (int)$id);
    if ($myRole === 'osas' && $flowNow['stage'] !== 'osas') {
        respond(false, null, 'Not yet — ' . ($flowNow['waiting_for'] ?: 'Administration and Academic Affairs must approve first') . '. OSAS makes the final decision after both approve.', 409);
    }
    if ($flowNow['steps'][$myRole]['state'] === 'skipped') respond(false, null, 'Not needed — this reservation already passed to OSAS for the final approval.', 409);
    if ($flowNow['steps'][$myRole]['state'] !== 'pending') respond(false, null, 'You already decided on this reservation. Use Undo first if you want to change it.', 409);

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

    $flowAfter = flow_state($pdo, (int)$id);
    $final = in_array($flowAfter['stage'], ['approved', 'declined'], true) ? $flowAfter['stage'] : null;
    $approve = $decline = $needed = $dNeeded = 0;

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

    if ($final === 'approved') {
        // Approval is final: now (and only now) take the borrowed items out of stock.
        try { deduct_reservation_stock($pdo, (int)$id); }
        catch (RuntimeException $e) {
            $pdo->rollBack();
            respond(false, null, 'Cannot approve: ' . $e->getMessage() . ' Ask the student to lower the quantity, or restock first.', 409);
        }
    }

    if ($final) {
        $pdo->prepare('UPDATE reservations SET status = ?, decline_reason = ? WHERE id = ?')
            ->execute([$final, $declineReason, $id]);
    }

    // ---- Tell the student where the request is in the sequence ----
    $roleLabel = FLOW_LABEL[$myRole];
    if ($action === 'approve') {
        $msg = "{$roleLabel} approved {$label}.";
        if ($reason) $msg .= " Comment: {$reason}";
        if ($final === 'approved')                $msg .= " OSAS gave the final approval — your reservation is now APPROVED.";
        elseif ($flowAfter['stage'] === 'osas')   $msg .= " It now goes to OSAS for the final approval.";
        else                                       $msg .= ' ' . $flowAfter['waiting_for'] . '.';
    } else {
        $msg = "{$roleLabel} declined {$label}. Reason: {$reason} Your reservation is now DECLINED.";
    }
    notify_student($res['student_id'], $msg);
    $pdo->commit();

    $message = $final === 'approved' ? 'Final approval given – reservation approved.'
             : ($final === 'declined' ? 'Reservation declined.'
             : ($flowAfter['stage'] === 'osas' ? 'Approved – it now goes to OSAS for the final approval.' : 'Approved. ' . $flowAfter['waiting_for'] . '.'));
    respond(true, ['status' => $final ?? 'pending', 'stage' => $flowAfter['stage']], $message);
}

respond(false, null, 'Method not allowed', 405);