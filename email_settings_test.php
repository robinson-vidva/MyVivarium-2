<?php
// Admin-only endpoint: send a test email to the current admin's own
// address using the settings as entered in the form. Posts back to the
// Email Settings page asynchronously; never touches the saved DB config.

require 'session_config.php';
require 'dbcon.php';
require 'config.php';
require_once __DIR__ . '/log_activity.php';
require_once __DIR__ . '/includes/email_settings.php';
require_once __DIR__ . '/includes/mailer.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function et_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    et_respond(['ok' => false, 'message' => 'Admin role required.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    et_respond(['ok' => false, 'message' => 'POST required.'], 405);
}
if (!isset($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])) {
    et_respond(['ok' => false, 'message' => 'CSRF token validation failed.'], 400);
}

$recipient = trim((string)($_SESSION['username'] ?? ''));
if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    et_respond(['ok' => false, 'message' => 'Your account email is not a valid address; cannot send test.'], 400);
}

$transport = strtolower(trim((string)($_POST['transport'] ?? 'smtp')));
if (!in_array($transport, ['smtp', 'brevo'], true)) $transport = 'smtp';

$cfg = [
    'transport'        => $transport,
    'smtp_host'        => trim((string)($_POST['smtp_host']       ?? '')),
    'smtp_port'        => (int)((string)($_POST['smtp_port']      ?? 0)),
    'smtp_username'    => trim((string)($_POST['smtp_username']   ?? '')),
    'smtp_password'    => (string)($_POST['smtp_password']        ?? ''),
    'smtp_encryption'  => strtolower(trim((string)($_POST['smtp_encryption'] ?? ''))),
    'brevo_api_key'    => (string)($_POST['brevo_api_key']        ?? ''),
    'sender_email'     => trim((string)($_POST['sender_email']    ?? '')),
    'sender_name'      => trim((string)($_POST['sender_name']     ?? '')),
];
if (!in_array($cfg['smtp_encryption'], ['', 'tls', 'ssl'], true)) $cfg['smtp_encryption'] = 'tls';

// For each secret that the admin left blank, fall back to whatever is
// currently saved in the database (or .env for the SMTP password). This
// lets the admin click "Send test email" without retyping a saved secret.
$saved = email_settings_resolve_active($con);
if ($cfg['smtp_password'] === '' && empty($_POST['clear_smtp_password'])) {
    $cfg['smtp_password'] = (string)($saved['smtp_password'] ?? '');
}
if ($cfg['brevo_api_key'] === '' && empty($_POST['clear_brevo_api_key'])) {
    $cfg['brevo_api_key'] = (string)($saved['brevo_api_key'] ?? '');
}
if ($cfg['sender_email'] === '') {
    $cfg['sender_email'] = (string)($saved['sender_email'] ?? '');
}
if ($cfg['sender_name'] === '') {
    $cfg['sender_name'] = (string)($saved['sender_name'] ?? '');
}
if ($cfg['smtp_host'] === '')       $cfg['smtp_host']       = (string)($saved['smtp_host'] ?? '');
if ($cfg['smtp_port'] === 0)        $cfg['smtp_port']       = (int)($saved['smtp_port'] ?? 0);
if ($cfg['smtp_username'] === '')   $cfg['smtp_username']   = (string)($saved['smtp_username'] ?? '');
if ($cfg['smtp_encryption'] === '') $cfg['smtp_encryption'] = (string)($saved['smtp_encryption'] ?? '');

$subject = 'MyVivarium email test';
$body    = '<p>This is a test message from MyVivarium Email Settings.</p>' .
           '<p>Transport: <strong>' . htmlspecialchars(strtoupper($transport)) . '</strong></p>' .
           '<p>If you received this, the configuration you just entered works.</p>';

[$ok, $err] = mv_send_mail($recipient, $subject, $body, ['config' => $cfg, 'is_html' => true]);

log_activity($con, 'email_settings_test', 'email_settings', $transport, $ok ? 'success' : ('fail: ' . substr($err, 0, 200)));

if ($ok) {
    et_respond(['ok' => true, 'recipient' => $recipient]);
}
et_respond(['ok' => false, 'message' => $err !== '' ? $err : 'Unknown error']);
