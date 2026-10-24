<?php
require "config.php";
/** @var PDO $pdo */

$email = trim($_GET["email"] ?? $_POST["email"] ?? "");
$token = trim($_GET["token"] ?? $_POST["token"] ?? "");
$error = null;

if ($email === "" || $token === "") {
    $error = "This reset link is missing information. Please request a new code.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $email !== "" && $token !== "") {
    $newPassword     = $_POST["newPassword"] ?? "";
    $confirmPassword = $_POST["confirmPassword"] ?? "";

    if (strlen($newPassword) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $pdo->prepare(
            "SELECT id FROM password_reset_tokens
             WHERE email = ? AND token = ? AND used = 0 AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$email, $token]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = "This reset link is invalid or has expired. Please request a new code.";
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
            $stmt->execute([$passwordHash, $email]);

            $stmt = $pdo->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = ?");
            $stmt->execute([$row["id"]]);

            header("Location: login.php?reset=success");
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
<title>Set New Password</title>
<?php render_styles(); ?>
</head>
<body>
    <div class="split">
        <?php render_side_hero(); ?>
        <div class="panel-form">
            <?php render_form_top(); ?>
            <div class="form-wrap">
                <h1>Set a new password</h1>
                <p class="subtitle">Choose a new password for your account.</p>

                <?php if ($error): ?><div class="message error"><?= h($error) ?></div><?php endif; ?>

                <form method="POST" action="reset-password.php">
                    <input type="hidden" name="email" value="<?= h($email) ?>">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <div class="field">
                        <label for="newPassword">New Password</label>
                        <input type="password" id="newPassword" name="newPassword" minlength="8" required>
                    </div>
                    <div class="field">
                        <label for="confirmPassword">Confirm New Password</label>
                        <input type="password" id="confirmPassword" name="confirmPassword" minlength="8" required>
                    </div>
                    <button type="submit" class="btn">Reset Password</button>
                </form>

                <div class="inline-link"><a href="login.php">Back to Sign in</a></div>
            </div>
            <div class="footer-note">&copy; 2026 University of Caloocan City. All rights reserved.</div>
        </div>
    </div>
</body>
</html>