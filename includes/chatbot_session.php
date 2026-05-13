<?php
/**
 * Chatbot per-session API key minting & revocation.
 *
 * The chatbot does NOT call service functions directly. It calls its own
 * REST API at /api/v1/* with a session-scoped api_keys row so the API
 * surface is exercised end-to-end.
 *
 * The first time a logged-in user opens the chat panel (or otherwise lands
 * on a request that calls chatbot_session_key_get()), we mint a row in
 * api_keys with scopes "read,write", a 4-hour expiry, and a clear label.
 * The raw key is only ever held in $_SESSION['chatbot_api_key'] — only the
 * sha256 hash hits the DB.
 *
 * Anything that ends a session (logout, timeout) must call
 * chatbot_session_key_revoke() so the row gets revoked_at stamped. If we
 * detect that a key has expired during a chat call, we mint a fresh one
 * transparently and retry once.
 */

require_once __DIR__ . '/../services/api_keys.php';

/**
 * Return the currently-active raw chatbot key for this PHP session.
 * Mints one if there is none. Caller must have an authenticated session.
 */
function chatbot_session_key_get(mysqli $con, int $user_id, string $username): string
{
    if (!empty($_SESSION['chatbot_api_key']) && !empty($_SESSION['chatbot_api_key_id'])) {
        return (string)$_SESSION['chatbot_api_key'];
    }
    return chatbot_session_key_mint($con, $user_id, $username);
}

/**
 * Force-mint a new chatbot key, replacing any existing one in this session.
 * Revokes the previous key so old transcripts can't be replayed.
 */
function chatbot_session_key_mint(mysqli $con, int $user_id, string $username): string
{
    if (!empty($_SESSION['chatbot_api_key_id'])) {
        api_key_revoke($con, (int)$_SESSION['chatbot_api_key_id']);
    }

    $label = 'chatbot session for ' . $username;
    $created = api_key_create($con, $user_id, $label, ['read', 'write']);

    // Stamp expires_at 4 hours out. api_key_create() doesn't take an expiry,
    // so update the row directly.
    $keyId = (int)$created['row']['id'];
    $expires = date('Y-m-d H:i:s', time() + 4 * 3600);
    $stmt = $con->prepare("UPDATE api_keys SET expires_at = ? WHERE id = ?");
    $stmt->bind_param('si', $expires, $keyId);
    $stmt->execute();
    $stmt->close();

    $_SESSION['chatbot_api_key']    = $created['raw'];
    $_SESSION['chatbot_api_key_id'] = $keyId;

    error_log("Chatbot session key minted for user $user_id ($username), key_id=$keyId, expires $expires");

    return $created['raw'];
}

/**
 * Revoke the current session's chatbot key, if any, and clear it from
 * $_SESSION. Safe to call when no key has been minted.
 */
function chatbot_session_key_revoke(mysqli $con): void
{
    if (!empty($_SESSION['chatbot_api_key_id'])) {
        api_key_revoke($con, (int)$_SESSION['chatbot_api_key_id']);
    }
    unset($_SESSION['chatbot_api_key'], $_SESSION['chatbot_api_key_id']);
}
