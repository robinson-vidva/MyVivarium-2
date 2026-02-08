<?php

/**
 * Session Security Configuration
 *
 * This file configures secure session settings to protect against session hijacking,
 * session fixation, and other session-based attacks. It should be included at the
 * beginning of any file that uses sessions.
 *
 * SECURITY FEATURES:
 * - HttpOnly cookies: Prevents JavaScript from accessing session cookies (XSS protection)
 * - Secure flag: Only sends cookies over HTTPS (prevents interception)
 * - SameSite protection: Prevents CSRF attacks
 * - Session timeout: 30 minutes of inactivity
 * - Session ID regeneration: Every 30 minutes
 *
 * DEPLOYMENT NOTES:
 *
 * FOR PRODUCTION (HTTPS REQUIRED):
 * - Ensure your site runs on HTTPS before enabling 'secure' => true
 * - All security features will be active
 * - To use: Replace session_start() with: require 'session_config.php';
 *
 * FOR DEVELOPMENT (HTTP):
 * - Change 'secure' => true to 'secure' => false if using HTTP
 * - Otherwise sessions will not work without HTTPS
 *
 * USAGE:
 * Replace all instances of session_start() with:
 *   require 'session_config.php';
 *
 * This ensures consistent session security across the entire application.
 *
 */

// Only configure and start session if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    // Configure session cookie parameters (must be set BEFORE session_start)
    session_set_cookie_params([
        'lifetime' => 1800,           // Cookie expires after 30 minutes
        'path'     => '/',
        'secure'   => true,           // Only send cookie over HTTPS (set to false for HTTP dev)
        'httponly'  => true,           // Prevent JavaScript access to session cookie (XSS protection)
        'samesite'  => 'Strict'       // Prevent CSRF attacks
    ]);

    // Configure session behavior
    ini_set('session.use_only_cookies', 1);  // Only use cookies for session ID
    ini_set('session.use_strict_mode', 1);   // Reject uninitialized session IDs
    ini_set('session.gc_maxlifetime', 1800); // 30 minutes garbage collection lifetime

    session_start();
}

// Check for session timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 1800)) {
    // Last request was more than 30 minutes ago
    session_unset();     // Unset $_SESSION variable
    session_destroy();   // Destroy session data in storage
    session_start();     // Start a new session
}
$_SESSION['LAST_ACTIVITY'] = time(); // Update last activity timestamp

// Regenerate session ID periodically to prevent session fixation
if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
} else if (time() - $_SESSION['CREATED'] > 1800) {
    // Session started more than 30 minutes ago
    session_regenerate_id(true);    // Change session ID and delete old session
    $_SESSION['CREATED'] = time();  // Update creation time
}
