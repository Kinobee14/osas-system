<?php
// GET                      list resources (id, name, stock)
// POST                     create {name, stock}
// PUT                      update {id, name, stock}
// DELETE ?id=              delete resource
require __DIR__ . '/_bootstrap.php';
require_auth();
$pdo = db();

function clean_resource(array $r): array {
    required($r, ['name']);
    $stock = filter_var($r['stock'] ?? 0, FILTER_VALIDATE_INT);
    if ($stock === false || $stock < 0) respond(false, null, 'Stock must be a whole number of 0 or more.', 422);
    return [trim($r['name']), $stock];
}

switch (method()) {
    case 'GET':
        respond(true, $pdo->query('SELECT * FROM resources ORDER BY name')->fetchAll());

    case 'POST':
        $data = clean_resource(body());
        $pdo->prepare('INSERT INTO resources (name, stock) VALUES (?, ?)')->execute($data);
        respond(true, ['id' => (int)$pdo->lastInsertId()], 'Resource added.', 201);

    case 'PUT':
        $row = body();
        $id = int_id($row['id'] ?? null);
        $data = clean_resource($row);
        $pdo->prepare('UPDATE resources SET name = ?, stock = ? WHERE id = ?')->execute([...$data, $id]);
        respond(true, null, 'Resource updated.');

    case 'DELETE':
        $id = int_id($_GET['id'] ?? null);
        $pdo->prepare('DELETE FROM resources WHERE id = ?')->execute([$id]);
        respond(true, null, 'Resource deleted.');
}

respond(false, null, 'Method not allowed', 405);
