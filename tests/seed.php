<?php
/**
 * Seed test data for the API acceptance tests (T1..T9).
 *
 *     php tests/seed.php
 *
 * Idempotent: deletes any rows it creates before re-inserting. Prints the
 * raw admin + member API keys at the end so the shell harness can curl with
 * them.
 */
require __DIR__ . '/../dbcon.php';
require_once __DIR__ . '/../services/api_keys.php';

$con->query("SET FOREIGN_KEY_CHECKS = 0");
foreach (['rate_limit','api_request_log','pending_operations','api_keys',
          'maintenance','mouse_cage_history','mice','cage_users','breeding',
          'litters','cages','users'] as $t) {
    $con->query("DELETE FROM `$t`");
}
$con->query("SET FOREIGN_KEY_CHECKS = 1");

$pw = password_hash('test', PASSWORD_DEFAULT);
$con->query("INSERT INTO users (name, username, position, role, password, status)
             VALUES ('Admin User', 'admin@test', 'PI', 'admin', '$pw', 'approved'),
                    ('Member One', 'm1@test',    'Tech', 'user', '$pw', 'approved'),
                    ('Member Two', 'm2@test',    'Tech', 'user', '$pw', 'approved')");
$ids = [];
$res = $con->query("SELECT id, username FROM users ORDER BY id");
while ($r = $res->fetch_assoc()) $ids[$r['username']] = (int)$r['id'];

$adminId  = $ids['admin@test'];
$memberId = $ids['m1@test'];

// Cages: HC-1 (member is in cage_users), HC-2 (admin only).
$con->query("INSERT INTO cages (cage_id, pi_name, quantity, status) VALUES
             ('HC-1', $adminId, 2, 'active'),
             ('HC-2', $adminId, 1, 'active')");
$con->query("INSERT INTO cage_users (cage_id, user_id) VALUES ('HC-1', $memberId)");

// Mice: M-1 in HC-1 (alive), M-2 in HC-1 (alive), M-3 in HC-2, M-arch archived.
$con->query("INSERT INTO mice (mouse_id, sex, dob, current_cage_id, strain, status, created_by) VALUES
             ('M-1','male','2025-01-01','HC-1', NULL,'alive',$adminId),
             ('M-2','female','2025-01-01','HC-1', NULL,'alive',$adminId),
             ('M-3','male','2025-01-01','HC-2', NULL,'alive',$adminId),
             ('M-arch','female','2024-06-01',NULL,NULL,'archived',$adminId)");

// API keys.
$adminKey = api_key_create($con, $adminId, 'admin-write', ['read','write']);
$readKey  = api_key_create($con, $memberId, 'member-read', ['read']);
$writeKey = api_key_create($con, $memberId, 'member-write', ['read','write']);

// Pre-create an expired key for T8 use.
$rawExpired = api_key_generate_raw();
$hash = api_key_hash($rawExpired);
$past = date('Y-m-d H:i:s', time() - 3600);
$stmt = $con->prepare("INSERT INTO api_keys (user_id, key_hash, label, scopes, expires_at) VALUES (?, ?, 'expired-key', 'read,write', ?)");
$stmt->bind_param('iss', $memberId, $hash, $past);
$stmt->execute();

echo "ADMIN_KEY=" . $adminKey['raw'] . "\n";
echo "READ_KEY=" . $readKey['raw'] . "\n";
echo "WRITE_KEY=" . $writeKey['raw'] . "\n";
echo "EXPIRED_KEY=" . $rawExpired . "\n";
echo "ADMIN_ID=$adminId\n";
echo "MEMBER_ID=$memberId\n";
