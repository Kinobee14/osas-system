<?php
require "config.php";
/** @var PDO $pdo */

$errors = [];
$old = ["firstName" => "", "lastName" => "", "email" => "", "confirmEmail" => "", "campus" => ""];

// Keep this list in one place so the <select> options below and any
// server-side validation always agree with each other.
$campusOptions = [
    "Main Campus",
    "Congressional Extension Campus",
    "Camarin Extension Campus",
    "Bagong Silang Campus",
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $old["firstName"]    = trim($_POST["firstName"] ?? "");
    $old["lastName"]     = trim($_POST["lastName"] ?? "");
    $old["email"]        = trim($_POST["email"] ?? "");
    $old["confirmEmail"] = trim($_POST["confirmEmail"] ?? "");
    $old["campus"]       = trim($_POST["campus"] ?? "");
    $password            = $_POST["password"] ?? "";
    $confirmPassword     = $_POST["confirmPassword"] ?? "";

    if ($old["firstName"] === "" || $old["lastName"] === "" || $old["email"] === "" || $password === "") {
        $errors[] = "Please fill in all required fields.";
    } elseif (!filter_var($old["email"], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email address.";
    } elseif ($old["email"] !== $old["confirmEmail"]) {
        $errors[] = "Email addresses do not match.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    } elseif ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    } elseif ($old["campus"] !== "" && !in_array($old["campus"], $campusOptions, true)) {
        $errors[] = "Please choose a valid campus.";
    } else {
        $stmt = $pdo->prepare("SELECT id, is_verified FROM users WHERE email = ?");
        $stmt->execute([$old["email"]]);
        $existing = $stmt->fetch();

        if ($existing && $existing["is_verified"]) {
            $errors[] = "An account with that email already exists.";
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            if ($existing) {
                // Unverified account from a previous attempt — update instead of duplicating
                $stmt = $pdo->prepare(
                    "UPDATE users SET first_name = ?, last_name = ?, campus = ?, password_hash = ? WHERE email = ?"
                );
                $stmt->execute([$old["firstName"], $old["lastName"], $old["campus"], $passwordHash, $old["email"]]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO users (first_name, last_name, email, campus, password_hash, is_verified) VALUES (?, ?, ?, ?, ?, 0)"
                );
                $stmt->execute([$old["firstName"], $old["lastName"], $old["email"], $old["campus"], $passwordHash]);
            }

            $otp = generate_otp();
            // Expiry is computed by MySQL itself (NOW() + INTERVAL), not PHP's date(),
            // so it's always compared against the same clock later — avoids PHP/MySQL
            // timezone mismatches that make a brand-new code look already expired.
            $stmt = $pdo->prepare("INSERT INTO email_verifications (email, otp_code, expires_at) VALUES (?, ?, NOW() + INTERVAL 10 MINUTE)");
            $stmt->execute([$old["email"], $otp]);

            $mailSent = send_otp_email($old["email"], $otp, "signup");

            $redirect = "verify.php?purpose=signup&email=" . urlencode($old["email"]);
            if (!$mailSent) $redirect .= "&mail_failed=1";
            header("Location: $redirect");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account</title>
<?php render_styles(); ?>
</head>
<body>
    <div class="split">
        <?php render_side_hero(); ?>
        <div class="panel-form">
            <?php render_form_top(); ?>
            <div class="form-wrap">
                <h1>Create Account</h1>
                <p class="subtitle">Fill in your details below to get started.</p>

                <?php foreach ($errors as $err): ?>
                    <div class="message error"><?= h($err) ?></div>
                <?php endforeach; ?>

                <form method="POST" action="signup.php">
                    <div class="field-row">
                        <div class="field">
                            <label for="firstName">First Name</label>
                            <input type="text" id="firstName" name="firstName" value="<?= h($old["firstName"]) ?>" required>
                        </div>
                        <div class="field">
                            <label for="lastName">Last Name</label>
                            <input type="text" id="lastName" name="lastName" value="<?= h($old["lastName"]) ?>" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?= h($old["email"]) ?>" required>
                    </div>
                    <div class="field">
                        <label for="confirmEmail">Confirm Email Address</label>
                        <input type="email" id="confirmEmail" name="confirmEmail" value="<?= h($old["confirmEmail"]) ?>" required>
                    </div>
                    <div class="field">
                        <label for="campus">Campus</label>
                        <select id="campus" name="campus" required>
                            <option value="" disabled <?= $old["campus"] === "" ? "selected" : "" ?>>Select a campus</option>
                            <?php foreach ($campusOptions as $campus): ?>
                                <option value="<?= h($campus) ?>" <?= $old["campus"] === $campus ? "selected" : "" ?>><?= h($campus) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" minlength="8" required>
                    </div>
                    <div class="field">
                        <label for="confirmPassword">Confirm Password</label>
                        <input type="password" id="confirmPassword" name="confirmPassword" minlength="8" required>
                    </div>
                    <button type="submit" class="btn">Register Account</button>
                </form>

                <div class="inline-link">Already have an account? <a href="login.php">Sign in here</a></div>
            </div>
            <div class="footer-note">&copy; 2026 University of Caloocan City. All rights reserved.</div>
        </div>
    </div>
</body>
</html>