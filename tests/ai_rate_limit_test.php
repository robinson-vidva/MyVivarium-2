<?php
/**
 * Unit tests for includes/ai_rate_limit.php.
 *
 * No real DB required — we stub mysqli with a tiny in-memory shim that
 * implements just the prepare/bind/execute methods used by the rate
 * limiter. This pins the count-up + window-rotation logic.
 *
 *     php tests/ai_rate_limit_test.php
 */

// Stub ai_settings_get before loading the rate-limit module.
$GLOBALS['__ai_settings_store'] = [];
function ai_settings_get(string $k): ?string
{
    return $GLOBALS['__ai_settings_store'][$k] ?? null;
}
class AiSettingsException extends RuntimeException {}

require_once __DIR__ . '/../includes/ai_rate_limit.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

// --- In-memory mysqli shim --------------------------------------------------

class StubResult
{
    public array $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function fetch_assoc(): ?array
    {
        return array_shift($this->rows);
    }
}

class StubStmt
{
    public array $params = [];
    public string $sql;
    public StubMysqli $con;
    public function __construct(string $sql, StubMysqli $con)
    {
        $this->sql = $sql;
        $this->con = $con;
    }
    public function bind_param(string $types, &...$args): void
    {
        $this->params = $args;
    }
    public function execute(): void
    {
        $this->con->execute($this->sql, $this->params);
    }
    public function get_result(): StubResult
    {
        return new StubResult($this->con->lastResultRows);
    }
    public function close(): void {}
}

class StubMysqli
{
    /** @var array<string, array{user_id:int,window_kind:string,window_start:string,count:int}> */
    public array $store = [];
    public array $lastResultRows = [];

    public function prepare(string $sql): StubStmt
    {
        return new StubStmt($sql, $this);
    }
    public function query(string $sql): bool { return true; } // CREATE TABLE no-op
    public function execute(string $sql, array $params): void
    {
        $this->lastResultRows = [];

        // INSERT … ON DUPLICATE KEY UPDATE
        if (strpos($sql, 'INSERT INTO ai_chat_rate') !== false) {
            [$uid, $kind, $win] = $params;
            $key = "$uid|$kind|$win";
            if (isset($this->store[$key])) {
                $this->store[$key]['count']++;
            } else {
                $this->store[$key] = [
                    'user_id' => (int)$uid, 'window_kind' => $kind,
                    'window_start' => $win, 'count' => 1
                ];
            }
            return;
        }

        // SELECT count FROM ai_chat_rate WHERE …
        if (strpos($sql, 'SELECT count FROM ai_chat_rate') !== false) {
            [$uid, $kind, $win] = $params;
            $key = "$uid|$kind|$win";
            if (isset($this->store[$key])) {
                $this->lastResultRows = [['count' => $this->store[$key]['count']]];
            }
            return;
        }
    }
}

// --- 1. Under limit allows -------------------------------------------------

$GLOBALS['__ai_settings_store'] = [
    'ai_rate_limit_messages_per_minute' => '3',
    'ai_rate_limit_messages_per_day'    => '10',
];
$con = new StubMysqli();

$res = ai_rate_limit_check($con, 1);
check('under limit allows (1/3)',      $res['ok'] === true && $res['used_minute'] === 1);
$res = ai_rate_limit_check($con, 1);
check('under limit allows (2/3)',      $res['ok'] === true && $res['used_minute'] === 2);
$res = ai_rate_limit_check($con, 1);
check('at limit boundary allows (3/3)',$res['ok'] === true && $res['used_minute'] === 3);

// --- 2. At limit blocks ----------------------------------------------------
$res = ai_rate_limit_check($con, 1);
check('over-limit blocks',                 $res['ok'] === false && $res['window'] === 'minute');
check('over-limit reports correct limit',  $res['limit'] === 3);

// --- 3. After window resets, allows again ----------------------------------
// Simulate window rollover: clear the per-minute window rows (keep day rows).
foreach (array_keys($con->store) as $k) {
    if (strpos($k, '|minute|') !== false) unset($con->store[$k]);
}
$res = ai_rate_limit_check($con, 1);
check('after window reset, allows again', $res['ok'] === true && $res['used_minute'] === 1);

// --- 4. Per-day limit is independent. Minimum is 10 (clamped). ------------
$GLOBALS['__ai_settings_store']['ai_rate_limit_messages_per_minute'] = '999';
$GLOBALS['__ai_settings_store']['ai_rate_limit_messages_per_day']    = '10';
$con2 = new StubMysqli();
for ($i = 0; $i < 10; $i++) ai_rate_limit_check($con2, 7);
$res = ai_rate_limit_check($con2, 7);
check('day limit blocks beyond threshold', $res['ok'] === false && $res['window'] === 'day');

// --- 5. Per-user isolation -------------------------------------------------
$con3 = new StubMysqli();
$GLOBALS['__ai_settings_store']['ai_rate_limit_messages_per_minute'] = '1';
ai_rate_limit_check($con3, 100);
$res = ai_rate_limit_check($con3, 101);  // different user
check('different user has fresh budget', $res['ok'] === true);

// --- 6. Limit clamps ------------------------------------------------------
$GLOBALS['__ai_settings_store']['ai_rate_limit_messages_per_minute'] = '0';
check('per-minute lower clamp',
    ai_rate_limit_get_limit('minute') === AI_RATE_LIMIT_PER_MINUTE_MIN);
$GLOBALS['__ai_settings_store']['ai_rate_limit_messages_per_minute'] = '9999';
check('per-minute upper clamp',
    ai_rate_limit_get_limit('minute') === AI_RATE_LIMIT_PER_MINUTE_MAX);

$GLOBALS['__ai_settings_store']['ai_rate_limit_messages_per_day'] = '1';
check('per-day lower clamp',
    ai_rate_limit_get_limit('day') === AI_RATE_LIMIT_PER_DAY_MIN);
$GLOBALS['__ai_settings_store']['ai_rate_limit_messages_per_day'] = '99999';
check('per-day upper clamp',
    ai_rate_limit_get_limit('day') === AI_RATE_LIMIT_PER_DAY_MAX);

// --- 7. Default values -----------------------------------------------------
unset($GLOBALS['__ai_settings_store']['ai_rate_limit_messages_per_minute']);
unset($GLOBALS['__ai_settings_store']['ai_rate_limit_messages_per_day']);
check('per-minute default is 10',
    ai_rate_limit_get_limit('minute') === AI_RATE_LIMIT_PER_MINUTE_DEFAULT);
check('per-day default is 200',
    ai_rate_limit_get_limit('day') === AI_RATE_LIMIT_PER_DAY_DEFAULT);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
