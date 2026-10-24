<?php
/**
 * api/profile_update.php
 * Saves changes from the "Profile" tab of student.php (first name, last
 * name, campus). Email is intentionally not editable here since it's the
 * account's login identity and is tied to OTP verification.
 */
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in again.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$campusOptions = [
    "Main Campus",
    "Congressional Extension Campus",
    "Camarin Extension Campus",
    "Bagong Silang Campus",
];

$firstName = trim($_POST['firstName'] ?? '');
$lastName  = trim($_POST['lastName'] ?? '');
$campus    = trim($_POST['campus'] ?? '');

if ($firstName === '' || $lastName === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'First and last name are required.']);
    exit;
}
if ($campus !== '' && !in_array($campus, $campusOptions, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Please choose a valid campus.']);
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, campus = ? WHERE id = ?');
$stmt->execute([$firstName, $lastName, $campus, $_SESSION['user_id']]);

// Keep the session's display name in sync too.
$_SESSION['first_name'] = $firstName;
$_SESSION['last_name']  = $lastName;

echo json_encode(['success' => true]);
