<?php
/**
 * api/reservation_pdf.php?id=12
 *
 * Lets a student download a PDF copy of one of their own APPROVED
 * reservations. Plain GET so a normal link triggers the download.
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Please sign in again.';
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../../admin/includes/pdf.php';
require_once __DIR__ . '/../../shared/signatories.php';
require_once __DIR__ . '/../../shared/esignatures.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Bad request.';
    exit;
}

$pdo = get_db();
$userId = (int) $_SESSION['user_id'];

$st = $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND student_id = ?');
$st->execute([$id, $userId]);
$r = $st->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Reservation not found.';
    exit;
}
if (!in_array($r['status'], ['approved', 'completed'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'A PDF is only available once the reservation is approved.';
    exit;
}

$rs = $pdo->prepare('SELECT resource_name, quantity FROM reservation_resources WHERE reservation_id = ? ORDER BY id');
$rs->execute([$id]);
$resources = $rs->fetchAll(PDO::FETCH_ASSOC);
$sig = get_signatories($pdo);

// E-signatures the admins placed by approving this reservation (empty if the feature isn't set up).
$esigs = []; $esigImg = [];
try {
    foreach (esig_for_reservation($pdo, (int) $id) as $k => $row) { $esigs[$k] = $row; $esigImg[$k] = esig_pdf_image($row['signature']); }
} catch (Throwable $e) { /* printed without signatures */ }
$esigNote = function (string $k) use ($esigs): ?string {
    return isset($esigs[$k]) ? 'E-SIGNED ' . esig_format_date($esigs[$k]['signed_at']) : null;
};

/* ---------- drawing helpers (form style) ---------- */
const INK = '#111111';
const SOFT = '#555555';

function fd(?string $d): string { if (!$d) return ''; $t = strtotime($d); return $t ? date('F j, Y', $t) : $d; }
function ft(?string $t): string { if (!$t) return ''; $x = strtotime($t); return $x ? date('g:i A', $x) : $t; }

function vline(SimplePdf $p, float $x, float $y, float $h): void { $p->rect($x - 0.4, $y, 0.8, $h, INK); }
function hl(SimplePdf $p, float $x, float $y, float $w): void { $p->rect($x, $y - 0.4, $w, 0.8, INK); }
function box(SimplePdf $p, float $x, float $y, float $w, float $h): void {
    hl($p, $x, $y, $w); hl($p, $x, $y + $h, $w); vline($p, $x, $y, $h); vline($p, $x + $w, $y, $h);
}
function cbox(SimplePdf $p, float $x, float $y, bool $on, string $label, float $size = 8): void {
    $s = 7;
    $p->rect($x, $y, $s, 0.6, INK); $p->rect($x, $y + $s, $s, 0.6, INK);
    $p->rect($x, $y, 0.6, $s, INK); $p->rect($x + $s, $y, 0.6, $s + 0.6, INK);
    if ($on) $p->rect($x + 1.6, $y + 1.6, $s - 2.6, $s - 2.6, INK);
    $p->textAt($x + $s + 5, $y - 0.5, $label, $size, '', INK);
}
function lbl(SimplePdf $p, float $x, float $y, string $t): void { $p->textAt($x, $y, $t, 7, 'B', INK); }
function val(SimplePdf $p, float $x, float $y, string $t, float $w): void {
    $lines = $p->wrap($t === '' ? '-' : $t, $w, 9);
    $p->textAt($x, $y, $lines[0] ?? '-', 9, '', '#1f3f8a');
}
/** Centered signature block: name underlined, title below. */
function sig(SimplePdf $p, float $x, float $y, float $w, string $name, string $title, string $extra = ''): void {
    $name = $name === '' ? ' ' : $name;
    $p->textAt($x, $y, $name, 8.5, 'B', INK, $w, 'center');
    $tw = min($w - 10, max(60, $p->textWidth(SimplePdf::ascii($name), 8.5, 'B') + 6));
    $p->hline($x + ($w - $tw) / 2, $y + 11, $tw, INK, 0.6);
    $p->textAt($x, $y + 14, $title, 6.8, '', INK, $w, 'center');
    if ($extra !== '') $p->textAt($x, $y + 23, $extra, 6.5, 'I', '#2f8a61', $w, 'center');
}
function pline(SimplePdf $p, float $x, float $y, float $w, string $name, string $cap1, string $cap2, ?array $img = null): void {
    if ($img) $p->drawImage($img, $x + ($w - 130) / 2, $y - 26, 130, 30);   // drawn signature sits on the line
    $p->textAt($x, $y, $name === '' ? ' ' : $name, 9, '', '#1f3f8a', $w, 'center');
    $p->hline($x, $y + 12, $w, INK, 0.6);
    $p->textAt($x, $y + 15, $cap1, 6.5, '', INK, $w, 'center');
    $p->textAt($x, $y + 23, $cap2, 6.5, '', INK, $w, 'center');
}

/* ---------- document ---------- */
$pdf = new SimplePdf();
$pdf->marginLeft = 28; $pdf->marginRight = 28;
$pdf->setMeta('Permission to Conduct Activity - ' . $r['ref_no'], 'OSAS');
$L = 28; $W = $pdf->pageWidth - 56; $R = $L + $W; $M = $L + $W / 2;
$y0 = 28;

// ---- header ----
$crest = SimplePdf::loadImage(__DIR__ . '/../pictures/UCC.png', 300) ?? SimplePdf::loadImage(__DIR__ . '/../pictures/logo.png', 300);
if ($crest) $pdf->drawImage($crest, $L + 105, $y0 + 7, 36, 36);
$pdf->textAt($L, $y0 + 8, 'UNIVERSITY OF CALOOCAN CITY', 11, 'B', INK, $W, 'center');
$pdf->textAt($L, $y0 + 21, 'Biglang-Awa St., 12th Avenue East, Caloocan City', 6.5, '', INK, $W, 'center');
$pdf->textAt($L, $y0 + 29, 'Telefax 310-6855', 6.5, '', INK, $W, 'center');
$pdf->textAt($L, $y0 + 38, 'OFFICE OF THE STUDENT AFFAIRS AND SERVICES', 10.5, 'B', INK, $W, 'center');

$y = $y0 + 60;
$scope = strtolower((string) $r['activity_scope']);
$off = str_contains($scope, 'off');
$pdf->textAt($L + 8, $y, 'PERMISSION TO CONDUCT ACTIVITY / PROGRAM', 7.5, 'B', INK);
cbox($pdf, $R - 150, $y, !$off, 'ON CAMPUS', 7);
cbox($pdf, $R - 75, $y, $off, 'OFF-CAMPUS', 7);
$y += 18;
lbl($pdf, $L + 8, $y, 'CAMPUS:'); val($pdf, $L + 50, $y - 1, (string) $r['campus'], 300);
$pdf->hline($L + 48, $y + 10, 340, INK, 0.5);
lbl($pdf, $R - 150, $y, 'Date Prepared'); val($pdf, $R - 95, $y - 1, fd($r['date_prepared'] ?: $r['created_at']), 90);
$pdf->hline($R - 97, $y + 10, 90, INK, 0.5);
$y += 18;
lbl($pdf, $L + 8, $y, 'Nature of Activity: (Please check only one)');
$y += 12;
$nat = (string) $r['nature_of_activity'];
$opts = ['Seminar / Convention', 'Educational Tour', 'Immersion', 'Community Extension', 'Academic Requirements'];
foreach ($opts as $k => $o) cbox($pdf, $L + 10, $y + $k * 12, stripos($nat, $o) !== false || ($o === 'Seminar / Convention' && stripos($nat, 'Seminar') !== false), $o);
cbox($pdf, $M + 10, $y, stripos($nat, 'Other') !== false, 'Others: (Please specify)');
val($pdf, $M + 12, $y + 13, (string) $r['nature_other'], 240);
$pdf->hline($M + 10, $y + 24, $R - $M - 18, INK, 0.5);
$y += 5 * 12 + 4;
box($pdf, $L, $y0, $W, $y - $y0);   // outer top block

// ---- rows ----
$row = function (float $h) use (&$y, $pdf, $L, $W) { $top = $y; box($pdf, $L, $top, $W, $h); $y += $h; return $top; };

$t = $row(30); lbl($pdf, $L + 8, $t + 6, 'Title of Activity:'); val($pdf, $L + 8, $t + 17, (string) $r['event_name'], $W - 16);

$t = $row(46); vline($pdf, $M, $t, 46);
lbl($pdf, $L + 8, $t + 6, 'Venue:'); val($pdf, $L + 8, $t + 18, (string) $r['venue_name'], $W / 2 - 16);
lbl($pdf, $M + 6, $t + 6, 'Date:'); val($pdf, $M + 6, $t + 16, fd($r['event_date']), $W / 2 - 12);
lbl($pdf, $M + 6, $t + 28, 'Time:'); val($pdf, $M + 6, $t + 36, ft($r['start_time']) . '  to  ' . ft($r['end_time']), $W / 2 - 12);

$t = $row(30); vline($pdf, $M, $t, 30);
lbl($pdf, $L + 8, $t + 6, 'No. of Participants:'); val($pdf, $L + 8, $t + 17, (string) $r['participants'], $W / 2 - 16);
lbl($pdf, $M + 6, $t + 6, 'Sponsoring Organization / Class:'); val($pdf, $M + 6, $t + 17, (string) $r['sponsoring_org'], $W / 2 - 12);

$t = $row(30);
$fund = strtolower((string) $r['source_of_fund']);
lbl($pdf, $L + 8, $t + 6, 'Source of Fund:');
$isC = str_contains($fund, 'contribution'); $isO = str_contains($fund, 'organization');
cbox($pdf, $L + 10, $t + 17, $isC, 'Contributions', 7);
cbox($pdf, $L + 90, $t + 17, $isO, 'Organization Fund', 7);
cbox($pdf, $L + 185, $t + 17, !$isC && !$isO && $fund !== '', 'Others:', 7);
if (!$isC && !$isO) val($pdf, $L + 225, $t + 16, (string) $r['source_of_fund'], 250);

$objLines = $pdf->wrap((string) ($r['purpose'] ?: '-'), $W - 16, 9);
$oh = max(50, 20 + count($objLines) * 12);
$t = $row($oh); lbl($pdf, $L + 8, $t + 6, 'Objectives of the Activity:');
foreach ($objLines as $k => $ln) $pdf->textAt($L + 8, $t + 17 + $k * 12, $ln, 9, '', '#1f3f8a');

// ---- certify / approvals block ----
$bh = 208;
$t = $row($bh); vline($pdf, $M, $t, $bh);
$lw = $M - $L - 16;
$cert = $pdf->wrap('I HEREBY CERTIFY THAT THIS ACTIVITY is necessary to provide students new avenue of knowledge and experiences. Report of this activity and liquidation of expenses (if any) shall be made available after the conduct of activity.', $lw, 6.8);
foreach ($cert as $k => $ln) $pdf->textAt($L + 8, $t + 6 + $k * 9, $ln, 6.8, '', INK);
$adv = trim((string) ($r['adviser_name'] ?: $r['requested_by']));
$advImg = $recImg = null;
try { $advImg = esig_pdf_image($r['adviser_signature'] ?? null); $recImg = esig_pdf_image($r['recommending_signature'] ?? null); } catch (Throwable $e) { /* print without */ }
pline($pdf, $L + 8, $t + 68, $lw, $adv, 'Signature over printed name', 'CLUB / ORGANIZATION / ADVISER', $advImg);
$pdf->textAt($L + 8, $t + 104, 'Recommending Approval:', 7.5, 'B', INK);
pline($pdf, $L + 8, $t + 138, $lw, (string) $r['recommending_name'], 'Signature over printed name', 'Program Coordinator / Program Chairman / Dean', $recImg);
lbl($pdf, $L + 8, $t + 172, 'Remarks:');
val($pdf, $L + 8, $t + 182, (string) $r['remarks'], $lw);
$pdf->hline($L + 8, $t + 193, $lw, INK, 0.5);
$pdf->hline($L + 8, $t + 204, $lw, INK, 0.5);

// right column: Approved by / Noted by
$rw = $R - $M - 12; $rx = $M + 6;
hl($pdf, $M, $t + 100, $R - $M);
foreach (['approver1' => 24, 'approver2' => 62, 'noted' => 132] as $k => $ny) {   // signature images first, text on top
    if (!empty($esigImg[$k])) $pdf->drawImage($esigImg[$k], $rx + ($rw - 120) / 2, $t + $ny - 18, 120, 32);
}
$pdf->textAt($rx, $t + 6, 'Approved by:', 7.5, 'B', INK);
$a1n = $r['approver1_name'] ?: $sig['approver1_name'];
$a1t = $r['approver1_title'] ?: $sig['approver1_title'];
$a2n = $r['approver2_name'] ?: $sig['approver2_name'];
$a2t = $r['approver2_title'] ?: $sig['approver2_title'];
$st = function ($s, $d) { $s = strtolower((string) $s); if ($s !== 'approved') return ''; return 'APPROVED' . ($d ? '  ' . fd($d) : ''); };
sig($pdf, $rx, $t + 24, $rw, (string) $a1n, (string) $a1t, $esigNote('approver1') ?? $st($r['approver1_status'], $r['approver1_date']));
sig($pdf, $rx, $t + 62, $rw, (string) $a2n, (string) $a2t, $esigNote('approver2') ?? $st($r['approver2_status'], $r['approver2_date']));
$pdf->textAt($rx, $t + 106, 'Noted by:', 7.5, 'B', INK);
sig($pdf, $rx, $t + 132, $rw, (string) $sig['noted_name'], (string) $sig['noted_title'], $esigNote('noted') ?? '');

// ---- attachments ----
$checked = json_decode((string) $r['attachments_checklist'], true);
if (!is_array($checked)) $checked = array_filter(array_map('trim', explode(',', (string) $r['attachments_checklist'])));
$att = [['List of Students', 'Project Proposal'], ['Budget Proposal', "Instructor's Acceptance of Responsibility"], ['Proposed Program / Invitation', 'Parent Consent']];
$t = $row(66);
$pdf->textAt($L + 8, $t + 6, 'Attachments:', 7.5, 'B', INK);
foreach ($att as $k => [$a, $b]) {
    cbox($pdf, $L + 10, $t + 20 + $k * 14, in_array($a, $checked, true), $a);
    cbox($pdf, $M + 10, $t + 20 + $k * 14, in_array($b, $checked, true), $b);
}

// ---- resources ----
$resText = $resources ? implode(',  ', array_map(fn($x) => $x['resource_name'] . ' x' . (int) $x['quantity'], $resources)) : 'None';
$rl = $pdf->wrap($resText, $W - 16, 8.5);
$t = $row(20 + count($rl) * 11);
lbl($pdf, $L + 8, $t + 6, 'Resources to Borrow:');
foreach ($rl as $k => $ln) $pdf->textAt($L + 8, $t + 16 + $k * 11, $ln, 8.5, '', '#1f3f8a');

// footer
$pdf->textAt($L, $y + 8, 'Ref. No. ' . $r['ref_no'] . '   |   Status: ' . strtoupper($r['status']) . '   |   Generated ' . date('M j, Y g:i A'), 7, '', SOFT, $W, 'center');

$pdf->download('reservation-' . $r['ref_no'] . '.pdf');
