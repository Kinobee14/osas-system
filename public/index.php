<?php
// Send signed-in students straight to their dashboard; everyone else to sign in.
session_start();
header("Location: " . (isset($_SESSION["user_id"]) ? "student.php" : "login.php"));
exit;
