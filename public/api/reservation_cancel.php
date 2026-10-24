<?php
/**
 * api/reservation_cancel.php  (POST, JSON body: {reservation_id, reason?})
 *
 * A student cancels one of their own pending/approved reservations that
 * hasn't finished yet. The row is KEPT (status becomes 'cancelled') so it
 * shows up under Past Events; its slot is freed because only pending and
 * approved reservations block a venue.
 */
session_start();
header('Content-Type: application/json');

function rc_fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (!isset($_SESSION['user_id'])) rc_fail(401, 'Please sign in again.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rc_fail(405, 'Method not allowed.');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../shared/scheduling.php';

$pdo    = get_db();
$userId = (int) $_SESSION['user_id'];

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$resId  = filter_var($in['reservation_id'] ?? null, FILTER_VALIDATE_INT);
$reason = mb_substr(trim((string) ($in['reason'] ?? '')), 0, 500);
if (!$resId) rc_fail(422, 'Missing reservation.');

$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND student_id = ? FOR UPDATE');
    $st->execute([$resId, $userId]);
    $res = $st->fetch();
    if (!$res) { $pdo->rollBack(); rc_fail(404, 'Reservation not found.'); }

    if (!in_array($res['status'], ['pending', 'approved'], true)) {
        $pdo->rollBack();
        rc_fail(422, 'Only pending or approved reservations can be cancelled.');
    }
    if (($res['event_date'] . ' ' . $res['end_time']) <= app_now()) {
        $pdo->rollBack();
        rc_fail(422, 'This event has already finished, so it can no longer be cancelled.');
    }

    $pdo->prepare("UPDATE reservations SET status = 'cancelled', cancelled_at = ?, cancel_reason = ? WHERE id = ?")
        ->execute([app_now(), $reason !== '' ? $reason : null, $res['id']]);

    // Any open reschedule request is moot now.
    if (sched_tables_ready($pdo)) {
        $pdo->prepare("UPDATE reservation_reschedule_requests SET status = 'withdrawn' WHERE reservation_id = ? AND status IN ('pending','suggested')")
            ->execute([$res['id']]);
    }

    // The borrowed items were never used — put them back in stock.
    $items = $pdo->prepare('SELECT resource_id, quantity FROM reservation_resources WHERE reservation_id = ? AND resource_id IS NOT NULL');
    $items->execute([$res['id']]);
    $restock = $pdo->prepare('UPDATE resources SET stock = stock + ? WHERE id = ?');
    foreach ($items->fetchAll() as $it) {
        $restock->execute([(int) $it['quantity'], (int) $it['resource_id']]);
    }

    notify_student($pdo, $userId, "Your reservation \"{$res['event_name']}\" ({$res['ref_no']}) has been cancelled. It stays in your Past Events history.");
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    rc_fail(500, 'Could not cancel the reservation. Please try again.');
}

echo json_encode(['success' => true, 'message' => 'Reservation cancelled. It now appears under Past Events.']);
