<?php
/**
 * V1 → V2 import engine.
 *
 * Pure data-transform functions shared by the admin import page
 * (admin_import.php) and the CLI/test harness. No session, header, or HTML
 * output here — just the destination snapshot, reset, and the transactional
 * import that turns a V1 export payload into V2 mouse-as-entity rows.
 *
 * Callers must have an open mysqli connection ($con) and have loaded
 * log_activity.php (run_import writes an audit entry on success).
 */

/**
 * Snapshot the destination DB so we can show admins exactly what's at risk
 * before they confirm an overwrite. `has_data` is the gate the upstream
 * caller uses to decide whether to ask for confirmation.
 */
function describe_destination(mysqli $con): array
{
    $row = $con->query("
        SELECT
          (SELECT COUNT(*) FROM mice)     AS mice,
          (SELECT COUNT(*) FROM cages)    AS cages,
          (SELECT COUNT(*) FROM breeding) AS breeding,
          (SELECT COUNT(*) FROM litters)  AS litters,
          (SELECT COUNT(*) FROM users WHERE id > 1) AS users_beyond_seed,
          (SELECT COUNT(*) FROM users)    AS users_total
    ")->fetch_assoc();

    return [
        'mice'              => (int)$row['mice'],
        'cages'             => (int)$row['cages'],
        'breeding'          => (int)$row['breeding'],
        'litters'           => (int)$row['litters'],
        'users_beyond_seed' => (int)$row['users_beyond_seed'],
        'users_total'       => (int)$row['users_total'],
        'has_data'          => ((int)$row['mice'] + (int)$row['cages']
                              + (int)$row['breeding'] + (int)$row['litters']
                              + (int)$row['users_beyond_seed']) > 0,
    ];
}

/**
 * Wipe all data from every table while keeping the schema intact. Used
 * when the admin has explicitly confirmed an overwrite.
 *
 * Uses DELETE FROM rather than TRUNCATE — TRUNCATE on a table that's
 * referenced by another table's FK can fail even with FOREIGN_KEY_CHECKS=0
 * on some MySQL versions, which would leave the DB in a half-wiped state
 * and the import in a broken position. DELETE is slower but reliable.
 * AUTO_INCREMENT is then reset on each table so freshly-imported ids
 * land at clean values rather than continuing where the wiped data
 * left off.
 */
function reset_destination(mysqli $con): void
{
    // Order matters: dependent tables first, then the parents they
    // reference. Inside a single FOREIGN_KEY_CHECKS=0 block this is
    // belt-and-suspenders, but it also reads naturally to a future
    // human auditor.
    $tables = [
        'activity_log', 'outbox', 'notifications', 'reminders',
        'maintenance', 'tasks', 'notes', 'files',
        'mouse_cage_history',
        'litters', 'breeding',
        'mice',
        'cage_users', 'cage_iacuc',
        'cages',
        'strains', 'iacuc',
        'settings',
        'users',
    ];
    $con->query("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($tables as $t) {
        $con->query("DELETE FROM `$t`");
        $con->query("ALTER TABLE `$t` AUTO_INCREMENT = 1");
    }
    $con->query("SET FOREIGN_KEY_CHECKS = 1");
}

/**
 * Insert a single row, named columns, INSERT IGNORE.
 */
function insert_row(mysqli $con, string $table, array $columns, array $row): void
{
    $cols = '`' . implode('`,`', $columns) . '`';
    $marks = implode(',', array_fill(0, count($columns), '?'));
    $stmt = $con->prepare("INSERT IGNORE INTO `$table` ($cols) VALUES ($marks)");
    if (!$stmt) throw new Exception("prepare $table: " . $con->error);

    $types  = '';
    $values = [];
    foreach ($columns as $c) {
        $v = $row[$c] ?? null;
        if ($v === null) {
            $types .= 's';
            $values[] = null;
        } elseif (is_int($v)) {
            $types .= 'i';
            $values[] = $v;
        } else {
            $types .= 's';
            $values[] = (string)$v;
        }
    }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) throw new Exception("insert $table: " . $stmt->error);
    $stmt->close();
}

function copy_table(mysqli $con, array $tables, string $name, array $columns, array &$counts): void
{
    $rows = $tables[$name] ?? [];
    foreach ($rows as $row) insert_row($con, $name, $columns, $row);
    $counts[$name] = count($rows);
}

/**
 * Apply the JSON payload. Returns a report (counts per logical step).
 * Throws on hard error so the caller can roll back.
 */
function run_import(mysqli $con, array $payload, array &$errors, ?int $actorUserId): ?array
{
    $tables = $payload['tables'];

    // Caller decides whether the destination is empty enough — if not, it
    // either bails out asking for confirmation, or calls reset_destination()
    // first. So we proceed directly here.

    $con->query("SET FOREIGN_KEY_CHECKS = 0");

    // Back up the seed admin row in PHP memory before the destructive step.
    // If we're not running on InnoDB (or the transaction rolls back partially)
    // we want to be able to put it back rather than losing administrative
    // access permanently.
    $seedAdminBackup = null;
    if ($seedRes = $con->query("SELECT * FROM users WHERE id = 1 LIMIT 1")) {
        $seedAdminBackup = $seedRes->fetch_assoc() ?: null;
        $seedRes->free();
    }

    $con->begin_transaction();
    $counts = [];
    try {
        // 0. Drop the seed admin (id=1, default Temporary Admin from
        // schema.sql) so the imported V1 user with id=1 doesn't collide
        // and get silently dropped by INSERT IGNORE.
        $con->query("DELETE FROM users WHERE id = 1");

        // 1. Reference data — copied as-is.
        copy_table($con, $tables, 'users',
            ['id','name','username','position','role','password','status',
             'reset_token','reset_token_expiration','login_attempts','account_locked',
             'email_verified','email_token','initials'],
            $counts);

        copy_table($con, $tables, 'iacuc', ['iacuc_id','iacuc_title','file_url'], $counts);
        copy_table($con, $tables, 'strains', ['id','str_id','str_name','str_aka','str_url','str_rrid','str_notes'], $counts);
        copy_table($con, $tables, 'settings', ['name','value'], $counts);

        // 2. Cages + junctions. V1 cages don't carry status/room/rack/
        // created_at on the original schema; default them here so the
        // INSERT works against V2's column set.
        $cageRows = $tables['cages'] ?? [];
        foreach ($cageRows as $row) {
            insert_row($con, 'cages',
                ['cage_id','pi_name','quantity','remarks','status','room','rack','created_at'],
                [
                    'cage_id'    => $row['cage_id'] ?? null,
                    'pi_name'    => $row['pi_name'] ?? null,
                    'quantity'   => $row['quantity'] ?? null,
                    'remarks'    => $row['remarks'] ?? null,
                    'status'     => $row['status']  ?? 'active',
                    'room'       => $row['room']    ?? null,
                    'rack'       => $row['rack']    ?? null,
                    'created_at' => $row['created_at'] ?? null,
                ]);
        }
        $counts['cages'] = count($cageRows);

        copy_table($con, $tables, 'cage_iacuc', ['cage_id','iacuc_id'], $counts);
        copy_table($con, $tables, 'cage_users', ['cage_id','user_id'], $counts);

        // 3. Mice. V1 stored cage-level defaults (strain/dob/sex/parent_cg)
        // on `holding` and per-mouse identity (mouse_id/genotype/notes) on
        // `mice`. V2 has a single canonical mouse entity, so each V1 mouse
        // picks up its cage's holding-row defaults at import time. This
        // preserves data that the previous "synthesize one mouse per
        // holding row" approach was throwing away.
        $holdingByCage = [];
        foreach (($tables['holding'] ?? []) as $h) {
            if (!empty($h['cage_id'])) $holdingByCage[$h['cage_id']] = $h;
        }

        $miceByCage = [];
        foreach (($tables['mice'] ?? []) as $m) {
            if (!empty($m['cage_id'])) $miceByCage[$m['cage_id']][] = $m;
        }

        // 3a. For each V1 mouse, merge in its cage's holding row defaults.
        // V1's mouse_id is only unique per cage; V2's is a global primary
        // key. Track the ids we've inserted so a mouse_id reused across two
        // V1 cages doesn't silently vanish under INSERT IGNORE — the
        // collider is re-homed under a disambiguated id and counted.
        $usedMouseIds = [];
        $importedV1Mice = 0;
        $renamedDuplicates = 0;
        foreach (($tables['mice'] ?? []) as $m) {
            if (empty($m['mouse_id'])) continue;
            $cageId  = $m['cage_id'] ?? null;
            $holding = $cageId ? ($holdingByCage[$cageId] ?? null) : null;

            $sex = strtolower((string)($holding['sex'] ?? ''));
            if (!in_array($sex, ['male', 'female'], true)) $sex = 'unknown';

            // Disambiguate a duplicate mouse_id (same id in another V1 cage)
            // so the second occurrence is preserved rather than dropped.
            $mouseId = (string)$m['mouse_id'];
            $notes   = $m['notes'] ?? null;
            if (isset($usedMouseIds[$mouseId])) {
                $suffix  = $cageId !== null ? '_' . $cageId : '_dup' . ($renamedDuplicates + 1);
                $newId   = $mouseId . $suffix;
                // In the unlikely event the suffixed id also collides, keep
                // appending until it's unique.
                $n = 2;
                while (isset($usedMouseIds[$newId])) { $newId = $mouseId . $suffix . '_' . $n; $n++; }
                $dupNote = 'Duplicate of V1 mouse_id "' . $mouseId . '" (reused across cages); re-homed as "' . $newId . '".';
                $notes   = $notes !== null && $notes !== '' ? $notes . ' ' . $dupNote : $dupNote;
                $mouseId = $newId;
                $renamedDuplicates++;
            }

            insert_row($con, 'mice',
                ['mouse_id','sex','dob','current_cage_id','strain','genotype','status','notes','source_cage_label'],
                [
                    'mouse_id'          => $mouseId,
                    'sex'               => $sex,
                    'dob'               => $holding['dob']    ?? null,
                    'current_cage_id'   => $cageId,
                    'strain'            => $holding['strain'] ?? null,
                    'genotype'          => $m['genotype']     ?? null,
                    'status'            => 'alive',
                    'notes'             => $notes,
                    'source_cage_label' => $holding['parent_cg'] ?? null,
                ]);
            $usedMouseIds[$mouseId] = true;
            $importedV1Mice++;
        }
        $counts['mice'] = $importedV1Mice;
        if ($renamedDuplicates > 0) $counts['mice_duplicate_ids_rehomed'] = $renamedDuplicates;

        // 3b. Defensive fallback: for any cage that had a holding row but
        // *no* mice rows (V1 cage-level data with no individual mice
        // listed), synthesize one mouse so the holding-row data isn't
        // lost. This shouldn't trigger on well-populated V1 dbs but
        // protects against partial/legacy data.
        $synthFromHolding = 0;
        foreach ($holdingByCage as $cageId => $h) {
            if (!empty($miceByCage[$cageId])) continue;
            $sex = strtolower((string)($h['sex'] ?? ''));
            if (!in_array($sex, ['male', 'female'], true)) $sex = 'unknown';
            $synthId = 'H_' . $cageId . '_' . ($h['id'] ?? '0');
            if (isset($usedMouseIds[$synthId])) continue;
            insert_row($con, 'mice',
                ['mouse_id','sex','dob','current_cage_id','strain','status','notes','source_cage_label'],
                [
                    'mouse_id'          => $synthId,
                    'sex'               => $sex,
                    'dob'               => $h['dob'] ?? null,
                    'current_cage_id'   => $cageId,
                    'strain'            => $h['strain'] ?? null,
                    'status'            => 'alive',
                    'notes'             => 'Synthesized from v1 holding row (no individual mice listed for this cage in v1).',
                    'source_cage_label' => $h['parent_cg'] ?? null,
                ]);
            $usedMouseIds[$synthId] = true;
            $synthFromHolding++;
        }
        if ($synthFromHolding > 0) $counts['mice_synthesized_from_orphan_holding'] = $synthFromHolding;

        // 3c. Breeding parents — synthesize archived mouse rows for any
        // male_id/female_id values that the V1 breeding row references but
        // we haven't already imported as a real mouse. status='archived'
        // + current_cage_id=NULL keeps them out of live counts.
        $existingMouseIds = [];
        $r = $con->query("SELECT mouse_id FROM mice");
        while ($row = $r->fetch_assoc()) $existingMouseIds[$row['mouse_id']] = true;
        $r->close();

        $synthMales = 0;
        $synthFemales = 0;
        foreach (($tables['breeding'] ?? []) as $b) {
            $mid = trim((string)($b['male_id'] ?? ''));
            if ($mid !== '' && !isset($existingMouseIds[$mid])) {
                insert_row($con, 'mice',
                    ['mouse_id','sex','dob','current_cage_id','genotype','status','notes'],
                    [
                        'mouse_id'        => $mid,
                        'sex'             => 'male',
                        'dob'             => $b['male_dob'] ?? null,
                        'current_cage_id' => null,
                        'genotype'        => $b['male_genotype'] ?? null,
                        'status'          => 'archived',
                        'notes'           => 'Synthesized from v1 breeding parent; v1 source: ' . ($b['male_parent_cage'] ?? 'unknown'),
                    ]);
                $existingMouseIds[$mid] = true;
                $synthMales++;
            }
            $fid = trim((string)($b['female_id'] ?? ''));
            if ($fid !== '' && !isset($existingMouseIds[$fid])) {
                insert_row($con, 'mice',
                    ['mouse_id','sex','dob','current_cage_id','genotype','status','notes'],
                    [
                        'mouse_id'        => $fid,
                        'sex'             => 'female',
                        'dob'             => $b['female_dob'] ?? null,
                        'current_cage_id' => null,
                        'genotype'        => $b['female_genotype'] ?? null,
                        'status'          => 'archived',
                        'notes'           => 'Synthesized from v1 breeding parent; v1 source: ' . ($b['female_parent_cage'] ?? 'unknown'),
                    ]);
                $existingMouseIds[$fid] = true;
                $synthFemales++;
            }
        }
        if ($synthMales + $synthFemales > 0) {
            $counts['mice_synthesized_from_breeding'] = $synthMales + $synthFemales;
        }

        // 4. Seed mouse_cage_history with one open interval per cage-resident mouse.
        $seedHistory = $con->prepare("
            INSERT INTO mouse_cage_history (mouse_id, cage_id, moved_in_at, reason)
            SELECT m.mouse_id, m.current_cage_id, m.created_at, 'v1 import: initial cage assignment'
              FROM mice m WHERE m.current_cage_id IS NOT NULL
        ");
        if (!$seedHistory->execute()) throw new Exception("seed history: " . $seedHistory->error);
        $counts['history_open_intervals'] = $seedHistory->affected_rows;
        $seedHistory->close();

        // 5. Breeding rows — slim columns, NULL out parent IDs that don't resolve to a mouse
        $breedingRows = $tables['breeding'] ?? [];
        foreach ($breedingRows as $b) {
            $male   = trim((string)($b['male_id'] ?? ''));
            $female = trim((string)($b['female_id'] ?? ''));
            if ($male !== '' && !isset($existingMouseIds[$male]))   $male   = null;
            if ($female !== '' && !isset($existingMouseIds[$female])) $female = null;
            if ($male === '')   $male   = null;
            if ($female === '') $female = null;

            insert_row($con, 'breeding',
                ['id','cage_id','cross','male_id','female_id'],
                [
                    'id'        => $b['id'] ?? null,
                    'cage_id'   => $b['cage_id'] ?? null,
                    'cross'     => $b['cross'] ?? null,
                    'male_id'   => $male,
                    'female_id' => $female,
                ]);
        }
        $counts['breeding'] = count($breedingRows);

        // 6. Tail tables
        copy_table($con, $tables, 'litters',
            ['id','cage_id','dom','litter_dob','pups_alive','pups_dead','pups_male','pups_female','remarks'], $counts);
        copy_table($con, $tables, 'files',
            ['id','file_name','file_path','uploaded_at','cage_id'], $counts);
        copy_table($con, $tables, 'notes',
            ['id','cage_id','note_text','created_at','user_id'], $counts);
        copy_table($con, $tables, 'tasks',
            ['id','title','description','assigned_by','assigned_to','status','completion_date',
             'cage_id','creation_date','updated_at'], $counts);
        copy_table($con, $tables, 'maintenance',
            ['id','cage_id','user_id','comments','timestamp'], $counts);
        copy_table($con, $tables, 'reminders',
            ['id','title','description','assigned_by','assigned_to','recurrence_type',
             'day_of_week','day_of_month','time_of_day','status','cage_id',
             'creation_date','updated_at','last_task_created'], $counts);
        copy_table($con, $tables, 'notifications',
            ['id','user_id','title','message','link','type','is_read','created_at'], $counts);
        copy_table($con, $tables, 'outbox',
            ['id','recipient','subject','body','status','created_at','scheduled_at','sent_at','error_message','task_id'], $counts);
        copy_table($con, $tables, 'activity_log',
            ['id','user_id','action','entity_type','entity_id','details','ip_address','created_at'], $counts);

        $con->commit();
        $con->query("SET FOREIGN_KEY_CHECKS = 1");

        log_activity($con, 'import', 'v1_data', null,
            'V1 export imported: ' . json_encode($counts));

        return $counts;
    } catch (Exception $e) {
        $con->rollback();
        $con->query("SET FOREIGN_KEY_CHECKS = 1");

        // If the seed admin was deleted before failure and the engine didn't
        // roll the DELETE back (e.g. non-InnoDB tables), restore it from the
        // in-memory backup so the operator isn't locked out.
        if ($seedAdminBackup !== null) {
            $checkSeed = $con->query("SELECT 1 FROM users WHERE id = 1 LIMIT 1");
            if ($checkSeed && $checkSeed->num_rows === 0) {
                $cols = array_keys($seedAdminBackup);
                $colList = '`' . implode('`,`', $cols) . '`';
                $marks = implode(',', array_fill(0, count($cols), '?'));
                $restoreStmt = $con->prepare("INSERT INTO users ($colList) VALUES ($marks)");
                if ($restoreStmt) {
                    $types = str_repeat('s', count($cols));
                    $vals  = array_values($seedAdminBackup);
                    $restoreStmt->bind_param($types, ...$vals);
                    $restoreStmt->execute();
                    $restoreStmt->close();
                }
            }
        }

        $errors[] = 'Import failed and was rolled back: ' . $e->getMessage();
        return null;
    }
}
