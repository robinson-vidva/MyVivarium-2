<?php

/**
 * Logout Script
 * 
 * This script logs out the user by destroying the session and redirecting to the index.php page.
 * 
 */

// Check if a session is already started, and start a new session if not
require 'session_config.php';
require 'dbcon.php';
require_once __DIR__ . '/includes/chatbot_session.php';

// Revoke the per-session chatbot API key (if any) before tearing down the
// session, so the row gets revoked_at stamped for audit.
try {
    chatbot_session_key_revoke($con);
} catch (Throwable $e) {
    error_log('logout chatbot revoke error: ' . $e->getMessage());
}

// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie
// Note: This will destroy the session, and not just the session data!
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

// Finally, destroy the session
session_destroy();

// Redirect to the index.php page
header("Location: index.php");
exit;  // Ensure no further code is executed
