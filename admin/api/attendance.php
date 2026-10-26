<?php
// GET  ?reservation_id=N                  attendance session + summary for one reservation
// GET  ?reservation_id=N&export=csv       the same list as a CSV download
// POST ?action=generate   {reservation_id}  create the QR token (approved reservations only; idempotent)
// POST ?action=open       {reservation_id}  switch attendance on (before the window it is scheduled and opens by itself; closes by itself at the end time)
// POST ?action=close      {reservation_id}  switch attendance off
// POST ?action=regenerate {reservation_id}  issue a new token (the old QR stops working; records are kept)
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../shared/events.php';
require_once __DIR__ . '/../../shared/attendance.php';
$admin = require_auth();
$pdo = db();

// Same lazy sweep the other admin endpoints run, so statuses are fresh.
auto_complete_due_reservations($pdo);

/** One reservation joined to its attendance session (session_* columns are NULL if none yet). */
function att_load(PDO $pdo, int $rid): ?array {
    try {
        $st = $pdo->prepare(
            'SELECT r.id, r.ref_no, r.event_name, r.venue_name, r.campus, r.event_date, r.start_time, r.end_time,
                    r.status, r.requested_by, r.sponsoring_org,
                    s.id AS session_id, s.token, s.is_open, s.opened_at, s.closed_at
               FROM reservations r
               LEFT JOIN attendance_sessions s ON s.reservation_id = r.id
              WHERE r.id = ?'
        );
        $st->execute([$rid]);
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            respond(false, null, 'Attendance tables are missing. Import database/migration_attendance.sql in phpMyAdmin first.', 503);
        }
        throw $e;
    }
    $row = $st->fetch();
    return $row ?: null;
}

/** Full URL of the public folder, e.g. https://osas.example.edu.ph/osas-system/public */
function att_public_base(): string {
    $env = getenv('ATTENDANCE_BASE_URL');
    if ($env) return rtrim($env, '/');
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // /osas-system/admin/api/attendance.php → /osas-system/public
    $root   = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
    $root   = ($root === '/' || $root === '\\' || $root === '.') ? '' : $root;
    return ($https ? 'https' : 'http') . '://' . $host . $root . '/public';
}

function att_records(PDO $pdo, int $sessionId): array {
    $st = $pdo->prepare('SELECT student_id, full_name, course, time_in FROM attendance_records WHERE session_id = ? ORDER BY time_in ASC, id ASC');
    $st->execute([$sessionId]);
    return $st->fetchAll();
}

function att_payload(PDO $pdo, array $res): array {
    $reservation = [
        'id' => (int)$res['id'], 'ref_no' => $res['ref_no'], 'event_name' => $res['event_name'],
        'venue_name' => $res['venue_name'], 'campus' => $res['campus'], 'event_date' => $res['event_date'],
        'start_time' => $res['start_time'], 'end_time' => $res['end_time'], 'status' => $res['status'],
        'requested_by' => $res['requested_by'], 'sponsoring_org' => $res['sponsoring_org'],
    ];
    $session = null;
    $records = [];
    if ($res['session_id']) {
        $records = att_records($pdo, (int)$res['session_id']);
        $session = [
            'token'     => $res['token'],
            'is_open'   => (bool)$res['is_open'],
            'opened_at' => $res['opened_at'],
            'closed_at' => $res['closed_at'],
            'state'     => att_state($res),
        ];
    }
    return [
        'reservation' => $reservation,
        'eligible'    => $res['status'] === 'approved',
        'session'     => $session,
        'public_base' => att_public_base(),
        'total'       => count($records),
        'records'     => $records,
    ];
}

/** Stops spreadsheet apps from running a cell as a formula. */
function att_csv_safe(string $v): string {
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

if (method() === 'GET') {
    $rid = int_id($_GET['reservation_id'] ?? null);
    $res = att_load($pdo, $rid);
    if (!$res) respond(false, null, 'Reservation not found.', 404);

    if (($_GET['export'] ?? '') === 'csv') {
        if (!$res['session_id']) respond(false, null, 'No attendance has been set up for this reservation.', 404);
        $records = att_records($pdo, (int)$res['session_id']);
        $file = 'attendance-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $res['ref_no']) . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 names correctly
        $put = fn(array $row) => fputcsv($out, array_map(fn($c) => att_csv_safe((string)$c), $row), ',', '"', '', "\r\n");
        $put(['Event', $res['event_name']]);
        $put(['Reference', $res['ref_no']]);
        $put(['Venue', $res['venue_name']]);
        $put(['Date', att_fmt_date($res['event_date'])]);
        $put(['Time', att_fmt_time($res['start_time']) . ' - ' . att_fmt_time($res['end_time'])]);
        $put(['Total present', (string)count($records)]);
        $put([]);
        $put(['No.', 'Student ID', 'Full Name', 'Course', 'Time-in']);
        foreach ($records as $i => $r) {
            $put([(string)($i + 1), $r['student_id'], $r['full_name'], $r['course'], att_fmt_dt($r['time_in'])]);
        }
        fclose($out);
        exit;
    }

    respond(true, att_payload($pdo, $res));
}

if (method() === 'POST') {
    $action = $_GET['action'] ?? '';
    $data   = body();
    required($data, ['reservation_id']);
    $rid = int_id($data['reservation_id']);
    $res = att_load($pdo, $rid);
    if (!$res) respond(false, null, 'Reservation not found.', 404);

    switch ($action) {
        case 'generate':
            if ($res['status'] !== 'approved') {
                respond(false, null, 'A QR code can only be generated for an approved reservation.', 422);
            }
            if (!$res['session_id']) {
                try {
                    $pdo->prepare('INSERT INTO attendance_sessions (reservation_id, token, created_by, created_at) VALUES (?, ?, ?, ?)')
                        ->execute([$rid, att_new_token(), (int)$admin['id'], app_now()]);
                } catch (PDOException $e) {
                    // Another admin created it a split second earlier: that's fine, use theirs.
                    if (!att_is_duplicate($e)) throw $e;
                }
            }
            $msg = 'Attendance QR is ready.';
            break;

        case 'open':
            if (!$res['session_id']) respond(false, null, 'Generate the QR code first.', 422);
            if ($res['status'] !== 'approved') respond(false, null, 'Only an approved, ongoing reservation can take attendance.', 422);
            if (att_state($res)['code'] === 'ended') respond(false, null, 'This event has already ended, so attendance cannot be opened.', 422);
            $pdo->prepare('UPDATE attendance_sessions SET is_open = 1, opened_at = ?, closed_at = NULL WHERE id = ?')
                ->execute([app_now(), $res['session_id']]);
            // Before the window starts this only "arms" it; the window itself opens/closes it on schedule.
            $after = att_state(att_load($pdo, $rid));
            $msg = $after['code'] === 'scheduled' ? $after['message'] : 'Attendance is now open.';
            break;

        case 'close':
            if (!$res['session_id']) respond(false, null, 'Generate the QR code first.', 422);
            $pdo->prepare('UPDATE attendance_sessions SET is_open = 0, closed_at = ? WHERE id = ?')
                ->execute([app_now(), $res['session_id']]);
            $msg = 'Attendance is now closed.';
            break;

        case 'regenerate':
            if (!$res['session_id']) respond(false, null, 'Generate the QR code first.', 422);
            if ($res['status'] !== 'approved') respond(false, null, 'Only an approved reservation can get a new QR code.', 422);
            $pdo->prepare('UPDATE attendance_sessions SET token = ? WHERE id = ?')
                ->execute([att_new_token(), $res['session_id']]);
            $msg = 'New QR code issued. The old one no longer works.';
            break;

        default:
            respond(false, null, 'Unknown action.', 400);
    }

    respond(true, att_payload($pdo, att_load($pdo, $rid)), $msg);
}

respond(false, null, 'Method not allowed.', 405);
