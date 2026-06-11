# Module 1 — Users Management — Design

**Date:** 2026-06-11
**Status:** Draft (written ahead; review at start of next session before planning)
**Depends on:** RBAC layer (`docs/03`, `User` model permission helpers, Gates with
admin-implies-all), AdminLayout nav, design tokens. Public registration already disabled
(session 14) — this module is now the only way to create staff.

---

## Goal

Admin-only screen to create staff, toggle their granular permissions, and enable/disable
accounts. Closes the staff-onboarding gap left by removing `/register`.

User stories (`docs/04` §1): *As Khalid, I create a technician account and grant only the
permissions they need. As a technician, I log in and see only the sections I'm allowed to use.*

## Constraints (already locked by docs/03 — not re-decidable)

- **P1** `manage_users` is admin-only, never grantable (UI never shows it as a toggle).
- **P2** only admin changes another user's permissions — every route gated `can:manage_users`.
- **P3** server-side enforcement; UI hiding is cosmetic.
- **P4** `active = false` blocks login (already enforced at login); UI exposes the toggle.
- New technician default = `view_clients`, `record_service`, `set_appointment`
  (already in `User::booted`).

## Decisions (proposed — confirm at session start)

1. **One page, modal CRUD** — `Users/Index` lists staff (name, email, role badge,
   active switch, permission count); create/edit in a modal (mirrors Service Fees pattern).
   No separate show page (YAGNI).
2. **Reuse `RegisteredUserController` logic, not the controller** — new `UserController`
   (index/store/update/toggleActive); password set at create (admin types a temp password),
   change-password stays self-serve via Profile. No email invites (out of scope v1).
3. **Permission editing** — checkbox grid of `User::PERMISSIONS` minus `ADMIN_ONLY_PERMISSIONS`;
   server re-filters via `grantPermission()` (already rejects admin-only).
4. **Guard rails** — cannot deactivate or demote yourself; cannot edit another admin's
   role/permissions (single-admin assumption, keep simple: admins are immutable in this UI
   except their own profile page).
5. **No delete** — deactivate only (`active=false`); preserves `created_by` history on visits.

## Components

- `UserController` — `index` (list, no pagination needed at this scale), `store`
  (StoreUserRequest: name, email unique, password min 8, role fixed `technician`,
  permissions array validated against catalogue), `update` (UpdateUserRequest: name,
  permissions; not email/password v1), `toggleActive`. All routes `can:manage_users`.
- Routes: `users` GET/POST, `users/{user}` PUT, `users/{user}/active` PATCH, inside auth
  group with `can:manage_users` middleware group.
- Vue: `Pages/Users/Index.vue` + `UserModal.vue` (create/edit, permission checkboxes with
  short descriptions). Nav item "Users" (icon `users`) gated `manage_users` in AdminLayout.

## Tests (`tests/Feature/UserManagementTest.php`)

- non-admin (even with all grantable permissions) → 403 on every route.
- admin creates technician → default/chosen permissions persisted; admin-only permission in
  payload is silently dropped (P1).
- update changes name + permissions; cannot target an admin (422/403).
- toggleActive flips; self-deactivation rejected (422).
- deactivated technician cannot log in (regression of P4 with the new toggle).

## Out of scope (v1)

Email invites/verification flows, password reset by admin, audit log, role creation,
pagination/search (staff count is tiny).
