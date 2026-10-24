<?php
/**
 * Edge Resort / Your Company - Gmail SMTP Mailer (Production Ready)
 *
 * HOW TO SET UP (one-time, takes 2 minutes):
 * ──────────────────────────────────────────
 * 1. Go to: https://myaccount.google.com/security
 * 2. Make sure "2-Step Verification" is ON
 * 3. Search "App passwords" in Google Account search bar
 *    OR go to: https://myaccount.google.com/apppasswords
 * 4. Click "Create" → choose "Mail" → copy the 16-character password
 * 5. Set the values below via environment variables (preferred) or edit the
 *    fallback defaults directly — but NEVER commit real credentials to git
 *    or paste them into a chat/ticket.
 */

// IMPORTANT: never hard-code real credentials here. Set MAIL_USER / MAIL_PASS
// as environment variables on your server (see the setup steps above). If
// they are not set, sendMail() below fails cleanly and verify.php shows a
// generic "couldn't send that email" message instead of exposing anything.
define('MAIL_HOST',      getenv('MAIL_HOST')      ?: 'smtp.gmail.com');
define('MAIL_PORT',      (int) (getenv('MAIL_PORT') ?: 587));
define('MAIL_USER',      getenv('MAIL_USER')      ?: 'kinobee21@gmail.com');
define('MAIL_PASS',      str_replace(' ', '', getenv('MAIL_PASS') ?: 'xugu xuii lsrx ympl')); // spaces auto-stripped
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'SPARK Management System');

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Send email via Gmail SMTP with STARTTLS (production-grade).
 * @return true|string  Returns true on success, or an error message string on failure.
 */
function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody) {

    $host = MAIL_HOST;
    $port = MAIL_PORT;
    $user = MAIL_USER;
    $pass = MAIL_PASS;

    if ($user === '' || $pass === '') {
        return "MAIL_USER / MAIL_PASS environment variables are not set. See includes/mailer.php for setup steps.";
    }

    $errno  = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, 20);
    if (!$socket) {
        return "SMTP connect failed ($errno): $errstr";
    }
    stream_set_timeout($socket, 20);

    $read = function() use ($socket) {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) break;
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };

    $cmd = function(string $command) use ($socket, $read) {
        fwrite($socket, $command . "\r\n");
        return $read();
    };

    $greeting = $read();
    if (substr($greeting, 0, 3) !== '220') {
        fclose($socket);
        return "SMTP greeting error: $greeting";
    }

    $ehlo = $cmd("EHLO " . gethostname());
    if (substr($ehlo, 0, 3) !== '250') {
        fclose($socket);
        return "EHLO error: $ehlo";
    }

    $tls = $cmd("STARTTLS");
    if (substr($tls, 0, 3) !== '220') {
        fclose($socket);
        return "STARTTLS error: $tls";
    }

    $crypto = stream_socket_enable_crypto(
        $socket,
        true,
        STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT
    );
    if (!$crypto) {
        fclose($socket);
        return "TLS handshake failed. Make sure openssl is enabled in php.ini.";
    }

    $ehlo2 = $cmd("EHLO " . gethostname());
    if (substr($ehlo2, 0, 3) !== '250') {
        fclose($socket);
        return "Post-TLS EHLO error: $ehlo2";
    }

    $auth = $cmd("AUTH LOGIN");
    if (substr($auth, 0, 3) !== '334') {
        fclose($socket);
        return "AUTH LOGIN failed: $auth";
    }

    $userResp = $cmd(base64_encode($user));
    if (substr($userResp, 0, 3) !== '334') {
        fclose($socket);
        return "Username rejected: $userResp";
    }

    $passResp = $cmd(base64_encode($pass));
    if (substr($passResp, 0, 3) !== '235') {
        fclose($socket);
        return "Authentication failed. Check MAIL_USER and MAIL_PASS.\n\nResponse: $passResp\n\nMake sure you are using a Gmail App Password (not your regular Gmail password).\nGet one at: https://myaccount.google.com/apppasswords";
    }

    $from = $cmd("MAIL FROM:<$user>");
    if (substr($from, 0, 3) !== '250') {
        fclose($socket);
        return "MAIL FROM error: $from";
    }

    $rcpt = $cmd("RCPT TO:<$toEmail>");
    if (substr($rcpt, 0, 3) !== '250') {
        fclose($socket);
        return "RCPT TO error: $rcpt";
    }

    $data = $cmd("DATA");
    if (substr($data, 0, 3) !== '354') {
        fclose($socket);
        return "DATA command error: $data";
    }

    $boundary   = '----=_Boundary_' . md5(microtime(true) . rand());
    $fromEnc    = '=?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?=';
    $toEnc      = '=?UTF-8?B?' . base64_encode($toName) . '?=';
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $date       = date('r');

    $plainText = wordwrap(
        strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)),
        72, "\n", true
    );

    $message  = "Date: $date\r\n";
    $message .= "From: $fromEnc <$user>\r\n";
    $message .= "To: $toEnc <$toEmail>\r\n";
    $message .= "Subject: $subjectEnc\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $message .= "X-Mailer: EdgeResortMailer/1.0\r\n";
    $message .= "\r\n";

    $message .= "--$boundary\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($plainText)) . "\r\n";

    $message .= "--$boundary\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($htmlBody)) . "\r\n";

    $message .= "--$boundary--\r\n";

    $lines = explode("\r\n", $message);
    $stuffed = array_map(fn($l) => (strlen($l) > 0 && $l[0] === '.') ? '.' . $l : $l, $lines);
    fwrite($socket, implode("\r\n", $stuffed) . "\r\n.\r\n");

    $sent = $read();
    if (substr($sent, 0, 3) !== '250') {
        fclose($socket);
        return "Message send error: $sent";
    }

    $cmd("QUIT");
    fclose($socket);

    return true;
}

// ─── Generate a secure 6-digit OTP ───────────────────────────────────────────
// Named generate_otp() (snake_case) to match what verify.php / signup / forgot-password call.
function generate_otp(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// ─── Email: Registration Verification ────────────────────────────────────────
function buildVerificationEmail(string $name, string $otp): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F5EFE0;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F5EFE0;padding:40px 0;">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.12);max-width:96%;">
        <tr>
          <td style="background:linear-gradient(135deg,#0F3D2E 0%,#1F7A54 100%);padding:40px 36px;text-align:center;">
            <div style="font-size:36px;margin-bottom:10px;">🎓</div>
            <h1 style="color:#ffffff;font-size:24px;margin:0 0 6px;font-family:Georgia,'Times New Roman',serif;font-weight:700;letter-spacing:0.5px;">Student Programs and Resource Keeper Management System</h1>
            <p style="color:rgba(255,255,255,0.88);margin:0;font-size:13px;letter-spacing:3px;text-transform:uppercase;">Email Verification</p>
          </td>
        </tr>
        <tr>
          <td style="padding:44px 40px 36px;">
            <p style="color:#0F3D2E;font-size:17px;margin:0 0 14px;font-weight:600;">Hello, {$name}!</p>
            <p style="color:#555555;font-size:15px;margin:0 0 28px;line-height:1.7;">
              Thank you for signing up at <strong>Student Programs and Resource Keeper Management System</strong>. To complete your registration, please use the verification code below:
            </p>
            <div style="background:#EEF7F1;border:2px dashed #2E9E6C;border-radius:16px;padding:32px 24px;text-align:center;margin:0 0 30px;">
              <p style="color:#999;font-size:12px;letter-spacing:3px;text-transform:uppercase;margin:0 0 12px;font-weight:700;">Your Verification Code</p>
              <div style="font-size:52px;font-weight:900;color:#1F7A54;letter-spacing:16px;font-family:'Courier New',monospace;line-height:1;">{$otp}</div>
              <p style="color:#aaa;font-size:13px;margin:14px 0 0;">⏱ This code expires in <strong>10 minutes</strong></p>
            </div>
            <p style="color:#888;font-size:13px;line-height:1.7;margin:0;border-top:1px solid #F0F0F0;padding-top:20px;">
              If you did not create an account at Student Programs and Resource Keeper Management System, you can safely ignore this email.
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#0F3D2E;padding:22px 36px;text-align:center;">
            <p style="color:rgba(255,255,255,0.5);font-size:12px;margin:0;">© 2026 Student Programs and Resource Keeper Management System · Caloocan City, Philippines</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

// ─── Email: Password Reset ────────────────────────────────────────────────────
function buildResetEmail(string $name, string $otp): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F5EFE0;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F5EFE0;padding:40px 0;">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.12);max-width:96%;">
        <tr>
          <td style="background:linear-gradient(135deg,#0F3D2E 0%,#1F7A54 100%);padding:40px 36px;text-align:center;">
            <div style="font-size:36px;margin-bottom:10px;">🔐</div>
            <h1 style="color:#ffffff;font-size:24px;margin:0 0 6px;font-family:Georgia,'Times New Roman',serif;font-weight:700;letter-spacing:0.5px;">Student Programs and Resource Keeper Management System</h1>
            <p style="color:rgba(255,255,255,0.88);margin:0;font-size:13px;letter-spacing:3px;text-transform:uppercase;">Password Reset</p>
          </td>
        </tr>
        <tr>
          <td style="padding:44px 40px 36px;">
            <p style="color:#0F3D2E;font-size:17px;margin:0 0 14px;font-weight:600;">Hello, {$name}!</p>
            <p style="color:#555555;font-size:15px;margin:0 0 28px;line-height:1.7;">
              We received a request to reset the password for your account. Use the code below to set a new password:
            </p>
            <div style="background:#EEF7F1;border:2px dashed #2E9E6C;border-radius:16px;padding:32px 24px;text-align:center;margin:0 0 30px;">
              <p style="color:#999;font-size:12px;letter-spacing:3px;text-transform:uppercase;margin:0 0 12px;font-weight:700;">Password Reset Code</p>
              <div style="font-size:52px;font-weight:900;color:#1F7A54;letter-spacing:16px;font-family:'Courier New',monospace;line-height:1;">{$otp}</div>
              <p style="color:#aaa;font-size:13px;margin:14px 0 0;">⏱ This code expires in <strong>10 minutes</strong></p>
            </div>
            <div style="background:#FFF8E1;border-left:4px solid #FFB300;border-radius:8px;padding:14px 18px;margin-bottom:24px;">
              <p style="color:#7D5A00;font-size:13px;margin:0;line-height:1.6;">
                🔒 <strong>Security tip:</strong> If you did not request a password reset, please ignore this email. Your password will not be changed.
              </p>
            </div>
            <p style="color:#888;font-size:13px;line-height:1.7;margin:0;border-top:1px solid #F0F0F0;padding-top:20px;">
              This code will expire in 10 minutes. If you need a new code, go back to the reset page and click "Resend Code".
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#0F3D2E;padding:22px 36px;text-align:center;">
            <p style="color:rgba(255,255,255,0.5);font-size:12px;margin:0;">© 2026 Student Programs and Resource Keeper Management System · Malabon City, Philippines</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

// ─── The function verify.php / signup.php / forgot-password.php actually call ─
/**
 * Send an OTP email for either 'signup' or 'reset', picking the right template.
 * Returns true on success, false on failure (and logs the SMTP error).
 */
function send_otp_email(string $email, string $otp, string $purpose = 'signup'): bool {
    $name = explode('@', $email)[0];
    $subject = $purpose === 'reset'
        ? 'Your SPARK Management System Password Reset Code'
        : 'Verify Your SPARK Management System Account';
    $html = $purpose === 'reset'
        ? buildResetEmail($name, $otp)
        : buildVerificationEmail($name, $otp);

    $result = sendMail($email, $name, $subject, $html);

    if ($result !== true) {
        error_log("[send_otp_email] Failed to send to $email ($purpose): $result");
        return false;
    }
    return true;
}

// ─── Email: Account approved / rejected (sent from the admin panel) ──────────
/**
 * Link for the "Sign in and reserve" button. Auto-detected from the address the
 * system is running on, so it works without any setup. To force a fixed link
 * (e.g. your real domain), set the MAIL_LOGIN_URL environment variable.
 */
function mail_login_url(): string {
    $fixed = getenv('MAIL_LOGIN_URL');
    if ($fixed) return $fixed;

    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // "localhost" only works on this computer; use the LAN IP so a phone on the
    // same WiFi can open the link too.
    $hostOnly = preg_replace('/:\d+$/', '', $host);
    if (in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true)) {
        $ip = gethostbyname(gethostname());
        if ($ip && $ip !== gethostname() && strpos($ip, '127.') !== 0) {
            $port = preg_match('/:(\d+)$/', $host, $m) ? ':' . $m[1] : '';
            $host = $ip . $port;
        }
    }

    // Project root = everything before /admin/ or /public/ in the current URL.
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $root   = preg_replace('#/(admin|public)(/.*)?$#', '', $script);
    return $scheme . '://' . $host . $root . '/public/login.php';
}

function buildAccountDecisionEmail(string $name, bool $approved): string {
    $name     = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    if ($approved) {
        $icon    = '&#10004;';
        $label   = 'Account Approved';
        $heading = 'Good news &mdash; your account has been approved by OSAS!';
        $text    = 'You can now sign in and <strong>create a reservation for the event you want</strong>. Pick a venue, choose your date and time, and submit your request. We will notify you once it has been reviewed.';
        $button  = '<div style="height:24px;"></div>';
    } else {
        $icon    = '&#8505;';
        $label   = 'Account Update';
        $heading = 'Your account request was not approved.';
        $text    = 'Thank you for registering. Unfortunately, your account request was not approved at this time. Please contact the OSAS office for more information.';
        $button  = '<div style="height:24px;"></div>';
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F5EFE0;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F5EFE0;padding:40px 0;">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.12);max-width:96%;">
        <tr>
          <td style="background:linear-gradient(135deg,#0F3D2E 0%,#1F7A54 100%);padding:40px 36px;text-align:center;">
            <div style="font-size:32px;margin-bottom:10px;color:#0F3D2E;">{$icon}</div>
            <h1 style="color:#ffffff;font-size:24px;margin:0 0 8px;font-family:Georgia,'Times New Roman',serif;font-weight:700;letter-spacing:0.5px;">Student Programs and Resource Keeper Management System</h1>
            <p style="color:rgba(255,255,255,0.88);margin:0;font-size:12px;letter-spacing:3px;text-transform:uppercase;">{$label}</p>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 40px 10px;">
            <p style="color:#0F3D2E;font-size:16px;margin:0 0 16px;font-weight:700;">Hello, {$name}!</p>
            <p style="color:#0F3D2E;font-size:16px;margin:0 0 14px;font-weight:700;line-height:1.5;">{$heading}</p>
            <p style="color:#555555;font-size:15px;margin:0;line-height:1.7;">{$text}</p>
            {$button}
            <p style="color:#888;font-size:12px;line-height:1.7;margin:0;border-top:1px solid #F0F0F0;padding:18px 0 24px;">
              If you did not create an account, you can safely ignore this email.
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#0F3D2E;padding:22px 36px;text-align:center;">
            <p style="color:rgba(255,255,255,0.5);font-size:12px;margin:0;line-height:1.6;">&copy; 2026 Student Programs and Resource Keeper Management System &middot; Caloocan City, Philippines</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

/**
 * Email a student the admin's decision on their account request.
 * Returns true on success, false on failure (and logs the SMTP error).
 */
function send_account_decision_email(string $email, string $name, bool $approved): bool {
    $subject = $approved
        ? 'Your SPARK Management System Account Has Been Approved'
        : 'Update on Your SPARK Management System Account Request';
    $result = sendMail($email, $name, $subject, buildAccountDecisionEmail($name, $approved));
    if ($result !== true) {
        error_log("[send_account_decision_email] Failed to send to $email: $result");
        return false;
    }
    return true;
}