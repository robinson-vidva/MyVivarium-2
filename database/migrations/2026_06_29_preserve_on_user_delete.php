<?php
/**
 * Migration: keep reminders + maintenance when their user is deleted.
 *
 * Previously reminders.assigned_by and maintenance.user_id were NOT NULL with
 * ON DELETE CASCADE, so deleting a user silently destroyed every reminder they
 * created (even ones assigned to other people) and erased their maintenance
 * audit history. This makes both columns nullable with ON DELETE SET NULL, so
 * the records survive (orphaned) and the attribution is simply cleared.
 *
 * Idempotent — safe to re-run. New installs already get this via schema.sql.
 *
 *   php database/migrations/2026_06_29_preserve_on_user_delete.php
 */

require __DIR__ . '/../../dbcon.php';

/** Current FK constraint name + delete rule for $table.$column -> users.id. */
function fk_info(mysqli $con, string $table, string $column): ?array {
    $stmt = $con->prepare("
        SELECT k.CONSTRAINT_NAME, r.DELETE_RULE
          FROM information_schema.KEY_COLUMN_USAGE k
          JOIN information_schema.REFERENTIAL_CONSTRAINTS r
            ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
           AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
         WHERE k.TABLE_SCHEMA = DATABASE()
           AND k.TABLE_NAME   = ?
           AND k.COLUMN_NAME  = ?
           AND k.REFERENCED_TABLE_NAME = 'users'
         LIMIT 1");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * @param string $colDef the new (nullable) column definition, e.g. 'INT DEFAULT NULL'
 */
function migrate(mysqli $con, string $table, string $column, string $colDef, string $newFk): void {
    $info = fk_info($con, $table, $column);
    if ($info && $info['CONSTRAINT_NAME'] === $newFk && strtoupper($info['DELETE_RULE']) === 'SET NULL') {
        echo "[skip]  $table.$column already SET NULL ($newFk)\n";
        return;
    }
    if ($info) {
        if (!$con->query("ALTER TABLE `$table` DROP FOREIGN KEY `{$info['CONSTRAINT_NAME']}`")) {
            fwrite(STDERR, "[error] drop FK {$info['CONSTRAINT_NAME']} on $table: {$con->error}\n"); exit(1);
        }
        echo "[ok]    dropped old FK {$info['CONSTRAINT_NAME']} on $table.$column\n";
    }
    if (!$con->query("ALTER TABLE `$table` MODIFY `$column` $colDef")) {
        fwrite(STDERR, "[error] modify $table.$column: {$con->error}\n"); exit(1);
    }
    if (!$con->query("ALTER TABLE `$table` ADD CONSTRAINT `$newFk` FOREIGN KEY (`$column`) REFERENCES `users` (`id`) ON DELETE SET NULL")) {
        fwrite(STDERR, "[error] add FK $newFk on $table: {$con->error}\n"); exit(1);
    }
    echo "[ok]    $table.$column -> nullable + ON DELETE SET NULL ($newFk)\n";
}

migrate($con, 'reminders',   'assigned_by', 'INT DEFAULT NULL', 'fk_reminders_assigned_by');
migrate($con, 'maintenance', 'user_id',     'int DEFAULT NULL', 'fk_maintenance_user');
echo "Done.\n";
