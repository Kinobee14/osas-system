<?php
// GET                      list venues
// POST                     create {name,capacity,equipment,status}
// PUT                      bulk save [{id?,name,capacity,equipment,status}, ...]
// DELETE ?id=              delete venue
require __DIR__ . '/_bootstrap.php';
require_auth();
$pdo = db();
$STATUSES = ['available', 'booked', 'reserved', 'maintenance'];

function clean_venue(array $v, array $statuses): array {
    required($v, ['name']);
    $status = strtolower($v['status'] ?? 'available');
    if (!in_array($status, $statuses, true)) respond(false, null, 'Invalid status.', 422);
    $cap = (int)($v['capacity'] ?? 0);
    if ($cap < 0) respond(false, null, 'Capacity cannot be negative.', 422);
    return [trim($v['name']), $cap, trim($v['campus'] ?? ''), trim($v['equipment'] ?? ''), $status];
}

switch (method()) {
    case 'GET':
        respond(true, $pdo->query('SELECT * FROM venues ORDER BY id')->fetchAll());

    case 'POST':
        $data = clean_venue(body(), $STATUSES);
        $pdo->prepare('INSERT INTO venues (name, capacity, campus, equipment, status) VALUES (?,?,?,?,?)')->execute($data);
        respond(true, ['id' => (int)$pdo->lastInsertId()], 'Venue added.', 201);

    case 'PUT':
        $rows = body();
        if (!array_is_list($rows)) respond(false, null, 'Expected a list of venues.', 422);
        $pdo->beginTransaction();
        $upd = $pdo->prepare('UPDATE venues SET name=?, capacity=?, campus=?, equipment=?, status=? WHERE id=?');
        $ins = $pdo->prepare('INSERT INTO venues (name, capacity, campus, equipment, status) VALUES (?,?,?,?,?)');
        foreach ($rows as $row) {
            $data = clean_venue($row, $STATUSES);
            if (!empty($row['id'])) $upd->execute([...$data, (int)$row['id']]);
            else $ins->execute($data);
        }
        $pdo->commit();
        respond(true, null, 'Venues saved.');

    case 'DELETE':
        $id = int_id($_GET['id'] ?? null);
        $pdo->prepare('DELETE FROM venues WHERE id = ?')->execute([$id]);
        respond(true, null, 'Venue deleted.');
}

respond(false, null, 'Method not allowed', 405);
