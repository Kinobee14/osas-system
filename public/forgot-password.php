<?php
require "config.php";
/** @var PDO $pdo */

$error = null;
$oldEmail = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $oldEmail = trim($_POST["email"] ?? "");

    if ($oldEmail === "" || !filter_var($oldEmail, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND is_verified = 1");
        $stmt->execute([$oldEmail]);
        $user = $stmt->fetch();

        $mailFailed = false;
        if ($user) {
            $otp = generate_otp();
            $stmt = $pdo->prepare("INSERT INTO password_resets (email, otp_code, expires_at) VALUES (?, ?, NOW() + INTERVAL 10 MINUTE)");
            $stmt->execute([$oldEmail, $otp]);
            $mailSent = send_otp_email($oldEmail, $otp, "reset");
            $mailFailed = !$mailSent;
        }

        // Redirect to the verify page regardless of whether the account exists,
        // so this form can't be used to check which emails are registered.
        $redirect = "verify.php?purpose=reset&email=" . urlencode($oldEmail);
        if ($mailFailed) $redirect .= "&mail_failed=1";
        header("Location: $redirect");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password</title>
<?php render_styles(); ?>
</head>
<body>
    <div class="split">
        <?php render_side_hero(); ?>
        <div class="panel-form">
            <?php render_form_top(); ?>
            <div class="form-wrap">
                <h1>Forgot password?</h1>
                <p class="subtitle">Enter the email associated with your account and we'll send you a code to reset your password.</p>

                <?php if ($error): ?><div class="message error"><?= h($error) ?></div><?php endif; ?>

                <form method="POST" action="forgot-password.php">
                    <div class="field">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?= h($oldEmail) ?>" required>
                    </div>
                    <button type="submit" class="btn">Send Code</button>
                </form>

                <div class="inline-link">Remember your password? <a href="login.php">Sign in</a></div>
            </div>
            <div class="footer-note">&copy; 2026 University of Caloocan City. All rights reserved.</div>
        </div>
    </div>
</body>
</html>