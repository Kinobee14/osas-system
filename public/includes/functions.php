<?php
/**
 * includes/functions.php
 * Small helpers shared by the reservation-related pages.
 */

/**
 * Generates a unique, human-friendly reservation reference number,
 * e.g. "RES-2026-000123". Retries a few times in the (very unlikely)
 * case of a collision.
 */
function generate_ref_no(PDO $pdo): string {
    $year = date('Y');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $random = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $refNo = "RES-{$year}-{$random}";

        $stmt = $pdo->prepare('SELECT 1 FROM reservations WHERE ref_no = ? LIMIT 1');
        $stmt->execute([$refNo]);

        if (!$stmt->fetch()) {
            return $refNo;
        }
    }

    // Extremely unlikely fallback: fall back to a value that's guaranteed
    // unique by including microtime.
    return 'RES-' . $year . '-' . substr((string) microtime(true) * 1000, -6);
}

/**
 * Formats two "H:i:s" / "H:i" time strings into a readable range,
 * e.g. "9:00 AM - 11:00 AM".
 */
function fmt_time_range(string $start, string $end): string {
    $startTs = strtotime($start);
    $endTs = strtotime($end);

    $startFmt = $startTs !== false ? date('g:i A', $startTs) : $start;
    $endFmt = $endTs !== false ? date('g:i A', $endTs) : $end;

    return "{$startFmt} - {$endFmt}";
}

/**
 * True if the given venue already has a pending/approved reservation that
 * overlaps the requested date/time window. Declined reservations don't
 * block the slot; completed ones are in the past so they can't overlap a
 * new request in practice, but are excluded too just in case of bad data.
 * Prevents the same room from being double-booked for the same time.
 */
function reservation_conflicts(PDO $pdo, ?int $venueId, string $date, string $start, string $end): bool {
    if (!$venueId) {
        return false; // Can't check for a conflict if we don't know which venue row this is.
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM reservations
         WHERE venue_id = :venue_id
           AND event_date = :event_date
           AND status IN ('pending', 'approved')
           AND start_time < :end_time
           AND end_time > :start_time
         LIMIT 1"
    );
    $stmt->execute([
        ':venue_id'   => $venueId,
        ':event_date' => $date,
        ':start_time' => $start,
        ':end_time'   => $end,
    ]);

    return (bool) $stmt->fetch();
}

/**
 * Inserts a notification row for a student. Used by the admin panel
 * whenever a decision (reservation vote, account approval, etc.) affects
 * a specific student, so it shows up on their "Notification" tab.
 */
function notify_student(PDO $pdo, int $studentId, string $message): void {
    $stmt = $pdo->prepare('INSERT INTO notifications (student_id, message) VALUES (?, ?)');
    $stmt->execute([$studentId, $message]);
}
