# CHG-011 — Permission Levels + Presets Tab

**Date:** 2026-06-15
**Feedback item:** CHG-011 (P1) — `docs/FEEDBACK-13062026.md`
**Status:** Design approved, ready for plan.

## Problem

Admins currently grant technician permissions one checkbox at a time from a flat
grid of 11 grantable permissions (`UserModal.vue`). There is no notion of a
"level"; every new technician gets the hardcoded `DEFAULT_TECHNICIAN_PERMISSIONS`
baseline and the admin hand-tunes from there. CHG-011 asks for:

1. Named technician **levels** L1 / L2 / L3 that auto-tick a sensible baseline.
2. An admin-**configurable** definition of what each level grants (per boss).
3. All permissions remain individually checkable regardless of level — picking a
   level just fills the boxes; the admin can override any box afterward.
4. Remove the **Reminders** item from the technician sidebar.

## Locked design decisions

- **Snapshot at apply-time.** Selecting a level fills the checkboxes once. The
  user's stored `permissions` array stays the single source of truth. Editing a
  preset later does NOT retroactively change existing users. No live-linking.
- **Per-tenant presets.** Each boss (Khalid / Saifzz) configures their own
  L1/L2/L3 baselines, scoped by `tenant_id` (consistent with CHG-002 isolation).
- **Level is NOT persisted on the user.** It is a pure fill-helper — no
  `users.permission_level` column, no level badge. Avoids stale "L2" labels after
  the admin overrides individual boxes.
- **Presets edited in their own modal** launched from the Users page (a
  "Permission levels" button), NOT as a tab inside the per-user Add/Edit modal.
  Presets are tenant-wide config set rarely; the per-user modal is frequent.
  Keeping them separate avoids "am I editing this user or the global defaults?"
  confusion. The per-user modal keeps only L1/L2/L3 quick-fill buttons.

## Data model

New table `permission_presets`, one row per (tenant, level):

| column      | type              | notes                                              |
|-------------|-------------------|----------------------------------------------------|
| id          | pk                |                                                    |
| tenant_id   | FK → users.id     | self-root boss id; matches existing tenant pattern |
| level       | unsigned tinyint  | 1, 2, or 3                                          |
| permissions | json              | array of permission keys                           |
| timestamps  |                   |                                                    |

- Unique index `(tenant_id, level)`.
- FK `tenant_id` → `users.id`, `cascadeOnDelete` (preset is meaningless without
  its boss).
- No change to the `users` table.
- `manage_users` is never stored in a preset (admin-only; stripped on save the
  same way `User::grantPermission()` already drops it).

### Seeded defaults (admin-editable afterward)

When a boss is seeded/created, seed their three presets:

- **L1** — `view_clients, record_service, set_appointment, manage_service_types, manage_units`
  (= current `DEFAULT_TECHNICIAN_PERMISSIONS`; own jobs + QR payment, no cash)
- **L2** — L1 + `collect_payment, edit_client` (cash + manage clients)
- **L3** — L2 + `view_all_data, view_reports, export_data` (see-all + reports)

`edit_fees` is in no baseline (still tickable manually).

**Lazy fallback:** if a tenant has no preset rows yet, the controller returns the
hardcoded defaults above. This keeps already-existing tenants working without a
data backfill — rows are only written when an admin saves the Presets modal.
Defaults live as a constant on the `PermissionPreset` model.

## Backend

- **`PermissionPreset` model** — `$fillable = ['tenant_id', 'level', 'permissions']`,
  `permissions` cast to `array`. Constant `DEFAULTS` (level → array) for the lazy
  fallback + seeding. Helper `PermissionPreset::forTenant(?int $tenantId): array`
  returns `{1: [...], 2: [...], 3: [...]}` — DB rows if present, else `DEFAULTS`.
  Any `manage_users` defensively stripped from returned arrays.
- **`PermissionPresetController`** — single `update` action. Bulk-saves all three
  levels for the acting tenant. Validates each level's permissions against
  `User::PERMISSIONS` minus `ADMIN_ONLY_PERMISSIONS`. Upserts on
  `(tenant_id, level)`. `tenant_id` taken from `$request->user()->tenantId()` —
  never from the request body. Route gated `can:manage_users` (admins only).
  No `index` action — the Users page already carries the `presets` prop (below),
  so there is no separate read endpoint to maintain.
- **`UserController::index`** also passes a `presets` prop (the tenant's three
  baselines from `forTenant`) so the user modal can fill checkboxes client-side.
- **`store` / `update` unchanged.** They still persist the final `permissions`
  array via `grantPermission()`. The snapshot model means nothing new server-side
  for user creation — the level resolution happens entirely in the browser.

## Frontend

### `Users/Index.vue`
- New **"Permission levels"** button (admin-only) near the Add-user action →
  opens `PresetsModal`.
- Passes the `presets` prop down to both `UserModal` (quick-fill source) and
  `PresetsModal` (edit target).

### `PresetsModal.vue` (new)
- Three sections (L1 / L2 / L3), each the same permission checkbox grid bound to a
  local edit form seeded from `presets`.
- "Save presets" → `router.put(route('permission-presets.update'), ...)`,
  flash toast on success.
- Reuses the permission-label map (extract to a shared module so `UserModal` and
  `PresetsModal` share one source — see fix below).

### `UserModal.vue`
- Add three **quick-fill buttons** `Level 1 / Level 2 / Level 3` above the
  permission grid. Click sets `form.permissions = [...presets[level]]` (overwrite).
- All 11 checkboxes stay individually editable below — grid unchanged.
- **Fix existing gap:** `permLabels` is missing `manage_service_types` and
  `manage_units` (they render as raw keys today). Add both. Extract `permLabels`
  to a shared module (`resources/js/permissionLabels.js` or similar) consumed by
  `UserModal` and `PresetsModal`.
- New `presets` prop.

### `AdminLayout.vue`
- Line 32 Reminders nav item: add `adminOnly: true`. The existing line-45 filter
  (`!i.adminOnly || isAdmin.value`) then hides it from technicians. The
  `reminders.*` routes remain accessible to admins (no route change).

## Testing

New feature tests (`tests/Feature/PermissionPresetTest.php`):

- `PermissionPreset::forTenant` → returns own tenant's three levels; lazy defaults
  when no rows; never another tenant's rows.
- Users/Index passes the acting tenant's presets prop (own tenant only).
- Admin saves presets → rows upserted for own tenant only.
- Cross-tenant isolation: Khalid's `update` cannot write Saifzz's rows.
- `update` rejects `manage_users` and unknown permission keys.
- Technician (no `manage_users`) is 403 on both endpoints.

Regression: creating a user with an explicit permissions array still snapshots
exactly that array (confirms no live-link to presets).

Existing 258-test suite stays green.

## Out of scope / YAGNI

- No live-link / cascade of preset edits to existing users.
- No `users.permission_level` column or level badge.
- No change to the reminders feature itself — only its sidebar visibility.
- `manage_users` stays admin-only and ungrantable.
