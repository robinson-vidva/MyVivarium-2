<?php
/**
 * Migration: enforce a unique login email (users.username).
 *
 * Idempotent. Aborts with a list of duplicates if any exist so the admin can
 * resolve them first. New installs already get this via schema.sql.
 *
 *   php database/migrations/2026_10_31_unique_username.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require __DIR__ . '/../../dbcon.php';

$idx = $con->query("SELECT 1 FROM information_schema.statistics
                     WHERE table_schema = DATABASE() AND table_name = 'users'
                       AND index_name = 'uniq_users_username' LIMIT 1");
if ($idx && $idx->num_rows > 0) {
    echo "uniq_users_username already present, nothing to do.\n";
    exit(0);
}

$dups = $con->query("SELECT username, COUNT(*) AS n FROM users GROUP BY username HAVING n > 1");
if ($dups && $dups->num_rows > 0) {
    fwrite(STDERR, "Cannot add unique index: duplicate usernames exist:\n");
    while ($r = $dups->fetch_assoc()) {
        fwrite(STDERR, "  {$r['username']} ({$r['n']} rows)\n");
    }
    fwrite(STDERR, "Rename or delete the duplicates in Manage Users, then re-run.\n");
    exit(1);
}

$con->query("ALTER TABLE users ADD UNIQUE KEY uniq_users_username (username)");
echo "Added uniq_users_username.\n";
