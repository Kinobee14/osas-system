<?php
require "config.php";
/** @var PDO $pdo */

$purpose = ($_GET["purpose"] ?? $_POST["purpose"] ?? "signup") === "reset" ? "reset" : "signup";
$email   = trim($_GET["email"] ?? $_POST["email"] ?? "");
$error   = null;
$notice  = null;
$mailFailed = isset($_GET["mail_failed"]);

// Signup codes and reset codes now live in two separate tables (email_verifications,
// password_resets) instead of one shared table with a `purpose` column. $purpose is
// already restricted to "signup" or "reset" above, so this is safe to interpolate
// into SQL below — it's never used as a raw identifier from user input.
$otpTable = $purpose === "signup" ? "email_verifications" : "password_resets";

if ($email === "") {
    $error = "Missing email address. Please start over.";
}

// --- Resend code (triggered by a GET link: verify.php?...&resend=1) ---
if ($email !== "" && isset($_GET["resend"])) {
    $stmt = $pdo->prepare("SELECT id, is_verified FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && !($purpose === "signup" && $user["is_verified"])) {
        $otp = generate_otp();
        $stmt = $pdo->prepare("INSERT INTO $otpTable (email, otp_code, expires_at) VALUES (?, ?, NOW() + INTERVAL 10 MINUTE)");
        $stmt->execute([$email, $otp]);
        $mailSent = send_otp_email($email, $otp, $purpose);
        $mailFailed = !$mailSent;
    }
    $notice = "If that account exists, a new code has been sent.";
}

// --- Submit code ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && $email !== "") {
    $digits = $_POST["otp"] ?? [];
    $otp = implode("", array_map(fn($d) => preg_replace('/[^0-9]/', '', (string) $d), $digits));

    if (strlen($otp) !== 6) {
        $error = "Please enter all 6 digits.";
    } else {
        $stmt = $pdo->prepare(
            "SELECT id FROM $otpTable WHERE email = ? AND otp_code = ? AND used = 0 AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$email, $otp]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = "That code is invalid or has expired.";
        } else {
            $stmt = $pdo->prepare("UPDATE $otpTable SET used = 1 WHERE id = ?");
            $stmt->execute([$row["id"]]);

            if ($purpose === "signup") {
                $stmt = $pdo->prepare("UPDATE users SET is_verified = 1 WHERE email = ?");
                $stmt->execute([$email]);
                header("Location: login.php?awaiting=1");
                exit;
            } else {
                $token = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("INSERT INTO password_reset_tokens (email, token, expires_at) VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)");
                $stmt->execute([$email, $token]);
                header("Location: reset-password.php?email=" . urlencode($email) . "&token=" . $token);
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Code</title>
<?php render_styles(); ?>
</head>
<body>
    <div class="split">
        <?php render_side_hero(); ?>
        <div class="panel-form">
            <?php render_form_top(); ?>
            <div class="form-wrap">
                <h1>Enter the code we sent you</h1>
                <p class="subtitle">
                    <?= $purpose === "signup"
                        ? "We emailed a 6-digit code to " . h($email) . ". Enter it below to verify your account."
                        : "We emailed a 6-digit code to " . h($email) . ". Enter it below to continue resetting your password."
                    ?>
                </p>

                <?php if ($error): ?><div class="message error"><?= h($error) ?></div><?php endif; ?>
                <?php if ($notice): ?><div class="message success"><?= h($notice) ?></div><?php endif; ?>

                <form method="POST" action="verify.php">
                    <input type="hidden" name="purpose" value="<?= h($purpose) ?>">
                    <input type="hidden" name="email" value="<?= h($email) ?>">
                    <div class="otp-group">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <input type="text" class="otp-box" name="otp[]" maxlength="1" inputmode="numeric" autocomplete="one-time-code">
                        <?php endfor; ?>
                    </div>
                    <button type="submit" class="btn">Verify</button>
                </form>

                <div class="inline-link">
                    Didn't get a code?
                    <a href="verify.php?purpose=<?= h($purpose) ?>&email=<?= urlencode($email) ?>&resend=1">Resend code</a>
                </div>

                <?php if ($mailFailed): ?>
                    <div class="message error">
                        We couldn't send that email right now. Please try "Resend code" in a moment, or contact support if this keeps happening.
                    </div>
                <?php endif; ?>
            </div>
            <div class="footer-note">&copy; 2026 University of Caloocan City. All rights reserved.</div>
        </div>
    </div>

    <script>
        // Small UX enhancement: auto-advance between OTP boxes. The form still
        // works fine without JS since inputs are plain named fields (otp[]).
        const boxes = Array.from(document.querySelectorAll(".otp-box"));
        boxes.forEach((box, i) => {
            box.addEventListener("input", () => {
                box.value = box.value.replace(/[^0-9]/g, "");
                if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
            });
            box.addEventListener("keydown", (e) => {
                if (e.key === "Backspace" && !box.value && i > 0) boxes[i - 1].focus();
            });
            box.addEventListener("paste", (e) => {
                e.preventDefault();
                const digits = (e.clipboardData.getData("text") || "").replace(/[^0-9]/g, "").split("");
                digits.forEach((d, idx) => { if (boxes[idx]) boxes[idx].value = d; });
                const next = boxes[Math.min(digits.length, boxes.length - 1)];
                if (next) next.focus();
            });
        });
        if (boxes[0]) boxes[0].focus();
    </script>
</body>
</html>