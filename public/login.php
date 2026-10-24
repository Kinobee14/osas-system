<?php
session_start();
require "config.php";
/** @var PDO $pdo */

$error = null;
$oldEmail = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $oldEmail = $email;

    if ($email === "" || $password === "") {
        $error = "Email and password are required.";
    } else {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, password_hash, is_verified, status FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !$user["password_hash"] || !password_verify($password, $user["password_hash"])) {
            // Same generic error whether the email doesn't exist or the password is wrong
            $error = "Invalid email or password.";
        } elseif (!$user["is_verified"]) {
            header("Location: verify.php?purpose=signup&email=" . urlencode($email));
            exit;
        } elseif ($user["status"] === "pending") {
            $error = "Your account is verified but still awaiting approval from OSAS. You'll be able to sign in once an admin approves it.";
        } elseif ($user["status"] === "rejected") {
            $error = "Your account request was not approved. Please contact OSAS if you think this is a mistake.";
        } elseif ($user["status"] === "inactive") {
            $error = "This account has been deactivated. Please contact OSAS for help.";
        } else {
            $_SESSION["user_id"]    = $user["id"];
            $_SESSION["first_name"] = $user["first_name"];
            $_SESSION["last_name"]  = $user["last_name"];
            $_SESSION["email"]      = $user["email"];
            header("Location: student.php");
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
<title>Sign In | SPARK - University of Caloocan City</title>
<?php render_styles(); ?>
<style>
.row { display: flex; justify-content: space-between; align-items: center; font-size: 14px; margin: 4px 0 18px; }
.row label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
.row input { width: 17px; height: 17px; accent-color: var(--g800); }
.row a { color: var(--g800); font-weight: 600; text-decoration: none; }
.row a:hover { text-decoration: underline; }
.pw { position: relative; }
.pw input { padding-right: 46px; }
.pw .eye { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: 0; cursor: pointer; display: flex; padding: 6px; }
.pw .eye svg { width: 20px; height: 20px; stroke: var(--g800); fill: none; stroke-width: 2; }
</style>
</head>
<body>
<div class="split">
    <?php render_side_hero(); ?>
    <div class="panel-form">
        <?php render_form_top(); ?>
        <div class="form-wrap">
            <h1>Sign in</h1>
            <p class="subtitle">Access your account to manage student programs, activities, and resources.</p>

            <?php if ($error): ?><div class="message error"><?= h($error) ?></div><?php endif; ?>
            <?php if (isset($_GET["reset"]) && $_GET["reset"] === "success"): ?><div class="message success">Your password has been reset. Please sign in.</div><?php endif; ?>
            <?php if (isset($_GET["awaiting"])): ?><div class="message success">Email verified! Your account now awaits approval from OSAS. We'll let you know once you can sign in.</div><?php endif; ?>
            <?php if (isset($_GET["verified"])): ?><div class="message success">Your account is verified. Please sign in.</div><?php endif; ?>

            <form method="POST" action="login.php">
                <div class="field">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="Enter your email address" value="<?= h($oldEmail) ?>" required>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <div class="pw">
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                        <button type="button" class="eye" aria-label="Show password" onclick="const p=document.getElementById('password');p.type=p.type==='password'?'text':'password'"><svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                </div>
                <div class="row">
                    <label><input type="checkbox" id="remember" name="remember"> Remember me</label>
                    <a href="forgot-password.php">Forgot Password?</a>
                </div>
                <button type="submit" class="btn">Sign In &rarr;</button>
            </form>

            <div class="inline-link">Don't have an account? <a href="signup.php">Register Here</a></div>
        </div>
        <div class="footer-note">&copy; 2026 University of Caloocan City. All rights reserved.</div>
    </div>
</div>
</body>
</html>
