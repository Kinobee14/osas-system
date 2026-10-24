<?php
// GET ?reservation_id=12 → the Permission to Conduct Activity form data for one
// reservation, the resources it borrows, and every file the student attached.
// Used by the "View Form" modal on the admin side (openRequirements() in app.js).
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../shared/uploads.php';
require_once __DIR__ . '/../../shared/signatories.php';
require_auth();
$pdo = db();

if (method() !== 'GET') respond(false, null, 'Method not allowed', 405);

$id = int_id($_GET['reservation_id'] ?? null);

$st = $pdo->prepare(
    "SELECT r.*, v.name AS venue_name, u.email AS student_email, u.organization AS organization
       FROM reservations r
       LEFT JOIN venues v ON v.id = r.venue_id
       LEFT JOIN users  u ON u.id = r.student_id
      WHERE r.id = ?"
);
$st->execute([$id]);
$reservation = $st->fetch();
if (!$reservation) respond(false, null, 'Reservation not found.', 404);

// Campus lives on the reservation itself; fall back to the venue name if the
// reservation row has no venue_name column of its own.
if (empty($reservation['venue_name'])) $reservation['venue_name'] = '';

$rs = $pdo->prepare('SELECT resource_name, quantity FROM reservation_resources WHERE reservation_id = ? ORDER BY resource_name');
$rs->execute([$id]);
$resources = $rs->fetchAll();

$fs = $pdo->prepare('SELECT id, kind, label, original_name, mime_type, size_bytes FROM reservation_attachments WHERE reservation_id = ? ORDER BY id');
$fs->execute([$id]);
$files = array_map(fn($f) => [
    'id'    => (int) $f['id'],
    'kind'  => $f['kind'],
    'label' => $f['label'],
    'name'  => $f['original_name'],
    'mime'  => $f['mime_type'],
    'size'  => format_bytes((int) $f['size_bytes']),
    'url'   => 'api/attachment.php?type=requirement&id=' . (int) $f['id'],
], $fs->fetchAll());

respond(true, [
    'reservation' => $reservation,
    'resources'   => $resources,
    'files'       => $files,
    'signatories' => get_signatories($pdo),
]);
