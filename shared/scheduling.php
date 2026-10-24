<?php
/**
 * shared/scheduling.php
 *
 * Everything about "is this venue free at this time?" in ONE place, used by
 * the student portal AND the admin panel so they can never disagree:
 *
 *   - overlap / conflict detection (the backend half of double-booking
 *     protection — the browser half is only a convenience)
 *   - automatic suggestions of free times when a slot is taken
 *   - applying an approved reschedule while keeping the original schedule
 *     in reservation_schedule_history
 *
 * Only `pending` and `approved` reservations block a slot. Declined,
 * cancelled and completed ones never do, but their rows are never removed.
 *
 * Nothing here ever moves a reservation by itself: a schedule only changes
 * through sched_apply_reschedule(), which is called after a student request
 * AND an admin decision.
 */
require_once __DIR__ . '/events.php';

/** Thrown inside a transaction when the slot turned out to be taken. */
class SchedConflictException extends RuntimeException {
    public array $conflicts;
    public function __construct(array $conflicts, string $message = 'That time is already booked.') {
        parent::__construct($message);
        $this->conflicts = $conflicts;
    }
}

/* ---------- settings ---------- */

/** Hours used for the slot grid and for suggestions (bookings outside them are still allowed). */
function sched_open_time(): string  { return getenv('SCHED_OPEN')  ?: '07:00'; }
function sched_close_time(): string { return getenv('SCHED_CLOSE') ?: '21:00'; }
function sched_step_minutes(): int  { return 60; }

/* ---------- small helpers ---------- */

/** "9:00", "09:00:00" → "09:00:00"; anything else → null. */
function sched_norm_time(string $t): ?string {
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($t), $m)) return null;
    return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], isset($m[3]) ? (int)$m[3] : 0);
}

function sched_valid_date(string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

function sched_to_min(string $t): int {
    $p = explode(':', $t);
    return ((int)$p[0]) * 60 + (int)($p[1] ?? 0);
}

function sched_from_min(int $m): string {
    return sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
}

/** "13:00:00" → "1:00 PM" */
function sched_fmt12(string $t): string {
    $m = sched_to_min($t);
    $h = intdiv($m, 60);
    return (($h % 12) === 0 ? 12 : $h % 12) . ':' . sprintf('%02d', $m % 60) . ' ' . ($h >= 12 ? 'PM' : 'AM');
}

/**
 * Checks a requested date + time window. Returns an error message, or null
 * when it's fine. "Now" is campus-local time (see app_now()).
 */
function sched_validate_window(string $date, string $start, string $end): ?string {
    if (!sched_valid_date($date)) return 'Please choose a valid date.';
    $s = sched_norm_time($start);
    $e = sched_norm_time($end);
    if ($s === null || $e === null) return 'Please enter a valid start and end time.';
    if ($s >= $e) return 'End time must be after start time.';
    $now = app_now();
    if ($date < substr($now, 0, 10)) return 'Please choose a date that is not in the past.';
    if ($date === substr($now, 0, 10) && $s <= substr($now, 11, 8)) {
        return 'That start time has already passed today. Please pick a later time.';
    }
    return null;
}

/* ---------- conflict detection ---------- */

/**
 * Reservations that overlap the requested window at the same venue.
 * Back-to-back bookings (one ends exactly when the next starts) are fine.
 *
 * @param string[] $statuses which statuses count as "blocking"
 */
function sched_find_conflicts(
    PDO $pdo, ?int $venueId, string $venueName, string $date, string $start, string $end,
    ?int $excludeId = null, array $statuses = ['pending', 'approved']
): array {
    $in  = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT id, ref_no, event_name, status, start_time, end_time, student_id
              FROM reservations
             WHERE (venue_id = ? OR (venue_id IS NULL AND venue_name = ?))
               AND event_date = ?
               AND status IN ($in)
               AND start_time < ? AND end_time > ?";
    $args = array_merge([$venueId, $venueName, $date], $statuses, [$end, $start]);
    if ($excludeId) { $sql .= ' AND id <> ?'; $args[] = $excludeId; }
    $sql .= ' ORDER BY start_time';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * Serialises bookings for one venue. Call inside a transaction BEFORE the
 * conflict check + insert/update, so two people submitting at the same
 * instant can't both pass the check (the second waits, then sees the first).
 */
function sched_lock_venue(PDO $pdo, ?int $venueId): void {
    if (!$venueId) return;
    $st = $pdo->prepare('SELECT id FROM venues WHERE id = ? FOR UPDATE');
    $st->execute([$venueId]);
}

/** What the browser is allowed to know about a conflict: times + status, never names. */
function sched_public_conflicts(array $rows, ?int $viewerStudentId = null): array {
    $out = [];
    foreach ($rows as $r) {
        $mine = $viewerStudentId !== null && (int)$r['student_id'] === $viewerStudentId;
        $out[] = [
            'start'  => substr($r['start_time'], 0, 5),
            'end'    => substr($r['end_time'], 0, 5),
            'status' => $r['status'],
            'mine'   => $mine,
            'reservation_id' => $mine ? (int)$r['id'] : null,
        ];
    }
    return $out;
}

/** "Booked 1:00 PM – 5:00 PM, Pending 6:00 PM – 7:00 PM" */
function sched_conflict_text(array $rows): string {
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = ($r['status'] === 'approved' ? 'Booked ' : 'Pending ')
                 . sched_fmt12($r['start_time']) . ' – ' . sched_fmt12($r['end_time']);
    }
    return implode(', ', $parts);
}

/** Busy intervals of a venue on a day (for the slot grid). */
function sched_busy_for_day(PDO $pdo, ?int $venueId, string $venueName, string $date, ?int $excludeId = null, ?int $viewerStudentId = null): array {
    $sql = "SELECT id, start_time, end_time, status, student_id FROM reservations
             WHERE (venue_id = ? OR (venue_id IS NULL AND venue_name = ?))
               AND event_date = ? AND status IN ('pending','approved')";
    $args = [$venueId, $venueName, $date];
    if ($excludeId) { $sql .= ' AND id <> ?'; $args[] = $excludeId; }
    $sql .= ' ORDER BY start_time';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'start'  => substr($r['start_time'], 0, 5),
            'end'    => substr($r['end_time'], 0, 5),
            'status' => $r['status'] === 'approved' ? 'booked' : 'pending',
            'mine'   => $viewerStudentId !== null && (int)$r['student_id'] === $viewerStudentId,
        ];
    }
    return $out;
}

/* ---------- automatic suggestions ---------- */

/**
 * Free gaps inside [open, close] given busy intervals (minutes).
 * @param array<int,array{0:int,1:int}> $busy
 */
function sched_free_gaps(array $busy, int $open, int $close): array {
    usort($busy, fn($a, $b) => $a[0] <=> $b[0]);
    $gaps = [];
    $cursor = $open;
    foreach ($busy as [$bs, $be]) {
        if ($be <= $cursor) continue;
        if ($bs > $cursor) $gaps[] = [$cursor, min($bs, $close)];
        $cursor = max($cursor, $be);
        if ($cursor >= $close) break;
    }
    if ($cursor < $close) $gaps[] = [$cursor, $close];
    return array_values(array_filter($gaps, fn($g) => $g[1] > $g[0]));
}

/**
 * Suggests available alternatives for a window that's taken (or that an
 * admin wants to offer instead). Order: other times the same day, the same
 * venue at the same time on nearby days, then other venues at the same time.
 *
 * $req: venue_id, venue_name, date, start, end, exclude_id (opt), other_venues (opt bool),
 * later_only (opt bool: same-day suggestions only start after the requested start, whole hours only)
 * Each suggestion: type, venue_id, venue_name, date, start, end ("HH:MM").
 */
function sched_suggest(PDO $pdo, array $req, int $limit = 8): array {
    $venueId   = $req['venue_id'] ?? null;
    $venueId   = $venueId ? (int)$venueId : null;
    $venueName = (string)($req['venue_name'] ?? '');
    $date      = (string)$req['date'];
    $start     = sched_norm_time((string)$req['start']);
    $end       = sched_norm_time((string)$req['end']);
    $exclude   = !empty($req['exclude_id']) ? (int)$req['exclude_id'] : null;
    if (!sched_valid_date($date) || $start === null || $end === null || $start >= $end) return [];

    $dur      = sched_to_min($end) - sched_to_min($start);
    $reqStart = sched_to_min($start);
    $step     = sched_step_minutes();
    $open     = sched_to_min(sched_open_time());
    $close    = sched_to_min(sched_close_time());
    $now      = app_now();
    $today    = substr($now, 0, 10);
    $nowMin   = sched_to_min(substr($now, 11, 5));
    $out      = [];
    $laterOnly = !empty($req['later_only']);

    $mk = function (string $type, ?int $vid, string $vname, string $d, int $s) use ($dur) {
        return [
            'type' => $type, 'venue_id' => $vid, 'venue_name' => $vname, 'date' => $d,
            'start' => substr(sched_from_min($s), 0, 5), 'end' => substr(sched_from_min($s + $dur), 0, 5),
        ];
    };

    // 1) Same venue, same day: the free gap(s) that fit, nearest to the requested start.
    if ($date >= $today && $dur <= ($close - $open)) {
        $busyRows = sched_busy_for_day($pdo, $venueId, $venueName, $date, $exclude);
        $busy = array_map(fn($b) => [sched_to_min($b['start']), sched_to_min($b['end'])], $busyRows);
        $dayOpen = $open;
        if ($date === $today) $dayOpen = max($open, (int)(ceil(($nowMin + 1) / $step) * $step));
        $cands = [];
        foreach (sched_free_gaps($busy, $dayOpen, $close) as [$gs, $ge]) {
            if ($ge - $gs < $dur) continue;
            $lo = (int)(ceil($gs / $step) * $step);
            $hi = (int)(floor(($ge - $dur) / $step) * $step);
            if ($lo <= $hi) {
                $near = (int)(round($reqStart / $step) * $step);
                $cs = min(max($near, $lo), $hi);
            } elseif ($laterOnly) {
                continue; // student view: only whole-hour starts
            } else {
                $cs = $gs; // gap fits the duration but not on the hourly grid
            }
            if ($cs === $reqStart) continue;
            // Student view: only offer times AFTER the requested start (past the taken slot).
            if ($laterOnly && $cs < $reqStart) continue;
            $cands[] = [abs($cs - $reqStart), $cs];
        }
        usort($cands, fn($a, $b) => $a[0] <=> $b[0]);
        foreach (array_slice($cands, 0, 3) as [, $cs]) {
            $out[] = $mk('same_day', $venueId, $venueName, $date, $cs);
        }
    }

    // 2) Same venue, same time, nearby days.
    $from = max($today, (new DateTime($date))->modify('-14 days')->format('Y-m-d'));
    $to   = (new DateTime($date))->modify('+14 days')->format('Y-m-d');
    $sql  = "SELECT event_date, start_time, end_time FROM reservations
              WHERE (venue_id = ? OR (venue_id IS NULL AND venue_name = ?))
                AND event_date BETWEEN ? AND ? AND status IN ('pending','approved')";
    $args = [$venueId, $venueName, $from, $to];
    if ($exclude) { $sql .= ' AND id <> ?'; $args[] = $exclude; }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $busyByDay = [];
    foreach ($st->fetchAll() as $r) {
        $busyByDay[$r['event_date']][] = [$r['start_time'], $r['end_time']];
    }
    $found = 0;
    for ($k = 1; $k <= 14 && $found < 3; $k++) {
        foreach ([1, -1] as $sign) {
            if ($found >= 3) break;
            $d = (new DateTime($date))->modify(($sign * $k) . ' days')->format('Y-m-d');
            if ($d < $today) continue;
            if ($d === $today && $start <= substr($now, 11, 8)) continue;
            $free = true;
            foreach ($busyByDay[$d] ?? [] as [$bs, $be]) {
                if ($bs < $end && $be > $start) { $free = false; break; }
            }
            if ($free) { $out[] = $mk('other_day', $venueId, $venueName, $d, $reqStart); $found++; }
        }
    }

    // 3) Other venues, same date and time.
    if (!empty($req['other_venues'])) {
        $busyV = $pdo->prepare(
            "SELECT DISTINCT venue_id FROM reservations
              WHERE event_date = ? AND status IN ('pending','approved') AND venue_id IS NOT NULL
                AND start_time < ? AND end_time > ?"
        );
        $busyV->execute([$date, $end, $start]);
        $taken = array_map('intval', $busyV->fetchAll(PDO::FETCH_COLUMN));
        $vs = $pdo->query("SELECT id, name FROM venues WHERE is_active = 1 AND status <> 'maintenance' ORDER BY name")->fetchAll();
        $n = 0;
        foreach ($vs as $v) {
            if ($n >= 2) break;
            if ((int)$v['id'] === $venueId || in_array((int)$v['id'], $taken, true)) continue;
            $out[] = $mk('other_venue', (int)$v['id'], $v['name'], $date, $reqStart);
            $n++;
        }
    }

    // Prefer: same day → other venue → other days (nearby alternatives first).
    $rank = ['same_day' => 0, 'other_venue' => 1, 'other_day' => 2];
    usort($out, fn($a, $b) => $rank[$a['type']] <=> $rank[$b['type']]);
    return array_slice($out, 0, $limit);
}

/* ---------- applying a reschedule ---------- */

/** True once migration_reschedule_history.sql has been imported. */
function sched_tables_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM reservation_reschedule_requests LIMIT 1');
        $pdo->query('SELECT 1 FROM reservation_schedule_history LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Moves a reservation to a new date/time and records the OLD schedule in
 * reservation_schedule_history. Call only after a student request + an
 * admin decision, inside a transaction that has already locked the venue
 * and re-checked for conflicts.
 *
 * @param array $res the reservation row as it is NOW (before the move)
 */
function sched_apply_reschedule(
    PDO $pdo, array $res, string $newDate, string $newStart, string $newEnd,
    ?int $requestId, ?int $adminId, string $reason
): void {
    $newStart = sched_norm_time($newStart);
    $newEnd   = sched_norm_time($newEnd);

    $pdo->prepare(
        'INSERT INTO reservation_schedule_history
            (reservation_id, request_id, venue_name, old_date, old_start, old_end,
             new_date, new_start, new_end, reason, changed_by_admin, changed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $res['id'], $requestId, $res['venue_name'],
        $res['event_date'], $res['start_time'], $res['end_time'],
        $newDate, $newStart, $newEnd, $reason, $adminId, app_now(),
    ]);

    $pdo->prepare('UPDATE reservations SET event_date = ?, start_time = ?, end_time = ? WHERE id = ?')
        ->execute([$newDate, $newStart, $newEnd, $res['id']]);
}

/** "Sat, Oct 10, 2026 · 1:00 PM – 3:00 PM" for notifications. */
function sched_describe(string $date, string $start, string $end): string {
    return date('D, M j, Y', strtotime($date)) . ' · ' . sched_fmt12($start) . ' – ' . sched_fmt12($end);
}
