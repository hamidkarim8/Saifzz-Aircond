# Technician Data Scoping — Design

**Date:** 2026-06-12
**Status:** Approved direction, spec under review

## Problem

RBAC today is capability-only: a permission flag (`view_reports`, `record_service`, …) decides
*whether* a technician can use a feature, never *which rows* they see. Any technician with
`view_reports` sees business-wide revenue, every client's transactions, and the whole appointment
book. The owner wants technicians to see **their own work** ("jobs under me") without exposing
total business takings, while admins keep the full picture.

## Objective

- Owner (admin): unchanged — sees everything.
- Technician (default): sees only the jobs they performed, the revenue from those jobs, and reports
  derived from them. Can still **find any client** to record a service.
- Technician (trusted): a grantable permission widens them back to business-wide visibility.

## Approach: Hybrid scoping at the ServiceVisit seam

Scoping is **orthogonal to RBAC**. Permissions (Laravel Gates, `can:*`) stay as the capability
check; scoping is a query-layer row filter applied *after* the capability check passes.

**Scope the job, not the client.** Aircon clients are shared business assets — any technician may
service any client, so the client list/search stays global (otherwise a tech can't find an existing
client to record a repeat visit). A **service visit**, by contrast, is owned: one technician
performed it. Revenue (`transactions.visit_id`) and service-type counts (`service_lines.visit_id`)
both chain through `ServiceVisit`, so a single filter on `ServiceVisit` transitively scopes revenue,
KPIs, reports, and the CSV export.

### Visibility matrix

| Data | Technician (default) | Technician + `view_all_data` | Admin |
|------|----------------------|------------------------------|-------|
| Client list / search / lookup | all | all | all |
| Service visits / "my jobs" | own only | all | all |
| Appointments / "my schedule" | own only | all | all |
| Revenue, KPIs, services-by-type, transactions | own jobs only | business-wide | business-wide |
| CSV export | own rows only | all rows | all rows |

### Ownership field

`ServiceVisit.created_by` records *who entered* the visit (may be an admin entering on a tech's
behalf), so it is the wrong ownership signal. Add an explicit **`technician_id`** = who performed
the service. Same for appointments (`Appointment.technician_id` = who the job is assigned to), so
"my jobs" = my completed visits + my upcoming appointments.

## Data model changes

Migration `..._add_technician_scoping`:

- `service_visits.technician_id` — nullable FK → `users.id`, `nullOnDelete`, indexed.
- `appointments.technician_id` — nullable FK → `users.id`, `nullOnDelete`, indexed.
- **Backfill:** set `service_visits.technician_id = created_by` for existing rows (best available
  signal). Appointments have no prior owner → leave null (an unassigned appointment is visible only
  to all-data users; see edge cases).

Nullable because an admin-entered job may have no assigned technician, and historical rows may not
map cleanly.

## Permission: `view_all_data`

- Add `'view_all_data'` to `User::PERMISSIONS`.
- **Not** admin-only and **not** in `DEFAULT_TECHNICIAN_PERMISSIONS` → new techs are scoped by
  default; the owner grants it explicitly to trusted staff.
- Admins implicitly hold it (existing `hasPermission` returns true for admins).

New helper on `User`:

```php
/** True when the user sees all rows (no per-technician scoping). */
public function seesAllData(): bool
{
    return $this->hasPermission('view_all_data'); // admins short-circuit to true
}
```

## Query layer

Eloquent scope on both models, single source of truth for the filter:

```php
// ServiceVisit and Appointment
public function scopeVisibleTo(Builder $q, User $user): Builder
{
    return $user->seesAllData() ? $q : $q->where('technician_id', $user->id);
}
```

`ReportService` runs raw query-builder joins, not Eloquent, so it takes an explicit nullable
technician id (null = unscoped):

- `kpis(?int $technicianId = null)`
- `servicesByType(string $period, ?int $technicianId = null)`
- `transactions(string $period, ?int $limit = 50, ?int $technicianId = null)`

When `$technicianId` is set, each query adds `where sv.technician_id = ?`. `kpis()` must now join
`service_visits` onto `transactions` (it currently queries `transactions` alone) so revenue can be
scoped.

### Scoped KPI semantics

KPI meanings shift for a scoped technician (concrete definitions, no ambiguity):

| KPI | All-data | Scoped technician |
|-----|----------|-------------------|
| `total_clients` | `Client::count()` | distinct `sv.client_id` where `technician_id = me` |
| `clients_this_month` | new clients this month | distinct clients I serviced this month |
| `revenue_month` / `revenue_all_time` / `revenue_mom_pct` | all paid txns | paid txns on my visits |
| `pending_reminders` | global derived count | **hidden for scoped techs in v1** (reminders are client-global and derived via `ReminderService`; scoping them is out of scope — the card is omitted when `!seesAllData()`) |

## Enforcement points

The query scope handles list views. URL-addressable single resources need an explicit ownership
check so a scoped tech can't open another tech's record by guessing an id:

- `ServiceVisitController@index` — `ServiceVisit::visibleTo($user)`.
- `ServiceVisitController@show` — 403 unless the visit is visible to the user.
- `AppointmentController@index` — `Appointment::visibleTo($user)`.
- `DashboardController@index` — pass `$user->seesAllData() ? null : $user->id` to the three
  `ReportService` calls; appointments list filtered `visibleTo`.
- `ReportController@exportTransactions` — same scoping id into `transactions()`.
- **Transaction-derived routes** (`PaymentController`, `DocumentController` show/PDF): a scoped tech
  must not view payment/invoice/receipt for a visit they don't own. Add a guard: the transaction's
  `visit` must be `visibleTo` the user, else 403. (These routes are gated `collect_payment` /
  `view_clients` for capability, but capability ≠ scope.)

Clients and reminders stay global by design — no scoping there.

## Write path / forms

- **Record-service form** gains a "Technician" selector:
  - Admin: dropdown of active technicians (+ self); defaults to self.
  - Technician: locked to self (hidden field or read-only), server forces `technician_id = auth id`
    regardless of payload (don't trust the client).
- **Appointment form**: same technician selector with the same admin/tech rules.
- Server-side: on store, a non-all-data user's `technician_id` is overwritten with their own id.

## UI

- Service-records index titled "My Jobs" for scoped techs, "Service Records" for all-data users.
- Appointments view shows only the user's schedule when scoped (no UI change beyond the filtered
  payload).
- Dashboard already renders the report block only for `view_reports`; additionally omit the
  reminders KPI card when scoped (see KPI table). Revenue numbers shown are already whatever the
  controller passes, so they become "my revenue" automatically.

## Testing

- **Model/scope:** `visibleTo` returns own rows for scoped tech, all rows for admin and for a tech
  with `view_all_data`.
- **ReportService:** scoped technician id filters kpis/servicesByType/transactions to that tech's
  visits; null id = unchanged global behavior (regression).
- **Authorization:** scoped tech gets 403 on another tech's `service-records/{id}`,
  `payments/{txn}`, `documents/invoice|receipt/{txn}`; own resources 200.
- **Write path:** technician's store request with a forged `technician_id` is overwritten to self;
  admin's chosen `technician_id` is honored.
- **Backfill:** existing visits get `technician_id = created_by`.
- **Grant flip:** granting `view_all_data` to a scoped tech widens their dashboard/reports/lists to
  business-wide.

## Out of scope (v1)

- Scoping reminders or the reminders KPI per technician.
- Team hierarchies / "jobs under technicians I manage" (only self vs all).
- Per-client ownership/assignment.
- Capability-URL portal token (tracked separately).

## Open decisions for user review

1. **Appointment scoping in v1** — included here for a coherent "my jobs = visits + appointments"
   story. Confirm, or defer appointments to v2 and scope only visits now.
2. **Unassigned appointments** (`technician_id` null) — currently visible only to all-data users.
   Acceptable, or should they surface to all techs as an "unclaimed" pool?
