<?php
/**
 * Role capability matrix — the single source of truth for what each account
 * category may do.
 *
 * These are PURE string functions with NO dependencies (no DB, no session),
 * so both layers can share them:
 *   - Web pages gate on $_SESSION['role'] (e.g. role_can_add_cage in hc_addn.php).
 *   - The service/API layer resolves the role from the DB first
 *     (services/permissions.php) and then defers to these helpers.
 *
 * Roles (descending privilege):
 *   admin            — full control: all management, plus delete/archive.
 *   vivarium_manager — view-only on cages/mice, but MAY still log maintenance.
 *   veterinarian     — user-level: add/edit cages & mice, but may NOT
 *                      delete/archive a cage.
 *   iacuc_member     — view-only on everything (no writes at all).
 *   user             — standard researcher: add/edit/delete cages they are
 *                      assigned to, and log maintenance.
 */

const ROLE_ADMIN            = 'admin';
const ROLE_VIVARIUM_MANAGER = 'vivarium_manager';
const ROLE_VETERINARIAN     = 'veterinarian';
const ROLE_IACUC_MEMBER     = 'iacuc_member';
const ROLE_USER             = 'user';

/** All assignable roles, in descending-privilege order. */
function roles_all(): array
{
    return [ROLE_ADMIN, ROLE_VIVARIUM_MANAGER, ROLE_VETERINARIAN, ROLE_IACUC_MEMBER, ROLE_USER];
}

/** Is this a recognised, assignable role? */
function role_is_valid(?string $role): bool
{
    return in_array($role, roles_all(), true);
}

/** Human-friendly label for a role string. */
function role_label(?string $role): string
{
    switch ($role) {
        case ROLE_ADMIN:            return 'Admin';
        case ROLE_VIVARIUM_MANAGER: return 'Vivarium Manager';
        case ROLE_VETERINARIAN:     return 'Veterinarian';
        case ROLE_IACUC_MEMBER:     return 'IACUC Member';
        case ROLE_USER:             return 'User';
        default:                    return $role ?? 'Unknown';
    }
}

/**
 * May this role create or edit cages/mice? Non-admins additionally need a
 * per-cage assignment (cage_users) — this only gates the role itself.
 */
function role_can_write(?string $role): bool
{
    return in_array($role, [ROLE_ADMIN, ROLE_USER, ROLE_VETERINARIAN], true);
}

/**
 * May this role create a new cage? Same role set as write; the per-cage
 * assignment check does not apply because the cage does not exist yet.
 */
function role_can_add_cage(?string $role): bool
{
    return role_can_write($role);
}

/** May this role delete/archive a cage? Veterinarians explicitly may not. */
function role_can_delete(?string $role): bool
{
    return in_array($role, [ROLE_ADMIN, ROLE_USER], true);
}

/** May this role add a maintenance note? View-only vivarium managers still can. */
function role_can_add_note(?string $role): bool
{
    return in_array($role, [ROLE_ADMIN, ROLE_VIVARIUM_MANAGER, ROLE_VETERINARIAN, ROLE_USER], true);
}

/**
 * View-only roles never write cages/mice. (vivarium_manager is view-only here
 * but may still log notes — see role_can_add_note.) Unknown/empty roles are
 * treated as view-only, which fails safe.
 */
function role_is_view_only(?string $role): bool
{
    return !role_can_write($role);
}
