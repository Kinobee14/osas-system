<?php
/**
 * api/availability.php  (GET)
 *
 * Live availability for one venue on one date, used by the New Reservation
 * form and the Reschedule window.
 *
 *   ?venue=Social Hall&date=2026-10-10                       → busy slots only
 *   ?venue=...&date=...&start=13:00&end=15:00                → + conflicts + suggestions
 *   &exclude=<reservation id>                                → ignore one of the student's own
 *                                                              reservations (used when rescheduling it)
 *
 * The browser also checks overlaps locally for instant feedback, but this
 * endpoint (and the checks inside reservation_create.php / reschedule_request.php)
 * is what actually decides.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in again.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../shared/scheduling.php';

$pdo    = get_db();
$userId = (int) $_SESSION['user_id'];

$venueName = trim($_GET['venue'] ?? '');
$venueIdIn = filter_var($_GET['venue_id'] ?? null, FILTER_VALIDATE_INT);
$date      = trim($_GET['date'] ?? '');
$startIn   = trim($_GET['start'] ?? '');
$endIn     = trim($_GET['end'] ?? '');

if (!sched_valid_date($date)) {
    echo json_encode(['success' => false, 'error' => 'Please choose a valid date.']);
    exit;
}

if ($venueIdIn) {
    $vs = $pdo->prepare('SELECT id, name, status FROM venues WHERE id = ? AND is_active = 1 LIMIT 1');
    $vs->execute([$venueIdIn]);
} else {
    $vs = $pdo->prepare('SELECT id, name, status FROM venues WHERE name = ? AND is_active = 1 LIMIT 1');
    $vs->execute([$venueName]);
}
$venue = $vs->fetch();
if (!$venue) {
    echo json_encode(['success' => false, 'error' => 'Venue not found.']);
    exit;
}
$venueId = (int) $venue['id'];

// Only honour "exclude" for a reservation that really belongs to this student.
$exclude = null;
$exIn = filter_var($_GET['exclude'] ?? null, FILTER_VALIDATE_INT);
if ($exIn) {
    $own = $pdo->prepare('SELECT id FROM reservations WHERE id = ? AND student_id = ?');
    $own->execute([$exIn, $userId]);
    if ($own->fetch()) $exclude = $exIn;
}

$busy = sched_busy_for_day($pdo, $venueId, $venue['name'], $date, $exclude, $userId);

$result = [
    'success'     => true,
    'venue'       => $venue['name'],
    'venue_id'    => $venueId,
    'date'        => $date,
    'maintenance' => $venue['status'] === 'maintenance',
    'busy'        => $busy,
    'open'        => substr(sched_open_time(), 0, 5),
    'close'       => substr(sched_close_time(), 0, 5),
    'step'        => sched_step_minutes(),
    'now'         => app_now(),
    'available'   => null,
    'conflicts'   => [],
    'suggestions' => [],
    'message'     => '',
    'key'         => $venue['name'] . '|' . $date . '|' . $startIn . '|' . $endIn,
];

$start = sched_norm_time($startIn);
$end   = sched_norm_time($endIn);

if ($start !== null && $end !== null && $start < $end) {
    $conflicts = sched_find_conflicts($pdo, $venueId, $venue['name'], $date, $start, $end, $exclude);
    $pastError = sched_validate_window($date, $start, $end);

    if ($result['maintenance']) {
        $result['available'] = false;
        $result['message'] = 'That venue is currently under maintenance.';
    } elseif ($conflicts) {
        $result['available'] = false;
        $result['message'] = 'That time overlaps an existing booking (' . sched_conflict_text($conflicts) . ').';
    } elseif ($pastError) {
        $result['available'] = false;
        $result['message'] = $pastError;
    } else {
        $result['available'] = true;
    }
    $result['conflicts'] = sched_public_conflicts($conflicts, $userId);

    if ($result['available'] === false) {
        $result['suggestions'] = sched_suggest($pdo, [
            'venue_id' => $venueId, 'venue_name' => $venue['name'],
            'date' => $date, 'start' => $start, 'end' => $end,
            'exclude_id' => $exclude, 'other_venues' => false, 'later_only' => true,
        ]);
    }
}

echo json_encode($result);
