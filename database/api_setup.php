<?php
/**
 * Idempotent installer for the REST API schema additions.
 *
 * Runs the CREATE TABLE IF NOT EXISTS statements from api_schema.sql and adds
 * the maintenance columns only if they don't already exist (so re-running
 * is safe). Invoke from the project root:
 *
 *     php database/api_setup.php
 *
 * Or from the shell with the same credentials as your web user.
 */

require __DIR__ . '/../dbcon.php';

function table_exists(mysqli $con, string $name): bool {
    $stmt = $con->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function column_exists(mysqli $con, string $table, string $column): bool {
    $stmt = $con->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

$statements = [
    'api_keys' => "CREATE TABLE `api_keys` (
        `id` int NOT NULL AUTO_INCREMENT,
        `user_id` int NOT NULL,
        `key_hash` char(64) NOT NULL,
        `label` varchar(255) NOT NULL,
        `scopes` varchar(64) NOT NULL DEFAULT 'read',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_used_at` datetime DEFAULT NULL,
        `revoked_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_api_key_hash` (`key_hash`),
        KEY `idx_api_keys_user` (`user_id`),
        CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    )",
    'pending_operations' => "CREATE TABLE `pending_operations` (
        `id` char(36) NOT NULL,
        `user_id` int NOT NULL,
        `method` varchar(10) NOT NULL,
        `path` varchar(512) NOT NULL,
        `body_json` mediumtext NOT NULL,
        `diff_json` mediumtext NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at` datetime NOT NULL,
        `executed_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_pending_user` (`user_id`),
        KEY `idx_pending_expires` (`expires_at`),
        CONSTRAINT `fk_pending_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    )",
    'api_request_log' => "CREATE TABLE `api_request_log` (
        `id` bigint NOT NULL AUTO_INCREMENT,
        `user_id` int DEFAULT NULL,
        `endpoint` varchar(512) NOT NULL,
        `method` varchar(10) NOT NULL,
        `status_code` int NOT NULL,
        `response_time_ms` int NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_arl_user` (`user_id`, `created_at`),
        KEY `idx_arl_created` (`created_at`),
        CONSTRAINT `fk_arl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
    )",
    'rate_limit' => "CREATE TABLE `rate_limit` (
        `key_id` int NOT NULL,
        `window_start` datetime NOT NULL,
        `request_count` int NOT NULL DEFAULT 0,
        PRIMARY KEY (`key_id`),
        CONSTRAINT `fk_rate_limit_key` FOREIGN KEY (`key_id`) REFERENCES `api_keys` (`id`) ON DELETE CASCADE
    )",
];

foreach ($statements as $table => $sql) {
    if (table_exists($con, $table)) {
        echo "[skip]  $table already exists\n";
        continue;
    }
    if ($con->query($sql) === false) {
        fwrite(STDERR, "[error] $table: " . $con->error . "\n");
        exit(1);
    }
    echo "[ok]    $table created\n";
}

$maintenanceColumns = [
    'note_type'  => "ALTER TABLE `maintenance` ADD COLUMN `note_type`  varchar(64) DEFAULT NULL",
    'deleted_at' => "ALTER TABLE `maintenance` ADD COLUMN `deleted_at` datetime    DEFAULT NULL",
    'updated_at' => "ALTER TABLE `maintenance` ADD COLUMN `updated_at` timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
];

foreach ($maintenanceColumns as $col => $sql) {
    if (column_exists($con, 'maintenance', $col)) {
        echo "[skip]  maintenance.$col already exists\n";
        continue;
    }
    if ($con->query($sql) === false) {
        fwrite(STDERR, "[error] maintenance.$col: " . $con->error . "\n");
        exit(1);
    }
    echo "[ok]    maintenance.$col added\n";
}

echo "Done.\n";
