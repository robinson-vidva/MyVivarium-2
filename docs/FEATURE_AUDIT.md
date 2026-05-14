# MyVivarium Feature Audit

Generated: 2026-05-14
API spec version: 1.0 (api/openapi.yaml)
Codebase commit: 8bf85b1

## Summary

- Total user-facing pages: **58**
- Pages with full API coverage: **15**
- Pages with partial API coverage: **6**
- Pages with no API coverage: **37**
- Database tables: **27 total**, **13 accessed via API**, **14 not exposed**

The API today covers the lab-animal core (mice, holding cages, breeding cages, maintenance notes, audit log, identity). Everything that supports the lab around that core — tasks, reminders, notifications, sticky notes, IACUC, strains, files, lab/AI settings, user administration, auth flows, dashboards, and printable cage cards — has no API surface yet.

## Feature Groups

### Mice

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| mouse_addn.php | Create a new mouse entity (sex, DOB, parentage, strain, cage). | mice, cages, strains, mouse_cage_history, activity_log | GET, POST | authenticated | FULL | — |
| mouse_dash.php | List all mice with search/filter and optional columns. | mice, cages | GET | authenticated | FULL | — |
| mouse_drop.php | Hard-delete a mouse (admin) with reason audit log. | mice, mouse_cage_history, breeding, activity_log | POST | admin-only | PARTIAL | hard delete a mouse (API only soft-deletes/archives) |
| mouse_edit.php | Edit mouse metadata (ID, sex, DOB, genotype, parents, strain). | mice, strains, cages | GET, POST | authenticated | FULL | — |
| mouse_fetch_data.php | AJAX paginated mouse list + parent typeahead + inline cage create. | mice, cages, strains, activity_log | GET, POST | authenticated | FULL | — |
| mouse_move.php | Move a mouse to another cage (transactional history). | mice, mouse_cage_history, cages, activity_log | GET, POST | authenticated | FULL | — |
| mouse_sacrifice.php | Mark a mouse sacrificed and close its cage interval. | mice, mouse_cage_history, activity_log | POST | authenticated | FULL | — |
| mouse_view.php | View one mouse with full cage history, parents, and offspring. | mice, mouse_cage_history, strains, users | GET | authenticated | PARTIAL | list a mouse's cage-move history; list a mouse's offspring/descendants |

### Holding Cages

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| hc_addn.php | Create a holding cage with PI, IACUC, assigned users. | cages, cage_users, cage_iacuc | GET, POST | authenticated | PARTIAL | attach IACUC protocols at create time; assign additional users at create time |
| hc_dash.php | Dashboard listing active/archived holding cages with search. | cages, cage_users, mice | GET | authenticated | FULL | — |
| hc_drop.php | Archive, restore, or hard-delete a holding cage and dependents. | cages, cage_users, cage_iacuc, mice, mouse_cage_history, files, activity_log | POST | cage user / admin | NONE | archive cage; restore cage; hard-delete cage; cascade-clean cage occupants |
| hc_edit.php | Edit holding cage metadata, users, IACUC, files. | cages, cage_users, cage_iacuc, files | GET, POST | cage user / admin | PARTIAL | assign/remove user from cage; attach/detach IACUC protocol; upload/list/delete file attachment |
| hc_fetch_data.php | AJAX paginated holding cage list with optional columns. | cages, mice, cage_users | GET | authenticated | FULL | — |
| hc_view.php | View one holding cage with occupants, files, IACUC, recent notes. | cages, cage_users, cage_iacuc, mice, files, settings | GET | authenticated | FULL | — |
| delete_file.php | Delete a file attached to a cage. | files, cage_users | GET | cage user / admin | NONE | list cage files; delete cage file |
| prnt_crd.php | Render printable cage cards (2x2 grid). | cages, breeding, cage_users, cage_iacuc, mice, files, settings | GET | authenticated | NONE | render printable cage card payload (HTML or structured data) |
| slct_crd.php | Multi-select picker for which cages to print. | cages, breeding | GET | authenticated | NONE | (same — bulk fetch of cage card data) |

### Breeding Cages

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| bc_addn.php | Create a breeding cage with sire/dam, litters, IACUC, users. | breeding, cages, cage_users, cage_iacuc, litters | GET, POST | authenticated | PARTIAL | create initial litter at cage create time; attach IACUC; assign additional users |
| bc_dash.php | Dashboard listing active/archived breeding cages with search. | breeding, cages, cage_users, cage_iacuc | GET | authenticated | FULL | — |
| bc_drop.php | Archive, restore, or hard-delete a breeding cage + dependents. | breeding, cages, cage_users, cage_iacuc, litters, mice, files, activity_log | POST | cage user / admin | NONE | archive cage; restore cage; hard-delete cage |
| bc_edit.php | Edit breeding cage, pair, litters, IACUC, users, files. | breeding, cages, cage_users, cage_iacuc, files, litters | GET, POST | cage user / admin | PARTIAL | add litter; update litter; delete litter; assign/remove users; attach/detach IACUC; upload/delete file |
| bc_fetch_data.php | AJAX paginated breeding cage list. | breeding, cages, cage_users | GET | authenticated | FULL | — |
| bc_view.php | View one breeding cage with pair, litters, files, recent notes. | breeding, cages, cage_users, cage_iacuc, litters, mice, files, settings | GET | authenticated | FULL | — |
| cage_lineage.php | Show ancestor/descendant cages via mouse parentage walk. | mice | GET | authenticated | NONE | walk cage lineage (ancestor cages, descendant cages) for a given cage |

### Maintenance

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| maintenance.php | Record maintenance activity on selected cages with comments. | maintenance, cages | GET, POST | authenticated | FULL | — |
| vivarium_manager_notes.php | Search/edit/delete maintenance notes across users (manager/admin). | maintenance, cages, users, activity_log | GET, POST | manager / admin | FULL | — (list/get/patch/delete all available via /maintenance-notes) |

### Activity Log

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| activity_log.php | Searchable, paginated audit trail of all logged actions. | activity_log, users | GET | admin-only | FULL | — |

### Users

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| manage_users.php | Admin: approve, suspend, delete users; change roles. | users, activity_log | GET, POST | admin-only | NONE | list users; get user by id; approve user; suspend user; delete user; change user role; resend verification |
| user_profile.php | Edit own name, position, email; request password change. | users, settings | GET, POST | authenticated | NONE | update own profile (PATCH /me); request password reset for self |

### Tasks

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| manage_tasks.php | Create/edit/delete tasks, assign users, schedule emails. | tasks, users, outbox, cage_users | GET, POST | authenticated | NONE | list tasks (mine / all); get task; create task; update task; assign task; mark task done; delete task |
| get_task.php | AJAX: fetch one task's details. | tasks, users | GET | authenticated | NONE | get task by id |
| manage_reminder.php | Create/edit/delete recurring reminders with cage linking. | reminders, cages, cage_users | GET, POST | authenticated | NONE | list reminders; get reminder; create reminder; update reminder; activate/deactivate reminder; delete reminder |
| get_reminder.php | AJAX: fetch one reminder's details. | reminders | GET | authenticated | NONE | get reminder by id |
| calendar.php | Monthly calendar view of tasks and reminders. | tasks, reminders, settings | GET | authenticated | NONE | list tasks+reminders in a date range (calendar feed) |
| calendar_events.php | AJAX: FullCalendar event feed (color-coded). | tasks, reminders, users | GET | authenticated | NONE | (same — feed of events between two dates) |

### Notifications

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| get_notifications.php | AJAX: recent notifications + unread count. | notifications | GET | authenticated | NONE | list my notifications; get unread count |
| mark_notification.php | AJAX: mark one or all notifications as read. | notifications | POST | authenticated | NONE | mark notification read; mark all notifications read |

### Reports

(No dedicated "reports" pages exist; the closest analogs are `home.php` summary stats and `export_data.php`. The Reports feature group is therefore empty in the current code.)

### IoT Sensors

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| iot_sensors.php | Embedded iframes for external room sensor dashboards. | settings | GET | authenticated | NONE | get configured IoT sensor URLs (settings.iot_url_*) |

### Admin Settings

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| admin_import.php | V1 → V2 data import via JSON upload (optional DB reset). | mice, cages, breeding, litters, users, activity_log | GET, POST | admin-only | NONE | bulk import; reset DB (intentionally not exposed) |
| ai_test_connection.php | AJAX: probe stored LLM API key for liveness. | ai_settings | GET | admin-only | NONE | test AI provider configuration |
| export_data.php | Export every table to CSV and download as ZIP. | (all) | GET | admin-only | NONE | export DB (likely should stay file-bound, not API) |
| manage_ai_config.php | Configure LLM provider, key, model, system prompt, toggle. | ai_settings | GET, POST | admin-only | NONE | get AI config; update AI config; rotate AI provider key |
| manage_api_keys.php | Generate/list/revoke REST API keys per user. | api_keys, users | GET, POST | admin-only | NONE | list keys; create key; revoke key (intentional self-bootstrap gap) |
| manage_iacuc.php | Add/edit/delete IACUC protocols with file uploads. | iacuc | GET, POST | admin-only | NONE | list IACUC; get IACUC; create IACUC; update IACUC; delete IACUC |
| manage_lab.php | Configure lab name/URL/timezone/IoT URLs/Turnstile keys. | settings | GET, POST | admin-only | NONE | get lab settings; update lab settings |
| manage_strain.php | Add/edit/delete strain definitions with external URLs/RRID. | strains | GET, POST | admin-only | NONE | list strains; get strain; create strain; update strain; delete strain |

### Auth

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| index.php | Login (email/password + Turnstile). | users, settings | GET, POST | public | NONE | (intentional — API uses X-API-Key, not session) |
| register.php | New user registration with email verification + CAPTCHA. | users, settings | GET, POST | public | NONE | (intentional) |
| confirm_email.php | Verify email token, mark user confirmed. | users, settings | GET | token-gated | NONE | (intentional) |
| forgot_password.php | Send password reset token via email. | users, settings | GET, POST | public | NONE | (intentional) |
| reset_password.php | Validate token and update password. | users | GET, POST | token-gated | NONE | (intentional) |
| logout.php | Destroy session and revoke chatbot key. | (none) | GET, POST | authenticated | NONE | (intentional) |

### Other

| Page | Purpose | DB tables | Methods | Access | API Status | Missing Operations |
|------|---------|-----------|---------|--------|------------|--------------------|
| home.php | Dashboard: cage counts, task stats, general notes. | cages, breeding, tasks, settings | GET | authenticated | NONE | dashboard summary endpoint (counts, recent activity) |
| nt_app.php | Sticky notes page (list user's notes by cage or general). | notes, users | GET | authenticated | NONE | list sticky notes |
| nt_add.php | AJAX: create a sticky note (optional cage link). | notes, users | POST | authenticated | NONE | create sticky note |
| nt_edit.php | AJAX: edit a sticky note (owner or admin). | notes | POST | owner / admin | NONE | update sticky note |
| nt_rmv.php | AJAX: delete a sticky note (owner or admin). | notes | POST | owner / admin | NONE | delete sticky note |
| ai_chat.php | Chatbot UI + backend that calls the REST API as tools. | ai_conversations, ai_messages, ai_usage_log | POST | authenticated | NONE | (the chatbot itself; out of scope for "API parity") |
| ai_chat_history.php | Fetch user's chatbot conversation history. | ai_conversations, ai_messages | GET | authenticated (owner) | NONE | list my AI conversations; get conversation transcript |

## Database Tables

| Table | Purpose | Accessed via API? |
|-------|---------|-------------------|
| users | User accounts (auth, profile, role, status). | YES (services/users.php, services/permissions.php) |
| iacuc | IACUC protocol records (id, title, file URL). | NO |
| cages | Holding & breeding cage records (status, location, PI). | YES (services/cages.php) |
| cage_iacuc | Junction: cages ↔ IACUC protocols. | NO |
| cage_users | Junction: cages ↔ users with write access. | YES (services/permissions.php, services/cages.php) |
| strains | Mouse strain dictionary (id, name, RRID, URL). | NO |
| mice | Canonical mouse entity (identity, parents, status). | YES (services/mice.php) |
| mouse_cage_history | Append-only log of each mouse's cage assignments. | YES (services/mice.php — INSERT/UPDATE on move/sacrifice) |
| breeding | Breeding pair definitions (sire/dam per cage). | YES (services/cages.php) |
| litters | Litter records (DOB, pups alive/dead/sex). | YES (services/cages.php — read; written by web only) |
| files | File attachments tied to cages. | NO |
| notes | Sticky notes (cage- or user-scoped). | NO |
| tasks | One-off tasks (title, description, assignee, status, due). | NO |
| outbox | Outbound email queue. | NO |
| reminders | Recurring reminder templates (daily/weekly/monthly). | NO |
| notifications | In-app notifications (reminder/task/system). | NO |
| maintenance | Husbandry / maintenance notes attached to a cage. | YES (services/maintenance.php) |
| activity_log | Audit log of read/write actions. | YES (services/activity.php + log_activity from api/index.php) |
| settings | Key/value system settings (lab name, IoT URLs, Turnstile). | NO |
| api_keys | Per-user REST API keys (hashed). | YES (services/api_keys.php) |
| pending_operations | Two-step confirm tokens for destructive writes. | YES (services/pending_operations.php) |
| api_request_log | Per-request audit row (method, status, latency). | YES (api/index.php INSERT) |
| rate_limit | Sliding-window rate-limit counters keyed by api_keys.id. | YES (services/rate_limit.php) |
| ai_conversations | AI chatbot conversation root rows. | NO |
| ai_messages | AI chatbot message turns + tool calls/results. | NO |
| ai_usage_log | AI chatbot token usage and model accounting. | NO |
| ai_settings | Admin-managed AI provider config (encrypted). | NO |

13 / 27 tables touched by the API today. The 14 not exposed are: `iacuc`, `cage_iacuc`, `strains`, `files`, `notes`, `tasks`, `outbox`, `reminders`, `notifications`, `settings`, `ai_conversations`, `ai_messages`, `ai_usage_log`, `ai_settings`.

## Gap Analysis — Features NOT in the API

Grouped by feature area. Each gap lists the exact CRUD verb a chatbot would expect, with a proposed `operationId` and route.

### Mice (gaps within an otherwise-FULL group)

- List a mouse's cage-move history → `listMouseCageHistory` `GET /mice/{id}/history`
- List a mouse's offspring/descendants → `listMouseOffspring` `GET /mice/{id}/offspring`
- Hard-delete a mouse (admin) → `hardDeleteMouse` `DELETE /mice/{id}?hard=true` (admin scope; today only soft-delete is exposed)

### Holding Cages

- Archive a holding cage → `archiveHoldingCage` `DELETE /cages/holding/{id}`
- Restore an archived holding cage → `restoreHoldingCage` `POST /cages/holding/{id}/restore`
- Hard-delete a holding cage (admin) → `hardDeleteHoldingCage` `DELETE /cages/holding/{id}?hard=true`
- List/assign/remove users on a cage → `listCageUsers` `GET /cages/holding/{id}/users`, `assignCageUser` `POST /cages/holding/{id}/users`, `removeCageUser` `DELETE /cages/holding/{id}/users/{user_id}`
- List/attach/detach IACUC on a cage → `listCageIacuc` `GET /cages/holding/{id}/iacuc`, `attachCageIacuc` `POST /cages/holding/{id}/iacuc`, `detachCageIacuc` `DELETE /cages/holding/{id}/iacuc/{iacuc_id}`
- List/upload/delete cage file attachments → `listCageFiles` `GET /cages/holding/{id}/files`, `uploadCageFile` `POST /cages/holding/{id}/files`, `deleteCageFile` `DELETE /files/{id}`
- Render printable cage card payload → `getCageCard` `GET /cages/{id}/card` (or bulk: `POST /cage-cards` with a list of ids)

### Breeding Cages

- Archive / restore / hard-delete a breeding cage → `archiveBreedingCage` `DELETE /cages/breeding/{id}`, `restoreBreedingCage` `POST /cages/breeding/{id}/restore`, `hardDeleteBreedingCage` `DELETE /cages/breeding/{id}?hard=true`
- Litter CRUD on a breeding cage → `listLitters` `GET /cages/breeding/{id}/litters`, `addLitter` `POST /cages/breeding/{id}/litters`, `updateLitter` `PATCH /litters/{id}`, `deleteLitter` `DELETE /litters/{id}`
- Cage users / IACUC / files → same as Holding Cages (apply to breeding cages too)
- Walk cage lineage → `getCageLineage` `GET /cages/{id}/lineage` (returns ancestor and descendant cages)

### Tasks

- List tasks → `listTasks` `GET /tasks?assigned_to=me|<id>&status=...`
- Get one task → `getTask` `GET /tasks/{id}`
- Create task → `createTask` `POST /tasks`
- Update task → `updateTask` `PATCH /tasks/{id}`
- Mark task done → `completeTask` `POST /tasks/{id}/complete`
- Assign task to user → `assignTask` `POST /tasks/{id}/assign`
- Delete task → `deleteTask` `DELETE /tasks/{id}`

### Reminders

- List reminders → `listReminders` `GET /reminders?assigned_to=me`
- Get reminder → `getReminder` `GET /reminders/{id}`
- Create reminder → `createReminder` `POST /reminders`
- Update reminder → `updateReminder` `PATCH /reminders/{id}`
- Activate/deactivate reminder → `setReminderStatus` `POST /reminders/{id}/status`
- Delete reminder → `deleteReminder` `DELETE /reminders/{id}`

### Calendar Feed

- List tasks + reminders in a date range → `listCalendarEvents` `GET /calendar-events?from=&to=`

### Notifications

- List my notifications → `listMyNotifications` `GET /notifications?unread_only=...`
- Unread count → `getUnreadNotificationCount` `GET /notifications/unread-count`
- Mark one read → `markNotificationRead` `POST /notifications/{id}/read`
- Mark all read → `markAllNotificationsRead` `POST /notifications/read-all`

### Users (administration)

- List users → `listUsers` `GET /users` (admin)
- Get user → `getUser` `GET /users/{id}` (admin or self)
- Approve user → `approveUser` `POST /users/{id}/approve`
- Suspend user → `suspendUser` `POST /users/{id}/suspend`
- Change role → `setUserRole` `PATCH /users/{id}` (role field)
- Delete user → `deleteUser` `DELETE /users/{id}`
- Update own profile → `updateMe` `PATCH /me`
- Request password reset for self → `requestPasswordReset` `POST /me/password-reset`

### IACUC

- List IACUC → `listIacuc` `GET /iacuc`
- Get IACUC → `getIacuc` `GET /iacuc/{id}`
- Create IACUC → `createIacuc` `POST /iacuc`
- Update IACUC → `updateIacuc` `PATCH /iacuc/{id}`
- Delete IACUC → `deleteIacuc` `DELETE /iacuc/{id}`

### Strains

- List strains → `listStrains` `GET /strains`
- Get strain → `getStrain` `GET /strains/{id}`
- Create strain → `createStrain` `POST /strains`
- Update strain → `updateStrain` `PATCH /strains/{id}`
- Delete strain → `deleteStrain` `DELETE /strains/{id}`

### Sticky Notes

- List my sticky notes → `listStickyNotes` `GET /sticky-notes?cage_id=...`
- Create sticky note → `createStickyNote` `POST /sticky-notes`
- Update sticky note → `updateStickyNote` `PATCH /sticky-notes/{id}`
- Delete sticky note → `deleteStickyNote` `DELETE /sticky-notes/{id}`

### Dashboard / Home

- Summary counts (cages, mice, tasks, recent activity) → `getDashboardSummary` `GET /dashboard`

### IoT Sensors

- Get sensor URLs (from settings) → `listIotSensors` `GET /iot-sensors`

### Admin Settings

- Get/set lab settings → `getSettings` `GET /settings`, `updateSettings` `PATCH /settings` (admin)
- Get/set AI config → `getAiConfig` `GET /admin/ai-config`, `updateAiConfig` `PATCH /admin/ai-config` (admin)
- API key administration (list/create/revoke for any user) → `adminListApiKeys` `GET /admin/api-keys`, `adminCreateApiKey` `POST /admin/api-keys`, `adminRevokeApiKey` `DELETE /admin/api-keys/{id}` (admin only; sensitive)
- V1 import → `importV1Data` `POST /admin/import` (admin; arguably should stay file-bound)
- Data export → `exportData` `GET /admin/export` (admin; large; arguably should stay file-bound)

### AI Chatbot Persistence

- List my conversations → `listAiConversations` `GET /ai/conversations`
- Get conversation transcript → `getAiConversation` `GET /ai/conversations/{id}`

### Auth (intentionally NOT in API)

- Login, registration, email verification, password reset, logout — these are session-based flows. The API uses `X-API-Key` instead, so no API parity is needed. **Skip.**

## Recommended Priority Order

Reasoning: prioritize features that are central to daily lab work, that a chatbot user is likely to ask about ("show my pending tasks", "remind me to weigh cage HC-12"), and that are read-only or low-risk first. Push admin-only and destructive operations to later tiers.

### Tier 1 — do soon

These close the most painful gaps for a chatbot user doing normal lab work.

1. **Tasks — read & light write.** `listTasks`, `getTask`, `createTask`, `completeTask`. This is the single most natural chatbot question ("what do I need to do today?").
2. **Reminders — read.** `listReminders`, `getReminder`. Pairs with tasks for "what's coming up".
3. **Calendar feed.** `listCalendarEvents` — single endpoint that backs "what's on my plate this week".
4. **Notifications — read & mark.** `listMyNotifications`, `getUnreadNotificationCount`, `markNotificationRead`, `markAllNotificationsRead`. Cheap, useful, low risk.
5. **Mouse cage history & offspring.** `listMouseCageHistory`, `listMouseOffspring`. Today `getMouse` shows the current cage but the page surfaces history and lineage; the chatbot will be asked "where has M042 lived" and "how many pups did M042 produce".
6. **Strains — list/get.** `listStrains`, `getStrain`. The mouse-create endpoint already takes a strain string, but the chatbot needs to know what strings are valid.
7. **IACUC — list/get.** `listIacuc`, `getIacuc`. Same reason — referenced by cages but invisible to the API today.
8. **Dashboard summary.** `getDashboardSummary`. Single read that mirrors `home.php` and gives the chatbot one cheap call to greet the user with their state.
9. **Cage users & IACUC junction — list-only first.** `listCageUsers`, `listCageIacuc`. Read-only sidecars on existing cage endpoints; enables "who has access to BC-7" and "which protocol covers HC-12".

### Tier 2 — medium term

These are write operations on existing core features; they're useful but each one needs a small confirm flow and a permissions check.

10. **Tasks — write.** `updateTask`, `assignTask`, `deleteTask`.
11. **Reminders — write.** `createReminder`, `updateReminder`, `setReminderStatus`, `deleteReminder`.
12. **Litter management on breeding cages.** `listLitters`, `addLitter`, `updateLitter`, `deleteLitter`. Today a user can read litters via `getBreedingCage` but not change them.
13. **Cage users — write.** `assignCageUser`, `removeCageUser`. Needs careful admin-vs-self rules.
14. **Cage IACUC — write.** `attachCageIacuc`, `detachCageIacuc`.
15. **Cage archive / restore.** `archiveHoldingCage`, `archiveBreedingCage`, `restoreHoldingCage`, `restoreBreedingCage`. Soft, reversible, supports the confirm-token flow.
16. **Sticky notes — full CRUD.** `listStickyNotes`, `createStickyNote`, `updateStickyNote`, `deleteStickyNote`. Low-stakes user-scoped data; nice for "remind me later" patterns.
17. **Strains & IACUC — write.** `createStrain`/`updateStrain`/`deleteStrain`, `createIacuc`/`updateIacuc`/`deleteIacuc`. Admin scope.
18. **Self profile.** `updateMe`. Needed before exposing admin user CRUD.
19. **Cage lineage walk.** `getCageLineage`. Read-only; pairs nicely with mouse history/offspring.
20. **File attachment read.** `listCageFiles` (read-only). Upload/delete can wait.

### Tier 3 — nice to have / risky / niche

21. **User administration.** `listUsers`, `getUser`, `approveUser`, `suspendUser`, `setUserRole`, `deleteUser`. Admin-only, destructive, sensitive — wire late and protect with a dedicated admin scope.
22. **Hard delete endpoints.** `hardDeleteMouse`, `hardDeleteHoldingCage`, `hardDeleteBreedingCage`. Admin-only, irreversible — keep gated behind explicit confirm + admin scope.
23. **File upload/delete.** `uploadCageFile`, `deleteCageFile`. Multipart; cross-cuts permissions and disk.
24. **Lab and AI settings.** `getSettings`/`updateSettings`, `getAiConfig`/`updateAiConfig`. Admin-only; rarely needed via chat.
25. **Admin API key management for other users.** `adminListApiKeys`, `adminCreateApiKey`, `adminRevokeApiKey`. Sensitive — leave to the web UI unless there's clear demand.
26. **Cage card rendering.** `getCageCard`. Niche; print is a browser path, not a chatbot path.
27. **IoT sensor URL passthrough.** `listIotSensors`. Trivial but rarely useful.
28. **V1 import / full DB export.** `importV1Data`, `exportData`. Probably better left as file/CLI flows.
29. **AI chatbot conversation history endpoints.** `listAiConversations`, `getAiConversation`. The chatbot already owns this data; only useful for an external client.
