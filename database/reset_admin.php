<?php
/**
 * Admin reset / seed helper.
 *
 * Creates or updates an admin user in the configured database. Useful for:
 *   - Recovering after a `php database/install.php --reset` when you don't
 *     remember the seeded admin password.
 *   - Spinning up a fresh admin in a brand-new install without going
 *     through the registration flow.
 *
 * Reads connection details from the project .env (same loader as dbcon.php
 * and install.php).
 *
 * Usage (from the project root):
 *
 *   php database/reset_admin.php --email=you@lab.org --password='changeme'
 *
 *   # Optional: pin the display name and initials (defaults applied otherwise)
 *   php database/reset_admin.php --email=you@lab.org --password='secret' \
 *       --name='Robinson V' --initials=RV
 *
 * If the email already exists, the script updates that row's password,
 * role, and status. Otherwise it inserts a new admin row.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$autoload    = $projectRoot . '/vendor/autoload.php';
$envFile     = $projectRoot . '/.env';

if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run `composer install` first.\n");
    exit(1);
}
if (!file_exists($envFile)) {
    fwrite(STDERR, ".env not found at $envFile.\n");
    exit(1);
}

require $autoload;
Dotenv\Dotenv::createImmutable($projectRoot)->load();

// Parse --key=value style flags.
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') === 0 && strpos($arg, '=') !== false) {
        [$k, $v] = explode('=', substr($arg, 2), 2);
        $args[$k] = $v;
    }
}

$email    = $args['email']    ?? null;
$password = $args['password'] ?? null;
$name     = $args['name']     ?? 'Admin';
$initials = $args['initials'] ?? 'ADM';
$position = $args['position'] ?? 'Principal Investigator';

if (!$email || !$password) {
    fwrite(STDERR,
        "Required: --email=... --password='...'\n" .
        "Optional: --name='...' --initials=... --position='...'\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$host = $_ENV['DB_HOST']     ?? 'localhost';
$user = $_ENV['DB_USERNAME'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';
$db   = $_ENV['DB_DATABASE'] ?? 'myvivarium';

$con = new mysqli($host, $user, $pass, $db);
if ($con->connect_error) {
    fwrite(STDERR, "Connection failed: {$con->connect_error}\n");
    exit(1);
}
$con->set_charset('utf8mb4');

$hash = password_hash($password, PASSWORD_BCRYPT);

// Check if a row with this email already exists (the schema uses `username`
// for the email field — matches dbcon / login forms).
$check = $con->prepare("SELECT id FROM users WHERE username = ?");
$check->bind_param("s", $email);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing) {
    $upd = $con->prepare("
        UPDATE users
           SET password = ?, role = 'admin', status = 'approved',
               email_verified = 1, login_attempts = 0, account_locked = NULL,
               name = ?, initials = ?, position = ?
         WHERE id = ?
    ");
    $upd->bind_param("ssssi", $hash, $name, $initials, $position, $existing['id']);
    if (!$upd->execute()) {
        fwrite(STDERR, "Update failed: " . $upd->error . "\n");
        exit(1);
    }
    $upd->close();
    echo "Updated existing user (id={$existing['id']}) → admin/approved with new password.\n";
} else {
    $ins = $con->prepare("
        INSERT INTO users
            (name, username, position, role, password, status,
             login_attempts, email_verified, initials)
        VALUES (?, ?, ?, 'admin', ?, 'approved', 0, 1, ?)
    ");
    $ins->bind_param("sssss", $name, $email, $position, $hash, $initials);
    if (!$ins->execute()) {
        fwrite(STDERR, "Insert failed: " . $ins->error . "\n");
        exit(1);
    }
    $newId = $ins->insert_id;
    $ins->close();
    echo "Created admin (id=$newId, $email).\n";
}

echo "Log in at /index.php with:\n  email:    $email\n  password: (the one you just set)\n";

$con->close();
