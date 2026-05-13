<?php
/**
 * Per-API-key rate limiting.
 *
 * Fixed 1-minute windows, 120 requests max. Implemented as a single row per
 * key in `rate_limit` (key_id, window_start, request_count). On each request:
 *   - if no row → insert with count=1
 *   - if row's window is the current minute → increment
 *   - else → reset row to current minute, count=1
 * Returns the post-increment count.
 */

require_once __DIR__ . '/helpers.php';

const RATE_LIMIT_MAX_PER_MINUTE = 120;

/**
 * Returns [count_in_window, retry_after_seconds_if_exceeded].
 * Caller compares count to RATE_LIMIT_MAX_PER_MINUTE.
 */
function rate_limit_check(mysqli $con, int $key_id): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $windowStart = $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0);
    $windowStartStr = $windowStart->format('Y-m-d H:i:s');
    $secondsLeft = 60 - (int)$now->format('s');

    mysqli_begin_transaction($con);
    try {
        $stmt = $con->prepare("SELECT window_start, request_count FROM rate_limit WHERE key_id = ? FOR UPDATE");
        $stmt->bind_param('i', $key_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $ins = $con->prepare("INSERT INTO rate_limit (key_id, window_start, request_count) VALUES (?, ?, 1)");
            $ins->bind_param('is', $key_id, $windowStartStr);
            $ins->execute();
            $ins->close();
            $count = 1;
        } else if ($row['window_start'] === $windowStartStr) {
            $upd = $con->prepare("UPDATE rate_limit SET request_count = request_count + 1 WHERE key_id = ?");
            $upd->bind_param('i', $key_id);
            $upd->execute();
            $upd->close();
            $count = ((int)$row['request_count']) + 1;
        } else {
            $upd = $con->prepare("UPDATE rate_limit SET window_start = ?, request_count = 1 WHERE key_id = ?");
            $upd->bind_param('si', $windowStartStr, $key_id);
            $upd->execute();
            $upd->close();
            $count = 1;
        }
        mysqli_commit($con);
    } catch (Throwable $e) {
        mysqli_rollback($con);
        // If rate-limit infra fails, fail-open rather than 500 every request.
        error_log('rate_limit_check error: ' . $e->getMessage());
        return [0, 0];
    }

    return [$count, $secondsLeft];
}
