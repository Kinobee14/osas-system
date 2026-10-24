<?php
/**
 * dashboard.php
 * Kept only for backward-compatible links/bookmarks — the real dashboard
 * lives at student.php now (it has the full sidebar, stats, calendar, etc).
 */
session_start();
require "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

header("Location: student.php");
exit;
