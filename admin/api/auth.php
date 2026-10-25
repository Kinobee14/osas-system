<?php
// POST ?action=login  {username,password}
// POST ?action=logout
// GET  ?action=me
require __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        if (method() !== 'POST') respond(false, null, 'Method not allowed', 405);
        $in = body();
        required($in, ['username', 'password']);
        $st = db()->prepare('SELECT * FROM admins WHERE username = ?');
        $st->execute([trim($in['username'])]);
        $admin = $st->fetch();
        if (!$admin || !password_verify($in['password'], $admin['password'])) {
            respond(false, null, 'Invalid username or password.', 401);
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = [
            'id' => (int)$admin['id'],
            'username' => $admin['username'],
            'display_name' => $admin['display_name'],
            'office' => $admin['office'],
        ];
        respond(true, $_SESSION['admin'], 'Welcome back!');

    case 'logout':
        $_SESSION = [];
        session_destroy();
        respond(true, null, 'Logged out.');

    case 'me':
        $me = require_auth();
        require_once __DIR__ . '/../../shared/clearance_access.php';
        $me['can_clearance'] = admin_can_download_clearance($me);
        respond(true, $me);

    default:
        respond(false, null, 'Unknown action', 404);
}