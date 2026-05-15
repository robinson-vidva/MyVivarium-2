<?php
/**
 * Sandbox-runnable tests for the LLM failure-message formatter.
 *
 *   - chatbot_categorize_llm_error() buckets each HTTP/network failure
 *     into the right category (rate_limit, server_error, timeout,
 *     network, other) with the right user-facing message.
 *   - chatbot_format_llm_failure_reply() layers in the
 *     "data-was-retrieved" prefix when tools already succeeded and the
 *     "configure a fallback" tip when no fallback is in the chain.
 *
 *     php tests/chatbot_error_messages_test.php
 */

require_once __DIR__ . '/../includes/chatbot_helpers.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

// ---------------------------------------------------------------------------
// chatbot_categorize_llm_error — every required bucket
// ---------------------------------------------------------------------------

$c = chatbot_categorize_llm_error(429, 'rate_limit_exceeded');
check('429 categorized as rate_limit',  $c['category'] === 'rate_limit');
check('429 message mentions rate limit', stripos($c['message'], 'rate limit') !== false);
check('429 message mentions retry',      stripos($c['message'], 'try again') !== false);

$c = chatbot_categorize_llm_error(500, 'internal');
check('500 categorized as server_error', $c['category'] === 'server_error');
check('500 message names the status',    strpos($c['message'], '500') !== false);

$c = chatbot_categorize_llm_error(503, 'unavailable');
check('503 categorized as server_error', $c['category'] === 'server_error');

$c = chatbot_categorize_llm_error(502, 'bad gateway');
check('502 categorized as server_error', $c['category'] === 'server_error');

$c = chatbot_categorize_llm_error(0, 'Operation timed out after 15000 milliseconds');
check('curl timeout categorized as timeout', $c['category'] === 'timeout');
check('timeout message says timed out',     stripos($c['message'], 'timed out') !== false);

$c = chatbot_categorize_llm_error(0, 'Connection timeout');
check('"timeout" word also categorized as timeout', $c['category'] === 'timeout');

$c = chatbot_categorize_llm_error(0, 'Could not resolve host: api.openai.com');
check('DNS failure categorized as network', $c['category'] === 'network');
check('network message names internet',     stripos($c['message'], 'internet') !== false);

$c = chatbot_categorize_llm_error(0, 'Connection refused');
check('connection refused categorized as network', $c['category'] === 'network');

$c = chatbot_categorize_llm_error(400, 'bad request');
check('400 categorized as other', $c['category'] === 'other');

$c = chatbot_categorize_llm_error(401, 'unauthorized');
check('401 categorized as other', $c['category'] === 'other');

$c = chatbot_categorize_llm_error(404, 'not found');
check('404 categorized as other', $c['category'] === 'other');

$c = chatbot_categorize_llm_error(418, 'teapot');
check('418 categorized as other', $c['category'] === 'other');

// ---------------------------------------------------------------------------
// chatbot_format_llm_failure_reply — layering
// ---------------------------------------------------------------------------

// Case 1: no tools succeeded, no fallback configured. Should be plain
// categorized message plus the fallback tip.
$r = chatbot_format_llm_failure_reply(429, '', false, false);
check('no-tools/no-fallback: contains rate-limit message',
    stripos($r, 'rate limit') !== false);
check('no-tools/no-fallback: does NOT claim data was retrieved',
    stripos($r, 'retrieved your data') === false);
check('no-tools/no-fallback: contains fallback tip',
    stripos($r, 'configure a fallback') !== false);

// Case 2: tools succeeded, no fallback. Should prefix with the "data was
// retrieved" sentence AND keep the categorized message + fallback tip.
$r = chatbot_format_llm_failure_reply(429, '', true, false);
check('tools/no-fallback: prefix mentions data was retrieved',
    stripos($r, 'retrieved your data') !== false);
check('tools/no-fallback: still names rate limit',
    stripos($r, 'rate limit') !== false);
check('tools/no-fallback: still has fallback tip',
    stripos($r, 'configure a fallback') !== false);

// Case 3: tools succeeded, fallback chain in place. Should keep the
// "data was retrieved" prefix but drop the fallback tip.
$r = chatbot_format_llm_failure_reply(429, '', true, true);
check('tools/with-fallback: prefix mentions data was retrieved',
    stripos($r, 'retrieved your data') !== false);
check('tools/with-fallback: no fallback tip',
    stripos($r, 'configure a fallback') === false);

// Case 4: no tools, fallback configured (e.g. all providers in chain failed).
$r = chatbot_format_llm_failure_reply(500, '', false, true);
check('no-tools/with-fallback: server-error message',
    strpos($r, '500') !== false);
check('no-tools/with-fallback: no fallback tip',
    stripos($r, 'configure a fallback') === false);

// Case 5: 5xx with tools succeeded, no fallback — full layered message.
$r = chatbot_format_llm_failure_reply(503, '', true, false);
check('layered 503: data was retrieved',
    stripos($r, 'retrieved your data') !== false);
check('layered 503: names the status',
    strpos($r, '503') !== false);
check('layered 503: fallback tip',
    stripos($r, 'configure a fallback') !== false);

// Case 6: timeout, tools succeeded — message should mention timeout
// AND data-was-retrieved.
$r = chatbot_format_llm_failure_reply(0, 'Operation timed out after 15000 milliseconds', true, false);
check('timeout/tools: data was retrieved prefix',
    stripos($r, 'retrieved your data') !== false);
check('timeout/tools: timeout mentioned',
    stripos($r, 'timed out') !== false);

// Case 7: network error, no tools, no fallback.
$r = chatbot_format_llm_failure_reply(0, 'Could not resolve host', false, false);
check('network/none: internet mentioned',
    stripos($r, 'internet') !== false);
check('network/none: no data-retrieved claim',
    stripos($r, 'retrieved your data') === false);

// Case 8: unknown / other status. Should mention checking logs.
$r = chatbot_format_llm_failure_reply(400, 'invalid request', false, false);
check('other-error: directs to logs',
    stripos($r, 'logs') !== false);

// Case 9: regression — must NEVER fall back to the old vague message.
$r = chatbot_format_llm_failure_reply(429, '', true, true);
check('regression: never the old vague message',
    stripos($r, 'temporarily unavailable') === false);

// Case 10: per-spec phrasing — when data was retrieved, suggest trying
// again in a minute.
$r = chatbot_format_llm_failure_reply(429, '', true, true);
check('tools-succeeded reply suggests trying again in a minute',
    stripos($r, 'try again in a minute') !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
