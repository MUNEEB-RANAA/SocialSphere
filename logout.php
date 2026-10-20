<?php
// logout.php - Session destruction
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION = [];
session_destroy();
header("Location: login.php");
exit;
