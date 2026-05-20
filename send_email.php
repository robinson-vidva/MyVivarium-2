#!/usr/bin/php
<?php

// Processes pending rows in the `outbox` queue. The actual transport is
// resolved by includes/mailer.php (admin-managed SMTP or Brevo API, with
// .env SMTP as the fallback).

require 'dbcon.php';
require 'config.php';
require_once __DIR__ . '/includes/mailer.php';

function sendEmail($recipients, $subject, $body)
{
    [$ok, $err] = mv_send_mail($recipients, $subject, $body);
    if (!$ok && $err !== '') {
        error_log('sendEmail failed: ' . $err);
    }
    return $ok;
}

function sendPendingEmails()
{
    global $con;

    $stmt = $con->prepare("SELECT id, recipient, subject, body FROM outbox WHERE status = 'pending'");
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($id, $recipient, $subject, $body);

    while ($stmt->fetch()) {
        $result = sendEmail($recipient, $subject, $body);
        $sentAt = date('Y-m-d H:i:s');

        if ($result) {
            $status = 'sent';
            $errorMessage = null;
        } else {
            $status = 'failed';
            $errorMessage = 'Error sending email';
        }

        $updateStmt = $con->prepare("UPDATE outbox SET status = ?, sent_at = ?, error_message = ? WHERE id = ?");
        $updateStmt->bind_param("sssi", $status, $sentAt, $errorMessage, $id);
        $updateStmt->execute();
        $updateStmt->close();
    }

    $stmt->close();
}

if (php_sapi_name() == "cli") {
    sendPendingEmails();
}
