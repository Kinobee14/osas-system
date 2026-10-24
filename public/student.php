<?php
/**
 * student.php
 * Student dashboard: My Reservation, New Reservation, Venue Availability,
 * Calendar, Notifications, Profile, Settings. Data is pulled from the
 * database for whichever account is currently logged in (see login.php).
 */
session_start();

// Not logged in? Bounce to the sign-in page instead of letting the page
// error out on a missing $_SESSION['user_id'].
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/../shared/events.php';
require_once __DIR__ . '/../shared/uploads.php';
require_once __DIR__ . '/../shared/scheduling.php';
require_once __DIR__ . '/../shared/votes.php';
require_once __DIR__ . '/../shared/signatories.php';

$pdo = get_db();
$userId = $_SESSION['user_id'];

// Any event whose end time has passed is Done — swept here so the student
// sees the same status the admin panel does, without waiting for a cron job
// this project doesn't have. See shared/events.php.
auto_complete_due_reservations($pdo);

// --- Logged-in user's profile, read fresh from the DB (not just the
// session) so Profile/Settings always reflect the latest saved data. ---
$stmt = $pdo->prepare('SELECT id, first_name, last_name, email, campus FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Session pointed at a user that no longer exists (e.g. deleted account) —
// clear it and send them back to sign in rather than letting the page error.
if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$fullName = trim($user['first_name'] . ' ' . $user['last_name']);
$initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));

// --- This user's reservations ---
$stmt = $pdo->prepare(
    'SELECT * FROM reservations WHERE student_id = ? ORDER BY event_date DESC'
);
$stmt->execute([$userId]);
$reservations = $stmt->fetchAll();

// --- Campus-local "now", and (once the reschedule migration is imported)
// every reservation's schedule history + its latest reschedule request. ---
$nowLocal   = app_now();
$schedReady = sched_tables_ready($pdo);
$historyByReservation  = [];
$requestsByReservation = [];
if ($schedReady && $reservations) {
    $ids = array_column($reservations, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT reservation_id, old_date, old_start, old_end, new_date, new_start, new_end, reason, changed_at
           FROM reservation_schedule_history WHERE reservation_id IN ($in) ORDER BY id"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $h) {
        $historyByReservation[$h['reservation_id']][] = [
            'oldDate' => $h['old_date'], 'oldStart' => substr($h['old_start'], 0, 5), 'oldEnd' => substr($h['old_end'], 0, 5),
            'newDate' => $h['new_date'], 'newStart' => substr($h['new_start'], 0, 5), 'newEnd' => substr($h['new_end'], 0, 5),
            'reason'  => $h['reason'], 'changedAt' => $h['changed_at'],
        ];
    }

    $stmt = $pdo->prepare("SELECT * FROM reservation_reschedule_requests WHERE reservation_id IN ($in) ORDER BY id DESC");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $q) {
        if (isset($requestsByReservation[$q['reservation_id']])) continue; // newest only
        $requestsByReservation[$q['reservation_id']] = [
            'id' => (int)$q['id'], 'status' => $q['status'],
            'newDate' => $q['new_date'], 'newStart' => substr($q['new_start'], 0, 5), 'newEnd' => substr($q['new_end'], 0, 5),
            'reason' => $q['reason'], 'adminNote' => $q['admin_note'],
            'sugDate' => $q['suggested_date'],
            'sugStart' => $q['suggested_start'] ? substr($q['suggested_start'], 0, 5) : null,
            'sugEnd'   => $q['suggested_end'] ? substr($q['suggested_end'], 0, 5) : null,
            'createdAt' => $q['created_at'], 'respondedAt' => $q['responded_at'],
        ];
    }
}

// --- Resources (chairs, tables, fans, etc.) borrowed per reservation, so
// "My Reservation" can show what was requested alongside the venue/date. ---
$resourcesByReservation = [];
if ($reservations) {
    $ids = array_column($reservations, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT reservation_id, resource_id, resource_name, quantity FROM reservation_resources WHERE reservation_id IN ($in) ORDER BY resource_name");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $rr) {
        $resourcesByReservation[$rr['reservation_id']][] = ['id' => $rr['resource_id'] ? (int)$rr['resource_id'] : null, 'name' => $rr['resource_name'], 'qty' => (int)$rr['quantity']];
    }
}

// --- Requirement files attached to each reservation. The bytes are served
// by api/attachment.php (which re-checks ownership); this is metadata only. ---
$filesByReservation = [];
if ($reservations) {
    $ids = array_column($reservations, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, reservation_id, kind, label, original_name, mime_type, size_bytes
           FROM reservation_attachments
          WHERE reservation_id IN ($in)
          ORDER BY kind DESC, id"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $f) {
        $filesByReservation[$f['reservation_id']][] = [
            'id'    => (int)$f['id'],
            'kind'  => $f['kind'],
            'name'  => $f['original_name'],
            'label' => $f['label'],
            'size'  => format_bytes((int)$f['size_bytes']),
            'url'   => 'api/attachment.php?type=requirement&id=' . (int)$f['id'],
        ];
    }
}

// --- The student's own post-event reports, with where each one stands in
// OSAS's review, so My Reservation can show "under review" / "needs changes"
// and pre-fill a resubmission. ---
$reportsByReservation = [];
if ($reservations) {
    $ids = array_column($reservations, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT pr.id, pr.reservation_id, pr.report_no, pr.attendees_count, pr.summary,
                pr.issues, pr.recommendations, pr.prepared_by, pr.created_at,
                pr.review_status, pr.review_remarks, pr.reviewed_at
           FROM post_event_reports pr
          WHERE pr.reservation_id IN ($in)"
    );
    $stmt->execute($ids);
    $reportRows = $stmt->fetchAll();

    $photosByReport = [];
    if ($reportRows) {
        $reportIds = array_column($reportRows, 'id');
        $pin = implode(',', array_fill(0, count($reportIds), '?'));
        $ph = $pdo->prepare("SELECT id, report_id, caption, original_name FROM post_event_photos WHERE report_id IN ($pin) ORDER BY id");
        $ph->execute($reportIds);
        foreach ($ph->fetchAll() as $p) {
            $photosByReport[$p['report_id']][] = [
                'caption' => $p['caption'] !== '' ? $p['caption'] : $p['original_name'],
                'url'     => 'api/attachment.php?type=photo&id=' . (int)$p['id'],
            ];
        }
    }

    foreach ($reportRows as $rep) {
        $reportsByReservation[$rep['reservation_id']] = [
            'no'          => $rep['report_no'],
            'attendees'   => (int)$rep['attendees_count'],
            'summary'     => $rep['summary'],
            'issues'      => $rep['issues'],
            'recommends'  => $rep['recommendations'],
            'preparedBy'  => $rep['prepared_by'],
            'filedOn'     => $rep['created_at'],
            'status'      => $rep['review_status'],
            'remarks'     => $rep['review_remarks'],
            'reviewedAt'  => $rep['reviewed_at'],
            'photos'      => $photosByReport[$rep['id']] ?? [],
        ];
    }
}

// --- Which admin approved / declined each reservation (with their comment) ---
$votesByReservation = [];
if ($reservations) {
    $vIds = array_column($reservations, 'id');
    $vIn  = implode(',', array_fill(0, count($vIds), '?'));
    $vq = $pdo->prepare(
        "SELECT x.reservation_id, x.vote, x.reason, x.voted_at, a.display_name, a.office
           FROM reservation_votes x JOIN admins a ON a.id = x.admin_id
          WHERE x.reservation_id IN ($vIn) ORDER BY x.voted_at, x.id"
    );
    $vq->execute($vIds);
    $voteTz = new DateTimeZone(app_timezone());
    foreach ($vq->fetchAll() as $v) {
        // voted_at is stored in UTC (the session clock); show it in campus time.
        $when = (new DateTime($v['voted_at'], new DateTimeZone('UTC')))->setTimezone($voteTz)->format('M j, Y g:i A');
        $votesByReservation[$v['reservation_id']][] = [
            'name'   => $v['office'] && $v['office'] !== 'Admin' ? 'Vice ' . $v['office'] : $v['display_name'],
            'vote'   => $v['vote'],
            'reason' => $v['reason'],
            'at'     => $when,
        ];
    }
}

$reservationsForJs = array_map(function ($r) use ($votesByReservation, $resourcesByReservation, $filesByReservation, $reportsByReservation, $historyByReservation, $requestsByReservation, $nowLocal) {
    $ts = strtotime($r['event_date']);
    return [
        'id'       => (int)$r['id'],
        'ref'      => $r['ref_no'],
        'event'    => $r['event_name'],
        'venue'    => $r['venue_name'],
        'y'        => (int)date('Y', $ts),
        'm'        => (int)date('n', $ts) - 1,
        'd'        => (int)date('j', $ts),
        'time'     => fmt_time_range($r['start_time'], $r['end_time']),
        'status'   => $r['status'],
        'purpose'  => $r['purpose'],
        // Full form fields, so a pending/declined request can be edited.
        'form' => [
            'scope' => $r['activity_scope'], 'campus' => $r['campus'],
            'nature' => $r['nature_of_activity'], 'natureOther' => $r['nature_other'],
            'participants' => $r['participants'] ? (int)$r['participants'] : null,
            'sponsor' => $r['sponsoring_org'], 'fund' => $r['source_of_fund'],
            'adviser' => $r['adviser_name'], 'recommending' => $r['recommending_name'],
            'remarks' => $r['remarks'], 'checklist' => $r['attachments_checklist'],
        ],
        'declineReason' => $r['decline_reason'] ?? null,
        'votes'    => $votesByReservation[$r['id']] ?? [],
        'resources'=> $resourcesByReservation[$r['id']] ?? [],
        'files'    => $filesByReservation[$r['id']] ?? [],
        'report'   => $reportsByReservation[$r['id']] ?? null,
        'isoDate'  => $r['event_date'],
        'start'    => substr($r['start_time'], 0, 5),
        'end'      => substr($r['end_time'], 0, 5),
        'venueId'  => $r['venue_id'] ? (int)$r['venue_id'] : null,
        'isPast'   => (($r['event_date'] . ' ' . $r['end_time']) <= $nowLocal),
        'cancelReason' => $r['cancel_reason'] ?? null,
        'cancelledAt'  => $r['cancelled_at'] ?? null,
        'history'    => $historyByReservation[$r['id']] ?? [],
        'reschedule' => $requestsByReservation[$r['id']] ?? null,
    ];
}, $reservations);

// --- Notifications for this user ---
$stmt = $pdo->prepare(
    'SELECT id, message, created_at FROM notifications WHERE student_id = ? ORDER BY created_at DESC, id DESC LIMIT 15'
);
$stmt->execute([$userId]);
$notifRows = $stmt->fetchAll();
$notificationsForJs = array_map(function ($n) {
    // created_at is stored in UTC (session clock); show the exact moment in campus time too.
    $at = (new DateTime($n['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(app_timezone()))->format('M j, Y g:i A');
    return ['id' => (int)$n['id'], 'text' => $n['message'], 'time' => time_ago($n['created_at']), 'at' => $at];
}, $notifRows);

function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return floor($diff / 604800) . ' weeks ago';
}

// --- Venues, with "booked today" status computed from reservations table ---
$venues = $pdo->query('SELECT id, name, capacity, campus, status FROM venues WHERE is_active = 1 ORDER BY name')->fetchAll();
// Venues an admin has taken out of service shouldn't be offered on the "New Reservation" form.
$bookableVenues = array_values(array_filter($venues, fn($v) => $v['status'] !== 'maintenance'));
$todayBooked = $pdo->query(
    "SELECT DISTINCT venue_name FROM reservations WHERE event_date = CURDATE() AND status NOT IN ('declined','cancelled')"
)->fetchAll(PDO::FETCH_COLUMN);

// --- Resources students can choose to borrow on the "New Reservation" form.
// Only what a Vice Admin has added on the admin Inventory page shows up here. ---
$resources = $pdo->query('SELECT id, name, stock FROM resources ORDER BY name')->fetchAll();

// --- All events across all users/venues, for the embedded Calendar tab.
// Same shape/logic calendar.php uses, just built here so the calendar can
// live inside the dashboard instead of on its own page. ---
$allEventRows = $pdo->query(
    "SELECT event_name, venue_id, venue_name, campus, event_date, start_time, end_time, requested_by, status, student_id
     FROM reservations
     WHERE status <> 'cancelled'
     ORDER BY event_date ASC"
)->fetchAll();

$calendarEvents = [];
foreach ($allEventRows as $r) {
    $ts = strtotime($r['event_date']);
    $key = date('Y', $ts) . '-' . ((int)date('n', $ts) - 1) . '-' . (int)date('j', $ts);
    $calendarEvents[$key][] = [
        'title'       => $r['event_name'],
        'time'        => fmt_time_range($r['start_time'], $r['end_time']),
        'venue'       => $r['venue_name'],
        'campus'      => $r['campus'],
        'requestedBy' => $r['requested_by'],
        'status'      => $r['status'],
        'venueId'     => $r['venue_id'] ? (int)$r['venue_id'] : null,
        'start'       => substr($r['start_time'], 0, 5),
        'end'         => substr($r['end_time'], 0, 5),
        'mine'        => (int)$r['student_id'] === (int)$userId,
    ];
}
$calendarEventsJson = json_encode($calendarEvents, JSON_UNESCAPED_SLASHES);

// Which sub-page to show first (used after a successful new reservation, etc).
$initialPage = $_GET['page'] ?? 'dashboard';
$allowedPages = ['dashboard','myReservations','newReservation','upcoming','history','venues','calendar','profile','settings'];
if (!in_array($initialPage, $allowedPages, true)) $initialPage = 'dashboard';
$justSubmitted = isset($_GET['submitted']);
$justReported = isset($_GET['reported']);
$flashTexts = [
    'edited'         => 'Your reservation was updated and sent back to OSAS. It shows as Pending until they review it again.',
    'resched_sent'   => 'Reschedule request sent. OSAS will approve, reject or suggest another time. Your current schedule stays the same until then.',
    'accepted'       => 'Done! Your reservation was moved to the suggested time. The original schedule is kept in your history.',
    'declined'       => 'Suggestion declined. Your original schedule is unchanged.',
    'withdrawn'      => 'Reschedule request withdrawn.',
    'cancelled'      => 'Reservation cancelled. It is kept under Past Events.',
];
$flash = $flashTexts[$_GET['flash'] ?? ''] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard — Resource Keeper Management System</title>
<style>
:root{
  --green-dark:#0d2b1f;
  --green-mid:#1f5c3f;
  --green-light:#2f8a5b;
  --cream:#f5f0e3;
  --cream-panel:#f7f3e8;
  --ink:#1c2b23;
  --ink-soft:#5b6b62;
  --line:#e2dac6;
  --gold:#c9a24a;
  --pending:#d98a3d;
  --confirmed:#3f8a5c;
}
*{box-sizing:border-box;}
html,body{margin:0;padding:0;}
body{
  font-family:"Georgia","Iowan Old Style",serif;
  background:var(--cream);
  color:var(--ink);
  min-height:100vh;
}
.sans{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;}

.btn{
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;
  font-size:.92rem; padding:11px 26px; border-radius:7px; cursor:pointer;
  border:1.5px solid transparent; transition:transform .15s ease, background .15s ease;
  text-decoration:none; display:inline-block;
}
.btn:hover{transform:translateY(-1px);}
.btn-solid{background:var(--green-light);color:#fff;}
.btn-solid:hover{background:#37996a;}

/* ================= STUDENT DASHBOARD ================= */
.student-shell{display:flex;min-height:100vh;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;}
.stu-sidebar{
  width:230px;flex-shrink:0;color:#fff;padding:26px 0 20px;
  background:linear-gradient(180deg,#1f5c3f 0%, #0d2b1f 100%);
  display:flex;flex-direction:column;
  position:sticky;top:0;height:100vh;overflow-y:auto;
}
.stu-brand{display:flex;align-items:center;gap:12px;padding:0 20px 18px;margin-bottom:16px;border-bottom:1px solid rgba(255,255,255,.15);text-decoration:none;color:#fff;}
.stu-brand__logo{width:42px;height:42px;border-radius:8px;background:rgba(255,255,255,.92);padding:3px;display:grid;place-items:center;overflow:hidden;flex-shrink:0;}
.stu-brand__logo img{width:100%;height:100%;object-fit:contain;display:block;}
.stu-brand__name{font-family:Georgia,serif;font-size:1.15rem;font-weight:700;line-height:1.1;}
.stu-brand__role{font-size:.64rem;color:#bfe0cd;letter-spacing:.04em;}
a.file-pill{text-decoration:none;display:inline-flex;align-items:center;}
.stu-profile{
  display:flex;align-items:center;gap:10px;padding:0 20px 18px;
  border-bottom:1px solid rgba(255,255,255,.15);margin-bottom:16px;cursor:pointer;
  text-decoration:none;color:inherit;
}
.stu-profile svg{width:32px;height:38px;flex-shrink:0;}
.stu-profile .name{font-size:.9rem;font-weight:700;line-height:1.25; }
.stu-profile .role{font-size:.66rem;color:#bfe0cd;}
.stu-nav-group{list-style:none;margin:0 0 20px;padding:0 14px;}
.stu-nav-group li, .stu-nav-group li a{
  display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:6px;
  font-size:.82rem;cursor:pointer;color:#e7f3ea;margin-bottom:3px;transition:background .15s;
  text-decoration:none;
}
.stu-nav-group li:hover{background:rgba(255,255,255,.08);}
.stu-nav-group li.active{background:rgba(255,255,255,.18);font-weight:700;}
.stu-nav-group li svg{width:15px;height:15px;flex-shrink:0;opacity:.9;}
.stu-main{flex:1;background:var(--cream);padding:34px 42px;overflow-y:auto;}
.stu-topline{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:26px;flex-wrap:wrap;gap:14px;}
.stu-topline .date{font-size:.76rem;color:var(--ink-soft);margin:0 0 4px;}
.stu-topline h1{font-family:Georgia,serif;font-size:1.55rem;margin:0;font-weight:700;}
.stu-add-btn{
  background:var(--green-light);color:#fff;border:none;padding:10px 18px;
  border-radius:6px;font-size:.82rem;cursor:pointer;font-weight:600;white-space:nowrap;
  text-decoration:none;display:inline-block;
}
.stu-add-btn:hover{background:#37996a;}
.stu-topline .stu-add-btn{margin-right:56px;}  /* keeps the button clear of the fixed bell */

.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:30px;}
@media(max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr);}}
.stat-card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:18px;}
.stat-card .stat-num{font-family:Georgia,serif;font-size:1.75rem;font-weight:700;margin:0 0 4px;}
.stat-card .stat-label{font-size:.74rem;color:var(--ink-soft);}

.stu-columns{display:grid;grid-template-columns:1.4fr 1fr;gap:24px;}
@media(max-width:900px){.stu-columns{grid-template-columns:1fr;}}
.stu-col h2{font-family:Georgia,serif;font-size:1.1rem;margin:0 0 12px;}
.stu-item-box{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px 16px;margin-bottom:12px;}
.stu-item-box .stu-item-top{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:4px;}
.stu-item-box h3{font-size:.9rem;margin:0;font-family:Georgia,serif;}
.stu-item-box .meta{font-size:.74rem;color:var(--ink-soft);}
.stu-box{background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px 16px;margin-bottom:22px;}
.notif-item{padding:11px 0;border-bottom:1px solid var(--line);font-size:.8rem;line-height:1.45;}
.notif-item:last-child{border-bottom:none;}
.notif-item .n-time{font-size:.68rem;color:var(--ink-soft);margin-top:3px;}
/* Notification bell (dropdown) */
.bell-wrap{position:fixed;top:30px;right:26px;z-index:60;}
.bell-btn{position:relative;width:42px;height:42px;border-radius:50%;border:1px solid var(--line);background:#fff;color:var(--green-dark);display:grid;place-items:center;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.08);}
.bell-btn:hover{background:var(--cream-panel);}
.bell-btn svg{width:20px;height:20px;}
.bell-dot{position:absolute;top:-5px;right:-5px;min-width:19px;height:19px;padding:0 5px;border-radius:10px;background:#d93025;color:#fff;font-size:.65rem;font-weight:700;line-height:1;display:grid;place-items:center;border:2px solid var(--cream);box-sizing:border-box;}
.bell-dot[hidden],.bell-menu[hidden]{display:none;}
.bell-menu{position:absolute;top:52px;right:0;width:340px;max-width:calc(100vw - 28px);background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 16px 40px -12px rgba(0,0,0,.28);overflow:hidden;}
.bell-head{padding:12px 14px;font-weight:700;font-size:.85rem;border-bottom:1px solid var(--line);}
.bell-list{max-height:min(420px,70vh);overflow-y:auto;}
.bell-list .notif-item{padding:11px 14px;}
.bell-list .notif-item.unread{background:#fdf0ee;}
.bell-list .empty-note{padding:18px 14px;margin:0;}
.bell-menu{width:400px;}
.bell-list .notif-item.nc{padding:12px 14px 12px 12px;border-left:4px solid var(--line);}
.nc--ok{border-left-color:var(--confirmed)!important;}
.nc--bad{border-left-color:#b23b2e!important;}
.nc--warn{border-left-color:#c77d1a!important;}
.nc--info{border-left-color:#8a9a92!important;}
.nc-title{font-weight:700;font-size:.84rem;color:var(--green-dark);}
.nc-time{font-size:.68rem;color:var(--ink-soft);margin:1px 0 6px;}
.nc-text{font-size:.78rem;line-height:1.45;overflow-wrap:anywhere;}
.nc-meta{font-size:.7rem;color:var(--ink-soft);margin-top:5px;}
.nc-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:9px;}
.nc-chips{display:flex;gap:5px;flex-wrap:wrap;}
.nc-btn{border:1px solid var(--green-dark);background:var(--green-dark);color:#fff;font:inherit;font-size:.72rem;font-weight:700;padding:6px 13px;border-radius:7px;cursor:pointer;text-decoration:none;white-space:nowrap;}
.nc-btn:hover{filter:brightness(1.12);}
.stu-box .notif-item.nc{padding:12px 12px;}
@media(max-width:768px){.bell-wrap{top:12px;right:14px;}}
.week-item{padding:10px 0;border-bottom:1px solid var(--line);font-size:.8rem;}
.week-item:last-child{border-bottom:none;}
.week-item .meta{font-size:.72rem;color:var(--ink-soft);margin-top:2px;}

.badge{display:inline-block;font-size:.66rem;font-weight:700;padding:3px 11px;border-radius:20px;letter-spacing:.3px;white-space:nowrap;}
.badge.b-pending{background:#fbead9;color:var(--pending);}
.badge.b-approved{background:#e2f2e6;color:var(--confirmed);}
.badge.b-completed{background:#e7e7ee;color:#5b5b70;}
.badge.b-declined{background:#fbe4e0;color:#b23b2e;}

.stu-table-wrap{background:#fff;border:1px solid var(--line);border-radius:8px;overflow-x:auto;}
table.stu-table{width:100%;min-width:560px;border-collapse:collapse;font-size:.85rem;}
table.stu-table th{text-align:left;font-size:.68rem;color:var(--ink-soft);letter-spacing:.4px;padding:14px 18px;border-bottom:1px solid var(--line);background:#faf6ea;}
table.stu-table td{padding:14px 18px;border-bottom:1px solid var(--line);}
table.stu-table tr:last-child td{border-bottom:none;}

.stu-form{background:#fff;border:1px solid var(--line);border-radius:8px;padding:30px;width:100%;}
.stu-form label{display:block;font-size:.78rem;font-weight:700;margin:0 0 6px;color:var(--ink);}
.stu-form input, .stu-form select, .stu-form textarea{
  width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:6px;
  font-size:.88rem;margin-bottom:18px;font-family:inherit;background:#fdfcf7;
}
.stu-form textarea{resize:vertical;min-height:120px;}
.stu-form .form-row{display:flex;gap:18px;}
.stu-form .form-row > div{flex:1;min-width:0;}
.stu-form .form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:0 18px;}
.stu-form .form-grid-2 > div{min-width:0;}
.stu-form .span-2{grid-column:1 / -1;}
.stu-form .btn-solid{width:100%;padding:13px;font-size:.95rem;}

/* ---- Reservation page: full-width two-column layout ---- */
.reservation-layout{display:grid;grid-template-columns:1.7fr 1fr;gap:24px;align-items:start;}
@media(max-width:900px){.reservation-layout{grid-template-columns:1fr;}}
.info-card{background:var(--cream-panel);border:1px solid var(--line);border-radius:8px;padding:22px;}
.info-card h3{font-family:Georgia,serif;font-size:1.02rem;margin:0 0 14px;}
.info-card ul{margin:0;padding-left:18px;}
.info-card ul li{font-size:.82rem;color:var(--ink-soft);line-height:1.7;}
.info-card + .info-card{margin-top:20px;}

/* ---- OSAS "Permission to Conduct Activity / Program" paper form ---- */
.ocf{border:1px solid #222;background:#fff;color:#111;font-family:"Segoe UI",Arial,sans-serif;margin-bottom:22px;}
.ocf-head{display:flex;align-items:center;justify-content:center;gap:16px;padding:14px 12px 8px;text-align:center;}
.ocf-head img{height:62px;width:auto;}
.ocf-head .u{font-weight:800;font-size:1.02rem;letter-spacing:.3px;}
.ocf-head .a{font-size:.72rem;line-height:1.35;}
.ocf-head .o{font-family:Georgia,serif;font-weight:700;font-size:1rem;margin-top:6px;letter-spacing:.4px;}
.ocf-title{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;padding:6px 14px 4px;font-weight:700;font-size:.8rem;}
.ocf-title .scope{display:inline-flex;align-items:center;gap:8px;}
.ocf-title .scope label{display:inline-flex;align-items:center;margin:0 6px 0 0 !important;}
.stu-form .ocf input[type=radio],.stu-form .ocf input[type=checkbox]{width:17px;height:17px;padding:0;margin:0;accent-color:var(--green-mid);flex:none;}
.ocf-line{display:flex;align-items:flex-end;gap:8px;padding:2px 14px;font-size:.8rem;font-weight:700;}
.ocf-line label{margin:0 !important;white-space:nowrap;}
.stu-form .ocf .u-in{border:0;border-bottom:1px solid #222;border-radius:0;background:transparent;padding:3px 4px;margin:0;font-size:.85rem;flex:1;min-width:0;}
.stu-form .ocf .u-in:focus{outline:none;border-bottom:2px solid var(--green-light);background:#f4faf6;}
.stu-form .ocf .u-in[readonly]{color:#444;}
.ocf-nature{display:grid;grid-template-columns:1.2fr 1fr;gap:6px 18px;padding:6px 14px 14px;}
.ocf-nature .opt{display:flex;align-items:center;gap:10px;font-size:.82rem;margin:0 0 7px !important;font-weight:500 !important;cursor:pointer;}
.ocf-others{display:flex;flex-direction:column;gap:6px;}
.ocf-grid{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #222;}
.ocf-cell{border-bottom:1px solid #222;padding:7px 12px 8px;min-width:0;}
.ocf-cell.full{grid-column:1 / -1;}
.ocf-cell.r{border-left:1px solid #222;}
.ocf-cell.nb{border-bottom:0;}
.stu-form .ocf .ocf-cell > label{font-size:.8rem;font-weight:800;margin:0 0 3px;}
.stu-form .ocf .ocf-cell input:not([type=radio]):not([type=checkbox]),.stu-form .ocf .ocf-cell select,.stu-form .ocf .ocf-cell textarea{
  border:0;border-radius:0;background:transparent;margin:0;padding:4px 2px;font-size:.88rem;box-shadow:none;}
.stu-form .ocf .ocf-cell textarea{min-height:84px;}
.stu-form .ocf .ocf-cell input:focus,.stu-form .ocf .ocf-cell select:focus,.stu-form .ocf .ocf-cell textarea:focus{outline:none;background:#f4faf6;}
.ocf-time{display:flex;gap:8px;align-items:center;}
.ocf-time span{font-size:.8rem;}
.ocf-sign{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #222;}
.ocf-sign > div{padding:10px 14px;font-size:.78rem;min-width:0;}
.ocf-sign .left{border-right:1px solid #222;}
.ocf-sign .sigline{margin-top:20px;text-align:center;}
.ocf-sign .sigline small{display:block;font-size:.76rem;}
.ocf-sign h4{margin:0 0 6px;font-size:.82rem;font-weight:800;}
.ocf-sign .nm{text-align:center;margin:14px 0 0;}
.ocf-sign .nm b{display:inline-block;text-decoration:underline;font-size:.82rem;}
.ocf-sign .nm small{display:block;font-size:.72rem;}
.ocf-sign .right-b{border-top:1px solid #222;}
.ocf-sign .remarks{margin-top:12px;font-size:.8rem;}
.ocf-sign .remarks i{display:block;border-bottom:1px solid #777;height:22px;}
.ocf-sign .remarks textarea{display:block;width:100%;box-sizing:border-box;border:0;border-radius:0;resize:vertical;margin:0;padding:0 4px;font:inherit;font-size:.8rem;line-height:22px;min-height:66px;background:repeating-linear-gradient(to bottom,transparent 0,transparent 21px,#777 21px,#777 22px);}
.ocf-sign .remarks textarea:focus{outline:none;background-color:#f4faf6;}

.ocf-att{border-top:1px solid #222;padding:10px 14px 12px;}
.ocf-att h4{margin:0 0 6px;font-size:.84rem;font-weight:800;}
.ocf-att .cols{display:grid;grid-template-columns:1fr 1fr;gap:2px 18px;}
.ocf-att .opt{display:flex;align-items:center;gap:8px;font-size:.84rem;margin:0 0 5px !important;font-weight:500 !important;cursor:pointer;}
.form-sec-title{font-family:Georgia,serif;font-size:1rem;margin:4px 0 10px;}
@media(max-width:700px){
  .ocf-nature,.ocf-grid,.ocf-sign,.ocf-att .cols{grid-template-columns:1fr;}
  .ocf-cell.r{border-left:0;}
  .ocf-sign .left{border-right:0;border-bottom:1px solid #222;}
  .ocf-head{flex-direction:column;}
}
.venue-preview{background:#fff;border:1px solid var(--line);border-radius:8px;padding:16px 18px;margin-top:16px;}
.venue-preview .vp-name{font-family:Georgia,serif;font-size:1rem;margin:0 0 8px;}
.venue-preview .vp-row{display:flex;justify-content:space-between;font-size:.78rem;color:var(--ink-soft);padding:4px 0;border-bottom:1px dashed var(--line);}
.venue-preview .vp-row:last-child{border-bottom:none;}
.venue-preview .vp-row span:last-child{color:var(--ink);font-weight:600;}
.venue-preview.empty{color:var(--ink-soft);font-size:.82rem;text-align:center;padding:24px 18px;}
.form-success{
  background:#e2f2e6;color:var(--confirmed);border-radius:6px;padding:10px 14px;
  font-size:.82rem;margin-bottom:16px;font-weight:600;
}
.form-error{
  background:#fbe4e0;color:#b23b2e;border-radius:6px;padding:10px 14px;
  font-size:.82rem;margin-bottom:16px;font-weight:600;
}

/* ---- Resources-to-borrow picker (dropdown + inline quantity panel + chips) ---- */
.res-chip-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;}
.res-chip{
  display:flex;align-items:center;gap:8px;background:var(--cream-panel);
  border:1px solid var(--line);border-radius:20px;padding:6px 8px 6px 14px;font-size:.8rem;
}
.res-chip-name{font-weight:700;}
.res-chip-qty{color:var(--ink-soft);}
.res-chip-edit, .res-chip-remove{
  border:none;background:#fff;border-radius:50%;width:22px;height:22px;cursor:pointer;
  color:var(--ink-soft);font-size:.85rem;line-height:1;display:inline-flex;align-items:center;justify-content:center;
}
.res-chip-edit:hover{color:var(--green-mid);}
.res-chip-remove:hover{color:#b23b2e;}

/* Inline "how many?" panel — sits right under the dropdown, hidden until a
   resource is picked from it. */
.res-inline-picker{
  background:var(--cream-panel);border:1px solid var(--line);border-radius:8px;
  padding:14px 16px;margin-top:10px;
}
.res-inline-picker-head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:10px;flex-wrap:wrap;}
.res-inline-name{font-family:Georgia,serif;font-size:.95rem;font-weight:700;}
.res-inline-stock{font-size:.76rem;color:var(--ink-soft);}
.res-inline-controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.res-inline-controls input{
  width:100px;padding:9px 11px;border:1px solid var(--line);border-radius:6px;
  font-size:.9rem;font-family:inherit;background:#fdfcf7;margin-bottom:0;
}
.res-inline-controls .btn{padding:9px 18px;font-size:.82rem;width:auto;}


.venue-card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:16px 18px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
.venue-card h3{margin:0 0 4px;font-family:Georgia,serif;font-size:1rem;}
.venue-card .meta{font-size:.78rem;color:var(--ink-soft);}

.profile-card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:26px;max-width:460px;}
.profile-card .avatar{
  width:60px;height:60px;border-radius:50%;background:var(--green-light);color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:700;margin-bottom:16px;
}
.settings-row{display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid var(--line);}
.settings-row:last-child{border-bottom:none;}
.settings-row .s-label{font-size:.85rem;font-weight:600;}
.settings-row .s-desc{font-size:.74rem;color:var(--ink-soft);margin-top:2px;}
.toggle{width:42px;height:24px;border-radius:14px;background:var(--line);position:relative;cursor:pointer;transition:background .2s;flex-shrink:0;}
.toggle.on{background:var(--green-light);}
.toggle .knob{width:18px;height:18px;border-radius:50%;background:#fff;position:absolute;top:3px;left:3px;transition:left .2s;}
.toggle.on .knob{left:21px;}

.empty-note, .empty-state{font-size:.85rem;color:var(--ink-soft);padding:16px 0;}

/* ---- Embedded calendar (Calendar tab) ---- */
.cal-layout{display:grid;grid-template-columns:1.55fr 1fr;gap:26px;align-items:start;}
@media(max-width:900px){.cal-layout{grid-template-columns:1fr;}}
.panel{background:var(--cream-panel);border:1px solid var(--line);border-radius:8px;padding:22px;}
.cal-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap;}
.cal-toolbar input{
  flex:1;padding:9px 12px;border:1px solid var(--line);border-radius:6px;
  background:#fff;font-size:.85rem;color:var(--ink-soft);min-width:140px;
}
.cal-toolbar .month-label{font-family:Georgia,serif;font-weight:600;font-size:1.05rem;white-space:nowrap;}
.cal-toolbar button{
  border:1px solid var(--line);background:#fff;border-radius:6px;
  width:32px;height:32px;cursor:pointer;font-size:.9rem;color:var(--ink);
}
.cal-toolbar button.today-btn{width:auto;padding:0 14px;font-size:.75rem;letter-spacing:.5px;}
.cal-toolbar button:hover{background:#efe9d8;}

table.cal{width:100%;border-collapse:collapse;table-layout:fixed;}
table.cal th{font-size:.72rem;color:var(--ink-soft);font-weight:600;padding-bottom:8px;letter-spacing:.4px;}
table.cal td{
  height:64px;vertical-align:top;border:1px solid var(--line);
  padding:6px 8px;font-size:.85rem;color:var(--ink);cursor:pointer;position:relative;
  background:#fff;transition:background .12s ease;
}
table.cal td.empty{background:transparent;border-color:transparent;cursor:default;}
table.cal td:not(.empty):hover{background:#f0ead7;}
table.cal td.selected{border:2px solid var(--green-light);background:#eef7f0;}
table.cal td.match{border:2px solid var(--gold);background:#fbf3de;}
table.cal td.dimmed{color:#c9c2ac;}
.dot{width:6px;height:6px;border-radius:50%;background:var(--green-light);position:absolute;bottom:8px;left:8px;}

.panel-title{font-family:Georgia,serif;font-size:1.15rem;font-weight:600;margin:0 0 18px;}
.day-label{font-size:.72rem;color:var(--ink-soft);margin:0 0 2px;letter-spacing:.3px;}
.date-heading{font-family:Georgia,serif;font-size:1.3rem;font-weight:600;margin:0 0 18px;}

.event-card{
  background:#fff;border:1px solid var(--line);border-left:3px solid var(--green-light);
  border-radius:6px;padding:13px 15px;margin-bottom:12px;
}
.event-card .ev-date{font-size:.68rem;color:var(--ink-soft);margin-bottom:4px;}
.event-card h3{font-family:Georgia,serif;font-size:1rem;margin:0 0 8px;}
.event-card .ev-meta{font-size:.78rem;color:var(--ink-soft);line-height:1.55;margin-bottom:8px;}
.status{display:inline-block;font-size:.68rem;font-weight:700;letter-spacing:.4px;padding:3px 10px;border-radius:20px;}
.status.confirmed, .status.approved{background:#e2f2e6;color:var(--confirmed);}
.status.pending{background:#fbead9;color:var(--pending);}
.status.declined{background:#fbe4e0;color:#b23b2e;}
.status.completed{background:#e7e7ee;color:#5b5b70;}

.back-link{display:inline-block;margin-bottom:16px;font-size:.8rem;color:var(--green-mid);cursor:pointer;text-decoration:none;}
.back-link:hover{text-decoration:underline;}

.search-wrap{position:relative;flex:1;}
.search-wrap input{width:100%;}
.search-clear{
  position:absolute;right:8px;top:50%;transform:translateY(-50%);
  border:none;background:transparent;color:var(--ink-soft);cursor:pointer;
  font-size:.9rem;width:auto;height:auto;padding:2px 4px;
}
.search-clear:hover{color:var(--ink);background:transparent;}
.search-meta{font-size:.75rem;color:var(--ink-soft);margin:0 0 14px;}
.result-card{cursor:pointer;}
.result-card:hover{border-left-color:var(--gold);background:#fffdf6;}

/* ---- Upcoming events pagination ---- */
.cal-pager{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:16px;}
.cal-pager-info{font-size:.72rem;color:var(--ink-soft);}
.cal-pager-btns{display:flex;align-items:center;gap:6px;}
.cal-pager-btns button{
  min-width:32px;height:32px;padding:0 10px;border:1px solid var(--line);background:#fff;
  border-radius:6px;cursor:pointer;font-size:.78rem;color:var(--ink);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;
}
.cal-pager-btns button:hover:not(:disabled){background:#efe9d8;}
.cal-pager-btns button.active{background:var(--green-light);border-color:var(--green-light);color:#fff;font-weight:700;}
.cal-pager-btns button:disabled{opacity:.4;cursor:not-allowed;}
/* Invisible placeholder cards: keep the list at 3 slots tall so the pager never jumps */
.event-card.ghost{visibility:hidden;pointer-events:none;border-left-color:transparent;}

/* ---- Requirements file container (drop zone + picked list) ---- */
.req-box{margin-bottom:18px;}
.req-head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap;margin-bottom:6px;}
.req-count{font-size:.72rem;font-weight:700;color:#b23b2e;}
.req-count.ok{color:var(--confirmed);}
.req-drop{
  border:2px dashed var(--line);border-radius:10px;padding:22px 16px;text-align:center;
  cursor:pointer;background:var(--cream-panel);transition:border-color .15s,background .15s;
}
.req-drop:hover,.req-drop:focus-visible,.req-drop.over{border-color:var(--green-light);background:#eef7f0;outline:none;}
.req-drop .ic{font-size:1.6rem;line-height:1;margin-bottom:6px;}
.req-drop p{margin:0;font-size:.84rem;}
.req-drop p.hint{font-size:.72rem;color:var(--ink-soft);margin-top:4px;}
.req-list{display:grid;gap:8px;margin-top:10px;}
.req-file{
  display:flex;align-items:center;gap:11px;padding:8px 11px;background:#fff;
  border:1px solid var(--line);border-radius:8px;
}
.req-file img{width:46px;height:46px;object-fit:cover;border-radius:6px;flex-shrink:0;background:#ece6d7;}
.req-file .doc-ic{
  width:46px;height:46px;border-radius:6px;flex-shrink:0;background:var(--cream-panel);
  display:flex;align-items:center;justify-content:center;font-size:1.2rem;
}
.req-file .rf-body{flex:1;min-width:0;display:grid;gap:3px;}
.req-file .rf-name{font-size:.78rem;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.req-file .rf-meta{font-size:.68rem;color:var(--ink-soft);}
.req-file .rf-label{
  font-size:.74rem;padding:5px 8px;border:1px solid var(--line);border-radius:5px;
  margin:0;font-family:inherit;background:#fdfcf7;width:100%;
}
.req-file .rf-remove{
  border:none;background:transparent;color:var(--ink-soft);cursor:pointer;
  font-size:.95rem;padding:4px 6px;flex-shrink:0;
}
.req-file .rf-remove:hover{color:#b23b2e;}
.req-star{color:#b23b2e;}
.req-count.optional{color:var(--ink-soft);font-weight:600;}
.att-slot{padding:4px 0 8px;}
.att-slot-ctl{display:flex;align-items:center;gap:8px;margin:0 0 0 26px;flex-wrap:wrap;}
.att-btn{border:1px dashed var(--green-light);background:#fff;color:var(--green-mid);border-radius:6px;padding:4px 10px;font-size:.72rem;font-weight:700;cursor:pointer;font-family:inherit;}
.att-btn:hover{background:#eef7f0;}
.att-pick{display:inline-flex;align-items:center;gap:6px;font-size:.72rem;color:var(--ink-soft);max-width:100%;}
.att-pick img{width:32px;height:32px;object-fit:cover;border-radius:4px;}
.att-pick .x{border:none;background:transparent;color:#b23b2e;cursor:pointer;font-size:.95rem;padding:0 4px;}

/* ---- Files button + viewer modal on My Reservation ---- */
.file-pill{
  border:1px solid var(--green-light);background:#fff;color:var(--green-mid);
  border-radius:20px;padding:4px 12px;font-size:.72rem;font-weight:700;cursor:pointer;font-family:inherit;
}
.file-pill:hover{background:#eef7f0;}
.file-pill.report{border-color:var(--gold);color:#8a6a1f;}

.stu-modal{position:fixed;inset:0;background:rgba(13,43,31,.55);z-index:1200;display:flex;align-items:center;justify-content:center;padding:18px;}
.stu-modal-box{
  background:#fff;border-radius:10px;padding:24px;width:min(680px,100%);
  max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.28);
}
.stu-modal-box h2{font-family:Georgia,serif;font-size:1.2rem;margin:0 0 4px;}
.stu-modal-box .m-sub{font-size:.75rem;color:var(--ink-soft);margin:0 0 16px;}
.m-section{font-size:.7rem;font-weight:700;letter-spacing:.5px;color:var(--ink-soft);margin:18px 0 9px;}
.m-text{font-size:.84rem;line-height:1.6;margin:0 0 6px;white-space:pre-wrap;}
.thumb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(128px,1fr));gap:10px;}
.thumb{margin:0;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--cream-panel);cursor:zoom-in;}
.thumb:hover{border-color:var(--green-light);}
.thumb img{display:block;width:100%;height:96px;object-fit:cover;background:#ece6d7;}
.thumb figcaption{padding:6px 8px;font-size:.66rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.doc-row{
  display:flex;align-items:center;gap:11px;padding:9px 12px;margin-bottom:8px;
  border:1px solid var(--line);border-radius:8px;background:var(--cream-panel);
}
.doc-row .rf-body{flex:1;min-width:0;}
.doc-row a{
  font-size:.72rem;font-weight:700;color:var(--green-mid);text-decoration:none;
  border:1px solid var(--green-light);border-radius:5px;padding:5px 11px;
}
.doc-row a:hover{background:#eef7f0;}
.stu-lightbox{position:fixed;inset:0;background:rgba(12,22,16,.9);z-index:1300;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:30px;cursor:zoom-out;}
.stu-lightbox img{max-width:100%;max-height:78vh;border-radius:8px;cursor:default;}
.stu-lightbox p{color:#eef5f0;font-size:.78rem;margin:0;text-align:center;}

/* ---- Post-event report: the nudge button, states and form ---- */
.report-cell{display:flex;align-items:center;gap:8px;white-space:nowrap;}
.pe-btn{
  width:24px;height:24px;border-radius:50%;border:none;cursor:pointer;flex-shrink:0;
  background:var(--green-light);color:#fff;font-weight:800;font-size:.85rem;line-height:1;
  display:inline-flex;align-items:center;justify-content:center;
  box-shadow:0 0 0 0 rgba(47,138,91,.55);animation:pePulse 2.2s ease-out infinite;
}
.pe-btn:hover{background:var(--green-mid);animation:none;}
.pe-btn--fix{background:#c0392b;box-shadow:0 0 0 0 rgba(192,57,43,.55);animation:pePulseRed 2.2s ease-out infinite;}
.pe-btn--fix:hover{background:#9c2f22;animation:none;}
@keyframes pePulse{
  0%{box-shadow:0 0 0 0 rgba(47,138,91,.5);}
  70%{box-shadow:0 0 0 7px rgba(47,138,91,0);}
  100%{box-shadow:0 0 0 0 rgba(47,138,91,0);}
}
@keyframes pePulseRed{
  0%{box-shadow:0 0 0 0 rgba(192,57,43,.5);}
  70%{box-shadow:0 0 0 7px rgba(192,57,43,0);}
  100%{box-shadow:0 0 0 0 rgba(192,57,43,0);}
}
@media(prefers-reduced-motion:reduce){.pe-btn{animation:none;}}
.pe-hint{font-size:.7rem;font-weight:700;color:var(--green-mid);}
.pe-hint.fix{color:#b23b2e;}
.pe-inline{
  display:inline-flex;align-items:center;justify-content:center;width:17px;height:17px;
  border-radius:50%;background:var(--green-light);color:#fff;font-weight:800;font-size:.66rem;vertical-align:-3px;
}
.file-pill.approved{border-color:var(--confirmed);color:var(--confirmed);background:#f2faf5;}

.notice-bar{
  display:flex;align-items:center;gap:12px;margin-bottom:18px;padding:12px 16px;
  background:#e2f2e6;border:1px solid #a9dcc0;border-radius:8px;font-size:.82rem;color:#17603f;
}
.notice-dot{
  flex-shrink:0;width:23px;height:23px;border-radius:50%;background:var(--green-light);
  color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;font-size:.8rem;
}

.revision-note{
  background:#fbe4e0;border-left:3px solid #b23b2e;border-radius:0 6px 6px 0;
  padding:12px 15px;margin:0 0 18px;font-size:.82rem;color:#7d2a20;
}
.revision-note strong{display:block;margin-bottom:5px;}
.revision-note p{margin:0 0 6px;line-height:1.55;white-space:pre-wrap;}
.revision-note span{font-size:.74rem;color:#9a4034;}
.msg-line{margin-top:6px;}
.vt{display:inline-block;font-size:.72rem;font-weight:700;border-radius:999px;padding:2px 8px;width:fit-content;}
.vt-ok{background:#e1f3e7;color:#1f6b3a;}
.vt-no{background:#fbe4e0;color:#8f2d22;}
.vote-list{display:flex;flex-direction:column;gap:8px;margin:0 0 14px;}
.vote-item{border:1px solid #e3ddd0;border-left-width:4px;border-radius:6px;padding:9px 12px;background:#fff;}
.vote-item.vi-ok{border-left-color:#2e8b57;}
.vote-item.vi-no{border-left-color:#b23b2e;}
.vi-top{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:.84rem;}
.vi-note{margin-top:5px;font-size:.8rem;line-height:1.5;color:#333;}
.vi-when{margin-top:4px;font-size:.72rem;color:#777;}

.pe-form label{display:block;font-size:.78rem;font-weight:700;margin:0 0 6px;color:var(--ink);}
.pe-form input, .pe-form select, .pe-form textarea{
  width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:6px;
  font-size:.86rem;margin-bottom:16px;font-family:inherit;background:#fdfcf7;
}
.pe-form textarea{resize:vertical;min-height:86px;}
.pe-form .rf-label{margin-bottom:0;}
.pe-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px;}
@media(max-width:560px){.pe-grid{grid-template-columns:1fr;}}

/* ================= RESPONSIVE ================= */

/* Hamburger button + backdrop, only shown once the sidebar goes off-canvas */
.mobile-menu-btn{
  display:none;
  position:fixed; top:14px; left:14px; z-index:1001;
  width:42px;height:42px;border-radius:8px;border:none;
  background:var(--green-mid);color:#fff;cursor:pointer;
  align-items:center;justify-content:center;
  box-shadow:0 2px 8px rgba(0,0,0,.25);
}
.mobile-menu-btn svg{width:20px;height:20px;}
.sidebar-overlay{
  display:none;
  position:fixed;inset:0;background:rgba(13,43,31,.45);z-index:999;
}
.sidebar-overlay.show{display:block;}

@media(max-width:768px){
  .mobile-menu-btn{display:flex;}
  .student-shell{display:block;}
  .stu-sidebar{
    position:fixed; top:0; left:0; height:100vh; width:230px; z-index:1000;
    transform:translateX(-100%); transition:transform .25s ease;
    box-shadow:4px 0 18px rgba(0,0,0,.25);
  }
  .stu-sidebar.open{transform:translateX(0);}
  .stu-main{padding:72px 20px 30px;}
  .stu-topline{flex-direction:column;align-items:stretch;}
  .stu-add-btn{width:100%;text-align:center;}
  .stu-topline .stu-add-btn{margin-right:0;}
  .stu-form{max-width:none;}
  .profile-card{max-width:none;}
}

@media(max-width:560px){
  .stu-form .form-row{flex-direction:column;gap:0;}
  .stu-form .form-grid-2{grid-template-columns:1fr;}
  .cal-toolbar{gap:8px;}
  .cal-toolbar .month-label{width:100%;text-align:center;order:-1;}
  .res-chip{width:100%;justify-content:space-between;}
  .res-inline-controls{width:100%;}
  .res-inline-controls input{flex:1;min-width:0;}
}

@media(max-width:480px){
  .stu-main{padding:64px 14px 24px;}
  .stat-grid{grid-template-columns:1fr 1fr;gap:10px;}
  .stat-card{padding:14px;}
  .stat-card .stat-num{font-size:1.4rem;}
  .stu-topline h1{font-size:1.25rem;}
  table.cal td{height:44px;padding:4px 5px;font-size:.72rem;}
  table.cal th{font-size:.6rem;}
  .venue-card{flex-direction:column;align-items:flex-start;}
}

/* ================= Scheduling: availability, conflicts, reschedule, history ================= */
:root{ --avail-ok:#2f8a5b; --avail-pend:#d98a3d; --avail-booked:#b23b2e; }
.badge.b-cancelled{background:#efe3e3;color:#8a4b4b;}
.avail-legend{display:flex;gap:14px;flex-wrap:wrap;font-size:.72rem;margin:6px 0 8px;font-family:-apple-system,"Segoe UI",Arial,sans-serif;}
.avail-legend .lg::before{content:'';display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px;}
.lg--available::before{background:#cfe9d8;border:1px solid var(--avail-ok);}
.lg--pending::before{background:#f8dfc0;border:1px solid var(--avail-pend);}
.lg--booked::before{background:#f1c9c4;border:1px solid var(--avail-booked);}
.slot-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:6px;margin:4px 0 10px;}
.slot-grid--compact{grid-template-columns:repeat(auto-fill,minmax(78px,1fr));}
.slot{font:inherit;font-size:.74rem;font-weight:700;text-align:center;padding:6px 4px;border-radius:6px;border:1px solid;line-height:1.25;font-family:-apple-system,"Segoe UI",Arial,sans-serif;}
.slot small{display:block;font-weight:600;font-size:.62rem;opacity:.85;}
.slot--available{background:#e8f5ec;border-color:#a9d4b9;color:#1f5c3f;cursor:pointer;}
.slot--available:hover{background:#d3ecdc;}
.slot--pending{background:#fcefdc;border-color:#ecc994;color:#9a5d17;cursor:not-allowed;}
.slot--booked{background:#fae3e0;border-color:#e3a8a1;color:#9c2f22;cursor:not-allowed;text-decoration:none;}
.slot--past{background:#f0eee8;border-color:#e2dac6;color:#aaa393;cursor:not-allowed;}
.slot:disabled{opacity:.9;}
.slot--endok{cursor:pointer;}
.slot.is-between{background:#cfeadb;border-color:#7fc29b;color:#1f5c3f;}
.slot.is-start,.slot.is-end{background:#2f8a5b;border-color:#1f5c3f;color:#fff;opacity:1;cursor:pointer;}
.slot.is-start small,.slot.is-end small{opacity:1;}
.avail-ok{background:#e2f2e6;color:#1f6b45;border-radius:6px;padding:8px 12px;font-size:.8rem;font-weight:600;}
.avail-hint{font-size:.74rem;color:var(--ink-soft);margin:6px 0 0;}
.avail-head{font-family:Georgia,serif;font-size:.95rem;font-weight:700;margin:14px 0 4px;}
.conflict-box{background:#fdf1ef;border:1px solid #efc3bd;color:#7d2a20;border-radius:8px;padding:12px 14px;font-size:.8rem;line-height:1.45;}
.conflict-help{margin:6px 0 0;color:#5b3a35;}
.sug-title{font-weight:700;margin:10px 0 6px;font-size:.76rem;color:#5b3a35;}
.sug-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px;}
.sug{font:inherit;font-size:.76rem;text-align:left;background:#fff;border:1px solid #cfe0d4;border-radius:8px;padding:8px 10px;cursor:pointer;color:var(--ink);line-height:1.35;}
.sug:hover{border-color:var(--green-light);background:#eef7f0;}
.sug small{display:block;color:var(--ink-soft);font-size:.66rem;margin-top:2px;}
.link-btn{background:none;border:none;padding:0;color:var(--green-mid);font:inherit;font-weight:700;text-decoration:underline;cursor:pointer;}
.cal-venue-sel{padding:7px 8px;border:1px solid var(--line);border-radius:6px;background:#fff;font-size:.78rem;max-width:150px;}
table.cal td.st-available:not(.selected){background:#eef7f0;}
table.cal td.st-pending:not(.selected){background:#fcefdc;}
table.cal td.st-booked:not(.selected){background:#fae3e0;}
table.cal td.past-day{color:#c9c2ac;background:transparent;}
.venue-day-list{display:flex;flex-direction:column;gap:6px;}
.vday{display:flex;justify-content:space-between;align-items:center;font:inherit;font-size:.8rem;padding:9px 12px;border-radius:8px;border:1px solid;cursor:pointer;background:#fff;}
.vday em{font-style:normal;font-weight:700;font-size:.7rem;}
.vday--available{border-color:#a9d4b9;color:#1f5c3f;}
.vday--pending{border-color:#ecc994;color:#9a5d17;background:#fffaf2;}
.vday--booked{border-color:#e3a8a1;color:#9c2f22;background:#fff6f5;}
.vday--maintenance{border-color:#ddd;color:#888;cursor:default;}
.meta-time{font-size:.72rem;color:var(--ink-soft);margin-top:2px;}
.tag{display:inline-block;font-size:.64rem;font-weight:700;padding:2px 8px;border-radius:20px;background:#eceae2;color:#5b5b50;font-family:-apple-system,"Segoe UI",Arial,sans-serif;}
.tag--resched{background:#fbf0d2;color:#7a5a14;}
.resched-note{margin-top:5px;font-size:.7rem;color:var(--ink-soft);}
.resched-note .was{display:block;margin-top:3px;}
.rs-pill{display:block;margin-top:6px;font:inherit;font-size:.66rem;font-weight:700;border-radius:20px;padding:3px 10px;border:1px solid;cursor:pointer;background:#fff;}
.rs-pill--pending{border-color:#ecc994;color:#9a5d17;}
.rs-pill--sug{border-color:var(--green-light);color:#fff;background:var(--green-light);animation:none;}
.rs-pill--no{border-color:#e3a8a1;color:#9c2f22;}
.up-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:8px;}
.file-pill--danger{color:#9c2f22;border-color:#e3a8a1;}
.rs-current{background:#f7f3e8;border:1px solid var(--line);border-radius:8px;padding:10px 12px;font-size:.82rem;margin:0 0 12px;}
.rs-current small{display:block;color:var(--ink-soft);margin-top:3px;}
.sug-offer{background:#eef7f0;border:1px solid #a9d4b9;border-radius:8px;padding:12px 14px;margin:8px 0;font-size:.84rem;}
.sug-offer-btns{display:flex;gap:10px;margin:10px 0 6px;flex-wrap:wrap;}
.hist-chain{border-left:3px solid var(--line);padding-left:12px;margin:6px 0 4px;}
.hist-row{font-size:.82rem;padding:5px 0;}
.hist-row small{display:block;color:var(--ink-soft);font-size:.7rem;margin-top:2px;}
.up-list{display:flex;flex-direction:column;gap:12px;}
.up-card{display:flex;gap:16px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px 16px;}
.up-when{flex:none;width:54px;text-align:center;border-right:1px solid var(--line);padding-right:14px;}
.up-day{font-family:Georgia,serif;font-size:1.6rem;font-weight:700;line-height:1;}
.up-mon{font-size:.7rem;color:var(--ink-soft);margin-top:3px;}
.up-body{flex:1;min-width:0;}
.up-top{display:flex;justify-content:space-between;gap:10px;align-items:center;}
.up-top h3{margin:0;font-family:Georgia,serif;font-size:1rem;}
.up-body .meta{font-size:.78rem;color:var(--ink-soft);margin-top:4px;line-height:1.5;}
.hist-filters{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 14px;align-items:center;}
.hist-filters input,.hist-filters select{padding:9px 11px;border:1px solid var(--line);border-radius:6px;font-size:.82rem;background:#fff;font-family:inherit;}
.hist-filters input{flex:1;min-width:210px;}
.sch-new{font-size:.82rem;}
.sch-old{font-size:.74rem;color:var(--ink-soft);margin-top:3px;}
.sch-lbl{display:inline-block;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px;background:#eceae2;border-radius:4px;padding:1px 5px;margin-right:4px;color:#5b5b50;}
@media(max-width:560px){.up-card{flex-direction:column;gap:8px;}.up-when{width:auto;border-right:none;padding-right:0;text-align:left;display:flex;gap:8px;align-items:baseline;}}
</style>
</head>
<body>

<div id="student" class="view active">
  <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleSidebar()" aria-label="Open menu">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
  </button>
  <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

  <div class="student-shell">
    <aside class="stu-sidebar" id="stuSidebar">
      <a class="stu-brand" href="student.php">
        <div class="stu-brand__logo"><img src="pictures/spark_logo.png" alt="SPARK logo"></div>
        <div>
          <div class="stu-brand__name">SPARK</div>
          <div class="stu-brand__role">Student Portal</div>
        </div>
      </a>
      <a class="stu-profile" href="student.php">
        <div class="sidebar-name">
            <div class="name"><?= h($fullName) ?></div>
          <div class="role">Student</div>
        </div>
      </a>

      <ul class="stu-nav-group" id="navGroup1">
        <li data-page="myReservations" onclick="navigateStudent('myReservations')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3h6v3H9z"/><path d="M9 11h6M9 15h6"/></svg>
          My Reservation
        </li>
        <li data-page="newReservation" onclick="navigateStudent('newReservation')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>
          New Reservation
        </li>
        <li data-page="upcoming" onclick="navigateStudent('upcoming')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
          Upcoming Events
        </li>
        <li data-page="history" onclick="navigateStudent('history')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 8v4l3 2"/></svg>
          Past Events
        </li>
      </ul>

      <ul class="stu-nav-group" id="navGroup2">
        <li data-page="calendar" onclick="navigateStudent('calendar')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4"/></svg>
          Calendar
        </li>
        <li data-page="venues" onclick="navigateStudent('venues')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 21V9l8-5 8 5v12"/><path d="M9 21v-6h6v6"/></svg>
          Venue Availability
        </li>
      </ul>

      <ul class="stu-nav-group" style="margin-top:auto;">
        <li data-page="profile" onclick="navigateStudent('profile')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
          Profile
        </li>
        <li data-page="settings" onclick="navigateStudent('settings')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.3.9a7 7 0 0 0-2-1.2L14.3 3H9.7l-.3 2.6a7 7 0 0 0-2 1.2l-2.3-.9-2 3.4 2 1.5A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.3-.9c.6.5 1.3.9 2 1.2l.3 2.6h4.6l.3-2.6a7 7 0 0 0 2-1.2l2.3.9 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z"/></svg>
          Settings
        </li>
        <li data-page="logout" onclick="window.location.href='logout.php'">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
          Log Out
        </li>
      </ul>
    </aside>

    <main class="stu-main" id="stuMain"></main>
  </div>
</div>

<div class="bell-wrap" id="bellWrap">
  <button class="bell-btn" id="bellBtn" type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" onclick="toggleBell(event)">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
    <span class="bell-dot" id="bellDot" hidden></span>
  </button>
  <div class="bell-menu" id="bellMenu" hidden>
    <div class="bell-head">Notifications</div>
    <div class="bell-list" id="bellList"></div>
  </div>
</div>

<script>
/* All of this comes from the database via student.php, scoped to whoever is logged in. */
const studentUser = {
  name: <?= json_encode($fullName) ?>,
  firstName: <?= json_encode($user['first_name']) ?>,
  lastName: <?= json_encode($user['last_name']) ?>,
  initials: <?= json_encode($initials) ?>,
  email: <?= json_encode($user['email']) ?>,
  campus: <?= json_encode($user['campus']) ?>
};
let reservationsData = <?= json_encode($reservationsForJs, JSON_UNESCAPED_SLASHES) ?>;
const APPROVALS_NEEDED = <?= (int)approvals_needed($pdo) ?>;
// "Approved by" / "Noted by" names + positions, set by the admins (Settings page).
const SIGNATORIES = <?= json_encode(get_signatories($pdo), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
let notificationsData = <?= json_encode($notificationsForJs, JSON_UNESCAPED_SLASHES) ?>;
const venuesList = <?= json_encode($venues, JSON_UNESCAPED_SLASHES) ?>;
const bookableVenuesList = <?= json_encode($bookableVenues, JSON_UNESCAPED_SLASHES) ?>;
const resourcesList = <?= json_encode($resources, JSON_UNESCAPED_SLASHES) ?>;
/* Resources the student has picked to borrow on the "New Reservation" form,
   e.g. [{id:1, name:"Chairs", stock:200, quantity:20}, ...] — reset each time
   the form is (re)rendered. */
let selectedResources = [];
let editingReservationId = null;
const bookedTodayVenues = <?= json_encode($todayBooked, JSON_UNESCAPED_SLASHES) ?>;
/* All-events map (every student's reservations) powering the embedded Calendar tab. */
const calendarEvents = <?= $calendarEventsJson ?>;
let stuSettings = { emailNotif:true, smsReminders:false };
let studentPage = <?= json_encode($initialPage) ?>;
let justSubmitted = <?= json_encode($justSubmitted) ?>;
let justReported = <?= json_encode($justReported) ?>;
let flashMessage = <?= json_encode($flash) ?>;
const schedReady = <?= json_encode($schedReady) ?>;
const SCHED = { open: <?= json_encode(substr(sched_open_time(), 0, 5)) ?>, close: <?= json_encode(substr(sched_close_time(), 0, 5)) ?>, step: <?= (int)sched_step_minutes() ?> };
const SERVER_NOW = <?= json_encode($nowLocal) ?>;           /* campus-local time, same clock the server uses */
const SERVER_TODAY = SERVER_NOW.slice(0, 10);

const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
const now = new Date();

/* Calendar tab state */
let calViewYear = now.getFullYear();
let calViewMonth = now.getMonth();
let calSelectedDate = null;
let calSearchQuery = "";
let calUpcomingPage = 1;          // current page of the Upcoming Events list
let calVenueId = null;            // null = all venues; a venue id shows Available/Pending/Booked per day
const CAL_PER_PAGE = 3;           // max events shown per page

function escapeHtml(str){
  const div = document.createElement('div');
  div.textContent = str ?? "";
  return div.innerHTML;
}
function dayName(y,m,d){
  return ["Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"][new Date(y,m,d).getDay()];
}
function fmtDate(y,m,d){
  return `${monthNames[m]} ${d}, ${y}`;
}
function badgeClass(status){
  if(status==="pending") return "b-pending";
  if(status==="approved" || status==="confirmed") return "b-approved";
  if(status==="declined" || status==="rejected") return "b-declined";
  if(status==="cancelled") return "b-cancelled";
  return "b-completed";
}
function badgeLabel(status){
  return status.charAt(0).toUpperCase()+status.slice(1);
}

function toggleSidebar(){
  document.getElementById('stuSidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('show');
}
function closeSidebar(){
  document.getElementById('stuSidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('show');
}

/* ---- Notification bell. "New" = id higher than the last one seen in this browser. ---- */
const BELL_KEY = 'osas_notif_seen_<?= (int) $userId ?>';
function bellSeen(){ try{ return parseInt(localStorage.getItem(BELL_KEY) || '0', 10) || 0; }catch(e){ return 0; } }
/* ---- Rich notification cards: title + specific details + CURRENT status + timestamp + action button ----
   The stored message is the "specific details". Which flow step it belongs to is read from the wording the
   system itself writes, then the live reservation (looked up by its RES- reference) decides the status chips
   and which button makes sense NOW (e.g. "Submit report" disappears once the report is sent). */
const NOTIF_TYPES = [
  // [type, pattern, title, tone]   — order matters: the more specific pattern first
  ['account_approved',  /^Your account has been approved/,                                    'Account approved',                 'ok'],
  ['account_rejected',  /^Your account request was not approved/,                             'Account not approved',             'bad'],
  ['vote_withdrawn',    /withdrew their (approval|decline) on /,                              'Decision withdrawn',               'warn'],
  ['res_approved',      /your reservation is now APPROVED\.|^Good news! .* has been approved \(\d+ approvals reached\)/, 'Reservation approved', 'ok'],
  ['res_declined',      /your reservation is now DECLINED\.|\) has been declined\. Reason:/,  'Reservation declined',            'bad'],
  ['vote_approved',     /^.+? approved Your reservation "/,                                   'Approval received',                'ok'],
  ['vote_declined',     /^.+? declined Your reservation "/,                                   'Decline received',                 'bad'],
  ['res_submitted',     /has been submitted.* and is pending OSAS approval\./,                'Reservation submitted',            'info'],
  ['res_updated',       /was updated and (resubmitted|is still pending)/,                     'Reservation updated',              'info'],
  ['res_cancelled',     /\) has been cancelled\./,                                            'Reservation cancelled',            'warn'],
  ['event_done',        /has finished, so the reservation is now marked Done|has been marked completed\./, 'Event finished',     'info'],
  ['report_revision',   /^OSAS has requested changes to your post-event report/,              'Changes requested on your report', 'warn'],
  ['report_approved',   /post-event report .* has been approved by OSAS/,                     'Post-event report approved',       'ok'],
  ['report_resubmitted',/^Your revised post-event report/,                                    'Report resubmitted',               'info'],
  ['report_submitted',  /^Your post-event report for .* has been submitted as /,              'Report submitted',                 'info'],
  ['resched_rejected',  /rejected your reschedule request/,                                   'Reschedule rejected',              'bad'],
  ['resched_approved',  /approved your reschedule request/,                                   'Reschedule approved',              'ok'],
  ['resched_suggested', /suggested a different schedule for/,                                 'New schedule suggested',           'warn'],
  ['resched_accepted',  /has been rescheduled to /,                                           'Reservation rescheduled',          'ok'],
  ['resched_requested', /^Your reschedule request for .* was sent to OSAS/,                   'Reschedule requested',             'info'],
];
const RESCHED_LABEL = { pending:'Pending', suggested:'New time suggested', rejected:'Rejected', approved:'Approved', withdrawn:'Withdrawn' };

function notifView(n){
  const text = String(n.text || '');
  const def = NOTIF_TYPES.find(t => t[1].test(text));
  const type = def ? def[0] : 'generic';
  const refMatch = text.match(/\b(RES-\d{4}-\d+)\b/);
  const idx = refMatch ? reservationsData.findIndex(x => x.ref === refMatch[1]) : -1;
  const r = idx >= 0 ? reservationsData[idx] : null;
  const v = { type, title: def ? def[2] : 'Notification', tone: def ? def[3] : 'info', r, idx, chips: [], action: null };
  const open  = (label, fn)  => { v.action = { label, fn }; };
  const link  = (label, href) => { v.action = { label, href }; };
  const chip = (label, cls) => v.chips.push({ label, cls });

  if(type === 'account_approved'){ chip('Account: Approved', 'b-approved'); open('Make a reservation', () => navigateStudent('newReservation')); return v; }
  if(type === 'account_rejected'){ chip('Account: Not approved', 'b-declined'); return v; }
  if(!r) return v;                                   // nothing live to show or act on

  // ---- current status chips (live, not what it was when the message was written) ----
  chip(badgeLabel(r.status), badgeClass(r.status));
  if(/^(report_|event_done)/.test(type)){
    if(!r.report)                              chip('Report: Not submitted', 'b-pending');
    else if(r.report.status === 'pending')     chip('Report: Under review', 'b-pending');
    else if(r.report.status === 'needs_revision') chip('Report: Needs changes', 'b-declined');
    else                                       chip('Report: Approved', 'b-approved');
  }
  if(/^resched_/.test(type) && r.reschedule){
    const s = r.reschedule.status;
    chip('Reschedule: ' + (RESCHED_LABEL[s] || s), s === 'approved' ? 'b-approved' : s === 'rejected' ? 'b-declined' : 'b-pending');
  }

  // ---- the button that matches the next step of the flow ----
  const rep = r.report ? r.report.status : null;
  const reportStep = () => {
    if(!r.report)             return open('Submit report', () => openPostEventForm(idx));
    if(rep === 'needs_revision') return open('Revise report', () => openPostEventForm(idx));
    if(rep === 'approved')    return link('Download clearance', `api/clearance_pdf.php?id=${encodeURIComponent(r.id)}`);
    return open('View report status', () => openReservationDetail(idx));
  };
  switch(type){
    case 'res_submitted': case 'res_updated':
      open('View request', () => openReservationDetail(idx)); break;
    case 'vote_approved': case 'vote_declined': case 'vote_withdrawn':
      open('View messages', () => openReservationMessage(idx)); break;
    case 'res_approved':
      open('View reservation', () => openReservationDetail(idx)); break;
    case 'res_declined':
      if(canEdit(r)) open('Edit & Resubmit', () => startEditReservation(idx));
      else open('See reason', () => openReservationDetail(idx));
      break;
    case 'res_cancelled':
      open('View history', () => navigateStudent('history')); break;
    case 'event_done': case 'report_revision': case 'report_approved': case 'report_submitted': case 'report_resubmitted':
      if(r.status === 'completed') reportStep(); else open('View reservation', () => openReservationDetail(idx));
      break;
    case 'resched_suggested':
      open(r.reschedule && r.reschedule.status === 'suggested' ? 'Review suggestion' : 'View request', () => openReservationDetail(idx)); break;
    case 'resched_rejected':
      if(canChange(r)) open('Request again', () => openRescheduleModal(idx));
      else open('View reservation', () => openReservationDetail(idx));
      break;
    case 'resched_requested':
      open('View request', () => openReservationDetail(idx)); break;
    case 'resched_approved': case 'resched_accepted':
      open('View reservation', () => openReservationDetail(idx)); break;
    default:
      open('View reservation', () => openReservationDetail(idx));
  }
  return v;
}

function notifCardHtml(n, unread){
  const v = notifView(n);
  const meta = v.r ? `<div class="nc-meta">${escapeHtml(v.r.venue)} · ${fmtDate(v.r.y, v.r.m, v.r.d)} · ${escapeHtml(v.r.time)}</div>` : '';
  const chips = v.chips.map(c => `<span class="badge ${c.cls}">${escapeHtml(c.label)}</span>`).join('');
  let btn = '';
  if(v.action && v.action.href) btn = `<a class="nc-btn" href="${escapeHtml(v.action.href)}" download>${escapeHtml(v.action.label)}</a>`;
  else if(v.action)             btn = `<button type="button" class="nc-btn" onclick="bellGo(${n.id})">${escapeHtml(v.action.label)}</button>`;
  return `<div class="notif-item nc nc--${v.tone}${unread ? ' unread' : ''}">
    <div class="nc-title">${escapeHtml(v.title)}</div>
    <div class="nc-time" title="${escapeHtml(n.at || '')}">${n.at ? escapeHtml(n.at) + ' · ' : ''}${escapeHtml(n.time)}</div>
    <div class="nc-text">${escapeHtml(n.text)}</div>
    ${meta}
    ${(chips || btn) ? `<div class="nc-foot"><div class="nc-chips">${chips}</div>${btn}</div>` : ''}
  </div>`;
}

function bellGo(id){
  const n = notificationsData.find(x => x.id === Number(id));
  if(!n) return;
  const v = notifView(n);
  closeBell();
  if(v.action && v.action.fn) v.action.fn();
}

function renderBell(){
  const seen = bellSeen();
  const unread = notificationsData.filter(n => n.id > seen).length;
  const dot = document.getElementById('bellDot');
  dot.textContent = unread > 9 ? '9+' : unread;
  dot.hidden = unread === 0;
  document.getElementById('bellList').innerHTML = notificationsData.length
    ? notificationsData.map(n => notifCardHtml(n, n.id > seen)).join('')
    : `<p class="empty-note">No notifications.</p>`;
}
function closeBell(){
  document.getElementById('bellMenu').hidden = true;
  document.getElementById('bellBtn').setAttribute('aria-expanded', 'false');
}
function toggleBell(e){
  e.stopPropagation();
  const menu = document.getElementById('bellMenu');
  if(!menu.hidden) return closeBell();
  renderBell();                        // list still shows which ones were new
  menu.hidden = false;
  document.getElementById('bellBtn').setAttribute('aria-expanded', 'true');
  const newest = notificationsData.reduce((m, n) => Math.max(m, n.id), 0);
  try{ localStorage.setItem(BELL_KEY, String(newest)); }catch(err){}
  document.getElementById('bellDot').hidden = true;   // now seen
}
document.addEventListener('click', e => { if(!document.getElementById('bellWrap').contains(e.target)) closeBell(); });
document.addEventListener('keydown', e => { if(e.key === 'Escape') closeBell(); });

function navigateStudent(page, keepEdit){
  if(!keepEdit) editingReservationId = null;
  studentPage = page;
  renderStudentShell();
  closeSidebar();
}

function renderStudentShell(){
  document.querySelectorAll('.stu-nav-group li').forEach(li=>{
    li.classList.toggle('active', li.dataset.page===studentPage);
  });
  renderBell();
  renderStudentMain();
}

function renderStudentMain(){
  const main = document.getElementById('stuMain');
  if(studentPage==='dashboard') return renderDashboardPage(main);
  if(studentPage==='myReservations') return renderMyReservationsPage(main);
  if(studentPage==='newReservation') return renderNewReservationPage(main, justSubmitted);
  if(studentPage==='upcoming') return renderUpcomingPage(main);
  if(studentPage==='history') return renderHistoryPage(main);
  if(studentPage==='venues') return renderVenuesPage(main);
  if(studentPage==='calendar') return renderCalendarPage(main);
  if(studentPage==='profile') return renderProfilePage(main);
  if(studentPage==='settings') return renderSettingsPage(main);
}

/* ---- Dashboard ---- */
function renderDashboardPage(main){
  const dateStr = `${dayName(now.getFullYear(),now.getMonth(),now.getDate())}, ${monthNames[now.getMonth()]} ${now.getDate()}, ${now.getFullYear()}`;
  const total = reservationsData.length;
  const pending = reservationsData.filter(r=>r.status==="pending").length;
  const approved = reservationsData.filter(r=>r.status==="approved" || r.status==="confirmed").length;
  const completed = reservationsData.filter(r=>r.status==="completed").length;

  const weekMs = 7*24*60*60*1000;
  const thisWeek = reservationsData.filter(r=>{
    const d = new Date(r.y, r.m, r.d);
    const diff = d - now;
    return diff >= 0 && diff <= weekMs && r.status !== 'cancelled' && r.status !== 'declined';
  });

  let recentHtml = "";
  reservationsData.slice(0,3).forEach(r=>{
    recentHtml += `
      <div class="stu-item-box">
        <div class="stu-item-top">
          <h3>${escapeHtml(r.event)}</h3>
          <span class="badge ${badgeClass(r.status)}">${badgeLabel(r.status)}</span>
        </div>
        <div class="meta">${escapeHtml(r.venue)} &middot; ${fmtDate(r.y,r.m,r.d)}</div>
      </div>`;
  });
  if(reservationsData.length===0) recentHtml = `<p class="empty-note">No reservations yet.</p>`;

  let notifHtml = "";
  notificationsData.slice(0,3).forEach(n=>{
    notifHtml += notifCardHtml(n, false);
  });
  if(notificationsData.length===0) notifHtml = `<p class="empty-note">No notifications.</p>`;

  let weekHtml = "";
  thisWeek.forEach(r=>{
    weekHtml += `<div class="week-item">${escapeHtml(r.event)}<div class="meta">${fmtDate(r.y,r.m,r.d)} &middot; ${escapeHtml(r.venue)}</div></div>`;
  });
  if(thisWeek.length===0) weekHtml = `<p class="empty-note">Nothing scheduled this week.</p>`;

  main.innerHTML = `
    <div class="stu-topline">
      <div>
        <p class="date">${dateStr}</p>
        <h1>Good day, ${studentUser.name.split(" ")[0]}!</h1>
      </div>
      <button class="stu-add-btn" onclick="navigateStudent('newReservation')">+ Add Reservation</button>
    </div>

    <div class="stat-grid">
      <div class="stat-card"><div class="stat-num">${total}</div><div class="stat-label">Total Reservations</div></div>
      <div class="stat-card"><div class="stat-num">${pending}</div><div class="stat-label">Pending</div></div>
      <div class="stat-card"><div class="stat-num">${approved}</div><div class="stat-label">Approved</div></div>
      <div class="stat-card"><div class="stat-num">${completed}</div><div class="stat-label">Completed</div></div>
    </div>

    <div class="stu-columns">
      <div class="stu-col">
        <h2>Reservations</h2>
        ${recentHtml}
      </div>
      <div class="stu-col">
        <h2>Notification</h2>
        <div class="stu-box">${notifHtml}</div>
        <h2>This Week</h2>
        <div class="stu-box">${weekHtml}</div>
      </div>
    </div>
  `;
}

/* ---- My Reservation (table) ---- */

/** Where a finished event stands on its report — and what to do about it. */
function reportCell(r, i){
  if(r.status !== 'completed'){
    return '<span class="empty-note" style="padding:0">—</span>';
  }
  if(!r.report){
    // The event is over and nothing's been sent yet — this is the nudge.
    return `<button class="pe-btn" onclick="openPostEventForm(${i})"
              title="Your event is finished — submit your post-event report"><span>!</span></button>
            <span class="pe-hint">Report due</span>`;
  }
  if(r.report.status === 'pending'){
    return `<button class="file-pill" onclick="openReservationDetail(${i})">Under review</button>`;
  }
  if(r.report.status === 'needs_revision'){
    return `<button class="pe-btn pe-btn--fix" onclick="openPostEventForm(${i})"
              title="OSAS sent your report back — click to see why and resubmit"><span>!</span></button>
            <span class="pe-hint fix">Needs changes</span>`;
  }
  return `<button class="file-pill approved" onclick="openReservationDetail(${i})">Approved</button>`;
}

/* Why OSAS declined a reservation — shown to the student in the table and the detail popup. */
function declineReasonHtml(r, compact){
  if(r.status !== 'declined' || !r.declineReason) return '';
  const body = escapeHtml(r.declineReason).replace(/\n/g, '<br>');
  if(compact){
    return `<div class="decline-note"><button class="file-pill" onclick="openReservationDetail(${reservationsData.indexOf(r)})">See reason</button></div>`;
  }
  return `<div class="revision-note" style="margin-top:12px;"><strong>Reason for declining:</strong><br>${body}</div>`;
}

/** The "Message" button: opens a read-only popup with each approver's name, comment and date. */
function votesHtml(r, compact){
  const n = (r.votes || []).length;
  const idx = reservationsData.indexOf(r);
  const btn = `<button class="file-pill msg-btn" onclick="openReservationMessage(${idx})">✉ Message${n ? ` (${n})` : ''}</button>`;
  return compact ? `<div class="msg-line">${btn}</div>` : `<div style="margin:0 0 14px;">${btn}</div>`;
}

function openReservationMessage(index){
  const r = reservationsData[index];
  if(!r) return;
  const votes = r.votes || [];
  const approvals = votes.filter(v => v.vote === 'approve').length;
  const body = votes.length
    ? `<div class="vote-list">${votes.map(v => `
        <div class="vote-item ${v.vote === 'approve' ? 'vi-ok' : 'vi-no'}">
          <div class="vi-top"><strong>${escapeHtml(v.name)}</strong>
            <span class="vt ${v.vote === 'approve' ? 'vt-ok' : 'vt-no'}">${v.vote === 'approve' ? 'Approved' : 'Declined'}</span></div>
          <div class="vi-note">${v.reason ? escapeHtml(v.reason).replace(/\n/g, '<br>') : '<span style="color:#888">No comment.</span>'}</div>
          <div class="vi-when">${escapeHtml(v.at)}</div>
        </div>`).join('')}</div>`
    : `<p class="empty-note" style="padding:0 0 10px">No admin has decided on this request yet.</p>`;
  openStuModal(`
    <h2>Messages</h2>
    <p class="m-sub">${escapeHtml(r.ref)} · ${escapeHtml(r.event)}
      &nbsp;<span class="badge ${badgeClass(r.status)}">${badgeLabel(r.status)}</span></p>
    <p class="m-sub" style="margin-top:-6px">${approvals} of ${APPROVALS_NEEDED} approvals</p>
    ${body}
    <div style="display:flex;justify-content:flex-end;margin-top:14px;">
      <button class="btn" style="background:#eee;" onclick="closeStuModal()">Close</button>
    </div>`);
}

function renderMyReservationsPage(main){
  let rows = "";
  reservationsData.forEach((r, i)=>{
    const resText = (r.resources && r.resources.length)
      ? r.resources.map(x => `${escapeHtml(x.name)} x${x.qty}`).join(', ')
      : '<span class="empty-note" style="padding:0">—</span>';

    const fileCount = (r.files || []).length;
    const filesCell = fileCount
      ? `<button class="file-pill" onclick="openReservationDetail(${i})">${fileCount} file${fileCount===1?'':'s'}</button>`
      : '<span class="empty-note" style="padding:0">—</span>';

    rows += `
      <tr>
        <td>${escapeHtml(r.ref)}</td>
        <td>${escapeHtml(r.event)}</td>
        <td>${escapeHtml(r.venue)}</td>
        <td>${fmtDate(r.y,r.m,r.d)}<div class="meta-time">${escapeHtml(r.time)}</div>${reschedTagHtml(r)}</td>
        <td>${resText}</td>
        <td>${filesCell}</td>
        <td><span class="badge ${badgeClass(r.status)}">${badgeLabel(r.status)}</span>${votesHtml(r, true)}${declineReasonHtml(r, true)}${reschedPillHtml(r, i)}</td>
        <td><div class="report-cell">${reportCell(r, i)}</div></td>
        <td><div class="up-actions">${actionButtonsHtml(r, i) || '<span class="empty-note" style="padding:0">—</span>'}</div></td>
      </tr>`;
  });
  if(reservationsData.length===0){
    rows = `<tr><td colspan="9" class="empty-note">You have no reservations yet.</td></tr>`;
  }

  const due = reservationsData.filter(r => r.status === 'completed' &&
    (!r.report || r.report.status === 'needs_revision')).length;

  main.innerHTML = `
    <div class="stu-topline">
      <div><h1>My Reservation</h1></div>
      <button class="stu-add-btn" onclick="navigateStudent('newReservation')">+ Add Reservation</button>
    </div>
    ${flashBannerHtml()}
    ${justReported ? `<div class="form-success">Post-event report submitted. OSAS will review it and let you know the outcome.</div>` : ''}
    ${due ? `<div class="notice-bar">
      <span class="notice-dot">!</span>
      <span><strong>${due} finished event${due===1?'':'s'}</strong> ${due===1?'needs':'need'} a post-event report from you.
      Look for the green <span class="pe-inline">!</span> in the Report column.</span>
    </div>` : ''}
    <div class="stu-table-wrap">
      <table class="stu-table">
        <thead><tr><th>REF NO.</th><th>EVENT</th><th>VENUE</th><th>DATE</th><th>RESOURCES</th><th>FILES</th><th>STATUS</th><th>REPORT</th><th>ACTIONS</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  `;
}

/* ===========================================================
   Post-event report — written and submitted by the student,
   then reviewed by OSAS.
   =========================================================== */

let pePhotos = [];              // newly picked File objects for this submission
let peReservationIndex = null;  // which reservation the open form belongs to
const PE_MAX_FILES = 10;
const PE_MAX_BYTES = 10 * 1024 * 1024;

async function openPostEventForm(index){
  const r = reservationsData[index];
  if(!r) return;
  peReservationIndex = index;
  pePhotos.forEach(p => URL.revokeObjectURL(p.preview));
  pePhotos = [];

  openStuModal(`<p class="empty-note">Loading event details…</p>`);

  let data;
  try{
    const res = await fetch(`api/post_event.php?reservation_id=${r.id}`);
    if(res.status === 401){ window.location.href = 'login.php'; return; }
    const json = await res.json();
    if(!json.success) throw new Error(json.error || 'Could not load this event.');
    data = json.data;
  } catch(err){
    return openStuModal(`<p class="form-error">${escapeHtml(err.message)}</p>
      <div style="display:flex;justify-content:flex-end;margin-top:16px;">
        <button class="btn btn-solid sans" onclick="closeStuModal()">Close</button></div>`);
  }

  const ev = data.reservation;
  const prev = data.report;          // null on a first submission
  const isRevision = !!prev;

  const opts = (map, selected) => Object.entries(map)
    .map(([v,l]) => `<option value="${escapeHtml(v)}" ${v===selected?'selected':''}>${escapeHtml(l)}</option>`).join('');

  const val = (k, fallback='') => escapeHtml(prev ? (prev[k] ?? '') : fallback);

  openStuModal(`
    <h2>Post-Event Report</h2>
    <p class="m-sub">${escapeHtml(ev.event_name)} · ${escapeHtml(ev.ref_no)} · ${escapeHtml(ev.venue_name)}</p>

    ${isRevision ? `
      <div class="revision-note">
        <strong>OSAS sent this back for changes.</strong>
        <p>${escapeHtml(prev.review_remarks || 'No remarks were given.')}</p>
        <span>Your previous answers are filled in below — correct them and resubmit. Photos you already uploaded are kept.</span>
      </div>` : ''}

    ${data.resources.length ? `<p class="m-sub"><strong>Resources you borrowed:</strong>
      ${data.resources.map(x=>`${escapeHtml(x.resource_name)} ×${x.quantity}`).join(', ')}</p>` : ''}

    <form id="peForm" class="pe-form">
      <div id="peError"></div>

      <div class="pe-grid">
        <div>
          <label>Actual attendance</label>
          <input type="number" id="peAttendees" min="0" step="1" value="${prev ? prev.attendees_count : 0}" required>
        </div>
        <div>
          <label>Prepared by</label>
          <input type="text" id="pePreparedBy" value="${prev ? escapeHtml(prev.prepared_by) : escapeHtml(studentUser.name)}" required>
        </div>
        <div>
          <label>Condition of borrowed resources</label>
          <select id="peResCond">${opts(data.resource_conditions,
            prev ? prev.resources_condition : (data.resources.length ? 'all_returned' : 'not_applicable'))}</select>
        </div>
        <div>
          <label>Condition of the venue</label>
          <select id="peVenueCond">${opts(data.venue_conditions, prev ? prev.venue_condition : 'good')}</select>
        </div>
      </div>

      <label>Summary of the event <span class="req-star">*</span></label>
      <textarea id="peSummary" required placeholder="What happened, how it went, notable moments…">${val('summary')}</textarea>

      <label>Issues or incidents encountered</label>
      <textarea id="peIssues" placeholder="Leave blank if there were none.">${val('issues')}</textarea>

      <label>Recommendations</label>
      <textarea id="peRecommends" placeholder="Anything OSAS should do differently next time?">${val('recommendations')}</textarea>

      ${isRevision && prev.photos.length ? `
        <label>Photos already on file (${prev.photos.length})</label>
        <div class="thumb-grid" style="margin-bottom:16px;">${prev.photos.map(p => `
          <figure class="thumb" onclick="openStuLightbox('${escapeHtml(p.url)}','${escapeHtml(p.caption||p.name)}')">
            <img src="${escapeHtml(p.url)}" alt="" loading="lazy">
            <figcaption>${escapeHtml(p.caption||p.name)}</figcaption>
          </figure>`).join('')}</div>` : ''}

      <div class="req-head">
        <label style="margin:0;">${isRevision ? 'Add more pictures (optional)' : 'Event pictures'}
          ${isRevision ? '' : '<span class="req-star">*</span>'}</label>
        <span class="req-count" id="pePhotoCount"></span>
      </div>
      <div class="req-drop" id="peDrop" tabindex="0" role="button">
        <div class="ic">🖼</div>
        <p><strong>Click to choose photos</strong> or drag them here</p>
        <p class="hint">JPG, PNG, WEBP or GIF · up to 10MB each · max ${PE_MAX_FILES} in total</p>
        <input type="file" id="pePhotoInput" accept="image/*" multiple hidden>
      </div>
      <div class="req-list" id="pePhotoList"></div>

      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
        <button type="button" class="btn" style="background:#eee;" onclick="closeStuModal()">Cancel</button>
        <button type="submit" class="btn btn-solid sans" id="peSubmitBtn" style="width:auto;">
          ${isRevision ? 'Resubmit Report' : 'Submit Report'}</button>
      </div>
    </form>
  `);

  const drop = document.getElementById('peDrop');
  const input = document.getElementById('pePhotoInput');
  const alreadyOnFile = isRevision ? prev.photos.length : 0;

  drop.addEventListener('click', () => input.click());
  drop.addEventListener('keydown', e => {
    if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); input.click(); }
  });
  input.addEventListener('change', () => { addPePhotos(input.files, alreadyOnFile); input.value = ''; });

  ['dragenter','dragover'].forEach(evName => drop.addEventListener(evName, e => {
    e.preventDefault(); drop.classList.add('over');
  }));
  ['dragleave','drop'].forEach(evName => drop.addEventListener(evName, e => {
    e.preventDefault(); drop.classList.remove('over');
  }));
  drop.addEventListener('drop', e => addPePhotos(e.dataTransfer.files, alreadyOnFile));

  renderPePhotos(alreadyOnFile, isRevision);

  document.getElementById('peForm').addEventListener('submit', e => submitPostEvent(e, r.id, isRevision, alreadyOnFile));
}

function addPePhotos(fileList, alreadyOnFile){
  const problems = [];
  [...fileList].forEach(file => {
    if(pePhotos.length + alreadyOnFile >= PE_MAX_FILES){
      problems.push(`A report can have at most ${PE_MAX_FILES} photos.`);
      return;
    }
    if(!file.type.startsWith('image/')){
      problems.push(`"${file.name}" isn't a picture.`);
      return;
    }
    if(file.size > PE_MAX_BYTES){
      problems.push(`"${file.name}" is bigger than 10MB.`);
      return;
    }
    if(pePhotos.some(p => p.file.name === file.name && p.file.size === file.size)) return;
    pePhotos.push({ file, caption: '', preview: URL.createObjectURL(file) });
  });

  const err = document.getElementById('peError');
  const unique = [...new Set(problems)];
  if(err) err.innerHTML = unique.length ? `<div class="form-error">${escapeHtml(unique.join(' '))}</div>` : '';

  renderPePhotos(alreadyOnFile);
}

function renderPePhotos(alreadyOnFile, isRevision){
  const list = document.getElementById('pePhotoList');
  const count = document.getElementById('pePhotoCount');
  if(!list || !count) return;

  const total = pePhotos.length + (alreadyOnFile || 0);
  if(total === 0){
    count.textContent = 'Required — at least one picture';
    count.className = 'req-count';
  } else {
    count.textContent = `${total} of ${PE_MAX_FILES} attached`;
    count.className = 'req-count ok';
  }

  list.innerHTML = pePhotos.map((p, i) => `
    <div class="req-file">
      <img src="${p.preview}" alt="">
      <div class="rf-body">
        <div class="rf-name" title="${escapeHtml(p.file.name)}">${escapeHtml(p.file.name)}</div>
        <div class="rf-meta">${humanFileSize(p.file.size)}</div>
        <input class="rf-label" data-pe-caption="${i}" value="${escapeHtml(p.caption)}"
               maxlength="160" placeholder="Caption (optional)">
      </div>
      <button type="button" class="rf-remove" data-pe-drop="${i}" title="Remove">&times;</button>
    </div>`).join('');

  list.querySelectorAll('[data-pe-caption]').forEach(inp => {
    inp.oninput = () => { pePhotos[+inp.dataset.peCaption].caption = inp.value; };
  });
  list.querySelectorAll('[data-pe-drop]').forEach(btn => {
    btn.onclick = () => {
      const i = +btn.dataset.peDrop;
      URL.revokeObjectURL(pePhotos[i].preview);
      pePhotos.splice(i, 1);
      renderPePhotos(alreadyOnFile, isRevision);
    };
  });
}

async function submitPostEvent(e, reservationId, isRevision, alreadyOnFile){
  e.preventDefault();
  const err = document.getElementById('peError');
  const btn = document.getElementById('peSubmitBtn');
  const showError = (m) => { err.innerHTML = `<div class="form-error">${escapeHtml(m)}</div>`; };

  const summary = document.getElementById('peSummary').value.trim();
  if(!summary) return showError('Please write a short summary of how the event went.');

  // A first submission has to carry at least one picture; a resubmission can
  // lean on the photos already on file.
  if(pePhotos.length === 0 && alreadyOnFile === 0){
    return showError('Please attach at least one picture of the event.');
  }

  btn.disabled = true;
  btn.textContent = 'Submitting…';

  const body = new FormData();
  body.append('reservation_id', reservationId);
  body.append('attendees_count', document.getElementById('peAttendees').value || '0');
  body.append('prepared_by', document.getElementById('pePreparedBy').value.trim());
  body.append('summary', summary);
  body.append('issues', document.getElementById('peIssues').value.trim());
  body.append('recommendations', document.getElementById('peRecommends').value.trim());
  body.append('resources_condition', document.getElementById('peResCond').value);
  body.append('venue_condition', document.getElementById('peVenueCond').value);
  body.append('captions', JSON.stringify(pePhotos.map(p => p.caption)));
  pePhotos.forEach(p => body.append('photos[]', p.file, p.file.name));

  try{
    const res = await fetch('api/post_event.php', { method: 'POST', body });
    if(res.status === 401){ window.location.href = 'login.php'; return; }

    let data;
    try{
      data = await res.json();
    } catch(parseErr){
      throw new Error(res.status === 413
        ? 'Those photos are too large for the server to accept. Please upload fewer or smaller pictures.'
        : 'The server sent back an unexpected response. Please try again.');
    }

    if(!data.success) throw new Error(data.error || 'Something went wrong. Please try again.');

    pePhotos.forEach(p => URL.revokeObjectURL(p.preview));
    pePhotos = [];
    // Reload so the table, the badge and the notification list all reflect
    // the new state from the database rather than a guess made here.
    window.location.href = 'student.php?page=myReservations&reported=1';
  } catch(ex){
    showError(ex.message);
    btn.disabled = false;
    btn.textContent = isRevision ? 'Resubmit Report' : 'Submit Report';
  }
}

/* ---- Reservation detail modal: the requirements the student submitted,
   plus the post-event report once OSAS has filed one. ---- */
function openReservationDetail(index){
  const r = reservationsData[index];
  if(!r) return;

  const files = r.files || [];
  const images = files.filter(f => f.kind === 'image');
  const docs = files.filter(f => f.kind !== 'image');

  const thumbs = (list) => `<div class="thumb-grid">${list.map(f => `
    <figure class="thumb" onclick="openStuLightbox('${escapeHtml(f.url)}','${escapeHtml(f.label || f.name || f.caption || '')}')">
      <img src="${escapeHtml(f.url)}" alt="" loading="lazy">
      <figcaption>${escapeHtml(f.label || f.caption || f.name || '')}</figcaption>
    </figure>`).join('')}</div>`;

  let html = `
    <h2>${escapeHtml(r.event)}</h2>
    <p class="m-sub">${escapeHtml(r.ref)} · ${escapeHtml(r.venue)} · ${fmtDate(r.y,r.m,r.d)} · ${escapeHtml(r.time)}
      &nbsp;<span class="badge ${badgeClass(r.status)}">${badgeLabel(r.status)}</span></p>

    ${declineReasonHtml(r, false)}
    ${votesHtml(r, false)}
    ${scheduleSectionHtml(r, index)}

    <div class="m-section">REQUIREMENTS YOU SUBMITTED (${files.length})</div>`;

  if(files.length === 0){
    html += `<p class="empty-note" style="padding:0 0 4px;">No files were attached to this reservation.</p>`;
  } else {
    if(images.length) html += thumbs(images);
    if(docs.length){
      html += docs.map(f => `
        <div class="doc-row" style="margin-top:10px;">
          <div class="doc-ic">${docGlyph(f.name)}</div>
          <div class="rf-body">
            <div class="rf-name">${escapeHtml(f.label || f.name)}</div>
            <div class="rf-meta">${escapeHtml(f.name)} · ${escapeHtml(f.size)}</div>
          </div>
          <a href="${escapeHtml(f.url)}" target="_blank" rel="noopener">Open</a>
        </div>`).join('');
    }
  }

  if(r.report){
    const rep = r.report;
    const tone = { pending:'b-pending', approved:'b-approved', needs_revision:'b-declined' }[rep.status] || 'b-completed';
    const label = { pending:'Under review', approved:'Approved', needs_revision:'Needs changes' }[rep.status] || rep.status;
    html += `
      <div class="m-section">POST-EVENT REPORT · ${escapeHtml(rep.no)}
        <span class="badge ${tone}" style="margin-left:6px;">${label}</span></div>
      <p class="m-sub" style="margin-bottom:10px;">
        ${Number(rep.attendees).toLocaleString()} attendees · prepared by ${escapeHtml(rep.preparedBy)}
      </p>
      ${rep.status === 'needs_revision' && rep.remarks ? `
        <div class="revision-note"><strong>OSAS asked for changes:</strong>
          <p>${escapeHtml(rep.remarks)}</p></div>` : ''}
      <p class="m-text">${escapeHtml(rep.summary)}</p>
      ${rep.issues ? `<div class="m-section">ISSUES ENCOUNTERED</div><p class="m-text">${escapeHtml(rep.issues)}</p>` : ''}
      ${rep.recommends ? `<div class="m-section">RECOMMENDATIONS</div><p class="m-text">${escapeHtml(rep.recommends)}</p>` : ''}
      <div class="m-section">EVENT PICTURES (${rep.photos.length})</div>
      ${rep.photos.length ? thumbs(rep.photos) : `<p class="empty-note" style="padding:0;">No pictures attached.</p>`}`;
  }

  html += `<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;flex-wrap:wrap;">
    ${changeButtonsHtml(r, index)}
    ${r.report && r.report.status === 'needs_revision'
      ? `<button class="btn btn-solid sans" style="width:auto;" onclick="openPostEventForm(${index})">Fix &amp; Resubmit</button>` : ''}
    <button class="btn" style="background:#eee;" onclick="closeStuModal()">Close</button>
  </div>`;

  openStuModal(html);
}

/** Puts arbitrary HTML in a centred modal, replacing whatever was open. */
function openStuModal(html){
  closeStuModal();
  const wrap = document.createElement('div');
  wrap.className = 'stu-modal';
  wrap.id = 'stuModal';
  wrap.innerHTML = `<div class="stu-modal-box">${html}</div>`;
  // Clicking the backdrop (but not the box itself) dismisses it.
  wrap.addEventListener('click', e => { if(e.target === wrap) closeStuModal(); });
  document.body.appendChild(wrap);
}

function closeStuModal(){
  document.getElementById('stuModal')?.remove();
}

function openStuLightbox(src, caption){
  closeStuLightbox();
  const box = document.createElement('div');
  box.className = 'stu-lightbox';
  box.id = 'stuLightbox';
  box.innerHTML = `<img src="${src}" alt=""><p>${caption}</p>`;
  box.addEventListener('click', e => { if(e.target.tagName !== 'IMG') closeStuLightbox(); });
  document.body.appendChild(box);
}
function closeStuLightbox(){
  document.getElementById('stuLightbox')?.remove();
}

document.addEventListener('keydown', e => {
  if(e.key !== 'Escape') return;
  if(document.getElementById('stuLightbox')) return closeStuLightbox();
  closeStuModal();
});

/* ---- New Reservation (form) — saves to the database ----
   Laid out like the paper "Permission to Conduct Activity / Program" form
   from the Office of the Student Affairs and Services. */
const NATURE_OPTIONS = ["Seminar / Convention","Educational Tour","Immersion","Community Extension","Academic Requirements"];
const ATTACH_OPTIONS = ["List of Students","Project Proposal","Budget Proposal","Instructor's Acceptance of Responsibility","Proposed Program / Invitation","Parent Consent"];

function renderNewReservationPage(main, showSuccess){
  selectedResources = [];
  const editR = editingReservationId ? reservationsData.find(x => x.id === editingReservationId) : null;
  if(!editR) editingReservationId = null;
  const todayLabel = new Date().toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
  main.innerHTML = `
    <div class="stu-topline"><div><h1>${editR ? 'Edit Reservation' : 'New Reservation'}</h1></div>
      ${editR ? `<button type="button" class="btn sans" style="width:auto;background:#eee;" onclick="cancelEditReservation()">Cancel editing</button>` : ''}</div>
    ${editR ? `<div class="notice-bar"><span class="notice-dot">✎</span><span>You are editing <strong>${escapeHtml(editR.ref)}</strong>${editR.status === 'declined' ? ' (declined by OSAS)' : ''}. Your pictures already on file are kept — upload a new picture only to replace or add one. Saving sends the request back to OSAS as <strong>Pending</strong>.</span></div>${declineReasonHtml(editR, false)}` : ''}
    <div id="formMessage">${showSuccess ? `<div class="form-success">Reservation request submitted! It now shows as Pending in "My Reservation".</div>` : ""}</div>

    <div class="reservation-layout">
      <form class="stu-form" id="reservationForm" onsubmit="submitReservation(event)">

        <div class="ocf">
          <div class="ocf-head">
            <img src="pictures/UCC.png" alt="University of Caloocan City">
            <div>
              <div class="u">UNIVERSITY OF CALOOCAN CITY</div>
              <div class="a">Biglang-Awa St., 12<sup>th</sup> Avenue East, Caloocan City<br>Telefax 310-6855</div>
              <div class="o">OFFICE OF THE STUDENT AFFAIRS AND SERVICES</div>
            </div>
          </div>

          <div class="ocf-title">
            <span>PERMISSION TO CONDUCT ACTIVITY / PROGRAM</span>
            <span class="scope">ON CAMPUS
              <label><input type="radio" name="rScope" value="on" id="rScopeOn"></label>
              AND OFF-CAMPUS
              <label><input type="radio" name="rScope" value="off" id="rScopeOff"></label>
            </span>
          </div>

          <div class="ocf-line" style="margin-top:6px;">
            <label for="rCampus">CAMPUS:</label>
            <input class="u-in" type="text" id="rCampus" maxlength="100" placeholder="Auto-filled from the venue you pick">
            <label for="rDatePrepared" style="margin-left:14px !important;">Date Prepared</label>
            <input class="u-in" type="text" id="rDatePrepared" value="${escapeHtml(todayLabel)}" readonly style="max-width:190px;">
          </div>

          <div style="padding:8px 14px 0;font-weight:700;font-size:.8rem;">Nature of Activity: <span style="font-weight:500;">(Please check only one)</span></div>
          <div class="ocf-nature">
            <div>
              ${NATURE_OPTIONS.map((n,i)=>`<label class="opt"><input type="radio" name="rNature" value="${escapeHtml(n)}" onchange="toggleNatureOthers()"> ${escapeHtml(n)}</label>`).join("")}
            </div>
            <div>
              <label class="opt"><input type="radio" name="rNature" value="Others" id="rNatureOthersBox" onchange="toggleNatureOthers()"> Others: (Please specify)</label>
              <div class="ocf-others">
                <input class="u-in" type="text" id="rNatureOther" maxlength="150" disabled placeholder="Specify here">
              </div>
            </div>
          </div>

          <div class="ocf-grid">
            <div class="ocf-cell full">
              <label for="rEvent">Title of Activity:</label>
              <input type="text" id="rEvent" placeholder="e.g. Freshmen Orientation" required>
            </div>

            <div class="ocf-cell">
              <label for="rVenue">Venue:</label>
              <select id="rVenue" required onchange="onVenueChosen()">
                <option value="" disabled selected>Select a venue</option>
                ${bookableVenuesList.map(v=>`<option value="${escapeHtml(v.name)}">${escapeHtml(v.name)}</option>`).join("")}
              </select>
              ${bookableVenuesList.length===0 ? '<p class="empty-note" style="padding:4px 0 0;">No venues are currently available for booking.</p>' : ""}
            </div>
            <div class="ocf-cell r">
              <label for="rDate">Date:</label>
              <input type="date" id="rDate" required onchange="availSchedule('form')">
              <label style="margin-top:6px;">Time:</label>
              <div class="ocf-time">
                <input type="time" step="3600" id="rStart" required onchange="updateVenuePreview();availSchedule('form')">
                <span>to</span>
                <input type="time" step="3600" id="rEnd" required onchange="updateVenuePreview();availSchedule('form')">
              </div>
            </div>

            <div class="ocf-cell full" id="availCell">
              <label>Venue availability on the selected date</label>
              ${AVAIL_LEGEND}
              <div class="slot-grid" id="availGrid"><p class="empty-note" style="padding:6px 0;">Choose a venue and a date to see which times are free.</p></div>
              <div id="conflictBox"></div>
            </div>

            <div class="ocf-cell">
              <label for="rParticipants">No. of Participants:</label>
              <select id="rParticipants" required>
                <option value="">Select number of participants</option>
                ${[100,200,300,400,500,600,700,800,900,1000].map(n=>`<option value="${n}">${n} participants</option>`).join("")}
              </select>
            </div>
            <div class="ocf-cell r">
              <label for="rSponsor">Sponsoring Organization / Class:</label>
              <input type="text" id="rSponsor" maxlength="150" required placeholder="e.g. BSIT Student Council">
            </div>

            <div class="ocf-cell full">
              <label for="rFund">Source of Fund:</label>
              <div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px 18px;margin-top:4px;">
                <label class="opt"><input type="radio" name="rFundType" value="Contributions" onchange="toggleFundOthers()"> Contributions</label>
                <label class="opt"><input type="radio" name="rFundType" value="Organization Fund" onchange="toggleFundOthers()"> Organization Fund</label>
                <label class="opt"><input type="radio" name="rFundType" value="Others" id="rFundOthersBox" onchange="toggleFundOthers()"> Others:</label>
                <input class="u-in" type="text" id="rFundOther" maxlength="130" disabled placeholder="Specify here" style="flex:1;min-width:160px;">
              </div>
            </div>

            <div class="ocf-cell full nb">
              <label for="rPurpose">Objectives of the Activity:</label>
              <textarea id="rPurpose" required placeholder="State the objectives of the activity"></textarea>
            </div>
          </div>

          <div class="ocf-sign">
            <div class="left">
              <p style="margin:0;"><b>I HEREBY CERTIFY THAT THIS ACTIVITY</b> is necessary to provide students new avenue of knowledge and experiences. Report of this activity and liquidation of expenses (if any) shall be made available after the conduct of activity.</p>
              <div class="sigline">
                <input class="u-in" type="text" id="rAdviser" maxlength="150" placeholder="Printed name" style="width:100%;text-align:center;">
                <small>Signature over printed name<br>CLUB / ORGANIZATION / ADVISER</small>
              </div>
              <h4 style="margin-top:18px;">Recommending Approval:</h4>
              <div class="sigline" style="margin-top:8px;">
                <input class="u-in" type="text" id="rRecommending" maxlength="150" placeholder="Printed name" style="width:100%;text-align:center;">
                <small>Signature over printed name<br>Program Coordinator / Program Chairman / Dean</small>
              </div>
              <div class="remarks"><label for="rRemarks">Remarks:</label><textarea id="rRemarks" rows="3" maxlength="255" placeholder="Type remarks here (optional)"></textarea></div>
            </div>
            <div style="padding:0;">
              <div style="padding:10px 14px;">
                <h4>Approved by:</h4>
                <div class="nm" style="margin-top:34px;"><b>${escapeHtml(SIGNATORIES.approver1_name)}</b><small>${escapeHtml(SIGNATORIES.approver1_title)}</small></div>
                <div class="nm" style="margin-top:34px;"><b>${escapeHtml(SIGNATORIES.approver2_name)}</b><small>${escapeHtml(SIGNATORIES.approver2_title)}</small></div>
              </div>
              <div class="right-b" style="padding:10px 14px;min-height:150px;">
                <h4>Noted by:</h4>
                <div class="nm" style="margin-top:48px;"><b>${escapeHtml(SIGNATORIES.noted_name)}</b><small>${escapeHtml(SIGNATORIES.noted_title)}</small></div>
              </div>
            </div>
          </div>

          <div class="ocf-att">
            <h4>Attachments: <span style="color:#b00020;font-weight:700;">* Tick each item you are submitting and upload its picture</span></h4>
            <div class="cols">
              ${ATTACH_OPTIONS.map((a,i)=>`
              <div class="att-slot" id="attSlot${i}">
                <label class="opt"><input type="checkbox" name="rAttach" data-slot="${i}" value="${escapeHtml(a)}" onchange="onSlotTick(${i})"> ${escapeHtml(a)}</label>
                <div class="att-slot-ctl">
                  <button type="button" class="att-btn" onclick="document.getElementById('attFile${i}').click()">📎 Upload picture</button>
                  <input type="file" id="attFile${i}" hidden accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" onchange="onSlotFile(${i}, this)">
                  <span class="att-pick" id="attPick${i}"></span>
                </div>
              </div>`).join("")}
            </div>
            <small style="display:block;margin-top:4px;color:var(--ink-soft);">Each picture: JPG, PNG, WEBP or GIF, up to 10MB. At least one picture is required to submit.</small>
          </div>
        </div>

        <div class="form-sec-title">Resources to Borrow <span style="font-weight:400;font-size:.8rem;color:var(--ink-soft);">(optional)</span></div>
        <div class="span-2" style="margin-bottom:20px;">
          <select id="rResourceSelect" onchange="onResourcePicked()">
            <option value="" selected>${resourcesList.length ? "Select a resource to borrow" : "No resources available"}</option>
            ${resourcesList.map(r=>`<option value="${r.id}" data-stock="${r.stock}" ${r.stock<=0?'disabled':''}>${escapeHtml(r.name)} — ${r.stock} in stock${r.stock<=0?' (Out of stock)':''}</option>`).join("")}
          </select>

          <!-- "How many do you want to borrow?" — hidden until a resource is picked above -->
          <div class="res-inline-picker" id="resInlinePicker" hidden>
            <div class="res-inline-picker-head">
              <span class="res-inline-name" id="resInlineName"></span>
              <span class="res-inline-stock" id="resInlineStock"></span>
            </div>
            <div class="res-inline-controls">
              <input type="number" id="resQtyInput" min="1" step="1">
              <button type="button" class="btn btn-solid sans" onclick="confirmResourceQty()">Add</button>
              <button type="button" class="btn" style="background:#eee" onclick="cancelResourceQty()">Cancel</button>
            </div>
          </div>

          <div id="selectedResourcesBox"></div>
        </div>

        <button type="submit" class="btn btn-solid sans" id="rSubmitBtn">${editR ? 'Save &amp; Resubmit' : 'Submit Request'}</button>
      </form>

      <div>
        <div class="info-card">
          <h3>Selected Venue</h3>
          <div id="venuePreview" class="venue-preview empty">Choose a venue above to see its details here.</div>
        </div>
        <div class="info-card">
          <h3>Before You Submit</h3>
          <ul>
            <li>You must upload <strong>one picture</strong> under <strong>Attachment</strong> at the bottom of the form, otherwise the request can't be submitted.</li>
            <li>Requests are reviewed by OSAS and marked as <strong>Pending</strong> until approved.</li>
            <li>Double-check the date and time — overlapping bookings may be declined.</li>
            <li>You'll get a notification once your request is approved or declined.</li>
            <li>Borrowed resources are deducted from stock once you submit.</li>
            <li>Your event is marked <strong>Done</strong> automatically once its end time passes.</li>
          </ul>
        </div>
      </div>
    </div>
  `;
  renderSelectedResources();
  initRequirementPicker();
  initFormAvail();
  applyBookingPrefill();
  if(editR) applyEditPrefill(editR);
}

/* ---- Edit a pending / declined reservation ---- */
const canEdit = r => !r.isPast && (r.status === 'pending' || r.status === 'declined');
function startEditReservation(index){
  const r = reservationsData[index];
  if(!r || !canEdit(r)) return;
  closeStuModal();
  editingReservationId = r.id;
  navigateStudent('newReservation', true);
  window.scrollTo(0, 0);
}
function cancelEditReservation(){
  editingReservationId = null;
  navigateStudent('myReservations');
}
function applyEditPrefill(r){
  const f = r.form || {};
  const setRadio = (name, val) => { const el = [...document.querySelectorAll(`input[name="${name}"]`)].find(x => x.value === val); if(el) el.checked = true; return !!el; };
  sv('rEvent', r.event);
  if(bookableVenuesList.some(v => v.name === r.venue)){ sv('rVenue', r.venue); onVenueChosen(); }
  if(f.campus) sv('rCampus', f.campus);
  sv('rDate', r.isoDate); sv('rStart', r.start); sv('rEnd', r.end);
  if(f.scope) setRadio('rScope', f.scope);
  if(f.nature){
    if(setRadio('rNature', f.nature) && f.nature === 'Others'){ const o = document.getElementById('rNatureOther'); o.disabled = false; o.value = f.natureOther || ''; }
  }
  if(f.participants) sv('rParticipants', String(f.participants));
  sv('rSponsor', f.sponsor || '');
  if(f.fund){
    if(f.fund.startsWith('Others')){ setRadio('rFundType', 'Others'); const o = document.getElementById('rFundOther'); o.disabled = false; o.value = f.fund.replace(/^Others:\s*/, ''); }
    else setRadio('rFundType', f.fund);
  }
  sv('rPurpose', r.purpose || '');
  sv('rAdviser', f.adviser || ''); sv('rRecommending', f.recommending || ''); sv('rRemarks', f.remarks || '');
  // Checklist: tick items that were submitted, and show the picture already on file.
  const onFile = {};
  (r.files || []).forEach(x => { if(x.label) onFile[x.label] = x; });
  const ticked = (f.checklist || '').split(', ').filter(Boolean);
  ATTACH_OPTIONS.forEach((a, i) => {
    const cb = document.querySelector(`input[name="rAttach"][data-slot="${i}"]`);
    if(cb && (ticked.includes(a) || onFile[a])) cb.checked = true;
    renderSlot(i);
  });
  // Resources: what this request already holds counts as available again.
  selectedResources = (r.resources || []).filter(x => x.id).map(x => {
    const live = resourcesList.find(y => Number(y.id) === Number(x.id));
    return { id: String(x.id), name: x.name, stock: (live ? Number(live.stock) : 0) + x.qty, quantity: x.qty };
  });
  renderSelectedResources();
  updateVenuePreview();
  availSchedule('form');
}
function editFileFor(i){
  const r = editingReservationId ? reservationsData.find(x => x.id === editingReservationId) : null;
  return r ? (r.files || []).find(x => x.label === ATTACH_OPTIONS[i]) : null;
}

/* Source of Fund: the "Others" text box only accepts text while "Others" is selected. */
function toggleFundOthers(){
  const box = document.getElementById('rFundOthersBox');
  const input = document.getElementById('rFundOther');
  if(!box || !input) return;
  input.disabled = !box.checked;
  if(box.checked) input.focus(); else input.value = '';
}

/* "Others: (Please specify)" only accepts text while its option is the selected one. */
function toggleNatureOthers(){
  const box = document.getElementById('rNatureOthersBox');
  const input = document.getElementById('rNatureOther');
  if(!box || !input) return;
  input.disabled = !box.checked;
  if(box.checked) input.focus(); else input.value = '';
}

/* Picking a venue fills the CAMPUS line (still editable) and the side preview. */
function onVenueChosen(){
  const sel = document.getElementById('rVenue');
  const venue = bookableVenuesList.find(v => v.name === sel.value);
  const campus = document.getElementById('rCampus');
  if(venue && campus) campus.value = venue.campus || '';
  updateVenuePreview();
  availSchedule('form');
}

/* ---- Requirements: the mandatory file container ----
   Files are held here as real File objects (not in the DOM) so the labels
   the student types survive a re-render, and so nothing is uploaded until
   the whole form is actually submitted. */
let selectedRequirements = [];
const REQ_MAX_FILES = 10;
const REQ_MAX_BYTES = 10 * 1024 * 1024;
const REQ_ALLOWED_EXT = ["jpg","jpeg","png","webp","gif","pdf","doc","docx","xls","xlsx","txt"];

/* ---- Checklist slots: one upload per checklist item ---- */
let slotFiles = {}; // index -> { file, preview, isImage }

function resetSlots(){
  Object.values(slotFiles).forEach(v => { if(v.preview) URL.revokeObjectURL(v.preview); });
  slotFiles = {};
}

function onSlotFile(i, input){
  const file = input.files[0];
  input.value = '';
  if(!file) return;
  const msgBox = document.getElementById('formMessage');
  const ext = (file.name.split('.').pop() || '').toLowerCase();
  let problem = '';
  if(!['jpg','jpeg','png','webp','gif'].includes(ext) || !file.type.startsWith('image/')) problem = `"${file.name}" isn't a picture. Please upload a JPG, PNG, WEBP or GIF.`;
  else if(file.size > REQ_MAX_BYTES) problem = `"${file.name}" is bigger than 10MB.`;
  else if(file.size === 0) problem = `"${file.name}" is empty.`;
  if(problem){
    if(msgBox) msgBox.innerHTML = `<div class="form-error">${escapeHtml(problem)}</div>`;
    return;
  }
  if(msgBox) msgBox.innerHTML = '';
  if(slotFiles[i] && slotFiles[i].preview) URL.revokeObjectURL(slotFiles[i].preview);
  const isImage = file.type.startsWith('image/');
  slotFiles[i] = { file, isImage, preview: isImage ? URL.createObjectURL(file) : null };
  const cb = document.querySelector(`input[name="rAttach"][data-slot="${i}"]`);
  if(cb) cb.checked = true;
  renderSlot(i);
}

function onSlotTick(i){
  const cb = document.querySelector(`input[name="rAttach"][data-slot="${i}"]`);
  // Unticking removes the file that was attached to that item.
  if(cb && !cb.checked && slotFiles[i]) removeSlotFile(i);
  else if(cb && cb.checked && !slotFiles[i] && !editFileFor(i)) document.getElementById('attFile'+i)?.click();
}

function removeSlotFile(i){
  if(slotFiles[i] && slotFiles[i].preview) URL.revokeObjectURL(slotFiles[i].preview);
  delete slotFiles[i];
  const cb = document.querySelector(`input[name="rAttach"][data-slot="${i}"]`);
  if(cb) cb.checked = false;
  renderSlot(i);
}

function renderSlot(i){
  const box = document.getElementById('attPick'+i);
  if(!box) return;
  const v = slotFiles[i];
  if(!v){
    const had = editFileFor(i);
    box.innerHTML = had ? `${had.kind === 'image' ? `<img src="${escapeHtml(had.url)}" alt="">` : docGlyph(had.name)}
      <span>On file: ${escapeHtml(had.name)} · ${escapeHtml(had.size)} — upload to replace</span>` : '';
    return;
  }
  box.innerHTML = `${v.isImage ? `<img src="${v.preview}" alt="">` : docGlyph(v.file.name)}
    <span>${escapeHtml(v.file.name)} · ${humanFileSize(v.file.size)}</span>
    <button type="button" class="x" title="Remove" onclick="removeSlotFile(${i})">&times;</button>`;
}

function initRequirementPicker(){
  resetSlots();
  // Release any object URLs from a previous visit to this page before dropping them.
  selectedRequirements.forEach(f => { if(f.preview) URL.revokeObjectURL(f.preview); });
  selectedRequirements = [];

  const drop = document.getElementById('reqDrop');
  const input = document.getElementById('reqInput');
  if(!drop || !input) return;

  drop.addEventListener('click', () => input.click());
  drop.addEventListener('keydown', e => {
    if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); input.click(); }
  });
  input.addEventListener('change', () => {
    addRequirementFiles(input.files);
    input.value = ''; // so picking the same file again still fires change
  });

  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => {
    e.preventDefault(); drop.classList.add('over');
  }));
  ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => {
    e.preventDefault(); drop.classList.remove('over');
  }));
  drop.addEventListener('drop', e => addRequirementFiles(e.dataTransfer.files));

  renderRequirementFiles();
}

function addRequirementFiles(fileList){
  const problems = [];

  [...fileList].forEach(file => {
    if(selectedRequirements.length >= REQ_MAX_FILES){
      problems.push(`You can attach at most ${REQ_MAX_FILES} files.`);
      return;
    }
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if(!REQ_ALLOWED_EXT.includes(ext)){
      problems.push(`"${file.name}" isn't an accepted file type.`);
      return;
    }
    if(file.size > REQ_MAX_BYTES){
      problems.push(`"${file.name}" is bigger than 10MB.`);
      return;
    }
    if(file.size === 0){
      problems.push(`"${file.name}" is empty.`);
      return;
    }
    // Same name and size twice over is almost certainly a double-pick, not
    // two genuinely different documents.
    if(selectedRequirements.some(f => f.file.name === file.name && f.file.size === file.size)) return;

    const isImage = file.type.startsWith('image/');
    selectedRequirements.push({
      file,
      label: '',
      isImage,
      preview: isImage ? URL.createObjectURL(file) : null,
    });
  });

  const msgBox = document.getElementById('formMessage');
  const unique = [...new Set(problems)];
  if(msgBox){
    msgBox.innerHTML = unique.length
      ? `<div class="form-error">${escapeHtml(unique.join(' '))}</div>`
      : '';
  }

  renderRequirementFiles();
}

function removeRequirementFile(index){
  const item = selectedRequirements[index];
  if(item && item.preview) URL.revokeObjectURL(item.preview);
  selectedRequirements.splice(index, 1);
  renderRequirementFiles();
}

function humanFileSize(bytes){
  if(bytes >= 1048576) return (bytes/1048576).toFixed(1) + ' MB';
  if(bytes >= 1024) return Math.round(bytes/1024) + ' KB';
  return bytes + ' B';
}

function docGlyph(name){
  const ext = (name.split('.').pop() || '').toLowerCase();
  if(ext === 'pdf') return '📕';
  if(ext === 'doc' || ext === 'docx') return '📘';
  if(ext === 'xls' || ext === 'xlsx') return '📗';
  return '📄';
}

function renderRequirementFiles(){
  const list = document.getElementById('reqList');
  const count = document.getElementById('reqCount');
  if(!list || !count) return;

  if(selectedRequirements.length === 0){
    count.textContent = 'Optional — add any other supporting files';
    count.className = 'req-count optional';
    list.innerHTML = '';
    return;
  }

  count.textContent = `${selectedRequirements.length} of ${REQ_MAX_FILES} attached`;
  count.className = 'req-count ok';

  list.innerHTML = selectedRequirements.map((f, i) => `
    <div class="req-file">
      ${f.isImage
        ? `<img src="${f.preview}" alt="">`
        : `<div class="doc-ic">${docGlyph(f.file.name)}</div>`}
      <div class="rf-body">
        <div class="rf-name" title="${escapeHtml(f.file.name)}">${escapeHtml(f.file.name)}</div>
        <div class="rf-meta">${humanFileSize(f.file.size)}</div>
        <input class="rf-label" data-req-label="${i}" value="${escapeHtml(f.label)}"
               maxlength="120" placeholder="What is this? e.g. Letter of Request">
      </div>
      <button type="button" class="rf-remove" data-req-remove="${i}" title="Remove">&times;</button>
    </div>`).join('');

  list.querySelectorAll('[data-req-label]').forEach(input => {
    input.oninput = () => { selectedRequirements[+input.dataset.reqLabel].label = input.value; };
  });
  list.querySelectorAll('[data-req-remove]').forEach(btn => {
    btn.onclick = () => removeRequirementFile(+btn.dataset.reqRemove);
  });
}

/* ---- Resource borrowing: dropdown -> inline quantity panel -> chip list ---- */
let pendingResource = null; // the resource currently shown in the inline "how many?" panel

function onResourcePicked(){
  const sel = document.getElementById('rResourceSelect');
  const opt = sel.selectedOptions[0];
  if(!sel.value || !opt) return;

  const id = sel.value;
  const stock = parseInt(opt.dataset.stock, 10) || 0;
  const name = opt.textContent.split(' — ')[0];
  const existing = selectedResources.find(r => String(r.id) === String(id));

  showResourcePicker({ id, name, stock }, existing ? existing.quantity : 1);

  // Reset the dropdown right away so re-selecting the same option later still fires onchange.
  sel.value = '';
}

/* Shows the inline "how many?" panel right under the dropdown, pre-filled
   for the given resource. Stays hidden the rest of the time. */
function showResourcePicker(resource, qty){
  pendingResource = resource;
  document.getElementById('resInlineName').textContent = resource.name;
  document.getElementById('resInlineStock').textContent = `${resource.stock} in stock`;
  const input = document.getElementById('resQtyInput');
  input.max = resource.stock;
  input.min = 1;
  input.value = qty;
  document.getElementById('resInlinePicker').hidden = false;
  input.focus();
  input.select();
}

// Stop the quantity input from ever holding a value outside [1, stock].
// Bound once on document (delegated), since #resQtyInput is recreated every
// time the New Reservation page renders.
if(!window._resQtyClampBound){
  window._resQtyClampBound = true;
  document.addEventListener('input', function(e){
    if(e.target && e.target.id === 'resQtyInput'){
      const max = parseInt(e.target.max, 10) || 1;
      if(e.target.value !== '' && parseInt(e.target.value, 10) > max) e.target.value = max;
      if(e.target.value !== '' && parseInt(e.target.value, 10) < 1) e.target.value = 1;
    }
  });
}

function cancelResourceQty(){
  pendingResource = null;
  document.getElementById('resInlinePicker').hidden = true;
}

function confirmResourceQty(){
  if(!pendingResource) return;
  const input = document.getElementById('resQtyInput');
  let qty = parseInt(input.value, 10);
  if(!qty || qty < 1) qty = 1;
  if(qty > pendingResource.stock) qty = pendingResource.stock; // can never exceed available stock

  const idx = selectedResources.findIndex(r => String(r.id) === String(pendingResource.id));
  const entry = { id: pendingResource.id, name: pendingResource.name, stock: pendingResource.stock, quantity: qty };
  if(idx > -1) selectedResources[idx] = entry;
  else selectedResources.push(entry);

  document.getElementById('resInlinePicker').hidden = true;
  pendingResource = null;
  renderSelectedResources();
}

function editSelectedResource(id){
  const r = selectedResources.find(x => String(x.id) === String(id));
  if(!r) return;
  showResourcePicker({ id: r.id, name: r.name, stock: r.stock }, r.quantity);
}

function removeSelectedResource(id){
  selectedResources = selectedResources.filter(r => String(r.id) !== String(id));
  renderSelectedResources();
}

function renderSelectedResources(){
  const box = document.getElementById('selectedResourcesBox');
  if(!box) return;
  if(selectedResources.length === 0){
    box.innerHTML = `<p class="empty-note" style="padding:6px 0 0;">No resources added yet — pick one from the dropdown above if you need any.</p>`;
    return;
  }
  box.innerHTML = `<div class="res-chip-list">${selectedResources.map(r => `
    <div class="res-chip">
      <span class="res-chip-name">${escapeHtml(r.name)}</span>
      <span class="res-chip-qty">x${r.quantity}</span>
      <button type="button" class="res-chip-edit" onclick="editSelectedResource('${r.id}')" title="Change quantity">✎</button>
      <button type="button" class="res-chip-remove" onclick="removeSelectedResource('${r.id}')" title="Remove">&times;</button>
    </div>`).join('')}</div>`;
}

function updateVenuePreview(){
  const sel = document.getElementById('rVenue');
  const box = document.getElementById('venuePreview');
  if(!sel || !box) return;
  const venue = bookableVenuesList.find(v => v.name === sel.value);
  if(!venue){
    box.className = 'venue-preview empty';
    box.innerHTML = 'Choose a venue above to see its details here.';
    return;
  }
  const start = document.getElementById('rStart')?.value || '';
  const end = document.getElementById('rEnd')?.value || '';
  box.className = 'venue-preview';
  box.innerHTML = `
    <div class="vp-name">${escapeHtml(venue.name)}</div>
    <div class="vp-row"><span>Campus</span><span>${escapeHtml(venue.campus)}</span></div>
    ${(start && end) ? `<div class="vp-row"><span>Time</span><span>${escapeHtml(fmtTimeRangeInputs(start,end))}</span></div>` : ''}
  `;
}

function fmtTimeRangeInputs(start, end){
  const to12 = t => {
    const [h,m] = t.split(':').map(Number);
    const period = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(m).padStart(2,'0')} ${period}`;
  };
  return `${to12(start)} – ${to12(end)}`;
}

async function submitReservation(e){
  e.preventDefault();
  const eventName = document.getElementById('rEvent').value.trim();
  const venue = document.getElementById('rVenue').value;
  const dateVal = document.getElementById('rDate').value;
  const start = document.getElementById('rStart').value;
  const end = document.getElementById('rEnd').value;
  const purpose = document.getElementById('rPurpose').value.trim();
  const msgBox = document.getElementById('formMessage');
  const btn = document.getElementById('rSubmitBtn');

  const scope = document.querySelector('input[name="rScope"]:checked')?.value || '';
  const campus = document.getElementById('rCampus').value.trim();
  const nature = [...document.querySelectorAll('input[name="rNature"]:checked')].map(c => c.value);
  const natureOther = document.getElementById('rNatureOther').value.trim();
  const participants = document.getElementById('rParticipants').value.trim();
  const sponsor = document.getElementById('rSponsor').value.trim();
  const fundType = document.querySelector('input[name="rFundType"]:checked')?.value || '';
  const fundOther = document.getElementById('rFundOther').value.trim();
  const fund = fundType === 'Others' ? (fundOther ? `Others: ${fundOther}` : '') : fundType;
  const adviser = document.getElementById('rAdviser').value.trim();
  const recommending = document.getElementById('rRecommending').value.trim();
  const remarks = document.getElementById('rRemarks').value.trim();
  const attach = [...document.querySelectorAll('input[name="rAttach"]:checked')].map(c => c.value);

  const showErr = (text, focusId) => {
    msgBox.innerHTML = `<div class="form-error">${escapeHtml(text)}</div>`;
    const el = focusId ? document.getElementById(focusId) : null;
    if(el){ el.scrollIntoView({ behavior:'smooth', block:'center' }); el.focus?.(); }
    else msgBox.scrollIntoView({ behavior:'smooth', block:'center' });
  };

  if(!scope) return showErr('Please tick whether the activity is On-Campus or Off-Campus.', 'rScopeOn');
  if(nature.length === 0) return showErr('Please check one Nature of Activity.');
  if(nature.includes('Others') && !natureOther) return showErr('Please specify the "Others" nature of activity.', 'rNatureOther');
  if(!eventName || !venue || !dateVal || !start || !end){
    return showErr('Please fill in the Title, Venue, Date and Time.');
  }
  if(!participants) return showErr('Please select the number of participants.', 'rParticipants');
  if(!sponsor) return showErr('Please enter the Sponsoring Organization / Class.', 'rSponsor');
  if(!fundType) return showErr('Please choose the Source of Fund.');
  if(fundType === 'Others' && !fundOther) return showErr('Please specify the "Others" source of fund.', 'rFundOther');
  if(!purpose) return showErr('Please enter the Objectives of the Activity.', 'rPurpose');

  // Frontend overlap check against the availability we just loaded (the server re-checks anyway).
  const editR = editingReservationId ? reservationsData.find(x => x.id === editingReservationId) : null;
  const fst = AVAIL.form && AVAIL.form.state;
  if(!editR && fst && fst.busy && fst.venue === venue && fst.date === dateVal && overlapsAny(fst.busy, start, end).length){
    availRender('form');
    return showErr('That time overlaps an existing booking. Please choose another time — see the Available slots and suggestions under the date and time.', 'availCell');
  }

  // The requirements are the whole point of the new gate — check them before
  // anything is sent, so the student gets the message instantly instead of
  // waiting on a round trip that was always going to be rejected.
  // A picture is mandatory.
  const slotIdx = Object.keys(slotFiles).map(Number).sort((x,y)=>x-y);
  const hasOnFile = editR && (editR.files || []).length > 0;
  if(slotIdx.length === 0 && !hasOnFile){
    return showErr('Please tick an attachment and upload its picture. You cannot submit the request without one.', 'attFile0');
  }
  const missing = ATTACH_OPTIONS.findIndex((a,i) => document.querySelector(`input[name="rAttach"][data-slot="${i}"]`)?.checked && !slotFiles[i] && !editFileFor(i));
  if(missing !== -1){
    return showErr(`Please upload a picture for "${ATTACH_OPTIONS[missing]}", or untick it.`, 'attFile'+missing);
  }

  // If the "how many?" panel is still open (a resource was picked from the
  // dropdown but "Add" was never clicked), commit it now so it isn't lost.
  const pickerOpen = document.getElementById('resInlinePicker') && !document.getElementById('resInlinePicker').hidden;
  if(pickerOpen && pendingResource) confirmResourceQty();

  btn.disabled = true;
  btn.textContent = editR ? "Saving..." : "Submitting...";

  try{
    // multipart/form-data now, because the files ride along with the fields.
    // Note there's deliberately no Content-Type header: the browser has to
    // set it itself so it can include the multipart boundary.
    const body = new FormData();
    if(editR) body.append('reservation_id', editR.id);
    body.append('event', eventName);
    body.append('venue', venue);
    body.append('date', dateVal);
    body.append('start', start);
    body.append('end', end);
    body.append('purpose', purpose);
    body.append('scope', scope);
    body.append('campus', campus);
    body.append('nature', JSON.stringify(nature));
    body.append('nature_other', natureOther);
    body.append('participants', participants);
    body.append('sponsoring_org', sponsor);
    body.append('source_of_fund', fund);
    body.append('adviser_name', adviser);
    body.append('recommending_name', recommending);
    body.append('remarks', remarks);
    body.append('attachments_checklist', JSON.stringify(attach));
    body.append('resources', JSON.stringify(selectedResources.map(r => ({ id: r.id, quantity: r.quantity }))));
    const labels = [];
    slotIdx.forEach(i => {
      body.append('requirements[]', slotFiles[i].file, slotFiles[i].file.name);
      labels.push(ATTACH_OPTIONS[i]);
    });
    body.append('requirement_labels', JSON.stringify(labels));

    const res = await fetch(editR ? 'api/reservation_update.php' : 'api/reservation_create.php', { method: 'POST', body });

    if(res.status === 401){
      window.location.href = 'login.php';
      return;
    }

    let data;
    try{
      data = await res.json();
    } catch(parseErr){
      // Apache/PHP answer an over-sized upload with an HTML error page, not
      // JSON, so give that its own message rather than "something went wrong".
      throw new Error(res.status === 413
        ? 'Those attachments are too large for the server to accept. Please upload fewer or smaller files.'
        : 'The server sent back an unexpected response. Please try again.');
    }

    if(data.success){
      window.location.href = editR ? 'student.php?page=myReservations&flash=edited' : 'student.php?page=myReservations&submitted=1';
    } else {
      if(data.conflict) availRun('form');   // refresh slots + suggestions from the server
      msgBox.innerHTML = `<div class="form-error">${escapeHtml(data.error || "Something went wrong. Please try again.")}</div>`;
      btn.disabled = false;
      btn.textContent = editR ? "Save & Resubmit" : "Submit Request";
    }
  } catch(err){
    msgBox.innerHTML = `<div class="form-error">${escapeHtml(err.message || 'Could not reach the server. Please try again.')}</div>`;
    btn.disabled = false;
    btn.textContent = editR ? "Save & Resubmit" : "Submit Request";
  }
}

/* ---- Venue Availability ---- */
function renderVenuesPage(main){
  let cards = "";
  venuesList.forEach(v=>{
    const booked = bookedTodayVenues.includes(v.name);
    let label, cls;
    if(v.status === 'maintenance'){ label = 'Under Maintenance'; cls = 'b-declined'; }
    else if(v.status === 'reserved'){ label = 'Reserved'; cls = 'b-pending'; }
    else if(booked){ label = 'Booked Today'; cls = 'b-pending'; }
    else { label = 'Available Today'; cls = 'b-approved'; }
    cards += `
      <div class="venue-card">
        <div>
          <h3>${escapeHtml(v.name)}</h3>
          <div class="meta">${escapeHtml(v.campus)}</div>
        </div>
        <span class="badge ${cls}">${label}</span>
      </div>`;
  });

  main.innerHTML = `
    <div class="stu-topline"><div><h1>Venue Availability</h1></div></div>
    <p class="empty-note" style="padding-top:0;">Status shown is for today. Check the Calendar tab for other dates.</p>
    ${cards}
  `;
}

/* ---- Calendar (embedded — same behavior as the public calendar, minus
   the branded header and the "sign in / create account" prompt, since
   the user is already signed in here). ---- */
function renderCalendarPage(main){
  main.innerHTML = `
    <div class="stu-topline"><div><h1>Calendar</h1></div></div>
    <div class="cal-layout">
      <div class="panel">
        <div class="cal-toolbar">
          <div class="search-wrap">
            <input type="text" id="calSearchInput" placeholder="Search date or event" oninput="calFilterSearch(this.value)"/>
            <button class="search-clear" id="calSearchClearBtn" onclick="calClearSearch()" style="display:none;">&times;</button>
          </div>
          <select id="calVenueSel" class="cal-venue-sel" onchange="calSetVenue(this.value)" title="Show availability for one venue">
            <option value="">All venues</option>
            ${venuesList.map(v=>`<option value="${v.id}" ${Number(v.id)===calVenueId?'selected':''}>${escapeHtml(v.name)}</option>`).join('')}
          </select>
          <span class="month-label" id="calMonthLabel"></span>
          <button onclick="calChangeMonth(-1)">&lt;</button>
          <button class="today-btn sans" onclick="calGoToday()">TODAY</button>
          <button onclick="calChangeMonth(1)">&gt;</button>
        </div>
        <table class="cal">
          <thead>
            <tr><th>SUN</th><th>MON</th><th>TUE</th><th>WED</th><th>THU</th><th>FRI</th><th>SAT</th></tr>
          </thead>
          <tbody id="calBody"></tbody>
        </table>
        ${AVAIL_LEGEND}
        <p class="avail-hint">${calVenueId ? 'Colours show each day for the chosen venue. Select a day to see its free time slots.' : 'Choose a venue above to colour the days Available / Pending / Booked.'}</p>
      </div>
      <div class="panel" id="calRightPanel"></div>
    </div>
  `;
  renderCalendarGrid();
  renderCalendarRightPanel();
}

function calEventsInMonth(y,m){
  const out = [];
  Object.keys(calendarEvents).forEach(key=>{
    const [ky,km,kd] = key.split("-").map(Number);
    if(ky===y && km===m){
      calendarEvents[key].forEach(ev=>out.push({d:kd, ...ev}));
    }
  });
  return out.sort((a,b)=>a.d-b.d);
}

function calChangeMonth(delta){
  calViewMonth += delta;
  if(calViewMonth<0){ calViewMonth=11; calViewYear--; }
  if(calViewMonth>11){ calViewMonth=0; calViewYear++; }
  calUpcomingPage = 1;
  renderCalendarGrid();
  renderCalendarRightPanel();
}
function calGoToday(){
  calViewYear = now.getFullYear();
  calViewMonth = now.getMonth();
  calUpcomingPage = 1;
  renderCalendarGrid();
  renderCalendarRightPanel();
}

function calFilterSearch(q){
  calSearchQuery = q;
  calUpcomingPage = 1;   // new search text -> back to the first page
  document.getElementById('calSearchClearBtn').style.display = q ? 'block' : 'none';
  if(q && calSelectedDate) calSelectedDate = null;
  renderCalendarGrid();
  renderCalendarRightPanel();
}
function calClearSearch(){
  calSearchQuery = "";
  calUpcomingPage = 1;
  document.getElementById('calSearchInput').value = "";
  document.getElementById('calSearchClearBtn').style.display = 'none';
  renderCalendarGrid();
  renderCalendarRightPanel();
}

function calSearchEvents(query){
  const q = query.trim().toLowerCase();
  if(!q) return [];
  const results = [];
  Object.keys(calendarEvents).forEach(key=>{
    const [y,m,d] = key.split("-").map(Number);
    calendarEvents[key].forEach(ev=>{
      const dateVariants = [
        `${monthNames[m]} ${d}, ${y}`,
        `${monthNames[m]} ${d}`,
        `${monthNames[m].slice(0,3)} ${d}`,
        `${m+1}/${d}/${y}`,
        `${m+1}/${d}`,
        `${y}-${m+1}-${d}`,
        `${dayName(y,m,d)}`,
        `${d}`
      ].map(v=>v.toLowerCase());
      const textFields = [ev.title, ev.venue, ev.campus, ev.requestedBy].join(" ").toLowerCase();
      const isMatch = dateVariants.some(v=>v.includes(q)) || textFields.includes(q);
      if(isMatch) results.push({y,m,d,...ev});
    });
  });
  results.sort((a,b)=> a.y-b.y || a.m-b.m || a.d-b.d);
  return results;
}

function calJumpToResult(y,m,d){
  calViewYear = y;
  calViewMonth = m;
  calSelectedDate = {y,m,d};
  calClearSearch();
  renderCalendarGrid();
  renderCalendarRightPanel();
}

function renderCalendarGrid(){
  document.getElementById('calMonthLabel').textContent = monthNames[calViewMonth] + " " + calViewYear;
  const body = document.getElementById('calBody');
  body.innerHTML = "";

  const activeResults = calSearchQuery ? calSearchEvents(calSearchQuery) : [];
  const matchDaysThisMonth = new Set(
    activeResults.filter(r=>r.y===calViewYear && r.m===calViewMonth).map(r=>r.d)
  );
  const isSearching = !!calSearchQuery;

  const firstDay = new Date(calViewYear, calViewMonth, 1).getDay();
  const daysInMonth = new Date(calViewYear, calViewMonth+1, 0).getDate();

  let row = document.createElement('tr');
  for(let i=0;i<firstDay;i++){
    const td = document.createElement('td');
    td.className = "empty";
    row.appendChild(td);
  }

  let day = 1;
  while(day<=daysInMonth){
    if(row.children.length===7){
      body.appendChild(row);
      row = document.createElement('tr');
    }
    const td = document.createElement('td');
    const key = `${calViewYear}-${calViewMonth}-${day}`;
    td.textContent = day;
    if((calendarEvents[key] || []).some(calVenueOk)) {
      const dot = document.createElement('span');
      dot.className = "dot";
      td.appendChild(dot);
    }
    if(calVenueId){
      if(isoOf(calViewYear, calViewMonth, day) < SERVER_TODAY) td.classList.add('past-day');
      else {
        const stt = calDayStatus(calVenueId, key);
        td.classList.add('st-' + stt);
        td.title = stt === 'booked' ? 'Booked — has an approved booking' : stt === 'pending' ? 'Pending — awaiting approval' : 'Available';
      }
    }
    if(calSelectedDate && calSelectedDate.y===calViewYear && calSelectedDate.m===calViewMonth && calSelectedDate.d===day){
      td.classList.add('selected');
    }
    if(isSearching){
      if(matchDaysThisMonth.has(day)) td.classList.add('match');
      else td.classList.add('dimmed');
    }
    const capturedDay = day;
    td.onclick = ()=>calSelectDate(calViewYear, calViewMonth, capturedDay);
    row.appendChild(td);
    day++;
  }
  while(row.children.length<7){
    const td = document.createElement('td');
    td.className = "empty";
    row.appendChild(td);
  }
  body.appendChild(row);
}

function calSelectDate(y,m,d){
  calSelectedDate = {y,m,d};
  renderCalendarGrid();
  renderCalendarRightPanel();
}
function calClearSelection(){
  calSelectedDate = null;
  calUpcomingPage = 1;
  renderCalendarGrid();
  renderCalendarRightPanel();
}

/* ---- Upcoming Events pagination helpers ---- */
function calGoUpcomingPage(p){
  calUpcomingPage = p;
  renderCalendarRightPanel();
}

/* Fills the empty slots on a page with invisible cards so the list always
   occupies 3 slots and the pagination box stays in the same spot. */
function calGhostCards(shown, withStatus){
  let out = '';
  for(let i = shown; i < CAL_PER_PAGE; i++){
    out += `
      <div class="event-card ghost" aria-hidden="true">
        <div class="ev-date">&nbsp;</div>
        <h3>&nbsp;</h3>
        <div class="ev-meta">
          &nbsp;<br>&nbsp;<br>&nbsp;<br>&nbsp;
        </div>
        ${withStatus ? '<span class="status">&nbsp;</span>' : ''}
      </div>`;
  }
  return out;
}

function calPaginationHtml(total, page, totalPages){
  if(total === 0) return '';             // nothing to paginate
  const from = (page-1)*CAL_PER_PAGE + 1;
  const to = Math.min(page*CAL_PER_PAGE, total);

  let nums = '';
  for(let p=1; p<=totalPages; p++){
    nums += `<button class="${p===page?'active':''}" onclick="calGoUpcomingPage(${p})">${p}</button>`;
  }
  return `
    <div class="cal-pager">
      <span class="cal-pager-info">Showing ${from}–${to} of ${total}</span>
      <div class="cal-pager-btns">
        <button onclick="calGoUpcomingPage(${page-1})" ${page<=1?'disabled':''} aria-label="Previous page">&lsaquo;</button>
        ${nums}
        <button onclick="calGoUpcomingPage(${page+1})" ${page>=totalPages?'disabled':''} aria-label="Next page">&rsaquo;</button>
      </div>
    </div>`;
}

function renderCalendarRightPanel(){
  const panel = document.getElementById('calRightPanel');
  if(!panel) return;

  if(calSearchQuery && !calSelectedDate){
    const results = calSearchEvents(calSearchQuery);
    let html = `<h2 class="panel-title">Search Results</h2>`;
    html += `<p class="search-meta">${results.length} event${results.length===1?'':'s'} matching "${escapeHtml(calSearchQuery)}"</p>`;
    if(results.length===0){
      html += `<p class="empty-state">No matching events or dates. Try a title, venue, or a date like "August 4".</p>`;
    }
    const totalPages = Math.max(1, Math.ceil(results.length / CAL_PER_PAGE));
    if(calUpcomingPage > totalPages) calUpcomingPage = totalPages;
    if(calUpcomingPage < 1) calUpcomingPage = 1;
    const pageResults = results.slice((calUpcomingPage - 1) * CAL_PER_PAGE, calUpcomingPage * CAL_PER_PAGE);

    pageResults.forEach(ev=>{
      const dateStr = `${dayName(ev.y,ev.m,ev.d)}, ${monthNames[ev.m]} ${ev.d}, ${ev.y}`;
      html += `
        <div class="event-card result-card" onclick="calJumpToResult(${ev.y},${ev.m},${ev.d})">
          <div class="ev-date">${dateStr}</div>
          <h3>${escapeHtml(ev.title)}</h3>
          <div class="ev-meta">
            Time: ${escapeHtml(ev.time)}<br>
            Venue: ${escapeHtml(ev.venue)}<br>
            Campus: ${escapeHtml(ev.campus)}<br>
            Requested by: ${escapeHtml(ev.requestedBy)}
          </div>
          <span class="status ${ev.status}">${ev.status.charAt(0).toUpperCase()+ev.status.slice(1)}</span>
        </div>`;
    });
    if(pageResults.length) html += calGhostCards(pageResults.length, true);
    html += calPaginationHtml(results.length, calUpcomingPage, totalPages);
    panel.innerHTML = html;
    return;
  }

  if(!calSelectedDate){
    // Pending and completed events are not "upcoming", so leave them out of this list.
    const upcoming = calEventsInMonth(calViewYear, calViewMonth)
      .filter(ev => ev.status !== 'pending' && ev.status !== 'completed')
      .filter(calVenueOk);
    const totalPages = Math.max(1, Math.ceil(upcoming.length / CAL_PER_PAGE));
    if(calUpcomingPage > totalPages) calUpcomingPage = totalPages;
    if(calUpcomingPage < 1) calUpcomingPage = 1;

    const startIdx = (calUpcomingPage - 1) * CAL_PER_PAGE;
    const pageItems = upcoming.slice(startIdx, startIdx + CAL_PER_PAGE);

    let html = `<h2 class="panel-title">Upcoming Events</h2>`;
    if(upcoming.length===0){
      html += `<p class="empty-state">No events scheduled this month yet.</p>`;
    }
    pageItems.forEach(ev=>{
      const dateStr = `${dayName(calViewYear,calViewMonth,ev.d)}, ${monthNames[calViewMonth]} ${ev.d}, ${calViewYear}`;
      html += `
        <div class="event-card">
          <div class="ev-date">${dateStr}</div>
          <h3>${escapeHtml(ev.title)}</h3>
          <div class="ev-meta">
            Time: ${escapeHtml(ev.time)}<br>
            Venue: ${escapeHtml(ev.venue)}<br>
            Campus: ${escapeHtml(ev.campus)}<br>
            Requested by: ${escapeHtml(ev.requestedBy)}
          </div>
        </div>`;
    });
    if(pageItems.length) html += calGhostCards(pageItems.length, false);
    html += calPaginationHtml(upcoming.length, calUpcomingPage, totalPages);
    panel.innerHTML = html;
  } else {
    const {y,m,d} = calSelectedDate;
    const key = `${y}-${m}-${d}`;
    const dayEvents = (calendarEvents[key] || []).filter(calVenueOk);
    let html = `
      <a class="back-link" onclick="calClearSelection()">&larr; Back to upcoming events</a>
      <p class="day-label">${dayName(y,m,d)} &middot; Selected date</p>
      <h2 class="date-heading">${monthNames[m]} ${d}</h2>`;
    if(dayEvents.length===0){
      html += `<p class="empty-state">No events booked on this date.</p>`;
    }
    dayEvents.forEach(ev=>{
      html += `
        <div class="event-card">
          <div class="ev-date">${monthNames[m]} ${d}, ${y}</div>
          <h3>${escapeHtml(ev.title)}</h3>
          <div class="ev-meta">
            ${escapeHtml(ev.time)} &middot; ${escapeHtml(ev.venue)}<br>
            Requested by ${escapeHtml(ev.requestedBy)}
          </div>
          <span class="status ${ev.status}">${ev.status.charAt(0).toUpperCase()+ev.status.slice(1)}</span>
        </div>`;
    });
    html += calAvailabilityHtml(y, m, d);
    panel.innerHTML = html;
  }
}

/* ===========================================================
   Scheduling: availability, conflicts, reschedule, upcoming,
   past events / history
   =========================================================== */
const monthShort = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
const toMin = t => { const [h,m] = String(t).split(':').map(Number); return h*60 + (m||0); };
const fromMin = m => `${String(Math.floor(m/60)).padStart(2,'0')}:${String(m%60).padStart(2,'0')}`;
function fmt12(t){
  const m = toMin(t), h = Math.floor(m/60);
  return `${h%12===0?12:h%12}:${String(m%60).padStart(2,'0')} ${h>=12?'PM':'AM'}`;
}
const rangeText = (s,e) => `${fmt12(s)} – ${fmt12(e)}`;
function isoOf(y,m,d){ return `${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`; }
function fmtIsoLong(iso){
  const [y,m,d] = iso.split('-').map(Number);
  return `${dayName(y,m-1,d).slice(0,3)}, ${monthShort[m-1]} ${d}, ${y}`;
}
const gv = id => document.getElementById(id)?.value || '';
const sv = (id,v) => { const el = document.getElementById(id); if(el) el.value = v; };

/** Busy intervals [{start,end,status}] that overlap [s,e). Back-to-back is NOT an overlap. */
function overlapsAny(busy, s, e){
  const a = toMin(s), b = toMin(e);
  return (busy||[]).filter(x => toMin(x.start) < b && toMin(x.end) > a);
}

const AVAIL_LEGEND = `<div class="avail-legend">
  <span class="lg lg--available">Available</span><span class="lg lg--pending">Pending</span><span class="lg lg--booked">Booked</span></div>`;

/** The hourly slot grid. Booked / Pending / past slots are disabled and say so.
 *  Click a start time, then an end time: the start, the end and every hour in
 *  between are highlighted. While waiting for the end time, a slot that is not
 *  free itself (e.g. 4:00 PM already taken) can still be the END of a free range. */
function slotGridHtml(busy, isoDate, o){
  o = o || {};
  const open = toMin(SCHED.open), close = toMin(SCHED.close), step = SCHED.step;
  const nowMin = isoDate === SERVER_TODAY ? toMin(SERVER_NOW.slice(11,16)) : (isoDate < SERVER_TODAY ? 1e9 : -1);
  const ps = o.start ? toMin(o.start) : null, pe = o.end ? toMin(o.end) : null;
  const complete = ps !== null && pe !== null && pe > ps;
  const awaitingEnd = o.selectable && ps !== null && !complete;
  const rangeFree = (from, to) => !(busy||[]).some(b => toMin(b.start) < to && toMin(b.end) > from);
  const pick = t => `onclick="availPick('${o.ctx}','${fromMin(t)}')"`;
  let out = '';
  for(let t = open; t + step <= close; t += step){
    const e = t + step;
    const hit = (busy||[]).filter(b => toMin(b.start) < e && toMin(b.end) > t);
    let state = 'available', label = 'Available';
    if(hit.some(b => b.status === 'booked')){ state = 'booked'; label = 'Booked'; }
    else if(hit.length){ state = 'pending'; label = 'Pending'; }
    else if(t < nowMin){ state = 'past'; label = 'Past'; }

    let mark = '';
    if(ps !== null && t === ps){ mark = ' is-start'; label = 'Start'; }
    else if(complete && t === pe){ mark = ' is-end'; label = 'End'; }
    else if(complete && t > ps && t < pe){ mark = ' is-between'; }

    // A slot can end the range if everything between the start and it is free.
    const canEnd = awaitingEnd && state !== 'past' && t > ps && rangeFree(ps, t);
    const cls = `slot slot--${state}${mark}${canEnd && state !== 'available' ? ' slot--endok' : ''}`;
    const inner = `${fmt12(fromMin(t))}<small>${label}</small>`;
    if(o.selectable){
      out += (state === 'available' || canEnd)
        ? `<button type="button" class="${cls}" ${pick(t)}>${inner}</button>`
        : `<button type="button" class="${cls}" disabled>${inner}</button>`;
    } else {
      out += `<div class="${cls}">${inner}</div>`;
    }
  }
  // The venue's closing time can only ever be an END (e.g. 8:00 PM – 9:00 PM).
  if(o.selectable && ps !== null && ps < close && ((awaitingEnd && rangeFree(ps, close)) || (complete && pe === close))){
    const isEnd = complete && pe === close;
    out += `<button type="button" class="slot slot--available${isEnd ? ' is-end' : ' slot--endok'}" ${pick(close)}>${fmt12(fromMin(close))}<small>${isEnd ? 'End' : 'Closing'}</small></button>`;
  }
  return out || '<p class="empty-note">No bookable hours configured.</p>';
}

/* ---- Live availability contexts: "form" = New Reservation, "rs" = Reschedule window ---- */
const AVAIL = {};
function availCtx(name, cfg){ AVAIL[name] = Object.assign({ seq:0, timer:null, state:null, exclude:null, shown:[] }, cfg); return AVAIL[name]; }

function availSchedule(name){
  const c = AVAIL[name]; if(!c) return;
  clearTimeout(c.timer);
  c.timer = setTimeout(() => availRun(name), 250);
  availRender(name);   // instant local feedback while the server answers
}

async function availRun(name){
  const c = AVAIL[name]; if(!c) return;
  const f = c.fields();
  if(!f.venue || !f.date){ c.state = null; availRender(name); return; }
  const seq = ++c.seq;
  const qs = new URLSearchParams({ venue:f.venue, date:f.date, start:f.start||'', end:f.end||'' });
  if(c.exclude) qs.set('exclude', c.exclude);
  try{
    const res = await fetch('api/availability.php?' + qs.toString());
    if(res.status === 401){ window.location.href = 'login.php'; return; }
    const data = await res.json();
    if(seq !== c.seq) return;
    c.state = data.success ? data : { error: data.error || 'Could not check availability.' };
  } catch(err){
    if(seq !== c.seq) return;
    c.state = { error: 'Could not check availability right now. The server will still check when you submit.' };
  }
  availRender(name);
}

function availRender(name){
  const c = AVAIL[name]; if(!c) return;
  const grid = document.getElementById(c.gridId), box = document.getElementById(c.boxId);
  if(!grid || !box) return;
  const f = c.fields(), st = c.state;
  if(!f.venue || !f.date){
    grid.innerHTML = '<p class="empty-note" style="padding:6px 0;">Choose a venue and a date to see which times are free.</p>';
    box.innerHTML = ''; return;
  }
  if(!st || (!st.error && (st.venue !== f.venue || st.date !== f.date))){
    grid.innerHTML = '<p class="empty-note" style="padding:6px 0;">Checking availability…</p>'; box.innerHTML = ''; return;
  }
  if(st.error){ grid.innerHTML = ''; box.innerHTML = `<div class="form-error">${escapeHtml(st.error)}</div>`; return; }

  grid.innerHTML = slotGridHtml(st.busy, f.date, { selectable:true, ctx:name, start:f.start, end:f.end });

  const hasTimes = f.start && f.end && f.start < f.end;
  if(f.start && f.end && f.start >= f.end){ box.innerHTML = `<div class="form-error">End time must be after the start time.</div>`; return; }
  if(!hasTimes){
    box.innerHTML = `<p class="avail-hint">${f.start ? `Start: <strong>${escapeHtml(fmt12(f.start))}</strong>. Now tap the <strong>end time</strong>.` : 'Tap a <strong>start time</strong>, then tap an <strong>end time</strong>.'}</p>`;
    return;
  }

  const clash = overlapsAny(st.busy, f.start, f.end);
  const passed = f.date === SERVER_TODAY && f.start <= SERVER_NOW.slice(11,16);
  if(!clash.length && !st.maintenance && !passed){
    box.innerHTML = `<div class="avail-ok">✓ ${escapeHtml(rangeText(f.start, f.end))} is available.</div>`;
    c.shown = []; return;
  }

  let why;
  if(st.maintenance) why = 'This venue is currently under maintenance.';
  else if(clash.length) why = 'It overlaps: ' + clash.map(b => `${b.status==='booked'?'Booked':'Pending'} ${rangeText(b.start,b.end)}`).join(', ') + '.';
  else why = 'That start time has already passed today.';

  const keyNow = `${f.venue}|${f.date}|${f.start}|${f.end}`;
  let sugs = (st.key === keyNow && st.suggestions) ? st.suggestions : [];
  if(name === 'rs') sugs = sugs.filter(s => s.type !== 'other_venue');   // a reschedule keeps its venue
  c.shown = sugs;

  const typeLabel = s => s.type === 'same_day' ? 'Same day'
    : s.type === 'other_venue' ? escapeHtml(s.venue_name) + ' · same time' : 'Same time, another day';
  const sugHtml = sugs.length ? `
    <div class="sug-title">Available alternatives — tap one to use it</div>
    <div class="sug-grid">${sugs.map((s,i) => `
      <button type="button" class="sug" onclick="availApply('${name}',${i})">
        ${escapeHtml(fmtIsoLong(s.date))}<br><strong>${escapeHtml(rangeText(s.start,s.end))}</strong>
        <small>${typeLabel(s)}</small></button>`).join('')}</div>` : '';

  let extra = '';
  if(name === 'rs'){
    extra = `<p class="conflict-help">Pick another time above, or press <strong>Request Reschedule</strong> to send this time anyway — OSAS can only approve it if it becomes free, otherwise they will reject it or suggest another schedule.</p>`;
  } else {
    const mine = (st.key === keyNow ? st.conflicts : []).find(x => x.mine && x.reservation_id);
    extra = mine
      ? `<p class="conflict-help">That is your own reservation. Choose another time, or
           <button type="button" class="link-btn" onclick="openRescheduleById(${mine.reservation_id})">Request Reschedule</button> for it.</p>`
      : `<p class="conflict-help">Choose another time — tap an Available slot or one of the suggestions.</p>`;
  }
  box.innerHTML = `<div class="conflict-box"><strong>⚠ This time isn't available.</strong> ${escapeHtml(why)}${extra}${sugHtml}</div>`;
}

/** Clicking a slot: 1st click = start time, 2nd click (later) = end time.
 *  Clicking the start again clears it; any click once a range is complete starts over. */
function availPick(name, t){
  const c = AVAIL[name]; if(!c || !c.state) return;
  const f = c.fields();
  const awaitingEnd = f.start && !(f.end && f.end > f.start);
  if(awaitingEnd){
    if(t === f.start){ c.set({ start: '', end: '' }); return; }          // un-pick
    if(toMin(t) > toMin(f.start) && !overlapsAny(c.state.busy, f.start, t).length){
      c.set({ end: t }); return;                                          // end time
    }
  }
  c.set({ start: t, end: '' });                                           // (re)start
}
function availApply(name, i){
  const c = AVAIL[name], s = c && c.shown[i]; if(!s) return;
  c.set({ venue: s.venue_name, date: s.date, start: s.start, end: s.end });
}

function initFormAvail(){
  availCtx('form', {
    gridId: 'availGrid', boxId: 'conflictBox',
    fields: () => ({ venue: gv('rVenue'), date: gv('rDate'), start: gv('rStart'), end: gv('rEnd') }),
    set: f => {
      if(f.venue !== undefined && f.venue !== gv('rVenue')){ sv('rVenue', f.venue); onVenueChosen(); }
      if(f.date  !== undefined) sv('rDate', f.date);
      if(f.start !== undefined) sv('rStart', f.start);
      if(f.end   !== undefined) sv('rEnd', f.end);
      updateVenuePreview();
      availSchedule('form');
    }
  });
  const d = document.getElementById('rDate'); if(d) d.min = SERVER_TODAY;
}

/* Calendar → "Book this date" pre-fills the New Reservation form. */
let bookingPrefill = null;
function startBooking(venueId, iso){
  const v = venuesList.find(x => Number(x.id) === Number(venueId));
  bookingPrefill = { venue: v ? v.name : '', date: iso };
  navigateStudent('newReservation');
}
function applyBookingPrefill(){
  if(!bookingPrefill) return;
  const { venue, date } = bookingPrefill; bookingPrefill = null;
  if(venue && bookableVenuesList.some(v => v.name === venue)){ sv('rVenue', venue); onVenueChosen(); }
  if(date) sv('rDate', date);
  availSchedule('form');
}

/* ---- Helpers shared by My Reservation / Upcoming / Past Events ---- */
const canChange = r => schedReady && !r.isPast && (r.status === 'pending' || r.status === 'approved');
const openRequest = r => r.reschedule && (r.reschedule.status === 'pending' || r.reschedule.status === 'suggested');

function schedLine(date, start, end){ return `${escapeHtml(fmtIsoLong(date))} · ${escapeHtml(rangeText(start, end))}`; }

function reschedTagHtml(r){
  if(!r.history || !r.history.length) return '';
  const o = r.history[0];
  return `<div class="resched-note"><span class="tag tag--resched">↻ Rescheduled</span>
    <span class="was">Originally ${schedLine(o.oldDate, o.oldStart, o.oldEnd)}</span></div>`;
}

function reschedPillHtml(r, i){
  const q = r.reschedule; if(!q) return '';
  if(q.status === 'pending')   return `<button class="rs-pill rs-pill--pending" onclick="openReservationDetail(${i})">Reschedule pending</button>`;
  if(q.status === 'suggested') return `<button class="rs-pill rs-pill--sug" onclick="openReservationDetail(${i})">New time suggested</button>`;
  if(q.status === 'rejected' && canChange(r)) return `<button class="rs-pill rs-pill--no" onclick="openReservationDetail(${i})">Reschedule rejected</button>`;
  return '';
}

function pdfButtonHtml(r){
  if(r.status !== 'approved' && r.status !== 'completed') return '';
  let out = `<a class="file-pill file-pill--pdf" href="api/reservation_pdf.php?id=${encodeURIComponent(r.id)}" download>⬇ PDF</a>`;
  if(r.status === 'completed' && r.report && r.report.status === 'approved'){
    out += `<a class="file-pill file-pill--pdf" href="api/clearance_pdf.php?id=${encodeURIComponent(r.id)}" download>⬇ Clearance</a>`;
  }
  return out;
}
function actionButtonsHtml(r, i){
  return actionButtonsCore(r, i) + pdfButtonHtml(r);
}
function actionButtonsCore(r, i){
  const edit = canEdit(r) ? `<button class="file-pill" onclick="startEditReservation(${i})">✎ ${r.status === 'declined' ? 'Edit &amp; Resubmit' : 'Edit'}</button>` : '';
  if(!canChange(r)) return edit;
  if(openRequest(r)) return edit + `<button class="file-pill" onclick="openReservationDetail(${i})">View request</button>`;
  return edit + actionButtonsBase(r, i);
}
function actionButtonsBase(r, i){
  if(openRequest(r)) return `<button class="file-pill" onclick="openReservationDetail(${i})">View request</button>`;
  return `<button class="file-pill" onclick="openRescheduleModal(${i})">Request Reschedule</button>
          <button class="file-pill file-pill--danger" onclick="openCancelModal(${i})">Cancel</button>`;
}

function flashBannerHtml(){
  if(!flashMessage) return '';
  const m = flashMessage; flashMessage = null;
  return `<div class="form-success">${escapeHtml(m)}</div>`;
}

function modalMsg(text){
  const el = document.getElementById('stuModalMsg');
  if(el) el.innerHTML = `<div class="form-error" style="margin-bottom:12px;">${escapeHtml(text)}</div>`;
  else alert(text);
}

function openRescheduleById(id){
  const i = reservationsData.findIndex(r => r.id === Number(id));
  if(i >= 0) openRescheduleModal(i);
}

/* ---- Request Reschedule window ---- */
function openRescheduleModal(index){
  const r = reservationsData[index]; if(!r) return;
  if(!schedReady){ alert('Rescheduling is not set up yet. Please ask the administrator to import database/migration_reschedule_history.sql.'); return; }
  if(openRequest(r)) return openReservationDetail(index);

  openStuModal(`
    <h2>Request Reschedule</h2>
    <p class="m-sub">${escapeHtml(r.event)} · ${escapeHtml(r.ref)} · ${escapeHtml(r.venue)}</p>
    <div class="rs-current">Current schedule: <strong>${schedLine(r.isoDate, r.start, r.end)}</strong>
      <small>It stays exactly as it is until OSAS approves a change.</small></div>
    <div id="stuModalMsg"></div>
    <form class="pe-form" onsubmit="submitReschedule(event, ${index})">
      <div class="pe-grid">
        <div><label for="rsDate">New date</label>
          <input type="date" id="rsDate" min="${SERVER_TODAY}" value="${escapeHtml(r.isoDate >= SERVER_TODAY ? r.isoDate : SERVER_TODAY)}" required onchange="availSchedule('rs')"></div>
        <div style="display:flex;gap:10px;">
          <div style="flex:1"><label for="rsStart">Start</label><input type="time" step="3600" id="rsStart" required onchange="availSchedule('rs')"></div>
          <div style="flex:1"><label for="rsEnd">End</label><input type="time" step="3600" id="rsEnd" required onchange="availSchedule('rs')"></div>
        </div>
      </div>
      ${AVAIL_LEGEND}
      <div class="slot-grid" id="rsGrid"></div>
      <div id="rsBox"></div>
      <label for="rsReason" style="margin-top:14px;">Reason for rescheduling</label>
      <textarea id="rsReason" maxlength="1000" required placeholder="Why do you need a different date or time?"></textarea>
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
        <button type="button" class="btn" style="background:#eee;" onclick="closeStuModal()">Close</button>
        <button type="submit" class="btn btn-solid sans" id="rsSubmit" style="width:auto;">Request Reschedule</button>
      </div>
    </form>`);

  availCtx('rs', {
    gridId: 'rsGrid', boxId: 'rsBox', exclude: r.id,
    fields: () => ({ venue: r.venue, date: gv('rsDate'), start: gv('rsStart'), end: gv('rsEnd') }),
    set: f => {
      if(f.date  !== undefined) sv('rsDate', f.date);
      if(f.start !== undefined) sv('rsStart', f.start);
      if(f.end   !== undefined) sv('rsEnd', f.end);
      availSchedule('rs');
    }
  });
  availRun('rs');
}

async function submitReschedule(e, index){
  e.preventDefault();
  const r = reservationsData[index];
  const date = gv('rsDate'), start = gv('rsStart'), end = gv('rsEnd'), reason = gv('rsReason').trim();
  if(!date || !start || !end) return modalMsg('Please choose the new date, start time and end time.');
  if(start >= end) return modalMsg('End time must be after the start time.');
  if(date === r.isoDate && start === r.start && end === r.end) return modalMsg('That is your current schedule. Pick a different date or time.');
  if(reason.length < 5) return modalMsg('Please tell us why you need to reschedule.');
  const btn = document.getElementById('rsSubmit'); btn.disabled = true; btn.textContent = 'Sending…';
  const ok = await rsPost({ action:'create', reservation_id:r.id, date, start, end, reason }, 'resched_sent');
  if(!ok){ btn.disabled = false; btn.textContent = 'Request Reschedule'; }
}

/** POSTs to the reschedule API. On success reloads onto the Upcoming page with a confirmation. */
async function rsPost(payload, flash, page){
  try{
    const res = await fetch('api/reschedule_request.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    if(res.status === 401){ window.location.href = 'login.php'; return true; }
    const d = await res.json().catch(() => ({ success:false, error:'The server sent an unexpected response.' }));
    if(d.success){ window.location.href = `student.php?page=${page || 'upcoming'}&flash=${flash}`; return true; }
    modalMsg(d.error || 'Something went wrong. Please try again.');
  } catch(err){ modalMsg('Could not reach the server. Please try again.'); }
  return false;
}

/* ---- Cancel a reservation (record is kept) ---- */
function openCancelModal(index){
  const r = reservationsData[index]; if(!r) return;
  openStuModal(`
    <h2>Cancel reservation?</h2>
    <p class="m-sub">${escapeHtml(r.event)} · ${escapeHtml(r.ref)} · ${schedLine(r.isoDate, r.start, r.end)}</p>
    <p class="m-text">The venue time is freed for others. The reservation is <strong>not deleted</strong> — it stays under Past Events as Cancelled.</p>
    <div id="stuModalMsg"></div>
    <label for="cxReason" style="display:block;font-size:.78rem;font-weight:700;margin:8px 0 6px;">Reason (optional)</label>
    <textarea id="cxReason" maxlength="500" style="width:100%;min-height:70px;padding:10px;border:1px solid var(--line);border-radius:6px;font:inherit;"></textarea>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
      <button class="btn" style="background:#eee;" onclick="closeStuModal()">Keep reservation</button>
      <button class="btn sans" id="cxOk" style="background:#b23b2e;color:#fff;" onclick="confirmCancel(${index})">Cancel reservation</button>
    </div>`);
}
async function confirmCancel(index){
  const r = reservationsData[index];
  const btn = document.getElementById('cxOk'); btn.disabled = true;
  try{
    const res = await fetch('api/reservation_cancel.php', { method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ reservation_id: r.id, reason: gv('cxReason').trim() }) });
    if(res.status === 401){ window.location.href = 'login.php'; return; }
    const d = await res.json().catch(() => ({ success:false, error:'The server sent an unexpected response.' }));
    if(d.success){ window.location.href = 'student.php?page=history&flash=cancelled'; return; }
    modalMsg(d.error || 'Could not cancel. Please try again.');
  } catch(err){ modalMsg('Could not reach the server. Please try again.'); }
  btn.disabled = false;
}

/* ---- Detail popup section: schedule, reschedule request, history ---- */
function scheduleSectionHtml(r, index){
  let html = '<div id="stuModalMsg"></div>';

  if(r.status === 'cancelled'){
    html += `<div class="revision-note"><strong>Cancelled</strong>${r.cancelledAt ? ' on ' + escapeHtml(r.cancelledAt.slice(0,10)) : ''}.
      ${r.cancelReason ? '<br>Reason: ' + escapeHtml(r.cancelReason) : ''}</div>`;
  }

  const q = r.reschedule;
  if(q){
    const tone = { pending:'b-pending', suggested:'b-pending', approved:'b-approved', rejected:'b-declined', withdrawn:'b-completed' }[q.status];
    const label = { pending:'Waiting for OSAS', suggested:'OSAS suggested a time', approved:'Approved', rejected:'Rejected', withdrawn:'Withdrawn' }[q.status];
    html += `<div class="m-section">LATEST RESCHEDULE REQUEST <span class="badge ${tone}" style="margin-left:6px;">${label}</span></div>
      <p class="m-text" style="margin-bottom:6px;"><strong>Asked for:</strong> ${schedLine(q.newDate, q.newStart, q.newEnd)}<br>
      <strong>Your reason:</strong> ${escapeHtml(q.reason)}</p>
      ${q.adminNote ? `<div class="revision-note"><strong>OSAS note:</strong> ${escapeHtml(q.adminNote)}</div>` : ''}`;
    if(q.status === 'suggested' && q.sugDate){
      html += `<div class="sug-offer"><strong>OSAS suggests:</strong> ${schedLine(q.sugDate, q.sugStart, q.sugEnd)}
        <div class="sug-offer-btns">
          <button class="btn btn-solid sans" style="width:auto;" onclick="rsPost({action:'accept_suggestion',request_id:${q.id}},'accepted')">Accept this time</button>
          <button class="btn sans" style="background:#eee;" onclick="rsPost({action:'decline_suggestion',request_id:${q.id}},'declined')">Decline</button>
        </div><small>Your reservation only moves if you accept.</small></div>`;
    } else if(q.status === 'pending'){
      html += `<div style="margin:8px 0;"><button class="btn sans" style="background:#eee;" onclick="rsPost({action:'withdraw',request_id:${q.id}},'withdrawn')">Withdraw request</button></div>`;
    }
  }

  if(r.history && r.history.length){
    html += `<div class="m-section">SCHEDULE HISTORY</div>
      <div class="hist-chain">
        <div class="hist-row"><span class="tag">Original</span> ${schedLine(r.history[0].oldDate, r.history[0].oldStart, r.history[0].oldEnd)}</div>
        ${r.history.map(h => `
          <div class="hist-row"><span class="tag tag--resched">↻ Moved</span> ${schedLine(h.newDate, h.newStart, h.newEnd)}
            <small>${escapeHtml((h.changedAt||'').slice(0,10))}${h.reason ? ' · ' + escapeHtml(h.reason) : ''}</small></div>`).join('')}
      </div>`;
  }
  return html;
}

function changeButtonsHtml(r, index){
  const edit = canEdit(r) ? `<button class="btn btn-solid sans" style="width:auto;" onclick="startEditReservation(${index})">✎ ${r.status === 'declined' ? 'Edit &amp; Resubmit' : 'Edit reservation'}</button>` : '';
  if(!canChange(r) || openRequest(r)) return edit;
  return edit + changeButtonsBase(r, index);
}
function changeButtonsBase(r, index){
  return `<button class="btn btn-solid sans" style="width:auto;" onclick="openRescheduleModal(${index})">Request Reschedule</button>
          <button class="btn sans" style="background:#fbe4e0;color:#b23b2e;" onclick="openCancelModal(${index})">Cancel reservation</button>`;
}

/* ---- Upcoming Events ---- */
function renderUpcomingPage(main){
  const list = reservationsData.map((r,i) => ({ r, i }))
    .filter(({r}) => !r.isPast && (r.status === 'pending' || r.status === 'approved'))
    .sort((a,b) => (a.r.isoDate + a.r.start).localeCompare(b.r.isoDate + b.r.start));
  const sugCount = list.filter(({r}) => r.reschedule && r.reschedule.status === 'suggested').length;

  const cards = list.map(({r,i}) => `
    <div class="up-card">
      <div class="up-when"><div class="up-day">${r.isoDate.slice(8,10).replace(/^0/,'')}</div><div class="up-mon">${monthShort[+r.isoDate.slice(5,7)-1]}</div></div>
      <div class="up-body">
        <div class="up-top"><h3>${escapeHtml(r.event)}</h3><span class="badge ${badgeClass(r.status)}">${badgeLabel(r.status)}</span></div>
        <div class="meta">${escapeHtml(fmtIsoLong(r.isoDate))} · ${escapeHtml(rangeText(r.start, r.end))}<br>${escapeHtml(r.venue)} · ${escapeHtml(r.ref)}</div>
        ${reschedTagHtml(r)}
        <div class="up-actions">${reschedPillHtml(r,i)} ${actionButtonsHtml(r,i)}
          <button class="file-pill" onclick="openReservationDetail(${i})">Details</button></div>
      </div>
    </div>`).join('');

  main.innerHTML = `
    <div class="stu-topline"><div><h1>Upcoming Events</h1></div>
      <button class="stu-add-btn" onclick="navigateStudent('newReservation')">+ Add Reservation</button></div>
    ${flashBannerHtml()}
    ${!schedReady ? `<div class="form-error">Rescheduling needs a one-time database update. Ask the administrator to import <code>database/migration_reschedule_history.sql</code>.</div>` : ''}
    ${sugCount ? `<div class="notice-bar"><span class="notice-dot">!</span><span><strong>OSAS suggested a new time</strong> for ${sugCount} of your reservations. Open <em>Details</em> to accept or decline.</span></div>` : ''}
    ${list.length ? `<div class="up-list">${cards}</div>` : `<p class="empty-note">You have no upcoming events. Events that are pending or approved and haven't finished yet appear here.</p>`}`;
}

/* ---- Past Events / Reservation History ---- */
let histFilter = { q:'', type:'all', venue:'all' };

function historyBase(){
  return reservationsData.map((r,i) => ({ r, i }))
    .filter(({r}) => r.status === 'completed' || r.status === 'cancelled' || (r.history && r.history.length));
}

function renderHistoryPage(main){
  const venues = [...new Set(historyBase().map(({r}) => r.venue))].sort();
  main.innerHTML = `
    <div class="stu-topline"><div><h1>Past Events</h1></div></div>
    ${flashBannerHtml()}
    <p class="empty-note" style="padding-top:0;">Your reservation history — completed, cancelled and rescheduled events. Nothing is ever deleted.</p>
    <div class="hist-filters">
      <input type="search" id="histQ" placeholder="Search event, venue, ref no. or date" value="${escapeHtml(histFilter.q)}" oninput="histFilter.q=this.value;renderHistoryResults()">
      <select onchange="histFilter.type=this.value;renderHistoryResults()">
        ${[['all','All outcomes'],['completed','Completed'],['cancelled','Cancelled'],['rescheduled','Rescheduled']].map(([v,l]) => `<option value="${v}" ${histFilter.type===v?'selected':''}>${l}</option>`).join('')}
      </select>
      <select onchange="histFilter.venue=this.value;renderHistoryResults()">
        <option value="all">All venues</option>
        ${venues.map(v => `<option value="${escapeHtml(v)}" ${histFilter.venue===v?'selected':''}>${escapeHtml(v)}</option>`).join('')}
      </select>
      <button class="file-pill" onclick="histFilter={q:'',type:'all',venue:'all'};renderHistoryPage(document.getElementById('stuMain'))">Clear</button>
    </div>
    <div id="histResults"></div>`;
  renderHistoryResults();
}

function renderHistoryResults(){
  const box = document.getElementById('histResults'); if(!box) return;
  const q = histFilter.q.trim().toLowerCase();
  let rows = historyBase().filter(({r}) => {
    if(histFilter.type === 'completed'   && r.status !== 'completed') return false;
    if(histFilter.type === 'cancelled'   && r.status !== 'cancelled') return false;
    if(histFilter.type === 'rescheduled' && !(r.history && r.history.length)) return false;
    if(histFilter.venue !== 'all' && r.venue !== histFilter.venue) return false;
    if(!q) return true;
    const hay = [r.event, r.ref, r.venue, r.isoDate, fmtIsoLong(r.isoDate), monthNames[r.m], r.purpose || '',
      ...(r.history||[]).flatMap(h => [h.oldDate, fmtIsoLong(h.oldDate), h.reason || ''])].join(' ').toLowerCase();
    return hay.includes(q);
  });
  rows.sort((a,b) => (b.r.isoDate + b.r.start).localeCompare(a.r.isoDate + a.r.start));

  const body = rows.map(({r,i}) => {
    const resched = r.history && r.history.length;
    const o = resched ? r.history[0] : null;
    const sched = resched
      ? `<div class="sch-new"><span class="sch-lbl">New</span> ${schedLine(r.isoDate, r.start, r.end)}</div>
         <div class="sch-old"><span class="sch-lbl">Original</span> <s>${schedLine(o.oldDate, o.oldStart, o.oldEnd)}</s></div>`
      : schedLine(r.isoDate, r.start, r.end);
    return `<tr>
      <td>${escapeHtml(r.ref)}</td>
      <td><strong>${escapeHtml(r.event)}</strong></td>
      <td>${escapeHtml(r.venue)}</td>
      <td>${sched}</td>
      <td><span class="badge ${badgeClass(r.status)}">${badgeLabel(r.status)}</span>
          ${resched ? '<span class="tag tag--resched">↻ Rescheduled</span>' : ''}
          ${r.status === 'cancelled' && r.cancelledAt ? `<div class="meta" style="font-size:.7rem;color:var(--ink-soft);margin-top:4px;">on ${escapeHtml(r.cancelledAt.slice(0,10))}</div>` : ''}</td>
      <td><button class="file-pill" onclick="openReservationDetail(${i})">Details</button></td>
    </tr>`;
  }).join('');

  box.innerHTML = `
    <p class="search-meta" style="margin:0 0 8px;">${rows.length} event${rows.length===1?'':'s'}</p>
    <div class="stu-table-wrap"><table class="stu-table">
      <thead><tr><th>REF NO.</th><th>EVENT</th><th>VENUE</th><th>SCHEDULE</th><th>OUTCOME</th><th></th></tr></thead>
      <tbody>${body || `<tr><td colspan="6" class="empty-note">${historyBase().length ? 'No events match your search or filters.' : 'No past events yet. Completed, cancelled and rescheduled events will show up here.'}</td></tr>`}</tbody>
    </table></div>`;
}


/* ---- Calendar availability helpers ---- */
const calVenueOk = ev => !calVenueId || ev.venueId === calVenueId;
function calSetVenue(v){
  calVenueId = v ? Number(v) : null;
  calUpcomingPage = 1;
  renderCalendarPage(document.getElementById('stuMain'));
}
/** Booked = an approved booking that day; Pending = only pending ones; Available = none. */
function calDayStatus(venueId, key){
  const evs = (calendarEvents[key] || []).filter(e => e.venueId === venueId);
  if(evs.some(e => e.status === 'approved')) return 'booked';
  if(evs.some(e => e.status === 'pending')) return 'pending';
  return 'available';
}
function calPickVenue(id){
  calVenueId = Number(id);
  renderCalendarPage(document.getElementById('stuMain'));
}
function calAvailabilityHtml(y, m, d){
  const iso = isoOf(y, m, d), key = `${y}-${m}-${d}`;
  if(iso < SERVER_TODAY) return `<p class="empty-note">This date has already passed.</p>`;
  if(calVenueId){
    const v = venuesList.find(x => Number(x.id) === calVenueId);
    if(!v) return '';
    if(v.status === 'maintenance') return `<div class="avail-head">${escapeHtml(v.name)}</div><p class="empty-note">This venue is under maintenance and can't be booked.</p>`;
    const busy = (calendarEvents[key] || []).filter(e => e.venueId === calVenueId && (e.status === 'pending' || e.status === 'approved'))
      .map(e => ({ start:e.start, end:e.end, status: e.status === 'approved' ? 'booked' : 'pending' }));
    return `<div class="avail-head">${escapeHtml(v.name)} — time slots</div>${AVAIL_LEGEND}
      <div class="slot-grid slot-grid--compact">${slotGridHtml(busy, iso, {})}</div>
      <button class="stu-add-btn" style="margin-top:12px;" onclick="startBooking(${v.id},'${iso}')">Book this date</button>`;
  }
  return `<div class="avail-head">Venues on this date</div>
    <div class="venue-day-list">${venuesList.map(v => {
      const st = v.status === 'maintenance' ? 'maintenance' : calDayStatus(Number(v.id), key);
      const label = st === 'maintenance' ? 'Maintenance' : st.charAt(0).toUpperCase() + st.slice(1);
      return `<button class="vday vday--${st}" onclick="calPickVenue(${v.id})"><span>${escapeHtml(v.name)}</span><em>${label}</em></button>`;
    }).join('')}</div>
    <p class="avail-hint">Tap a venue to see its free time slots.</p>`;
}

/* ---- Profile (saves for real via api/profile_update.php) ---- */
const campusOptions = ["Main Campus","Congressional Extension Campus","Camarin Extension Campus","Bagong Silang Campus"];
function renderProfilePage(main){
  main.innerHTML = `
    <div class="stu-topline"><div><h1>Profile</h1></div></div>
    <div class="profile-card">
      <div class="avatar">${studentUser.initials}</div>
      <div id="profileMessage"></div>
      <form id="profileForm">
        <label class="sans" style="font-size:.78rem;font-weight:700;">First Name</label>
        <input type="text" id="pFirstName" value="${escapeHtml(studentUser.firstName)}" required style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:6px;margin:6px 0 14px;font-family:inherit;">
        <label class="sans" style="font-size:.78rem;font-weight:700;">Last Name</label>
        <input type="text" id="pLastName" value="${escapeHtml(studentUser.lastName)}" required style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:6px;margin:6px 0 14px;font-family:inherit;">
        <label class="sans" style="font-size:.78rem;font-weight:700;">Campus</label>
        <select id="pCampus" style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:6px;margin:6px 0 14px;font-family:inherit;">
          ${campusOptions.map(c=>`<option value="${escapeHtml(c)}" ${studentUser.campus===c?'selected':''}>${escapeHtml(c)}</option>`).join("")}
        </select>
        <label class="sans" style="font-size:.78rem;font-weight:700;">Email</label>
        <input type="email" value="${escapeHtml(studentUser.email)}" disabled style="width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:6px;margin:6px 0 4px;font-family:inherit;background:#f5f2e8;color:var(--ink-soft);">
        <p class="empty-note" style="padding:0 0 14px;">Email can't be changed here. Contact OSAS if it needs to be updated.</p>
        <button type="submit" class="btn btn-solid sans" id="pSaveBtn">Save Changes</button>
      </form>
    </div>
  `;
  document.getElementById('profileForm').addEventListener('submit', saveProfile);
}

async function saveProfile(e){
  e.preventDefault();
  const firstName = document.getElementById('pFirstName').value.trim();
  const lastName = document.getElementById('pLastName').value.trim();
  const campus = document.getElementById('pCampus').value;
  const msgBox = document.getElementById('profileMessage');
  const btn = document.getElementById('pSaveBtn');

  if(!firstName || !lastName){
    msgBox.innerHTML = `<div class="form-error">First and last name are required.</div>`;
    return;
  }

  btn.disabled = true;
  btn.textContent = "Saving...";
  try{
    const body = new URLSearchParams({ firstName, lastName, campus });
    const res = await fetch('api/profile_update.php', { method: 'POST', body });
    if(res.status === 401){ window.location.href = 'login.php'; return; }
    const data = await res.json();
    if(data.success){
      studentUser.firstName = firstName;
      studentUser.lastName = lastName;
      studentUser.campus = campus;
      studentUser.name = `${firstName} ${lastName}`.trim();
      studentUser.initials = (firstName[0]||"").toUpperCase() + (lastName[0]||"").toUpperCase();
      document.querySelector('.stu-profile .name').textContent = studentUser.name;
      msgBox.innerHTML = `<div class="form-success">Profile updated.</div>`;
    } else {
      msgBox.innerHTML = `<div class="form-error">${escapeHtml(data.error || "Something went wrong. Please try again.")}</div>`;
    }
  } catch(err){
    msgBox.innerHTML = `<div class="form-error">Could not reach the server. Please try again.</div>`;
  } finally {
    btn.disabled = false;
    btn.textContent = "Save Changes";
  }
}

/* ---- Settings ---- */
function renderSettingsPage(main){
  main.innerHTML = `
    <div class="stu-topline"><div><h1>Settings</h1></div></div>
    <div class="profile-card" style="max-width:520px;">
      <div class="settings-row">
        <div><div class="s-label">Email Notifications</div><div class="s-desc">Get an email when your reservation status changes.</div></div>
        <div class="toggle ${stuSettings.emailNotif?'on':''}" onclick="toggleSetting('emailNotif')"><div class="knob"></div></div>
      </div>
      <div class="settings-row">
        <div><div class="s-label">SMS Reminders</div><div class="s-desc">Get a text reminder a day before your event.</div></div>
        <div class="toggle ${stuSettings.smsReminders?'on':''}" onclick="toggleSetting('smsReminders')"><div class="knob"></div></div>
      </div>
    </div>
  `;
}
function toggleSetting(key){
  stuSettings[key] = !stuSettings[key];
  renderSettingsPage(document.getElementById('stuMain'));
}

renderStudentShell();

window.addEventListener('resize', ()=>{
  if(window.innerWidth > 768) closeSidebar();
});
</script>
</body>
</html>