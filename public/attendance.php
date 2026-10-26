<?php
/**
 * attendance.php  (public, no login) — opened by scanning the event's QR code.
 *
 * Shows ONLY the event's name, venue and schedule, plus the check-in form.
 * It never lists attendees or any admin data. The actual validation and the
 * insert happen in api/attendance_checkin.php.
 */
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../shared/events.php';
require_once __DIR__ . '/../shared/attendance.php';

$token = $_GET['t'] ?? '';
$event = null;
$state = null;
$error = null;

if (!att_valid_token_format($token)) {
    $error = 'This QR code is not valid. Please scan the code shown at the event.';
} else {
    try {
        $st = get_db()->prepare(
            'SELECT s.is_open, r.event_name, r.venue_name, r.campus, r.event_date, r.start_time, r.end_time, r.status
               FROM attendance_sessions s
               JOIN reservations r ON r.id = s.reservation_id
              WHERE s.token = ?'
        );
        $st->execute([$token]);
        $event = $st->fetch() ?: null;
    } catch (PDOException $e) {
        error_log('attendance page lookup failed: ' . $e->getMessage());
        $error = 'Attendance is temporarily unavailable. Please tell the event organizer.';
    }
    if (!$error && !$event) $error = 'This QR code is not valid. Please scan the code shown at the event.';
    if ($event) $state = att_state($event);
}
$canSubmit = $state && $state['code'] === 'open';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Event Attendance</title>
<style>
:root{--g900:#0b3d24;--g800:#0f5132;--g700:#13693f;--g500:#2fb565;--cream:#f4efdf;--line:#e2ddcc;--ink:#173a2a;--muted:#5d6b62;--err:#b3261e;--errbg:#fdecea}
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;background:var(--cream);color:var(--ink);font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;display:flex;justify-content:center;padding:16px}
.wrap{width:100%;max-width:440px}
.brand{background:var(--g800);color:#fff;border-radius:16px 16px 0 0;padding:18px 20px;font-weight:700;letter-spacing:.04em}
.card{background:#fff;border:1px solid var(--line);border-top:0;border-radius:0 0 16px 16px;padding:20px}
h1{font-size:1.3rem;line-height:1.3;margin-bottom:10px;overflow-wrap:anywhere}
.meta{color:var(--muted);font-size:.92rem;line-height:1.6;margin-bottom:18px}
label{display:block;font-weight:600;font-size:.9rem;margin:14px 0 6px}
input{width:100%;padding:13px 14px;font-size:1rem;border:1.5px solid var(--line);border-radius:10px;background:#fff;color:var(--ink)}
input:focus{outline:none;border-color:var(--g500);box-shadow:0 0 0 3px rgba(47,181,101,.2)}
button{width:100%;margin-top:20px;padding:14px;font-size:1rem;font-weight:700;color:#fff;background:var(--g700);border:0;border-radius:10px;cursor:pointer}
button:disabled{opacity:.6;cursor:default}
.msg{margin-top:14px;padding:12px 14px;border-radius:10px;font-size:.93rem;line-height:1.45}
.msg--err{background:var(--errbg);color:var(--err)}
.msg--info{background:#eef6f0;color:var(--g800)}
.ok{text-align:center;padding:10px 0}
.ok__tick{width:64px;height:64px;border-radius:50%;background:var(--g500);color:#fff;font-size:34px;line-height:64px;margin:0 auto 14px}
.ok h2{font-size:1.2rem;margin-bottom:8px}
.ok p{color:var(--muted);font-size:.95rem;line-height:1.6}
[hidden]{display:none!important}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">EVENT ATTENDANCE</div>
  <div class="card">
  <?php if ($error): ?>
    <h1>Can’t open attendance</h1>
    <div class="msg msg--err"><?= h($error) ?></div>
  <?php else: ?>
    <h1><?= h($event['event_name']) ?></h1>
    <div class="meta">
      <?= h($event['venue_name']) ?><?= $event['campus'] ? ' · ' . h($event['campus']) : '' ?><br>
      <?= h(att_fmt_date($event['event_date'])) ?> · <?= h(att_fmt_time($event['start_time'])) ?> – <?= h(att_fmt_time($event['end_time'])) ?>
    </div>

    <?php if (!$canSubmit): ?>
      <div class="msg msg--info"><?= h($state['message']) ?></div>
    <?php else: ?>
      <div id="formBox">
        <form id="f" autocomplete="off" novalidate>
          <label for="sid">Student ID</label>
          <input id="sid" name="student_id" type="text" inputmode="text" maxlength="30" autocapitalize="characters" required>
          <label for="nm">Full name</label>
          <input id="nm" name="full_name" type="text" maxlength="100" autocomplete="name" placeholder="e.g. Juan Dela Cruz" required>
          <label for="crs">Course</label>
          <input id="crs" name="course" type="text" maxlength="50" placeholder="e.g. BSIS" required>
          <div id="err" class="msg msg--err" role="alert" hidden></div>
          <button id="btn" type="submit">Time in</button>
        </form>
      </div>
      <div id="done" class="ok" hidden>
        <div class="ok__tick">✓</div>
        <h2>You’re marked present</h2>
        <p id="doneInfo"></p>
      </div>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>

<?php if ($canSubmit): ?>
<script>
(function () {
  var TOKEN = <?= json_encode($token) ?>;
  var form = document.getElementById('f');
  var btn = document.getElementById('btn');
  var err = document.getElementById('err');

  function showErr(m) { err.textContent = m; err.hidden = false; }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    err.hidden = true;
    var sid = form.student_id.value.trim();
    var nm = form.full_name.value.trim();
    var crs = form.course.value.trim();
    if (!sid || !nm || !crs) { showErr('Please fill in your Student ID, full name and course.'); return; }

    btn.disabled = true;
    btn.textContent = 'Saving…';
    fetch('api/attendance_checkin.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN, student_id: sid, full_name: nm, course: crs })
    })
      .then(function (r) { return r.json().catch(function () { return { success: false, error: 'Unexpected server response.' }; }); })
      .then(function (j) {
        if (!j.success) { showErr(j.error || 'Something went wrong.'); btn.disabled = false; btn.textContent = 'Time in'; return; }
        document.getElementById('formBox').hidden = true;
        var info = document.getElementById('doneInfo');
        info.textContent = '';
        [j.data.full_name, 'ID: ' + j.data.student_id, 'Course: ' + j.data.course, 'Time-in: ' + j.data.time_in].forEach(function (line, i) {
          if (i) info.appendChild(document.createElement('br'));
          info.appendChild(document.createTextNode(line));
        });
        document.getElementById('done').hidden = false;
      })
      .catch(function () {
        showErr('Could not reach the server. Check your connection and try again.');
        btn.disabled = false; btn.textContent = 'Time in';
      });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
