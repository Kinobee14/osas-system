<?php
/**
 * shared/events.php
 *
 * The "an event that's over is Done" rule, in one place so the student
 * portal and the admin panel can never disagree about it.
 *
 * There's no cron job in this project, so instead of a scheduled task the
 * sweep runs opportunistically: any page or endpoint that's about to show
 * reservation statuses calls auto_complete_due_reservations() first. That
 * costs one indexed SELECT and is always correct by the time anyone looks.
 */

/**
 * The campus's real-world timezone. Everything the students type (event
 * dates and times) is local time, so "has this event finished?" has to be
 * answered in local time too.
 *
 * This matters because public/config.php deliberately puts PHP *and* the
 * MySQL session into UTC (so OTP expiry maths can't drift), while the admin
 * panel's connection uses the server default. If we compared against the
 * database's NOW() the two portals would flip a reservation to Done up to
 * 8 hours apart. Comparing against an explicit Asia/Manila timestamp that
 * we compute ourselves sidesteps the whole problem.
 */
function app_timezone(): string {
    return getenv('APP_TIMEZONE') ?: 'Asia/Manila';
}

/** Current local wall-clock time as 'Y-m-d H:i:s'. */
function app_now(): string {
    return (new DateTime('now', new DateTimeZone(app_timezone())))->format('Y-m-d H:i:s');
}

/**
 * Flips every approved reservation whose end time has already passed over
 * to `completed`, and tells the student it happened.
 *
 * Returns how many rows changed (0 on the overwhelming majority of calls).
 *
 * Only `approved` rows are touched: a pending request that nobody voted on
 * in time stays pending so it's visible as a miss rather than quietly
 * disappearing into the completed pile, and declined ones are left alone.
 */
function auto_complete_due_reservations(PDO $pdo): int {
    $now = app_now();

    $find = $pdo->prepare(
        "SELECT id, ref_no, event_name, student_id
           FROM reservations
          WHERE status = 'approved'
            AND TIMESTAMP(event_date, end_time) <= ?"
    );
    $find->execute([$now]);
    $due = $find->fetchAll();
    if (!$due) return 0;

    $update = $pdo->prepare("UPDATE reservations SET status = 'completed' WHERE id = ? AND status = 'approved'");
    $notify = $pdo->prepare('INSERT INTO notifications (student_id, message) VALUES (?, ?)');

    $changed = 0;
    foreach ($due as $r) {
        $update->execute([$r['id']]);
        // rowCount() guards against two requests sweeping the same row at once:
        // whoever loses the race updates nothing and skips the notification.
        if ($update->rowCount() === 0) continue;

        $changed++;
        if ($r['student_id']) {
            $notify->execute([
                $r['student_id'],
                "Your event \"{$r['event_name']}\" ({$r['ref_no']}) has finished, so the reservation is now marked Done. Please submit your post-event report.",
            ]);
        }
    }

    return $changed;
}

/** "PER-2026-004213" — the reference printed on a post-event report. */
function generate_report_no(PDO $pdo): string {
    $year = (new DateTime('now', new DateTimeZone(app_timezone())))->format('Y');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = 'PER-' . $year . '-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $check = $pdo->prepare('SELECT 1 FROM post_event_reports WHERE report_no = ? LIMIT 1');
        $check->execute([$candidate]);
        if (!$check->fetch()) return $candidate;
    }

    return 'PER-' . $year . '-' . substr((string)(int)(microtime(true) * 1000), -6);
}

/** Dropdown labels for the two condition fields on the post-event form. */
function resource_condition_labels(): array {
    return [
        'all_returned'       => 'All borrowed resources returned',
        'partially_returned' => 'Partially returned / still outstanding',
        'damaged'            => 'Returned with damage or losses',
        'not_applicable'     => 'No resources were borrowed',
    ];
}

function venue_condition_labels(): array {
    return [
        'good'         => 'Good — left clean and undamaged',
        'minor_damage' => 'Minor issues (cleanliness, small repairs)',
        'major_damage' => 'Major damage — needs OSAS action',
    ];
}

/**
 * A post-event report is written by the student and reviewed by OSAS:
 *   pending         → submitted, waiting on a Vice Admin to look at it
 *   approved        → OSAS accepted it; the student can no longer edit it
 *   needs_revision  → OSAS sent it back with remarks; the student can
 *                     resubmit, which returns it to `pending`
 */
function review_status_labels(): array {
    return [
        'pending'        => 'Awaiting Review',
        'approved'       => 'Approved',
        'needs_revision' => 'Needs Revision',
    ];
}
