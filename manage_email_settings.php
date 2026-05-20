<?php
// Admin-only Email Settings page. Lets the admin choose an outbound mail
// transport (SMTP or Brevo API), edit the per-transport fields, save them
// to the database (passwords/keys encrypted with the shared AES-256-CBC
// helper), and send a test message to their own address before relying on
// the config.

require 'session_config.php';
require 'dbcon.php';
require 'config.php';
require_once __DIR__ . '/log_activity.php';
require_once __DIR__ . '/includes/ai_settings.php';
require_once __DIR__ . '/includes/email_settings.php';
require_once __DIR__ . '/includes/mailer.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['message'] = 'Unauthorized: admin role required.';
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$envError    = null;
$saveError   = null;
$saveSuccess = null;

try {
    ai_settings_ensure_key();
} catch (AiSettingsException $e) {
    $envError = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        die('CSRF token validation failed');
    }
    if ($envError !== null) {
        $saveError = 'Cannot save: ' . $envError;
    } else {
        $transport = strtolower(trim((string)($_POST['transport'] ?? 'smtp')));
        if (!in_array($transport, ['smtp', 'brevo'], true)) $transport = 'smtp';

        $smtpHost     = trim((string)($_POST['smtp_host']       ?? ''));
        $smtpPortRaw  = trim((string)($_POST['smtp_port']       ?? ''));
        $smtpUsername = trim((string)($_POST['smtp_username']   ?? ''));
        $smtpPassword = (string)($_POST['smtp_password']        ?? '');
        $smtpEnc      = strtolower(trim((string)($_POST['smtp_encryption'] ?? '')));
        if (!in_array($smtpEnc, ['', 'tls', 'ssl'], true)) $smtpEnc = 'tls';

        $brevoApiKey  = (string)($_POST['brevo_api_key'] ?? '');
        $senderEmail  = trim((string)($_POST['sender_email']  ?? ''));
        $senderName   = trim((string)($_POST['sender_name']   ?? ''));

        $clearSmtpPassword = !empty($_POST['clear_smtp_password']);
        $clearBrevoApiKey  = !empty($_POST['clear_brevo_api_key']);

        $errors = [];

        if ($senderEmail !== '' && !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Sender email is not a valid address.';
        }

        $smtpPort = '';
        if ($smtpPortRaw !== '') {
            if (!ctype_digit($smtpPortRaw) || (int)$smtpPortRaw < 1 || (int)$smtpPortRaw > 65535) {
                $errors[] = 'SMTP port must be an integer between 1 and 65535.';
            } else {
                $smtpPort = (string)(int)$smtpPortRaw;
            }
        }

        if ($transport === 'smtp') {
            if ($smtpHost === '')    $errors[] = 'SMTP host is required.';
            if ($smtpPort === '')    $errors[] = 'SMTP port is required.';
            if ($senderEmail === '') $errors[] = 'Sender email is required.';
        } else {
            if ($brevoApiKey === '') {
                // Brevo: missing API key is only an error if there is no
                // saved key being kept. Detect "keeping" via empty input
                // and no clear flag with a key already set.
                $existing = email_settings_get($con, 'brevo_api_key');
                if ($existing === null || $existing === '' || $clearBrevoApiKey) {
                    $errors[] = 'Brevo API key is required.';
                }
            }
            if ($senderEmail === '') $errors[] = 'Sender email is required.';
        }

        if ($errors) {
            $saveError = implode(' ', $errors);
        } else {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            try {
                email_settings_set($con, 'transport',        $transport,   $userId);
                email_settings_set($con, 'smtp_host',        $smtpHost,    $userId);
                email_settings_set($con, 'smtp_port',        $smtpPort,    $userId);
                email_settings_set($con, 'smtp_username',    $smtpUsername,$userId);
                email_settings_set($con, 'smtp_encryption',  $smtpEnc,     $userId);
                email_settings_set($con, 'sender_email',     $senderEmail, $userId);
                email_settings_set($con, 'sender_name',      $senderName,  $userId);

                // Secrets: only write when admin typed a new value, or explicitly
                // cleared. Empty input + no clear flag = keep stored value.
                if ($clearSmtpPassword) {
                    email_settings_set($con, 'smtp_password', '', $userId);
                } elseif ($smtpPassword !== '') {
                    email_settings_set($con, 'smtp_password', $smtpPassword, $userId);
                }
                if ($clearBrevoApiKey) {
                    email_settings_set($con, 'brevo_api_key', '', $userId);
                } elseif ($brevoApiKey !== '') {
                    email_settings_set($con, 'brevo_api_key', $brevoApiKey, $userId);
                }

                log_activity($con, 'email_settings_change', 'email_settings', $transport, 'saved');
                $_SESSION['message'] = 'Email settings saved.';
                header('Location: manage_email_settings.php');
                exit;
            } catch (Throwable $e) {
                $saveError = 'Save failed: ' . $e->getMessage();
            }
        }
    }
}

// Build view-model. If no DB config exists, prefill from .env so the admin
// does not have to retype host/port/etc on first load.
$dbHasConfig = email_settings_any_configured($con);
if ($dbHasConfig) {
    $view = email_settings_get_view($con);
    $values    = $view['values'];
    $secretSet = $view['secret_set'];        // true iff a DB row holds the secret
    $secretInDb = $view['secret_set'];
} else {
    $values    = email_settings_env_defaults();
    $secretSet = ['smtp_password' => false, 'brevo_api_key' => false];
    $secretInDb = ['smtp_password' => false, 'brevo_api_key' => false];
}

// "Effectively set" reflects what email_settings_resolve_active() would
// actually use at send time: a DB value OR (for smtp_password only) the
// .env fallback. We drive the on-page hint + status indicator from this
// so the UI never says "no password stored" while .env is silently in use.
$envSmtpPasswordSet = defined('SMTP_PASSWORD') && SMTP_PASSWORD !== '';
$secretSet['smtp_password'] = $secretInDb['smtp_password'] || $envSmtpPasswordSet;
// Brevo has no .env fallback, so DB-only.
$secretSet['brevo_api_key'] = $secretInDb['brevo_api_key'];
if (!in_array(($values['transport'] ?? ''), ['smtp', 'brevo'], true)) {
    $values['transport'] = 'smtp';
}
if (!in_array(($values['smtp_encryption'] ?? ''), ['', 'tls', 'ssl'], true)) {
    $values['smtp_encryption'] = 'tls';
}

require 'header.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Settings | <?= htmlspecialchars($labName); ?></title>
    <style>
        .container.email-cfg { max-width: 800px; }
        .email-card {
            background-color: var(--bs-tertiary-bg);
            padding: 18px;
            border-radius: 8px;
            margin-bottom: 18px;
        }
        .email-card h4 { margin-top: 0; }
        .secret-status { font-size: 0.85rem; color: var(--bs-secondary-color); }
        .secret-status.is-set    { color: var(--bs-success); }
        .secret-status.is-unset  { color: var(--bs-secondary-color); }
        .test-result { font-size: 0.95rem; margin-top: 8px; }
        .test-result.ok  { color: var(--bs-success); }
        .test-result.bad { color: var(--bs-danger); }
        .transport-section { display: none; }
        .transport-section.active { display: block; }
    </style>
</head>
<body>
<div class="container mt-4 content email-cfg">
    <h1 class="mb-1">Email Settings</h1>
    <p class="text-muted">
        Configure the transport used to send password resets, email verifications, reminders, and other system mail.
        Passwords and API keys are encrypted at rest with AES-256-CBC and are never echoed back to the browser.
        If no configuration is saved here, the system falls back to the SMTP values in the .env file.
    </p>

    <?php include 'message.php'; ?>

    <?php if ($envError !== null): ?>
        <div class="alert alert-danger"><strong>Encryption key error:</strong> <?= htmlspecialchars($envError); ?></div>
    <?php endif; ?>
    <?php if ($saveError !== null): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($saveError); ?></div>
    <?php endif; ?>
    <?php if (!$dbHasConfig): ?>
        <div class="alert alert-info">
            No email configuration is stored in the database yet. The fields below are pre-filled from the .env file.
            The SMTP password has not been pre-filled for security; enter it again so it can be stored encrypted on first save.
        </div>
    <?php endif; ?>

    <form method="post" action="manage_email_settings.php" id="emailSettingsForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="email-card">
            <h4>Transport</h4>
            <p class="text-muted small mb-2">Only one transport is active at a time.</p>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="transport" id="transport_smtp"
                       value="smtp" <?= $values['transport'] === 'smtp' ? 'checked' : ''; ?>>
                <label class="form-check-label" for="transport_smtp">SMTP</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="transport" id="transport_brevo"
                       value="brevo" <?= $values['transport'] === 'brevo' ? 'checked' : ''; ?>>
                <label class="form-check-label" for="transport_brevo">Brevo API</label>
            </div>
        </div>

        <div class="email-card transport-section" id="section_smtp">
            <h4>SMTP</h4>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="smtp_host">Host</label>
                    <input type="text" class="form-control" id="smtp_host" name="smtp_host"
                           value="<?= htmlspecialchars($values['smtp_host'] ?? ''); ?>" placeholder="smtp.example.com">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="smtp_port">Port</label>
                    <input type="number" min="1" max="65535" class="form-control" id="smtp_port" name="smtp_port"
                           value="<?= htmlspecialchars((string)($values['smtp_port'] ?? '')); ?>" placeholder="587">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="smtp_username">Username</label>
                    <input type="text" class="form-control" id="smtp_username" name="smtp_username"
                           value="<?= htmlspecialchars($values['smtp_username'] ?? ''); ?>" autocomplete="off">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="smtp_encryption">Encryption</label>
                    <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                        <?php
                        $enc = (string)($values['smtp_encryption'] ?? '');
                        $options = [
                            'tls' => 'tls',
                            'ssl' => 'ssl',
                            ''    => 'none',
                        ];
                        foreach ($options as $val => $label) {
                            $sel = ($enc === $val) ? ' selected' : '';
                            echo '<option value="' . htmlspecialchars($val) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="smtp_password">Password</label>
                    <input type="password" class="form-control" id="smtp_password" name="smtp_password"
                           placeholder="<?= !empty($secretSet['smtp_password']) ? 'leave blank to keep the current value' : 'enter SMTP password'; ?>"
                           autocomplete="new-password">
                    <?php if (!empty($secretSet['smtp_password'])): ?>
                        <div class="form-text">Leave blank to keep the current value. Type a new password to replace it.</div>
                    <?php endif; ?>
                    <div class="secret-status <?= !empty($secretSet['smtp_password']) ? 'is-set' : 'is-unset'; ?>" id="smtpPasswordStatus">
                        <?php if (!empty($secretInDb['smtp_password'])): ?>
                            Password is set (encrypted in database).
                        <?php elseif (!empty($secretSet['smtp_password'])): ?>
                            Password is set (using .env fallback).
                        <?php else: ?>
                            No password stored.
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($secretInDb['smtp_password'])): ?>
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" id="clear_smtp_password" name="clear_smtp_password" value="1">
                            <label class="form-check-label" for="clear_smtp_password">Clear stored SMTP password (revert to .env fallback if present)</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="email-card transport-section" id="section_brevo">
            <h4>Brevo API</h4>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="brevo_api_key">API key</label>
                    <input type="password" class="form-control" id="brevo_api_key" name="brevo_api_key"
                           placeholder="<?= !empty($secretSet['brevo_api_key']) ? 'leave blank to keep the current value' : 'paste Brevo API key'; ?>"
                           autocomplete="new-password">
                    <?php if (!empty($secretSet['brevo_api_key'])): ?>
                        <div class="form-text">Leave blank to keep the current value. Type a new key to replace it.</div>
                    <?php endif; ?>
                    <div class="secret-status <?= !empty($secretSet['brevo_api_key']) ? 'is-set' : 'is-unset'; ?>" id="brevoApiKeyStatus">
                        <?= !empty($secretSet['brevo_api_key']) ? 'API key is set (encrypted in database).' : 'No API key stored.'; ?>
                    </div>
                    <?php if (!empty($secretInDb['brevo_api_key'])): ?>
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" id="clear_brevo_api_key" name="clear_brevo_api_key" value="1">
                            <label class="form-check-label" for="clear_brevo_api_key">Clear stored Brevo API key</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="email-card">
            <h4>Sender</h4>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="sender_email">Sender email</label>
                    <input type="email" class="form-control" id="sender_email" name="sender_email"
                           value="<?= htmlspecialchars($values['sender_email'] ?? ''); ?>" placeholder="noreply@example.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="sender_name">Sender name</label>
                    <input type="text" class="form-control" id="sender_name" name="sender_name"
                           value="<?= htmlspecialchars($values['sender_name'] ?? ''); ?>" placeholder="MyVivarium">
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
            <button type="button" class="btn btn-outline-secondary" id="sendTestBtn">
                <i class="fas fa-paper-plane"></i> Send test email
            </button>
            <span class="test-result" id="testResult" role="status" aria-live="polite"></span>
        </div>
    </form>
</div>

<?php include 'footer.php'; ?>

<script>
(function () {
    const radios = document.querySelectorAll('input[name="transport"]');
    const smtpSection  = document.getElementById('section_smtp');
    const brevoSection = document.getElementById('section_brevo');

    function syncTransport() {
        const sel = document.querySelector('input[name="transport"]:checked')?.value || 'smtp';
        smtpSection.classList.toggle('active',  sel === 'smtp');
        brevoSection.classList.toggle('active', sel === 'brevo');
    }
    radios.forEach(r => r.addEventListener('change', syncTransport));
    syncTransport();

    const csrf = <?= json_encode($_SESSION['csrf_token']); ?>;
    const btn = document.getElementById('sendTestBtn');
    const out = document.getElementById('testResult');

    btn.addEventListener('click', function () {
        out.textContent = 'Sending test email...';
        out.className = 'test-result';

        const form = document.getElementById('emailSettingsForm');
        const fd = new FormData(form);
        fd.set('csrf_token', csrf);

        btn.disabled = true;
        fetch('email_settings_test.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(r => r.json().then(j => ({ ok: r.ok, body: j })))
          .then(({ ok, body }) => {
              btn.disabled = false;
              if (ok && body && body.ok) {
                  out.textContent = 'Test email sent to ' + (body.recipient || 'you') + '.';
                  out.className = 'test-result ok';
              } else {
                  const msg = (body && body.message) ? body.message : 'Unknown error.';
                  out.textContent = 'Test failed: ' + msg;
                  out.className = 'test-result bad';
              }
          })
          .catch(err => {
              btn.disabled = false;
              out.textContent = 'Test failed: ' + err;
              out.className = 'test-result bad';
          });
    });
})();
</script>
</body>
</html>
