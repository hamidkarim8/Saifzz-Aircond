# 03 — Roles & Permissions (RBAC)

Access control is **role + granular permissions**. It is not a fixed two-role system:
the owner can tailor exactly what each technician may do.

## Roles

| Role | Description |
|------|-------------|
| **admin** | Khalid (owner). Superuser — implicitly holds every permission. The **only** role that can create staff accounts and grant/revoke permissions. There is normally one admin. |
| **technician** | Field/office staff. Holds a configurable subset of permissions, assigned per individual by the admin. |

## Permission catalogue

Permissions are fine-grained capabilities. Admins have all of them implicitly; technicians
have only those granted.

| Permission | Allows | In default minimum? |
|------------|--------|:---:|
| `view_clients` | View client registry and a client's history | ✅ |
| `record_service` | Create service records (visits + lines) | ✅ |
| `set_appointment` | Create / edit appointments | ✅ |
| `collect_payment` | Take payment, confirm DuitNow QR / cash | ❌ |
| `edit_client` | Create / edit / soft-delete clients | ❌ |
| `view_reports` | Dashboard revenue figures, reports, CSV export | ❌ |
| `edit_fees` | Maintain the service-fee price book | ❌ |
| `export_data` | Export transactions / data to CSV | ❌ |
| `view_all_data` | See all technicians' jobs, revenue, and reports (not just own) | ❌ |
| `manage_service_types` | Add / edit service types (catalog) | ❌ |
| `manage_units` | Add / edit a client's AC units | ✅ |
| `manage_users` | Create staff, grant/revoke permissions | ❌ **admin-only — never grantable to technicians** |

### Default minimum (new technician)
A newly created technician starts with exactly (`User::DEFAULT_TECHNICIAN_PERMISSIONS`, = Level 1):

- `view_clients`
- `record_service`
- `set_appointment`
- `manage_units`

Everything else is off until Khalid explicitly enables it on the user-management screen.

## Permission presets (Levels 1–3)

The user-management screen offers three one-click baselines (`PermissionPreset::DEFAULTS`,
admin-editable per tenant). They only pre-fill the checkboxes — the admin can then customise
any individual technician's permissions freely (fully dynamic).

| Permission | L1 | L2 | L3 |
|------------|:--:|:--:|:--:|
| `view_clients`, `record_service`, `set_appointment`, `manage_units` | ✅ | ✅ | ✅ |
| `collect_payment`, `edit_client` | – | ✅ | ✅ |
| `manage_service_types` | – | – | ✅ |
| `view_all_data`, `view_reports`, `export_data` | – | – | ✅ |

Resulting menus: **L1** → Catalog, Appointments, My Jobs, Reminders. **L2** adds payment
collection + client editing. **L3** adds Dashboard, Transactions, and Services settings.

## Dashboard & data scoping

- **Dashboard is gated on `view_reports`** (L3 / admin only). Users without it have no
  dashboard — `/dashboard` redirects them to Appointments (or Catalog as a fallback).
  The nav link is hidden for them too.
- **Own-clients scoping** — users without `view_all_data` (L1/L2) see only clients they
  have personally serviced on the **Clients registry** (`Client::scopeOwnedBy`) and the
  **Reminders** list (`ReminderService::dueList($tenantId, $technicianId)`). The record-service
  client picker stays tenant-wide so a technician can still service any client.

## Rules

- **P1** — `manage_users` is reserved to the `admin` role and can never be granted to a
  technician.
- **P2** — Only `admin` (via `manage_users`) can change another user's permissions.
- **P3** — Permissions are enforced **server-side** on every protected action, not merely
  hidden in the UI. The UI also hides/disables actions the user lacks, for clarity.
- **P4** — A disabled (`active = false`) user cannot log in regardless of permissions.
- **P5** — The client portal is **unauthenticated** and serial-gated; it is outside this
  RBAC system and exposes read-only data for the matched serial only.

## UI implications

- The admin sidebar/nav shows only the sections a user's permissions allow.
- A user-management screen (admin only) lists staff with per-permission toggles and an
  active/disabled switch.
- Attempting a forbidden action server-side returns an authorization error; the UI should
  never surface the entry point in the first place.
