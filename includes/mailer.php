<?php
// Unified outbound mail dispatcher. All places that send mail go through
// mv_send_mail(); the active transport (SMTP via PHPMailer, or Brevo API
// via HTTPS) is selected by the admin on the Email Settings page, with
// .env SMTP values as the fallback.

require_once __DIR__ . '/email_settings.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Normalize recipients into a deduped list of trimmed addresses.
function mv_mail_normalize_recipients($recipients): array
{
    $out = [];
    if (is_array($recipients)) {
        foreach ($recipients as $r) {
            $r = trim((string)$r);
            if ($r !== '') $out[] = $r;
        }
    } else {
        foreach (explode(',', (string)$recipients) as $r) {
            $r = trim($r);
            if ($r !== '') $out[] = $r;
        }
    }
    return array_values(array_unique($out));
}

// Send a single message. Returns [bool $ok, string $error]. $opts may set
// 'is_html' (default true), 'config' (override config from caller, used by
// the test-email endpoint), and 'reply_to' (string).
function mv_send_mail($recipients, string $subject, string $body, array $opts = []): array
{
    $to = mv_mail_normalize_recipients($recipients);
    if (!$to) {
        return [false, 'No recipients.'];
    }
    $isHtml = array_key_exists('is_html', $opts) ? (bool)$opts['is_html'] : true;
    $cfg    = $opts['config'] ?? email_settings_resolve_active();

    $sender = $cfg['sender_email'] ?? '';
    if ($sender === '') {
        return [false, 'Sender email is not configured.'];
    }
    $senderName = (string)($cfg['sender_name'] ?? '');

    $transport = strtolower((string)($cfg['transport'] ?? 'smtp'));
    if ($transport === 'brevo') {
        return mv_send_mail_brevo($to, $subject, $body, $isHtml, $sender, $senderName, $cfg, $opts);
    }
    return mv_send_mail_smtp($to, $subject, $body, $isHtml, $sender, $senderName, $cfg, $opts);
}

function mv_send_mail_smtp(array $to, string $subject, string $body, bool $isHtml,
                           string $sender, string $senderName, array $cfg, array $opts): array
{
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return [false, 'PHPMailer is not installed. Run composer install.'];
    }
    $host = (string)($cfg['smtp_host'] ?? '');
    if ($host === '') {
        return [false, 'SMTP host is not configured.'];
    }
    $port       = (int)($cfg['smtp_port'] ?? 0);
    $username   = (string)($cfg['smtp_username'] ?? '');
    $password   = (string)($cfg['smtp_password'] ?? '');
    $encryption = (string)($cfg['smtp_encryption'] ?? '');

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $host;
        if ($port > 0) $mail->Port = $port;
        if ($username !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
        }
        if ($encryption !== '') {
            $mail->SMTPSecure = $encryption;
        }

        $mail->setFrom($sender, $senderName);
        foreach ($to as $addr) {
            $mail->addAddress($addr);
        }
        if (!empty($opts['reply_to'])) {
            $mail->addReplyTo((string)$opts['reply_to']);
        }
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        if ($isHtml) {
            $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body)));
        }
        $mail->send();
        return [true, ''];
    } catch (\Throwable $e) {
        $err = isset($mail) && $mail instanceof \PHPMailer\PHPMailer\PHPMailer && $mail->ErrorInfo !== ''
             ? $mail->ErrorInfo
             : $e->getMessage();
        error_log('mv_send_mail SMTP error: ' . $err);
        return [false, $err];
    }
}

function mv_send_mail_brevo(array $to, string $subject, string $body, bool $isHtml,
                            string $sender, string $senderName, array $cfg, array $opts): array
{
    $apiKey = (string)($cfg['brevo_api_key'] ?? '');
    if ($apiKey === '') {
        return [false, 'Brevo API key is not configured.'];
    }

    $payload = [
        'sender'      => array_filter(['name' => $senderName, 'email' => $sender], static fn($v) => $v !== ''),
        'to'          => array_map(static fn($addr) => ['email' => $addr], $to),
        'subject'     => $subject,
        'htmlContent' => $isHtml ? $body : nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
    ];
    if (!$isHtml) {
        $payload['textContent'] = $body;
    }
    if (!empty($opts['reply_to'])) {
        $payload['replyTo'] = ['email' => (string)$opts['reply_to']];
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    if ($ch === false) {
        return [false, 'curl_init failed.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch) ?: 'Unknown cURL error';
        curl_close($ch);
        error_log('mv_send_mail Brevo cURL error: ' . $err);
        return [false, $err];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        return [true, ''];
    }
    $detail = '';
    $decoded = json_decode((string)$resp, true);
    if (is_array($decoded)) {
        $detail = (string)($decoded['message'] ?? ($decoded['code'] ?? ''));
    }
    if ($detail === '') $detail = substr((string)$resp, 0, 300);
    $msg = 'Brevo API HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '');
    error_log('mv_send_mail Brevo error: ' . $msg);
    return [false, $msg];
}

// Convenience wrapper for callers that only care about the boolean.
function mv_send_mail_bool($recipients, string $subject, string $body, array $opts = []): bool
{
    [$ok] = mv_send_mail($recipients, $subject, $body, $opts);
    return $ok;
}
