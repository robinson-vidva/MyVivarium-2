<?php
/**
 * Activity Logger Helper
 * Include this file and call log_activity() to record user actions.
 */
function log_activity($con, $action, $entity_type, $entity_id = null, $details = null) {
    $user_id = $_SESSION['user_id'] ?? null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $con->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $user_id, $action, $entity_type, $entity_id, $details, $ip_address);
    $stmt->execute();
    $stmt->close();
}
?>
