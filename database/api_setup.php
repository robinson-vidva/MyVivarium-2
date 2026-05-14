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

function index_exists(mysqli $con, string $table, string $index): bool {
    $stmt = $con->prepare("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1");
    $stmt->bind_param('ss', $table, $index);
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
        `expires_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_api_key_hash` (`key_hash`),
        KEY `idx_api_keys_user` (`user_id`),
        KEY `idx_api_keys_expires` (`expires_at`),
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

// Additive column for api_keys (in case the table predates the expires_at column).
$apiKeyColumns = [
    'expires_at' => "ALTER TABLE `api_keys` ADD COLUMN `expires_at` datetime DEFAULT NULL",
];
foreach ($apiKeyColumns as $col => $sql) {
    if (column_exists($con, 'api_keys', $col)) {
        echo "[skip]  api_keys.$col already exists\n";
        continue;
    }
    if ($con->query($sql) === false) {
        fwrite(STDERR, "[error] api_keys.$col: " . $con->error . "\n");
        exit(1);
    }
    echo "[ok]    api_keys.$col added\n";
}

// Idempotent index creation. Each entry: [table, index_name, definition].
$indexes = [
    ['api_keys',          'idx_api_keys_hash',     "CREATE INDEX `idx_api_keys_hash` ON `api_keys` (`key_hash`)"],
    ['api_keys',          'idx_api_keys_expires',  "CREATE INDEX `idx_api_keys_expires` ON `api_keys` (`expires_at`)"],
    ['api_request_log',   'idx_arl_user',          "CREATE INDEX `idx_arl_user` ON `api_request_log` (`user_id`, `created_at`)"],
    ['api_request_log',   'idx_arl_created',       "CREATE INDEX `idx_arl_created` ON `api_request_log` (`created_at`)"],
    ['pending_operations','idx_pending_expires',   "CREATE INDEX `idx_pending_expires` ON `pending_operations` (`expires_at`)"],
    ['pending_operations','idx_pending_user_exec', "CREATE INDEX `idx_pending_user_exec` ON `pending_operations` (`user_id`, `executed_at`)"],
];
foreach ($indexes as [$table, $name, $sql]) {
    // The unique key on key_hash already covers idx_api_keys_hash — skip if any index named
    // 'uniq_api_key_hash' already exists on the column to avoid redundant indexing.
    if ($name === 'idx_api_keys_hash' && index_exists($con, 'api_keys', 'uniq_api_key_hash')) {
        echo "[skip]  $table.$name redundant (uniq_api_key_hash covers key_hash)\n";
        continue;
    }
    if (index_exists($con, $table, $name)) {
        echo "[skip]  $table.$name already exists\n";
        continue;
    }
    if ($con->query($sql) === false) {
        fwrite(STDERR, "[error] $table.$name: " . $con->error . "\n");
        exit(1);
    }
    echo "[ok]    $table.$name added\n";
}

// AI chatbot conversation persistence (part three).
$chatbotTables = [
    'ai_conversations' => "CREATE TABLE `ai_conversations` (
        `id` char(36) NOT NULL,
        `user_id` int NOT NULL,
        `title` varchar(200) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_ai_conv_user` (`user_id`),
        KEY `idx_ai_conv_updated` (`updated_at`),
        CONSTRAINT `fk_ai_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    )",
    'ai_messages' => "CREATE TABLE `ai_messages` (
        `id` int NOT NULL AUTO_INCREMENT,
        `conversation_id` char(36) NOT NULL,
        `role` enum('user','assistant','tool','system_event') NOT NULL,
        `content` text,
        `tool_call_json` text,
        `tool_result_json` text,
        `pending_op_id` varchar(36) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_ai_msg_conv_created` (`conversation_id`, `created_at`),
        CONSTRAINT `fk_ai_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
    )",
    'ai_usage_log' => "CREATE TABLE `ai_usage_log` (
        `id` bigint NOT NULL AUTO_INCREMENT,
        `user_id` int DEFAULT NULL,
        `conversation_id` char(36) DEFAULT NULL,
        `prompt_tokens` int NOT NULL DEFAULT 0,
        `completion_tokens` int NOT NULL DEFAULT 0,
        `estimated_prompt_tokens` int NOT NULL DEFAULT 0,
        `model` varchar(64) NOT NULL DEFAULT '',
        `provider` varchar(16) NOT NULL DEFAULT '',
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_ai_usage_user_created` (`user_id`, `created_at`),
        KEY `idx_ai_usage_conv` (`conversation_id`),
        CONSTRAINT `fk_ai_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
    )",
];
foreach ($chatbotTables as $table => $sql) {
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

// Migration: add estimated_prompt_tokens to ai_usage_log on existing DBs.
if (table_exists($con, 'ai_usage_log') && !column_exists($con, 'ai_usage_log', 'estimated_prompt_tokens')) {
    if ($con->query("ALTER TABLE `ai_usage_log` ADD COLUMN `estimated_prompt_tokens` int NOT NULL DEFAULT 0 AFTER `completion_tokens`") === false) {
        fwrite(STDERR, "[error] ai_usage_log.estimated_prompt_tokens: " . $con->error . "\n");
        exit(1);
    }
    echo "[ok]    ai_usage_log.estimated_prompt_tokens added\n";
}

// Migration: add provider column to ai_usage_log so we can attribute usage
// to Groq vs OpenAI side by side. Idempotent.
if (table_exists($con, 'ai_usage_log') && !column_exists($con, 'ai_usage_log', 'provider')) {
    if ($con->query("ALTER TABLE `ai_usage_log` ADD COLUMN `provider` varchar(16) NOT NULL DEFAULT '' AFTER `model`") === false) {
        fwrite(STDERR, "[error] ai_usage_log.provider: " . $con->error . "\n");
        exit(1);
    }
    echo "[ok]    ai_usage_log.provider added\n";
}

// AI settings storage for the admin chatbot configuration.
if (!table_exists($con, 'ai_settings')) {
    $aiSql = "CREATE TABLE `ai_settings` (
        `id` int NOT NULL AUTO_INCREMENT,
        `setting_key` varchar(64) NOT NULL,
        `setting_value` mediumtext NOT NULL,
        `updated_by` int DEFAULT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_ai_settings_key` (`setting_key`),
        KEY `idx_ai_settings_updated_by` (`updated_by`),
        CONSTRAINT `fk_ai_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
    )";
    if ($con->query($aiSql) === false) {
        fwrite(STDERR, "[error] ai_settings: " . $con->error . "\n");
        exit(1);
    }
    echo "[ok]    ai_settings created\n";
} else {
    echo "[skip]  ai_settings already exists\n";
}

// AI chatbot per-user rate limit counters. One row per user per
// (window_kind, window_start) pair. Idempotent.
if (!table_exists($con, 'ai_chat_rate')) {
    $sql = "CREATE TABLE `ai_chat_rate` (
        `user_id` int NOT NULL,
        `window_kind` enum('minute','day') NOT NULL,
        `window_start` datetime NOT NULL,
        `count` int NOT NULL DEFAULT 0,
        PRIMARY KEY (`user_id`, `window_kind`, `window_start`),
        KEY `idx_aicr_user_kind` (`user_id`, `window_kind`)
    )";
    if ($con->query($sql) === false) {
        fwrite(STDERR, "[error] ai_chat_rate: " . $con->error . "\n");
        exit(1);
    }
    echo "[ok]    ai_chat_rate created\n";
} else {
    echo "[skip]  ai_chat_rate already exists\n";
}

echo "Done.\n";
