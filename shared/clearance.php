<?php
/**
 * shared/clearance.php
 *
 * The Activity Clearance PDF. It is issued automatically once OSAS approves
 * a student's post-event report, and both the student portal
 * (public/api/clearance_pdf.php) and the admin panel
 * (admin/api/clearance_pdf.php) download the very same document from here.
 *
 * Each signatory block carries an electronic signature: the signer's name,
 * the date they signed, and a short verification code derived from the
 * report, so a printed copy can be matched back to the record.
 */
require_once __DIR__ . '/../admin/includes/pdf.php';
require_once __DIR__ . '/signatories.php';
require_once __DIR__ . '/events.php';

/** Loads everything the clearance needs, or null if it isn't issued yet. */
function load_clearance(PDO $pdo, int $reservationId, ?int $studentId = null): ?array {
    $sql = "SELECT r.*, pr.id AS report_id, pr.report_no, pr.attendees_count, pr.summary, pr.issues,
                   pr.recommendations, pr.resources_condition, pr.venue_condition, pr.prepared_by,
                   pr.review_status, pr.reviewed_at, pr.created_at AS report_created,
                   a.display_name AS reviewer_name, a.office AS reviewer_office
              FROM reservations r
              JOIN post_event_reports pr ON pr.reservation_id = r.id
              LEFT JOIN admins a ON a.id = pr.reviewed_by
             WHERE r.id = ?";
    $args = [$reservationId];
    if ($studentId !== null) { $sql .= ' AND r.student_id = ?'; $args[] = $studentId; }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['review_status'] !== 'approved') return null;

    $ph = $pdo->prepare('SELECT COUNT(*) FROM post_event_photos WHERE report_id = ?');
    $ph->execute([(int) $c['report_id']]);
    $c['photo_count'] = (int) $ph->fetchColumn();
    return $c;
}

function clearance_no(array $c): string {
    return 'CLR-' . preg_replace('/^PER-/', '', (string) $c['report_no']);
}

/** Short code printed under each e-signature (stable for the same record). */
function esign_code(array $c, string $who): string {
    return strtoupper(substr(hash('sha256', $c['report_no'] . '|' . $c['ref_no'] . '|' . $who . '|' . $c['reviewed_at']), 0, 10));
}

function render_clearance_pdf(PDO $pdo, array $c): void {
    $INK = '#111111'; $BLUE = '#1f3f8a'; $GREEN = '#1f5a3c'; $SOFT = '#555555';
    $fd = function (?string $d): string { if (!$d) return '-'; $t = strtotime($d); return $t ? date('F j, Y', $t) : $d; };
    $ft = function (?string $t): string { if (!$t) return ''; $x = strtotime($t); return $x ? date('g:i A', $x) : $t; };
    $sig = get_signatories($pdo);

    $pdf = new SimplePdf();
    $pdf->marginLeft = 36; $pdf->marginRight = 36;
    $pdf->setMeta('Activity Clearance - ' . $c['ref_no'], 'OSAS');
    $L = 36; $W = $pdf->pageWidth - 72; $R = $L + $W;
    $box = function (float $x, float $y, float $w, float $h) use ($pdf, $INK) {
        $pdf->rect($x, $y, $w, 0.8, $INK); $pdf->rect($x, $y + $h, $w, 0.8, $INK);
        $pdf->rect($x, $y, 0.8, $h, $INK); $pdf->rect($x + $w, $y, 0.8, $h + 0.8, $INK);
    };

    // ---- header ----
    $y = 32;
    $crest = SimplePdf::loadImage(__DIR__ . '/../public/pictures/UCC.png', 300)
          ?? SimplePdf::loadImage(__DIR__ . '/../public/pictures/logo.png', 300);
    if ($crest) $pdf->drawImage($crest, $L + 70, $y + 2, 40, 40);
    $pdf->textAt($L, $y + 6, 'UNIVERSITY OF CALOOCAN CITY', 12, 'B', $INK, $W, 'center');
    $pdf->textAt($L, $y + 20, 'Biglang-Awa St., 12th Avenue East, Caloocan City  ·  Telefax 310-6855', 7, '', $INK, $W, 'center');
    $pdf->textAt($L, $y + 31, 'OFFICE OF THE STUDENT AFFAIRS AND SERVICES', 10.5, 'B', $INK, $W, 'center');
    $y += 56;
    $pdf->rect($L, $y, $W, 26, $GREEN);
    $pdf->textAt($L, $y + 7, 'ACTIVITY CLEARANCE', 14, 'B', '#ffffff', $W, 'center');
    $y += 34;
    $pdf->textAt($L, $y, 'Clearance No. ' . clearance_no($c), 8.5, 'B', $INK);
    $pdf->textAt($L, $y, 'Date Issued: ' . $fd($c['reviewed_at']), 8.5, 'B', $INK, $W, 'right');
    $y += 18;

    $intro = 'This is to certify that the activity described below has been conducted, its post-event report has been '
           . 'reviewed and approved by the Office of the Student Affairs and Services, and the organization is hereby '
           . 'CLEARED of all obligations for this activity.';
    foreach ($pdf->wrap($intro, $W, 9) as $ln) { $pdf->textAt($L, $y, $ln, 9, '', $INK); $y += 12; }
    $y += 6;

    // ---- details table ----
    $rows = [
        ['Title of Activity', (string) $c['event_name']],
        ['Reservation Ref. No.', (string) $c['ref_no']],
        ['Post-Event Report No.', (string) $c['report_no']],
        ['Organization / Class', (string) ($c['sponsoring_org'] ?: '-')],
        ['Requested by', (string) ($c['requested_by'] ?: '-')],
        ['Venue', (string) $c['venue_name']],
        ['Date & Time', $fd($c['event_date']) . '   ' . $ft($c['start_time']) . ' to ' . $ft($c['end_time'])],
        ['Actual Attendees', (string) (int) $c['attendees_count']],
        ['Resources', resource_condition_labels()[$c['resources_condition']] ?? (string) $c['resources_condition']],
        ['Venue Condition', venue_condition_labels()[$c['venue_condition']] ?? (string) $c['venue_condition']],
        ['Documentation', $c['photo_count'] . ' photo(s) submitted and reviewed'],
    ];
    $lw = 140;
    foreach ($rows as [$k, $v]) {
        $lines = $pdf->wrap($v === '' ? '-' : $v, $W - $lw - 16, 9);
        $h = max(18, 6 + count($lines) * 11);
        $box($L, $y, $W, $h);
        $pdf->rect($L, $y, $lw, $h, '#f1efe6');
        $box($L, $y, $lw, $h);
        $pdf->textAt($L + 6, $y + 5, $k, 8, 'B', $INK);
        foreach ($lines as $i => $ln) $pdf->textAt($L + $lw + 8, $y + 5 + $i * 11, $ln, 9, '', $BLUE);
        $y += $h;
    }
    $y += 12;

    // ---- free-text sections ----
    $section = function (string $title, ?string $text) use ($pdf, &$y, $L, $W, $INK, $BLUE) {
        $pdf->textAt($L, $y, $title, 8.5, 'B', $INK); $y += 12;
        $lines = $pdf->wrap(trim((string) $text) === '' ? 'None reported.' : (string) $text, $W - 8, 8.5);
        $lines = array_slice($lines, 0, 4);
        foreach ($lines as $ln) { $pdf->textAt($L + 6, $y, $ln, 8.5, '', $BLUE); $y += 11; }
        $y += 6;
    };
    $section('Summary of the Activity', $c['summary']);
    $section('Issues Encountered', $c['issues']);
    $section('Recommendations / Suggestions', $c['recommendations']);

    // ---- e-signatures ----
    $y = max($y + 6, $pdf->pageHeight - 250);
    $colW = ($W - 20) / 2;
    $signed = $fd($c['reviewed_at']);
    $esig = function (float $x, float $yy, string $label, string $name, string $title, string $who) use ($pdf, $c, $colW, $INK, $GREEN, $BLUE, $signed) {
        $pdf->textAt($x, $yy, $label, 7.5, 'B', $INK);
        $name = $name === '' ? ' ' : $name;
        // signature mark
        $pdf->textAt($x, $yy + 14, $name, 11, 'I', $BLUE, $colW, 'center');
        $pdf->textAt($x, $yy + 30, strtoupper($name), 8.5, 'B', $INK, $colW, 'center');
        $pdf->hline($x + 10, $yy + 41, $colW - 20, $INK, 0.6);
        $pdf->textAt($x, $yy + 44, $title, 6.8, '', $INK, $colW, 'center');
        $pdf->textAt($x, $yy + 54, 'E-SIGNED ' . $signed . '  ·  Code ' . esign_code($c, $who), 6.3, 'B', $GREEN, $colW, 'center');
    };
    $reviewer = trim((string) ($c['reviewer_name'] ?? ''));
    $revTitle = !empty($c['reviewer_office']) && $c['reviewer_office'] !== 'Admin' ? 'Vice ' . $c['reviewer_office'] . ', OSAS' : 'OSAS Reviewer';
    $esig($L, $y, 'Prepared by:', (string) $c['prepared_by'], 'Student / Organization Representative', 'prepared');
    $esig($L + $colW + 20, $y, 'Reviewed by:', $reviewer ?: 'OSAS', $revTitle, 'reviewed');
    $y += 74;
    $esig($L, $y, 'Approved by:', (string) $sig['approver1_name'], (string) $sig['approver1_title'], 'approved');
    $esig($L + $colW + 20, $y, 'Noted by:', (string) $sig['noted_name'], (string) $sig['noted_title'], 'noted');
    $y += 74;

    $pdf->hline($L, $y, $W, '#cccccc', 0.5);
    $pdf->textAt($L, $y + 6, 'This clearance was electronically generated and signed through SPARK. Generated ' . date('M j, Y g:i A') . '.', 6.8, '', $SOFT, $W, 'center');

    $pdf->download('clearance-' . $c['ref_no'] . '.pdf');
}
