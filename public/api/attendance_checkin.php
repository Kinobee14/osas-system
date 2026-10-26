<?php
/**
 * api/attendance_checkin.php  (POST, JSON body: {token, student_id, full_name, course})
 *
 * Public endpoint (no login) used by public/attendance.php. Everything is
 * validated here, never in the browser: the token, the reservation status, the
 * attendance window and both submitted values. The time-in is the SERVER's
 * Asia/Manila clock. The response never contains anyone else's data.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

function ac_fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ac_fail(405, 'Method not allowed.');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../shared/events.php';
require_once __DIR__ . '/../../shared/attendance.php';

$raw  = file_get_contents('php://input', false, null, 0, 8192);
$data = json_decode($raw ?: '', true);
if (!is_array($data)) $data = $_POST;

$token = $data['token'] ?? '';
if (!att_valid_token_format($token)) ac_fail(404, 'This QR code is not valid.');

$pdo = get_db();

try {
    $st = $pdo->prepare(
        'SELECT s.id AS session_id, s.is_open, r.status, r.event_date, r.start_time, r.end_time
           FROM attendance_sessions s
           JOIN reservations r ON r.id = s.reservation_id
          WHERE s.token = ?'
    );
    $st->execute([$token]);
    $row = $st->fetch();
} catch (PDOException $e) {
    error_log('attendance_checkin lookup failed: ' . $e->getMessage());
    ac_fail(500, 'Attendance is temporarily unavailable. Please tell the event organizer.');
}
if (!$row) ac_fail(404, 'This QR code is not valid.');

$state = att_state($row);
if ($state['code'] !== 'open') ac_fail(403, $state['message']);

$studentId = att_normalize_student_id($data['student_id'] ?? null);
if ($studentId === null) ac_fail(422, 'Enter a valid Student ID (3-30 letters, numbers, dashes).');
$fullName = att_clean_name($data['full_name'] ?? null);
if ($fullName === null) ac_fail(422, 'Enter your full name using letters only (2-100 characters).');

$course = att_clean_course($data['course'] ?? null);
if ($course === null) ac_fail(422, 'Enter your course (for example BSIS), 2-50 characters.');

$sessionId = (int)$row['session_id'];

try {
    $dup = $pdo->prepare('SELECT time_in FROM attendance_records WHERE session_id = ? AND student_id = ?');
    $dup->execute([$sessionId, $studentId]);
    $existing = $dup->fetchColumn();
    if ($existing !== false) {
        ac_fail(409, 'You are already marked present (time-in ' . att_fmt_dt($existing) . ').');
    }

    $timeIn = app_now();
    try {
        $pdo->prepare('INSERT INTO attendance_records (session_id, student_id, full_name, course, time_in) VALUES (?, ?, ?, ?, ?)')
            ->execute([$sessionId, $studentId, $fullName, $course, $timeIn]);
    } catch (PDOException $e) {
        // Two taps at the same instant: the unique key let only one through.
        if (att_is_duplicate($e)) ac_fail(409, 'You are already marked present.');
        throw $e;
    }
} catch (PDOException $e) {
    error_log('attendance_checkin insert failed: ' . $e->getMessage());
    ac_fail(500, 'Could not save your attendance. Please try again.');
}

echo json_encode([
    'success' => true,
    'message' => 'You are marked present.',
    'data'    => ['student_id' => $studentId, 'full_name' => $fullName, 'course' => $course, 'time_in' => att_fmt_dt($timeIn)],
]);
