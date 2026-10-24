<?php
/**
 * api/report_pdf.php
 *
 *   ?id=4                          → one post-event report, in full, with photos
 *   ?days=90&venue_id=0            → the whole register for that filter
 *   ?days=90&venue_id=0&detail=1   → the register, then every report in full
 *
 * This replaces the old CSV export. It's a plain GET so the browser can just
 * follow a link and get a real file; no fetch/blob juggling needed, and the
 * session cookie rides along automatically.
 *
 * Like attachment.php this skips _bootstrap.php on purpose — that file sends
 * a JSON content-type on every request, which would corrupt the PDF.
 */
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/pdf.php';
require_once __DIR__ . '/../../shared/events.php';
require_once __DIR__ . '/../../shared/uploads.php';

if (empty($_SESSION['admin'])) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Please log in to the admin panel first.';
    exit;
}

$pdo = db();
$admin = $_SESSION['admin'];
$officeName = (!empty($admin['office']) && $admin['office'] !== 'Admin')
    ? 'Vice ' . $admin['office']
    : ($admin['display_name'] ?? 'OSAS');

/* ======================================================================
   Shared chrome
   ====================================================================== */

const BRAND_GREEN = '#1f5a3c';
const BRAND_MID   = '#2f8a61';
const INK         = '#1d1d1b';
const MUTED       = '#7b7667';
const LINE        = '#e6dcc3';
const CREAM       = '#f7f3e8';

function fmt_date_long(?string $date): string
{
    if (!$date) return '—';
    $ts = strtotime($date);
    return $ts ? date('F j, Y', $ts) : $date;
}

function fmt_time(?string $t): string
{
    if (!$t) return '';
    $ts = strtotime($t);
    return $ts ? date('g:i A', $ts) : $t;
}

/** Green banner at the top of every page. */
function attach_chrome(SimplePdf $pdf, string $docTitle, string $subtitle, string $office): void
{
    $pdf->setHeader(function (SimplePdf $p) use ($docTitle, $subtitle) {
        $p->rect(0, 0, $p->pageWidth, 66, BRAND_GREEN);
        $p->rect(0, 66, $p->pageWidth, 3, BRAND_MID);
        $p->textAt($p->marginLeft, 18, 'UNIVERSITY OF CALOOCAN CITY', 9, 'B', '#bfe0cd');
        $p->textAt($p->marginLeft, 31, $docTitle, 16, 'B', '#ffffff');
        $p->textAt($p->marginLeft, 50, $subtitle, 9, '', '#d8ecdf');
        $p->setY(92);
    });

    $pdf->setFooter(function (SimplePdf $p, int $pageNo) use ($office) {
        $y = $p->pageHeight - 34;
        $p->hline($p->marginLeft, $y, $p->contentWidth(), LINE);
        $p->textAt($p->marginLeft, $y + 8,
            'Office of Student Affairs and Services  ·  Generated ' . date('M j, Y g:i A') . ' by ' . $office,
            7.5, '', MUTED);
        $p->textAt($p->marginLeft, $y + 8, 'Page ' . $pageNo, 7.5, '', MUTED, $p->contentWidth(), 'right');
    });
}

/** Section heading with a coloured tick beside it. */
function section(SimplePdf $pdf, string $title): void
{
    $pdf->ensureSpace(40);
    $pdf->moveY(8);
    $pdf->rect($pdf->marginLeft, $pdf->getY() - 1, 3, 13, BRAND_MID);
    $pdf->textAt($pdf->marginLeft + 10, $pdf->getY(), strtoupper($title), 10.5, 'B', BRAND_GREEN);
    $pdf->moveY(20);
}

/** Two-column label/value grid — the event detail block. */
function detail_grid(SimplePdf $pdf, array $pairs): void
{
    $colW = $pdf->contentWidth() / 2;
    $rowH = 26;
    $i = 0;
    foreach ($pairs as $label => $value) {
        $col = $i % 2;
        if ($col === 0) $pdf->ensureSpace($rowH);
        $x = $pdf->marginLeft + $col * $colW;
        $y = $pdf->getY();

        $pdf->textAt($x, $y, strtoupper($label), 7, 'B', MUTED);
        $lines = $pdf->wrap((string) $value, $colW - 14, 9.5);
        $pdf->textAt($x, $y + 10, $lines[0] ?? '—', 9.5, '', INK);

        if ($col === 1 || $i === count($pairs) - 1) $pdf->moveY($rowH);
        $i++;
    }
}

/** Free-text block with a light background, used for the narrative fields. */
function narrative(SimplePdf $pdf, string $label, ?string $text): void
{
    $text = trim((string) $text);
    if ($text === '') $text = 'Not provided.';

    $pdf->ensureSpace(46);
    $pdf->textAt($pdf->marginLeft, $pdf->getY(), strtoupper($label), 7.5, 'B', MUTED);
    $pdf->moveY(13);
    $pdf->paragraph($text, 9.5, '', INK, 1.5);
    $pdf->moveY(6);
}

/**
 * Draws a table that flows across pages, repeating its header row.
 * $columns: [['label' => 'Ref', 'w' => 0.18, 'align' => 'left'], ...] — widths
 * are fractions of the content width so callers never do arithmetic.
 */
function draw_table(SimplePdf $pdf, array $columns, array $rows, string $emptyText = 'No records.'): void
{
    $total = $pdf->contentWidth();
    $pad = 5;
    $headerH = 20;
    $fontSize = 8.5;

    $drawHeader = function () use ($pdf, $columns, $total, $pad, $headerH) {
        $pdf->rect($pdf->marginLeft, $pdf->getY(), $total, $headerH, BRAND_GREEN);
        $x = $pdf->marginLeft;
        foreach ($columns as $c) {
            $w = $total * $c['w'];
            $pdf->textAt($x + $pad, $pdf->getY() + 5, strtoupper($c['label']), 7.5, 'B', '#ffffff',
                $w - $pad * 2, $c['align'] ?? 'left');
            $x += $w;
        }
        $pdf->moveY($headerH);
    };

    $pdf->ensureSpace($headerH + 30);
    $drawHeader();

    if (!$rows) {
        $pdf->rect($pdf->marginLeft, $pdf->getY(), $total, 26, CREAM);
        $pdf->textAt($pdf->marginLeft, $pdf->getY() + 9, $emptyText, 9, 'I', MUTED, $total, 'center');
        $pdf->moveY(26);
        return;
    }

    $zebra = false;
    foreach ($rows as $row) {
        // Measure first: a row is only as tall as its tallest wrapped cell.
        $lineSets = [];
        $maxLines = 1;
        foreach ($columns as $idx => $c) {
            $w = $total * $c['w'] - $pad * 2;
            $lines = $pdf->wrap((string) ($row[$idx] ?? ''), $w, $fontSize);
            if (!$lines) $lines = [''];
            $lines = array_slice($lines, 0, 3); // keep the register scannable
            $lineSets[] = $lines;
            $maxLines = max($maxLines, count($lines));
        }
        $rowH = 8 + $maxLines * 11;

        if ($pdf->getY() + $rowH > $pdf->pageHeight - $pdf->marginBottom) {
            $pdf->addPage();
            $drawHeader();
        }

        if ($zebra) $pdf->rect($pdf->marginLeft, $pdf->getY(), $total, $rowH, CREAM);
        $zebra = !$zebra;

        $x = $pdf->marginLeft;
        foreach ($columns as $idx => $c) {
            $w = $total * $c['w'];
            foreach ($lineSets[$idx] as $n => $line) {
                $pdf->textAt($x + $pad, $pdf->getY() + 5 + $n * 11, $line, $fontSize,
                    (($c['bold'] ?? false) ? 'B' : ''), INK, $w - $pad * 2, $c['align'] ?? 'left');
            }
            $x += $w;
        }

        $pdf->hline($pdf->marginLeft, $pdf->getY() + $rowH, $total, LINE, 0.5);
        $pdf->moveY($rowH);
    }
}

/** A row of headline figures. */
function stat_row(SimplePdf $pdf, array $stats): void
{
    $count = count($stats);
    if ($count === 0) return;
    $gap = 10;
    $w = ($pdf->contentWidth() - $gap * ($count - 1)) / $count;
    $h = 52;

    $pdf->ensureSpace($h + 10);
    $x = $pdf->marginLeft;
    foreach ($stats as $label => $value) {
        $pdf->rect($x, $pdf->getY(), $w, $h, CREAM);
        $pdf->rect($x, $pdf->getY(), 3, $h, BRAND_MID);
        $pdf->textAt($x + 12, $pdf->getY() + 12, (string) $value, 19, 'B', BRAND_GREEN);
        $pdf->textAt($x + 12, $pdf->getY() + 36, strtoupper($label), 7, 'B', MUTED, $w - 18);
        $x += $w + $gap;
    }
    $pdf->moveY($h + 14);
}

/** Horizontal bar chart for reports-per-venue. */
function bar_chart(SimplePdf $pdf, array $items): void
{
    if (!$items) {
        $pdf->paragraph('No reports in this period.', 9.5, 'I', MUTED);
        return;
    }
    $max = 1;
    foreach ($items as $i) $max = max($max, (int) $i['value']);

    $labelW = 150;
    $valueW = 34;
    $trackW = $pdf->contentWidth() - $labelW - $valueW;

    foreach ($items as $item) {
        $pdf->ensureSpace(20);
        $y = $pdf->getY();
        $pdf->textAt($pdf->marginLeft, $y + 2, (string) $item['label'], 9, '', INK, $labelW - 8);
        $pdf->rect($pdf->marginLeft + $labelW, $y + 3, $trackW, 9, '#efe8d6');
        $fill = $trackW * ((int) $item['value'] / $max);
        if ($fill > 0.5) $pdf->rect($pdf->marginLeft + $labelW, $y + 3, $fill, 9, BRAND_MID);
        $pdf->textAt($pdf->marginLeft + $labelW + $trackW, $y + 2, (string) $item['value'], 9, 'B', BRAND_GREEN, $valueW, 'right');
        $pdf->moveY(18);
    }
}

/* ======================================================================
   One full report (used by both modes)
   ====================================================================== */

function render_report(SimplePdf $pdf, array $r, array $photos, array $resources, bool $withHeading = true): void
{
    if ($withHeading) {
        $pdf->ensureSpace(60);
        $pdf->rect($pdf->marginLeft, $pdf->getY(), $pdf->contentWidth(), 34, CREAM);
        $pdf->textAt($pdf->marginLeft + 12, $pdf->getY() + 7, SimplePdf::ascii($r['event_name']), 13, 'B', BRAND_GREEN);
        $pdf->textAt($pdf->marginLeft + 12, $pdf->getY() + 22,
            $r['report_no'] . '  ·  Reservation ' . $r['ref_no'], 8, '', MUTED);
        $pdf->moveY(46);
    }

    section($pdf, 'Event Details');
    detail_grid($pdf, [
        'Event'          => $r['event_name'],
        'Reference No.'  => $r['ref_no'],
        'Venue'          => $r['venue_name'],
        'Campus'         => $r['campus'] ?: 'Main Campus',
        'Date Held'      => fmt_date_long($r['event_date']),
        'Time'           => fmt_time($r['start_time']) . ' - ' . fmt_time($r['end_time']),
        'Requested By'   => $r['requested_by'],
        'Attendance'     => number_format((int) $r['attendees_count']) . ' participants',
    ]);

    if ($resources) {
        $items = [];
        foreach ($resources as $res) {
            $items[] = $res['resource_name'] . ' x' . (int) $res['quantity'];
        }
        $pdf->moveY(2);
        $pdf->textAt($pdf->marginLeft, $pdf->getY(), 'RESOURCES BORROWED', 7, 'B', MUTED);
        $pdf->moveY(11);
        $pdf->paragraph(implode(', ', $items), 9.5, '', INK);
        $pdf->moveY(4);
    }

    section($pdf, 'Report');
    narrative($pdf, 'Summary of the event', $r['summary']);
    narrative($pdf, 'Issues or incidents encountered', $r['issues'] ?? '');
    narrative($pdf, 'Recommendations', $r['recommendations'] ?? '');

    $condLabels = resource_condition_labels();
    $venueLabels = venue_condition_labels();
    $reviewLabels = review_status_labels();
    detail_grid($pdf, [
        'Resource Condition' => $condLabels[$r['resources_condition']] ?? $r['resources_condition'],
        'Venue Condition'    => $venueLabels[$r['venue_condition']] ?? $r['venue_condition'],
        'Prepared By'        => $r['prepared_by'] . ' (student)',
        'Submitted On'       => fmt_date_long($r['created_at']),
        'Review Status'      => $reviewLabels[$r['review_status']] ?? $r['review_status'],
        'Reviewed On'        => $r['reviewed_at'] ? fmt_date_long($r['reviewed_at']) : 'Not yet reviewed',
    ]);

    if (!empty($r['review_remarks'])) {
        narrative($pdf, 'OSAS review remarks', $r['review_remarks']);
    }

    /* ---- Photo documentation: two per row, captions underneath ---- */
    section($pdf, 'Event Documentation (' . count($photos) . ' photo' . (count($photos) === 1 ? '' : 's') . ')');

    if (!$photos) {
        $pdf->paragraph('No photos were attached to this report.', 9.5, 'I', MUTED);
        return;
    }

    $gap = 14;
    $cellW = ($pdf->contentWidth() - $gap) / 2;
    $cellH = 132;
    $col = 0;
    $rowTop = $pdf->getY();

    foreach ($photos as $photo) {
        if ($col === 0) {
            $pdf->ensureSpace($cellH + 26);
            $rowTop = $pdf->getY();
        }
        $x = $pdf->marginLeft + $col * ($cellW + $gap);

        $image = SimplePdf::loadImage(upload_path(UPLOAD_BUCKET_POST_EVENTS, $photo['stored_name']));
        if ($image) {
            $pdf->rect($x, $rowTop, $cellW, $cellH, '#f0ebdd');
            $pdf->drawImage($image, $x + 4, $rowTop + 4, $cellW - 8, $cellH - 8);
        } else {
            // GD missing, or the file went walkabout — say so rather than
            // leaving a mysterious blank rectangle on the page.
            $pdf->rect($x, $rowTop, $cellW, $cellH, '#f0ebdd');
            $pdf->textAt($x, $rowTop + $cellH / 2 - 4, '[ image unavailable ]', 8.5, 'I', MUTED, $cellW, 'center');
        }

        $caption = trim((string) ($photo['caption'] ?? '')) ?: $photo['original_name'];
        $capLines = $pdf->wrap($caption, $cellW, 7.5);
        $pdf->textAt($x, $rowTop + $cellH + 5, $capLines[0] ?? '', 7.5, '', MUTED, $cellW, 'center');

        $col++;
        if ($col === 2) {
            $col = 0;
            $pdf->setY($rowTop + $cellH + 24);
        }
    }
    if ($col === 1) $pdf->setY($rowTop + $cellH + 24);
}

/* ======================================================================
   Mode 1 — a single report
   ====================================================================== */

if (!empty($_GET['id'])) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(422);
        header('Content-Type: text/plain');
        echo 'Invalid report id.';
        exit;
    }

    $st = $pdo->prepare(
        "SELECT pr.*, r.ref_no, r.event_name, r.venue_name, r.campus, r.event_date,
                r.start_time, r.end_time, r.purpose, r.requested_by
           FROM post_event_reports pr
           JOIN reservations r ON r.id = pr.reservation_id
          WHERE pr.id = ?"
    );
    $st->execute([$id]);
    $report = $st->fetch();

    if (!$report) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'Report not found.';
        exit;
    }

    $ph = $pdo->prepare('SELECT * FROM post_event_photos WHERE report_id = ? ORDER BY id');
    $ph->execute([$id]);
    $photos = $ph->fetchAll();

    $rs = $pdo->prepare('SELECT resource_name, quantity FROM reservation_resources WHERE reservation_id = ? ORDER BY resource_name');
    $rs->execute([(int) $report['reservation_id']]);
    $resources = $rs->fetchAll();

    $pdf = new SimplePdf();
    $pdf->setMeta('Post-Event Report ' . $report['report_no'], $officeName);
    attach_chrome($pdf, 'Post-Event Report',
        $report['report_no'] . '  ·  ' . SimplePdf::ascii($report['event_name']), $officeName);

    render_report($pdf, $report, $photos, $resources, false);

    $pdf->download('post-event-' . $report['report_no'] . '.pdf');
}

/* ======================================================================
   Mode 2 — the whole register
   ====================================================================== */

auto_complete_due_reservations($pdo);

$days = isset($_GET['days']) ? (int) $_GET['days'] : 90;
$venueId = (int) ($_GET['venue_id'] ?? 0);
$withDetail = !empty($_GET['detail']);

$where = ['1=1'];
$args = [];
if ($days > 0) {
    $where[] = 'r.event_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
    $args[] = $days;
}
if ($venueId > 0) {
    $where[] = 'r.venue_id = ?';
    $args[] = $venueId;
}
$w = implode(' AND ', $where);

$st = $pdo->prepare(
    "SELECT pr.*, r.ref_no, r.event_name, r.venue_name, r.campus, r.event_date,
            r.start_time, r.end_time, r.purpose, r.requested_by,
            (SELECT COUNT(*) FROM post_event_photos p WHERE p.report_id = pr.id) AS photo_count
       FROM post_event_reports pr
       JOIN reservations r ON r.id = pr.reservation_id
      WHERE $w
      ORDER BY r.event_date DESC, pr.id DESC"
);
$st->execute($args);
$reports = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT r.ref_no, r.event_name, r.requested_by, r.event_date,
            COALESCE(v.name, r.venue_name) AS venue,
            DATEDIFF(CURDATE(), r.event_date) AS days_since
       FROM reservations r
       LEFT JOIN venues v ON v.id = r.venue_id
      WHERE r.status = 'completed'
        AND NOT EXISTS (SELECT 1 FROM post_event_reports pr WHERE pr.reservation_id = r.id)
        AND $w
      ORDER BY r.event_date DESC"
);
$st->execute($args);
$pending = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT COALESCE(v.name, r.venue_name, '(No venue)') AS label, COUNT(*) AS value
       FROM post_event_reports pr
       JOIN reservations r ON r.id = pr.reservation_id
       LEFT JOIN venues v ON v.id = r.venue_id
      WHERE $w GROUP BY label ORDER BY value DESC, label"
);
$st->execute($args);
$byVenue = $st->fetchAll();

$venueLabel = 'All venues';
if ($venueId > 0) {
    $vq = $pdo->prepare('SELECT name FROM venues WHERE id = ?');
    $vq->execute([$venueId]);
    $venueLabel = $vq->fetchColumn() ?: 'Venue #' . $venueId;
}
$periodLabel = $days > 0 ? "Last $days days" : 'All time';

$totalAttendees = 0;
$totalPhotos = 0;
$approvedCount = 0;
$pendingReviewCount = 0;
$needsRevisionCount = 0;
foreach ($reports as $r) {
    $totalAttendees += (int) $r['attendees_count'];
    $totalPhotos += (int) $r['photo_count'];
    if ($r['review_status'] === 'approved') $approvedCount++;
    elseif ($r['review_status'] === 'pending') $pendingReviewCount++;
    elseif ($r['review_status'] === 'needs_revision') $needsRevisionCount++;
}
$finished = count($reports) + count($pending);
// Completion means approved, not merely submitted — a report still stuck in
// revision hasn't actually documented the event as far as the record goes.
$rate = $finished > 0 ? round($approvedCount / $finished * 100) : 0;

$pdf = new SimplePdf();
$pdf->setMeta('Post-Event Reports — ' . $periodLabel, $officeName);
$subtitle = $periodLabel . '  ·  ' . SimplePdf::ascii($venueLabel);
attach_chrome($pdf, 'Post-Event Reports', $subtitle, $officeName);

stat_row($pdf, [
    'Approved'          => $approvedCount,
    'Awaiting Review'   => $pendingReviewCount,
    'Needs Revision'    => $needsRevisionCount,
    'Awaiting Submission' => count($pending),
    'Completion'        => $rate . '%',
]);

section($pdf, 'Reports by Venue');
bar_chart($pdf, $byVenue);

$reviewLabels = review_status_labels();
section($pdf, 'Register of Post-Event Reports');
draw_table($pdf, [
    ['label' => 'Report No.', 'w' => 0.14, 'bold' => true],
    ['label' => 'Event',      'w' => 0.22],
    ['label' => 'Venue',      'w' => 0.14],
    ['label' => 'Date Held',  'w' => 0.12],
    ['label' => 'Attendance', 'w' => 0.09, 'align' => 'right'],
    ['label' => 'Submitted By', 'w' => 0.14],
    ['label' => 'Review',     'w' => 0.15],
], array_map(fn($r) => [
    $r['report_no'],
    $r['event_name'],
    $r['venue_name'],
    fmt_date_long($r['event_date']),
    number_format((int) $r['attendees_count']),
    $r['prepared_by'],
    $reviewLabels[$r['review_status']] ?? $r['review_status'],
], $reports), 'No post-event reports have been submitted for this period.');

if ($pending) {
    section($pdf, 'Finished Events Awaiting Student Submission');
    draw_table($pdf, [
        ['label' => 'Ref No.',   'w' => 0.15, 'bold' => true],
        ['label' => 'Event',     'w' => 0.32],
        ['label' => 'Venue',     'w' => 0.20],
        ['label' => 'Date Held', 'w' => 0.18],
        ['label' => 'Days Since', 'w' => 0.15, 'align' => 'right'],
    ], array_map(fn($p) => [
        $p['ref_no'], $p['event_name'], $p['venue'],
        fmt_date_long($p['event_date']),
        (int) $p['days_since'] . ' days',
    ], $pending), 'Every finished event has a report. Nice.');
}

if ($withDetail && $reports) {
    foreach ($reports as $r) {
        $pdf->addPage();

        $ph = $pdo->prepare('SELECT * FROM post_event_photos WHERE report_id = ? ORDER BY id');
        $ph->execute([(int) $r['id']]);

        $rs = $pdo->prepare('SELECT resource_name, quantity FROM reservation_resources WHERE reservation_id = ? ORDER BY resource_name');
        $rs->execute([(int) $r['reservation_id']]);

        render_report($pdf, $r, $ph->fetchAll(), $rs->fetchAll(), true);
    }
}

$suffix = $days > 0 ? "last-$days-days" : 'all-time';
$pdf->download("post-event-reports-$suffix.pdf");
