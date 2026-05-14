<?php
/**
 * Per-user AI chatbot rate limiter.
 *
 * Separate from the per-API-key REST rate limit in services/rate_limit.php.
 * Counts USER MESSAGES (one per chatbot turn from the user) against two
 * fixed windows:
 *
 *   - per-minute:  resets every 60 seconds (window keyed on YYYY-MM-DD HH:MM:00 UTC)
 *   - per-day:     resets at midnight UTC   (window keyed on YYYY-MM-DD 00:00:00 UTC)
 *
 * Limits are admin-configurable via ai_settings_set:
 *   ai_rate_limit_messages_per_minute (default 10, min 1, max 60)
 *   ai_rate_limit_messages_per_day    (default 200, min 10, max 5000)
 *
 * Tool calls and LLM round-trips do NOT count — the limit is applied to
 * user-typed messages only, BEFORE the assistant starts processing.
 *
 * On a hit, ai_rate_limit_record_hit() writes an activity_log row with
 * action='ai_rate_limit_hit' so admins can see who is being throttled.
 */

require_once __DIR__ . '/../services/helpers.php';
// Allow tests to stub ai_settings_get without loading the real ai_settings.php
// (which opens a mysqli connection on import).
if (!function_exists('ai_settings_get')) {
    require_once __DIR__ . '/ai_settings.php';
}

const AI_RATE_LIMIT_PER_MINUTE_DEFAULT = 10;
const AI_RATE_LIMIT_PER_MINUTE_MIN     = 1;
const AI_RATE_LIMIT_PER_MINUTE_MAX     = 60;
const AI_RATE_LIMIT_PER_DAY_DEFAULT    = 200;
const AI_RATE_LIMIT_PER_DAY_MIN        = 10;
const AI_RATE_LIMIT_PER_DAY_MAX        = 5000;

function ai_rate_limit_get_limit(string $kind): int
{
    if ($kind === 'minute') {
        $raw = ai_settings_get('ai_rate_limit_messages_per_minute');
        $v = is_numeric($raw) ? (int)$raw : AI_RATE_LIMIT_PER_MINUTE_DEFAULT;
        if ($v < AI_RATE_LIMIT_PER_MINUTE_MIN) $v = AI_RATE_LIMIT_PER_MINUTE_MIN;
        if ($v > AI_RATE_LIMIT_PER_MINUTE_MAX) $v = AI_RATE_LIMIT_PER_MINUTE_MAX;
        return $v;
    }
    $raw = ai_settings_get('ai_rate_limit_messages_per_day');
    $v = is_numeric($raw) ? (int)$raw : AI_RATE_LIMIT_PER_DAY_DEFAULT;
    if ($v < AI_RATE_LIMIT_PER_DAY_MIN) $v = AI_RATE_LIMIT_PER_DAY_MIN;
    if ($v > AI_RATE_LIMIT_PER_DAY_MAX) $v = AI_RATE_LIMIT_PER_DAY_MAX;
    return $v;
}

function ai_rate_limit_window_start(string $kind, ?DateTimeImmutable $now = null): string
{
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($kind === 'minute') {
        return $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0)->format('Y-m-d H:i:s');
    }
    return $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
}

/**
 * Best-effort idempotent install of the ai_chat_rate table. Called lazily on
 * the first ai_rate_limit_check() so admins do not have to remember to run
 * an installer. Safe to call repeatedly.
 */
function ai_rate_limit_ensure_table($con): void
{
    static $ensured = false;
    if ($ensured) return;
    $sql = "CREATE TABLE IF NOT EXISTS `ai_chat_rate` (
        `user_id` int NOT NULL,
        `window_kind` enum('minute','day') NOT NULL,
        `window_start` datetime NOT NULL,
        `count` int NOT NULL DEFAULT 0,
        PRIMARY KEY (`user_id`, `window_kind`, `window_start`),
        KEY `idx_aicr_user_kind` (`user_id`, `window_kind`)
    )";
    @$con->query($sql);
    $ensured = true;
}

/**
 * Returns the count in the current window (post-increment if $increment is
 * true, otherwise just the current count without writing).
 */
function ai_rate_limit_count($con, int $user_id, string $kind, bool $increment): int
{
    ai_rate_limit_ensure_table($con);
    $windowStart = ai_rate_limit_window_start($kind);

    if ($increment) {
        // INSERT … ON DUPLICATE KEY UPDATE keeps both branches atomic.
        $sql = "INSERT INTO ai_chat_rate (user_id, window_kind, window_start, count)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE count = count + 1";
        $stmt = $con->prepare($sql);
        $stmt->bind_param('iss', $user_id, $kind, $windowStart);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $con->prepare("SELECT count FROM ai_chat_rate WHERE user_id = ? AND window_kind = ? AND window_start = ?");
    $stmt->bind_param('iss', $user_id, $kind, $windowStart);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['count'] : 0;
}

/**
 * Check both windows. Returns:
 *   ['ok' => true,  'limit_minute' => N, 'used_minute' => M, 'limit_day' => N, 'used_day' => M]
 *   ['ok' => false, 'window' => 'minute'|'day', 'limit' => N, 'used' => M]
 *
 * Performs an atomic increment when 'ok' is true. When 'ok' is false NO
 * increment happens — the user message did not get processed.
 */
function ai_rate_limit_check($con, int $user_id): array
{
    ai_rate_limit_ensure_table($con);
    $minLimit = ai_rate_limit_get_limit('minute');
    $dayLimit = ai_rate_limit_get_limit('day');

    $usedMin = ai_rate_limit_count($con, $user_id, 'minute', false);
    if ($usedMin >= $minLimit) {
        return ['ok' => false, 'window' => 'minute', 'limit' => $minLimit, 'used' => $usedMin];
    }
    $usedDay = ai_rate_limit_count($con, $user_id, 'day', false);
    if ($usedDay >= $dayLimit) {
        return ['ok' => false, 'window' => 'day', 'limit' => $dayLimit, 'used' => $usedDay];
    }

    // Both under: increment both, then return the post-increment numbers.
    $usedMin = ai_rate_limit_count($con, $user_id, 'minute', true);
    $usedDay = ai_rate_limit_count($con, $user_id, 'day', true);
    return [
        'ok' => true,
        'limit_minute' => $minLimit, 'used_minute' => $usedMin,
        'limit_day'    => $dayLimit, 'used_day'    => $usedDay,
    ];
}

function ai_rate_limit_record_hit($con, int $user_id, string $window, int $limit, int $used): void
{
    // log_activity reads $_SESSION['user_id']; spoof it so the helper
    // attributes the hit to the calling user.
    $prev = $_SESSION['user_id'] ?? null;
    $_SESSION['user_id'] = $user_id;
    if (function_exists('log_activity')) {
        log_activity($con, 'ai_rate_limit_hit', 'ai_chat', null,
            "window=$window used=$used limit=$limit");
    }
    if ($prev !== null) $_SESSION['user_id'] = $prev;
    else                unset($_SESSION['user_id']);
}
