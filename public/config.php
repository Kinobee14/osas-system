<?php
// Force PHP to use UTC, and (below) tell MySQL to use UTC for this connection too.
// Without this, PHP's date() and MySQL's NOW() can disagree on what time it is,
// which is what makes a freshly created OTP look already expired.
date_default_timezone_set("UTC");

// Database connection settings — update these for your environment, or set
// them as environment variables (handy if you deploy the /public and /admin
// folders to different servers but share one MySQL instance).
$DB_HOST = getenv("DB_HOST") ?: "localhost";
$DB_NAME = getenv("DB_NAME") ?: "osas_db";
$DB_USER = getenv("DB_USER") ?: "root";
$DB_PASS = getenv("DB_PASS") ?: "";

// OTP codes are always sent by real email now (via includes/mailer.php / Gmail SMTP) —
// there is no on-screen "demo" display of the code anymore.

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed. Check the settings in config.php.");
}

// Match MySQL's session clock to PHP's (UTC), so NOW() and date() never disagree.
$pdo->exec("SET time_zone = '+00:00'");

/** Shorthand for htmlspecialchars() to keep templates readable. */
function h(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// includes/mailer.php provides generate_otp(), send_otp_email(), and the
// Gmail SMTP sending logic itself (sendMail(), buildVerificationEmail(),
// buildResetEmail()). It reads MAIL_USER / MAIL_PASS from environment
// variables — see includes/mailer.php's header comment for setup steps.
require_once __DIR__ . "/includes/mailer.php";

/**
 * Prints the shared <style> block used by every page. Kept in one place so the
 * look stays consistent, but each page still renders as one self-contained
 * HTML+CSS+PHP response — this is just where the CSS text lives.
 */
function render_styles(): void {
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&family=Cormorant+Garamond:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{--g900:#0b3d24;--g800:#0f5132;--g700:#13693f;--g500:#2fb565;--cream:#f4efdf;--line:#e2ddcc;--ink:#173a2a;--muted:#5d6b62}
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body { min-height: 100vh; background: var(--cream); color: var(--ink); font-family: 'Lexend', 'Segoe UI', Arial, sans-serif; }
.split { display: grid; grid-template-columns: 50% 50%; min-height: 100vh; }

/* ================= LEFT HERO (identical on every page) ================= */
.panel-image { position: relative; overflow: hidden; color: #fff; padding: 48px 56px 40px; display: flex; flex-direction: column; background: var(--g900); }
.panel-image > * { position: relative; z-index: 2; }
.panel-image > .hero-bg { position: absolute; inset: 0; z-index: 0; overflow: hidden; }
.hero-bg::before { content: ""; position: absolute; inset: 0; background: url("pictures/UCC building.jpg") center/cover no-repeat; animation: kenburns 28s ease-in-out infinite alternate; will-change: transform; }
.hero-bg::after { content: ""; position: absolute; inset: 0; background: linear-gradient(160deg, rgba(5,60,32,.93) 0%, rgba(10,100,55,.80) 55%, rgba(10,100,55,.62) 100%); }
.panel-image > .shape { position: absolute; z-index: 1; border-radius: 48px; pointer-events: none; }
.shape.s1 { width: 520px; height: 220px; left: -190px; bottom: 40px; transform: rotate(-38deg); background: linear-gradient(90deg, rgba(47,181,101,.55), rgba(47,181,101,0)); }
.shape.s2 { width: 520px; height: 90px; left: -230px; bottom: 150px; transform: rotate(-38deg); background: linear-gradient(90deg, rgba(126,226,160,.30), rgba(126,226,160,0)); }
.shape.s3 { width: 260px; height: 260px; left: -90px; top: -120px; transform: rotate(45deg); background: linear-gradient(135deg, rgba(47,181,101,.45), rgba(47,181,101,0) 70%); }
.shape.s4 { width: 380px; height: 160px; right: -150px; bottom: -40px; transform: rotate(-38deg); background: linear-gradient(270deg, rgba(47,181,101,.55), rgba(47,181,101,0)); }
@keyframes kenburns { from { transform: scale(1); } to { transform: scale(1.12) translate(-1.5%, -1%); } }
.uni { display: flex; align-items: center; gap: 16px; }
.uni img { width: 80px; height: auto; filter: drop-shadow(0 4px 10px rgba(0,0,0,.3)); }
.uni .txt { border-left: 2px solid rgba(255,255,255,.6); padding-left: 16px; }
.uni h2 { font-family: 'Cormorant Garamond', serif; font-size: 32px; line-height: 1.05; letter-spacing: .5px; text-transform: uppercase; font-weight: 600; }
.uni small { display: block; margin-top: 8px; font-size: 10px; letter-spacing: 3.5px; opacity: .92; }
.hero-mid { margin: auto 0; padding: 24px 0; }
.lockup { display: flex; align-items: center; gap: 18px; }
.lockup img { width: 118px; height: auto; filter: drop-shadow(0 6px 18px rgba(0,0,0,.35)); }
.lockup b { display: block; font-size: 76px; line-height: .95; font-weight: 700; letter-spacing: 1px; }
.lockup span { display: block; font-size: 17px; line-height: 1.35; margin-top: 8px; opacity: .95; }
.tagline { font-size: 25px; font-style: italic; font-weight: 400; margin-top: 44px; }
.lead { font-size: 16px; line-height: 1.75; margin-top: 12px; max-width: 420px; opacity: .95; }
.features { display: grid; grid-template-columns: repeat(4, 1fr); }
.feat { text-align: center; padding: 0 8px; border-left: 1px solid rgba(255,255,255,.28); }
.feat:first-child { border-left: 0; }
.feat svg { width: 36px; height: 36px; stroke: #7ee2a0; fill: none; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
.feat b { display: block; font-size: 14px; font-weight: 600; margin-top: 8px; }
.feat span { display: block; font-size: 11px; opacity: .8; margin-top: 3px; }

/* ================= RIGHT FORM SIDE ================= */
.panel-form { position: relative; overflow: hidden; background: var(--cream); display: flex; flex-direction: column; align-items: center; padding: 36px clamp(28px, 6vw, 80px) 20px; }
.panel-form::before { content: ""; position: absolute; top: -170px; right: -170px; width: 280px; height: 280px; border-radius: 48px; transform: rotate(45deg); z-index: 0; pointer-events: none;
  background: linear-gradient(135deg, var(--g800), #138a52); box-shadow: -26px 26px 0 -6px rgba(47,181,101,.28), -52px 52px 0 -12px rgba(47,181,101,.14); }
.panel-form > * { position: relative; z-index: 1; }
.form-top { display: flex; align-items: center; gap: 22px; padding-bottom: 24px; border-bottom: 1px solid var(--line); width: 100%; max-width: 640px; }
.form-top img { width: 104px; height: auto; }
.form-top b { font-size: 60px; line-height: 1; font-weight: 700; color: var(--g800); letter-spacing: 1px; }
.form-top span { border-left: 2px solid var(--g800); padding-left: 22px; font-size: 16px; line-height: 1.45; color: var(--g800); }
.form-wrap { width: 100%; max-width: 640px; margin: auto 0; padding: 26px 0; }
.form-wrap h1 { font-family: 'Cormorant Garamond', serif; font-size: 44px; line-height: 1.05; color: var(--g900); margin-bottom: 8px; font-weight: 700; }
.form-wrap .subtitle { font-size: 15px; color: var(--muted); margin-bottom: 24px; line-height: 1.55; max-width: 440px; }
.field { margin-bottom: 18px; }
.field-row { display: flex; gap: 16px; }
.field-row .field { flex: 1; }
.field label { display: block; font-size: 12.5px; font-weight: 600; letter-spacing: .4px; color: var(--ink); margin-bottom: 8px; text-transform: uppercase; }
.field input, .field select { width: 100%; height: 52px; padding: 0 16px; border: 1px solid var(--line); border-radius: 8px; font: inherit; font-size: 15px; color: var(--ink); outline: none; background-color: #fbfaf4; background-repeat: no-repeat; background-position: 14px center; background-size: 20px; transition: .2s; }
.field input[type=email], .field input[type=password], .field input[type=text], .field select { padding-left: 46px; }
.field input[type=email] { background-image: url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230f5132' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='5' width='18' height='14' rx='2'/><path d='m3 7 9 6 9-6'/></svg>"); }
.field input[type=password] { background-image: url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230f5132' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='5' y='11' width='14' height='10' rx='2'/><path d='M8 11V7a4 4 0 0 1 8 0v4'/></svg>"); }
.field .pw input { background-image: url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230f5132' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='5' y='11' width='14' height='10' rx='2'/><path d='M8 11V7a4 4 0 0 1 8 0v4'/></svg>"); }
.field input[type=text] { background-image: url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230f5132' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='8' r='4'/><path d='M4 21c0-4 4-6 8-6s8 2 8 6'/></svg>"); }
.field select { background-image: url("data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230f5132' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M3 21h18M5 21V8l7-4 7 4v13'/><path d='M9 21v-6h6v6M9 10h.01M15 10h.01'/></svg>"); }
.field input:focus, .field select:focus { border-color: var(--g700); box-shadow: 0 0 0 3px rgba(19,105,63,.12); }
.checkbox-row { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; font-size: 14px; color: var(--ink); }
.checkbox-row input { width: auto; }
.btn { width: 100%; height: 56px; border: none; border-radius: 8px; color: #fff; font: 600 16px 'Lexend', sans-serif; letter-spacing: .5px; text-transform: uppercase; cursor: pointer; margin-top: 6px;
  background: linear-gradient(90deg, var(--g800), #138a52); box-shadow: 0 8px 20px -8px rgba(15,81,50,.6); transition: .2s; }
.btn:hover { filter: brightness(1.1); transform: translateY(-1px); }
.btn:disabled { filter: grayscale(.6); opacity: .6; cursor: not-allowed; transform: none; }
.links-row { display: flex; justify-content: space-between; margin-top: 16px; font-size: 14px; }
.links-row a, .inline-link a, .switch-link a { color: var(--g800); text-decoration: none; font-weight: 600; }
.links-row a:hover, .inline-link a:hover, .switch-link a:hover { text-decoration: underline; }
.switch-link { font-size: 14px; color: var(--muted); margin-top: 4px; }
.inline-link { text-align: center; font-size: 14.5px; color: var(--muted); margin-top: 20px; }
.footer-note { width: 100%; max-width: 640px; text-align: right; font-size: 11px; color: var(--muted); padding-top: 12px; }
.message { margin-bottom: 16px; padding: 12px 14px; border-radius: 8px; font-size: 14px; line-height: 1.45; }
.message.error { background: #fdecec; color: #9b1c1c; border: 1px solid #f5c2c2; }
.message.success { background: #e7f6ec; color: var(--g800); border: 1px solid #b9e3c7; }
.otp-group { display: flex; gap: 12px; margin-bottom: 22px; }
.otp-box { width: 62px; height: 66px; text-align: center; font: 600 24px 'Lexend', sans-serif; color: var(--g900); border: 1px solid var(--line); border-radius: 10px; background: #fbfaf4; outline: none; transition: .2s; }
.otp-box:focus { border-color: var(--g700); box-shadow: 0 0 0 3px rgba(19,105,63,.12); }
@media (prefers-reduced-motion: reduce) { .hero-bg::before { animation: none; } }
@media (max-width: 1600px) { .form-top { gap: 16px; } .form-top img { width: 78px; } .form-top b { font-size: 46px; } .form-top span { font-size: 13.5px; padding-left: 16px; flex: 1; min-width: 0; } }
@media (max-width: 1250px) { .lockup b { font-size: 60px; } .lockup img { width: 92px; } .panel-image { padding: 40px 34px 34px; } }
@media (max-width: 1100px) { .features { grid-template-columns: repeat(2, 1fr); row-gap: 18px; } .feat:nth-child(3) { border-left: 0; } }
@media (max-width: 900px) {
    .split { grid-template-columns: 1fr; }
    .panel-image { padding: 26px 22px; }
    .hero-mid { padding: 22px 0 6px; } .tagline { margin-top: 20px; font-size: 20px; } .lead, .features { display: none; }
    .lockup img { width: 64px; } .lockup b { font-size: 42px; } .lockup span { font-size: 13px; }
    .panel-form { padding: 28px 22px 18px; }
}
@media (max-width: 480px) {
    .uni img { width: 56px; } .uni h2 { font-size: 21px; }
    .form-top { gap: 14px; } .form-top img { width: 56px; } .form-top b { font-size: 34px; } .form-top span { display: none; }
    .form-wrap h1 { font-size: 36px; }
    .field-row { flex-direction: column; gap: 0; }
    .field input, .field select, .btn { font-size: 16px; }
    .links-row { flex-direction: column; gap: 8px; text-align: center; }
    .otp-group { justify-content: space-between; gap: 6px; }
    .otp-box { flex: 1 1 0; width: auto; min-width: 0; height: 52px; font-size: 18px; }
    .footer-note { text-align: center; font-size: 10px; }
}
</style>
<?php
}

/** Left-side hero. Identical on every customer page so they stay consistent. (Args kept for old callers; ignored.) */
function render_side_hero(string $eyebrow = "", string $headline = "", string $lead = ""): void {
?>
<div class="panel-image">
    <div class="hero-bg" aria-hidden="true"></div>
    <span class="shape s1"></span><span class="shape s2"></span><span class="shape s3"></span><span class="shape s4"></span>
    <div class="uni">
        <img src="pictures/ucc_logo.png" alt="University of Caloocan City logo">
        <div class="txt"><h2>University of<br>Caloocan City</h2><small>DISCIPLINE &bull; EXCELLENCE &bull; SERVICE</small></div>
    </div>
    <div class="hero-mid">
        <div class="lockup">
            <img src="pictures/spark_logo.png" alt="SPARK logo">
            <div><b>SPARK</b><span>Student Programs and Activities<br>Resource Keeper Management System</span></div>
        </div>
        <p class="tagline">Organize. Support. Empower.</p>
        <p class="lead">A smarter way to manage student programs and activities at UCC.</p>
    </div>
    <div class="features">
        <div class="feat"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><b>Programs</b><span>Plan &amp; Track</span></div>
        <div class="feat"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.4"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5M15.5 14.8c3 0 5.5 1.7 5.5 4.7"/></svg><b>Activities</b><span>Engage &amp; Connect</span></div>
        <div class="feat"><svg viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h8M9 17h8"/></svg><b>Resources</b><span>Manage &amp; Allocate</span></div>
        <div class="feat"><svg viewBox="0 0 24 24"><path d="M4 21V14M9 21v-5M14 21v-8M19 21V9"/><path d="m4 9 5-4 4 3 6-5"/></svg><b>Student Success</b><span>Build a Better Tomorrow</span></div>
    </div>
</div>
<?php
}

/** SPARK header on the form side (same on every page). */
function render_form_top(): void {
?>
<div class="form-top">
    <img src="pictures/spark_logo.png" alt="SPARK logo">
    <b>SPARK</b>
    <span>Student Programs and<br>Activities Resource Keeper<br>Management System</span>
</div>
<?php
}
