<?php
/**
 * Tests for llm_chat_completions_with_fallback() in includes/llm_provider.php.
 *
 *   php tests/provider_fallback_test.php
 *
 * Covers the failover trigger rules in the spec:
 *
 *   - 429              → falls through to next provider
 *   - 500-599          → falls through
 *   - status=0 + curl  → falls through (network/timeout/DNS/connection refused)
 *   - 400 / 401 / 403  → does NOT fall through (deterministic config error)
 *   - 404              → does NOT fall through
 *   - all-fail         → returns the last attempted provider's error envelope
 *   - first-try ok     → served_by_provider matches that provider, fell_back_from=[]
 *   - 429 then ok      → served_by_provider is the second one, fell_back_from=['first']
 *
 * HTTP is stubbed via LLM_HTTP_POST_HOOK / LLM_HTTP_GET_HOOK so the tests
 * never touch the network.
 */

class AiSettingsException extends RuntimeException {}

$GLOBALS['__llm_test_store'] = [];
function ai_settings_get(string $key): ?string
{
    return array_key_exists($key, $GLOBALS['__llm_test_store']) ? $GLOBALS['__llm_test_store'][$key] : null;
}
function _llm_test_set(string $key, ?string $value): void
{
    if ($value === null) { unset($GLOBALS['__llm_test_store'][$key]); return; }
    $GLOBALS['__llm_test_store'][$key] = $value;
}
function _llm_test_reset(): void
{
    $GLOBALS['__llm_test_store'] = [];
    unset($GLOBALS['LLM_HTTP_POST_HOOK']);
    unset($GLOBALS['LLM_HTTP_GET_HOOK']);
    unset($GLOBALS['__attempts']);
}

require_once __DIR__ . '/../includes/llm_provider.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

/**
 * Stand up a chain with groq → openai → custom (all configured) and stub the
 * HTTP hook to return one of a queue of canned (status, body) responses per
 * call. Each call appends the URL to $GLOBALS['__attempts'] so tests can
 * assert which providers were tried in which order.
 */
function setup_three_provider_chain(array $responses): void
{
    _llm_test_reset();
    _llm_test_set('llm_provider_chain', json_encode([
        ['provider' => 'groq',   'enabled' => true, 'priority' => 1],
        ['provider' => 'openai', 'enabled' => true, 'priority' => 2],
        ['provider' => 'custom', 'enabled' => true, 'priority' => 3],
    ]));
    _llm_test_set('groq_api_key',   'gsk_x');
    _llm_test_set('openai_api_key', 'sk-x');
    _llm_test_set('custom_preset',  'openai_compatible');
    _llm_test_set('custom_base_url','https://api.custom.example.com/v1');
    _llm_test_set('custom_model',   'foo');
    _llm_test_set('custom_api_key', 'cx');

    $GLOBALS['__attempts'] = [];
    $GLOBALS['__queue']    = $responses;
    $GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) {
        $GLOBALS['__attempts'][] = $url;
        $r = array_shift($GLOBALS['__queue']);
        if ($r === null) return [500, '{}', ''];
        return $r;
    };
}

function url_provider(string $url): string {
    if (strpos($url, 'groq.com')   !== false) return 'groq';
    if (strpos($url, 'openai.com') !== false) return 'openai';
    if (strpos($url, 'custom')     !== false) return 'custom';
    return '?';
}

function attempted_providers(): array {
    return array_map('url_provider', $GLOBALS['__attempts']);
}

$okResp = [200, json_encode([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
    'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
]), ''];

// ---------------------------------------------------------------------------
// 1. First-try success
// ---------------------------------------------------------------------------
setup_three_provider_chain([$okResp]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('first-try success: ok=true', $r['ok'] === true);
check('first-try success: served_by_provider=groq', $r['served_by_provider'] === 'groq');
check('first-try success: fell_back_from is empty', $r['fell_back_from'] === []);
check('first-try success: only groq attempted', attempted_providers() === ['groq']);

// ---------------------------------------------------------------------------
// 2. 429 from groq → falls through to openai which succeeds.
// ---------------------------------------------------------------------------
setup_three_provider_chain([
    [429, '{"error":{"message":"slow down"}}', ''],
    $okResp,
]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('429 → failover: ok=true (openai responded)', $r['ok'] === true);
check('429 → failover: served_by_provider=openai', $r['served_by_provider'] === 'openai');
check('429 → failover: fell_back_from=[groq]', $r['fell_back_from'] === ['groq']);
check('429 → failover: both providers attempted', attempted_providers() === ['groq', 'openai']);

// ---------------------------------------------------------------------------
// 3. 500 from groq → falls through.
// ---------------------------------------------------------------------------
setup_three_provider_chain([
    [500, '{"error":"upstream blew up"}', ''],
    $okResp,
]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('500 → failover: ok=true', $r['ok'] === true);
check('500 → failover: served_by_provider=openai', $r['served_by_provider'] === 'openai');
check('500 → failover: fell_back_from=[groq]', $r['fell_back_from'] === ['groq']);

// 5xx: 503 too
setup_three_provider_chain([
    [503, '{}', ''],
    $okResp,
]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('503 → failover', $r['ok'] === true && $r['fell_back_from'] === ['groq']);

// ---------------------------------------------------------------------------
// 4. Network/timeout (status=0) → falls through.
// ---------------------------------------------------------------------------
setup_three_provider_chain([
    [0, '', 'Operation timed out after 30000 milliseconds'],
    $okResp,
]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('timeout (status=0) → failover: ok=true', $r['ok'] === true);
check('timeout → failover: fell_back_from=[groq]', $r['fell_back_from'] === ['groq']);

// Connection refused (status=0 + curl error)
setup_three_provider_chain([
    [0, '', 'Could not resolve host: api.groq.com'],
    $okResp,
]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('DNS failure → failover', $r['ok'] === true && $r['fell_back_from'] === ['groq']);

// ---------------------------------------------------------------------------
// 5. 400 → does NOT fall through (returns error immediately).
// ---------------------------------------------------------------------------
setup_three_provider_chain([
    [400, '{"error":{"message":"bad request"}}', ''],
    $okResp, // would respond ok if asked, but failover should NOT happen
]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('400 → no failover: ok=false', $r['ok'] === false);
check('400 → no failover: only groq attempted (no failover)', attempted_providers() === ['groq']);
check('400 → no failover: status is 400', $r['status'] === 400);

// ---------------------------------------------------------------------------
// 6. 401 → does NOT fall through.
// ---------------------------------------------------------------------------
setup_three_provider_chain([
    [401, '{"error":{"message":"invalid api key"}}', ''],
    $okResp,
]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('401 → no failover: ok=false',   $r['ok'] === false);
check('401 → no failover: status=401', $r['status'] === 401);
check('401 → no failover: only groq attempted', attempted_providers() === ['groq']);
check('401 → no failover: fell_back_from empty', $r['fell_back_from'] === []);

// ---------------------------------------------------------------------------
// 7. 403 → does NOT fall through.
// ---------------------------------------------------------------------------
setup_three_provider_chain([
    [403, '{}', ''],
    $okResp,
]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('403 → no failover: ok=false',   $r['ok'] === false);
check('403 → no failover: only groq attempted', attempted_providers() === ['groq']);

// ---------------------------------------------------------------------------
// 8. 404 → does NOT fall through.
// ---------------------------------------------------------------------------
setup_three_provider_chain([
    [404, '{}', ''],
    $okResp,
]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('404 → no failover: ok=false',   $r['ok'] === false);
check('404 → no failover: only groq attempted', attempted_providers() === ['groq']);

// ---------------------------------------------------------------------------
// 9. All three providers fail with failover-worthy errors → returns the
// last error envelope with chain context.
// ---------------------------------------------------------------------------
setup_three_provider_chain([
    [429, '{"error":"rate limit"}', ''],
    [500, '{"error":"internal"}',   ''],
    [502, '{"error":"bad gateway"}', ''],
]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('all-fail: ok=false', $r['ok'] === false);
check('all-fail: status reflects last attempt (502)', $r['status'] === 502);
check('all-fail: served_by_provider is empty', $r['served_by_provider'] === '');
check('all-fail: fell_back_from lists every provider attempted',
    $r['fell_back_from'] === ['groq', 'openai', 'custom']);
check('all-fail: chain_attempted matches fell_back_from on total failure',
    $r['chain_attempted'] === ['groq', 'openai', 'custom']);

// ---------------------------------------------------------------------------
// 10. 429 → 500 → ok chain: served_by=custom, fell_back_from=[groq, openai]
// ---------------------------------------------------------------------------
setup_three_provider_chain([
    [429, '{"error":"slow down"}', ''],
    [500, '{"error":"internal"}',  ''],
    $okResp,
]);
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('429→500→ok: ok=true', $r['ok'] === true);
check('429→500→ok: served_by_provider=custom', $r['served_by_provider'] === 'custom');
check('429→500→ok: fell_back_from=[groq, openai]', $r['fell_back_from'] === ['groq', 'openai']);

// ---------------------------------------------------------------------------
// 11. Empty chain (no providers configured) → returns no_provider_available
// without any HTTP attempt.
// ---------------------------------------------------------------------------
_llm_test_reset();
$attempted = false;
$GLOBALS['LLM_HTTP_POST_HOOK'] = function () use (&$attempted) {
    $attempted = true; return [200, '{}', ''];
};
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('empty chain: ok=false', $r['ok'] === false);
check('empty chain: error=no_provider_available', $r['error'] === 'no_provider_available');
check('empty chain: no HTTP attempt made', $attempted === false);
check('empty chain: served_by_provider is empty', $r['served_by_provider'] === '');

// ---------------------------------------------------------------------------
// 12. Failover skips disabled / misconfigured entries silently.
// Only enabled-and-configured ones count toward the chain.
// ---------------------------------------------------------------------------
_llm_test_reset();
_llm_test_set('llm_provider_chain', json_encode([
    ['provider' => 'groq',   'enabled' => false, 'priority' => 1], // disabled
    ['provider' => 'openai', 'enabled' => true,  'priority' => 2], // configured
    ['provider' => 'custom', 'enabled' => true,  'priority' => 3], // misconfigured (no key)
]));
_llm_test_set('openai_api_key', 'sk-x');
_llm_test_set('custom_preset',  'azure_openai'); // missing other fields
$GLOBALS['__attempts'] = [];
$GLOBALS['LLM_HTTP_POST_HOOK'] = function ($url, $headers, $body) {
    $GLOBALS['__attempts'][] = $url;
    return [200, json_encode([
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
        'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ]), ''];
};
$r = llm_chat_completions_with_fallback([['role' => 'user', 'content' => 'hi']], []);
check('skip-disabled-misconfigured: ok=true', $r['ok'] === true);
check('skip-disabled-misconfigured: served_by_provider=openai',
    $r['served_by_provider'] === 'openai');
check('skip-disabled-misconfigured: only openai was attempted',
    attempted_providers() === ['openai']);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
