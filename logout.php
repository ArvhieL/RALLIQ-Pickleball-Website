<?php
session_start();

// Clear all session variables
$_SESSION = [];

// Delete the session cookie from the browser if present
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the active server-side session
session_destroy();

// Redirect to the unified login page with a confirmation flag
header("Location: login.php?status=logged_out");
exit();
?>