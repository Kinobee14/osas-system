<?php
/**
 * shared/attendance.php
 *
 * QR attendance rules in one place so the admin panel and the public
 * check-in page can never disagree about "is attendance open right now?".
 *
 * Requires shared/events.php (app_now()) to be loaded by the caller.
 *
 * Window rule: attendance is possible only while the reservation is
 * `approved`, from ATTENDANCE_EARLY_MIN minutes before its start time
 * (default 30) until its end time, AND an admin has switched it on.
 */

/** Minutes before the start time that check-in may open. */
function att_early_minutes(): int {
    $v = getenv('ATTENDANCE_EARLY_MIN');
    return ($v !== false && ctype_digit($v)) ? min((int)$v, 720) : 30;
}

/** 256 random bits as 64 lowercase hex characters. */
function att_new_token(): string {
    return bin2hex(random_bytes(32));
}

function att_valid_token_format($t): bool {
    return is_string($t) && preg_match('/^[a-f0-9]{64}$/', $t) === 1;
}

/** True for a duplicate-key violation (MySQL 1062 / SQLSTATE 23000). */
function att_is_duplicate(PDOException $e): bool {
    return $e->getCode() === '23000' && (int)($e->errorInfo[1] ?? 0) === 1062;
}

/** "2026-10-26" + "09:00:00" → 'Y-m-d H:i:s' (naive arithmetic, no timezone surprises). */
function att_slot_dt(string $date, string $time, int $shiftMinutes = 0): string {
    $dt = new DateTime("$date $time", new DateTimeZone('UTC'));
    if ($shiftMinutes) $dt->modify(($shiftMinutes > 0 ? '+' : '') . $shiftMinutes . ' minutes');
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Where is this reservation in its attendance window?
 * $row needs: status, is_open, event_date, start_time, end_time.
 * Returns ['code' => open|scheduled|closed|too_early|ended|unavailable, 'message' => string,
 *          'opens_at' => 'Y-m-d H:i:s', 'closes_at' => 'Y-m-d H:i:s'].
 */
function att_state(array $row, ?string $now = null): array {
    $now      = $now ?? app_now();
    $opensAt  = att_slot_dt($row['event_date'], $row['start_time'], -att_early_minutes());
    $closesAt = att_slot_dt($row['event_date'], $row['end_time']);
    $out = ['opens_at' => $opensAt, 'closes_at' => $closesAt];

    if ($row['status'] === 'completed' || ($row['status'] === 'approved' && $now >= $closesAt)) {
        return $out + ['code' => 'ended', 'message' => 'Attendance for this event has ended.'];
    }
    if ($row['status'] !== 'approved') {
        return $out + ['code' => 'unavailable', 'message' => 'Attendance is not available for this event.'];
    }
    if ($now < $opensAt) {
        // Admin already pressed Open: it switches on by itself when the window starts.
        if ((int)$row['is_open']) {
            return $out + ['code' => 'scheduled', 'message' => 'Attendance is scheduled and will open automatically at ' . att_fmt_dt($opensAt) . '.'];
        }
        return $out + ['code' => 'too_early', 'message' => 'Attendance has not opened yet. It opens at ' . att_fmt_dt($opensAt) . '.'];
    }
    if (!(int)$row['is_open']) {
        return $out + ['code' => 'closed', 'message' => 'Attendance is currently closed. Please ask the event organizer.'];
    }
    return $out + ['code' => 'open', 'message' => 'Attendance is open.'];
}

/** "9:00 AM" */
function att_fmt_time(string $time): string {
    $d = DateTime::createFromFormat('H:i:s', strlen($time) === 5 ? "$time:00" : $time);
    return $d ? $d->format('g:i A') : $time;
}

/** "October 26, 2026" */
function att_fmt_date(string $date): string {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d ? $d->format('F j, Y') : $date;
}

/** "Oct 26, 2026 9:00 AM" */
function att_fmt_dt(string $dt): string {
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $dt);
    return $d ? $d->format('M j, Y g:i A') : $dt;
}

/**
 * Normalised student id: trimmed, spaces removed, upper-cased.
 * 3-30 chars of letters, digits and . _ / - (first char alphanumeric).
 * Returns null when invalid. "2021 - 0001" and "2021-0001" are the same id;
 * "2021-0001" and "20210001" are NOT (we don't guess the school's id format).
 */
function att_normalize_student_id($raw): ?string {
    if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) return null;
    $s = strtoupper(preg_replace('/\s+/u', '', trim($raw)) ?? '');
    return preg_match('/^[A-Z0-9][A-Z0-9._\/-]{2,29}$/', $s) === 1 ? $s : null;
}

/** Course / program (e.g. "BSIS", "BS Information Systems"): collapsed spaces, 2-50 chars, letters, digits and . , & / ( ) ' - . Null when invalid. */
function att_clean_course($raw): ?string {
    if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) return null;
    $s = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    $len = mb_strlen($s);
    if ($len < 2 || $len > 50) return null;
    return preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{M}\\p{N} .,&\\/()'’-]*$/u", $s) === 1 ? $s : null;
}

/** Full name: collapsed spaces, 2-100 chars, letters/marks plus space . , ' ’ -. Null when invalid. */
function att_clean_name($raw): ?string {
    if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) return null;
    $s = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    $len = mb_strlen($s);
    if ($len < 2 || $len > 100) return null;
    return preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} .,'’-]*$/u", $s) === 1 ? $s : null;
}
