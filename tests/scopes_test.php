<?php
/**
 * Unit-style test for api_key_scopes() / api_key_has_scope().
 *
 * The validation middleware (api/index.php) and the admin UI
 * (manage_api_keys.php) must agree on what an api_keys.scopes value means.
 * This script pins the parser behavior so a future refactor can't silently
 * desync the two call sites.
 *
 *     php tests/scopes_test.php
 */

require_once __DIR__ . '/../services/api_keys.php';

$failures = 0;

function expect(string $name, bool $ok): void {
    global $failures;
    if ($ok) {
        echo "[PASS] $name\n";
    } else {
        echo "[FAIL] $name\n";
        $failures++;
    }
}

// 1. Read-only key: 'read' scope only.
$readOnly = ['scopes' => 'read'];
expect('read-only key has read scope',
    api_key_has_scope($readOnly, 'read') === true);
expect('read-only key lacks write scope',
    api_key_has_scope($readOnly, 'write') === false);
expect('read-only key scopes list is exactly [read]',
    array_values(api_key_scopes($readOnly)) === ['read']);

// 2. Write key: 'read,write' (canonical comma-separated, sorted).
$write = ['scopes' => 'read,write'];
expect('write key has read scope',
    api_key_has_scope($write, 'read') === true);
expect('write key has write scope',
    api_key_has_scope($write, 'write') === true);
expect('write key scopes list is [read, write]',
    array_values(api_key_scopes($write)) === ['read', 'write']);

// 3. Malformed scopes: empty / whitespace / commas / nonsense values.
$malformed = ['scopes' => '  ,, , foo , read , '];
expect('malformed scopes still extract read',
    api_key_has_scope($malformed, 'read') === true);
expect('malformed scopes do NOT grant write',
    api_key_has_scope($malformed, 'write') === false);
expect('malformed scopes parser drops empty tokens but keeps unknowns',
    array_values(api_key_scopes($malformed)) === ['foo', 'read']);

// 4. Missing column / empty string => no scopes at all.
$empty = ['scopes' => ''];
expect('empty scopes string => no read',
    api_key_has_scope($empty, 'read') === false);
expect('empty scopes string => no write',
    api_key_has_scope($empty, 'write') === false);

$missing = [];
expect('missing scopes key => no read',
    api_key_has_scope($missing, 'read') === false);
expect('missing scopes key => no write',
    api_key_has_scope($missing, 'write') === false);

if ($failures > 0) {
    fwrite(STDERR, "\n$failures test(s) failed\n");
    exit(1);
}
echo "\nAll scope tests passed.\n";
