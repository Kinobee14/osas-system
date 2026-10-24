<?php
// GET  ?q=search             list users (active/inactive directory — pending/
//                             rejected signups live on the Accounts page instead)
// POST {firstName,lastName,email,role,organization}         create
// PUT  {id,firstName,lastName,email,role,organization}      update
// POST ?action=toggle {id}   activate / deactivate
require __DIR__ . '/_bootstrap.php';
require_auth();
$pdo = db();
$ROLES = ['Student', 'Admin', 'Resource Keeper', 'Faculty'];

if (method() === 'GET') {
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $st = $pdo->prepare(
        "SELECT id, first_name, last_name, email, campus, organization, role, status, created_at
         FROM users
         WHERE status IN ('active','inactive')
           AND (CONCAT(first_name,' ',last_name) LIKE ? OR organization LIKE ?)
         ORDER BY first_name, last_name"
    );
    $st->execute([$q, $q]);
    respond(true, $st->fetchAll());
}

$in = body();

if (method() === 'POST' && ($_GET['action'] ?? '') === 'toggle') {
    $id = int_id($in['id'] ?? null);
    $pdo->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id = ?")->execute([$id]);
    respond(true, null, 'User status updated.');
}

if (in_array(method(), ['POST', 'PUT'], true)) {
    required($in, ['firstName', 'lastName', 'role']);
    if (!in_array($in['role'], $ROLES, true)) respond(false, null, 'Invalid role.', 422);
    $email = trim($in['email'] ?? '') ?: null;
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(false, null, 'Invalid email.', 422);
    $data = [trim($in['firstName']), trim($in['lastName']), $email, $in['role'], trim($in['organization'] ?? '')];

    if (method() === 'POST') {
        // Users added here are directory entries (staff/faculty/resource keepers
        // or manually-added students) — they're active immediately and don't go
        // through the public OTP signup + approval flow.
        $pdo->prepare(
            "INSERT INTO users (first_name, last_name, email, role, organization, is_verified, status)
             VALUES (?,?,?,?,?, 1, 'active')"
        )->execute($data);
        respond(true, ['id' => (int)$pdo->lastInsertId()], 'User added.', 201);
    }
    $id = int_id($in['id'] ?? null);
    $pdo->prepare('UPDATE users SET first_name=?, last_name=?, email=?, role=?, organization=? WHERE id=?')
        ->execute([...$data, $id]);
    respond(true, null, 'User updated.');
}

respond(false, null, 'Method not allowed', 405);
