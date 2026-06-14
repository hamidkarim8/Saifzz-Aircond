# CHG-002 — Multi-Boss Tenant Isolation (Design Spec)

**Date:** 2026-06-14
**Status:** Approved design — ready for implementation plan
**Priority:** P1
**Closes:** CHG-002, CHG-015
**Feedback ref:** `docs/FEEDBACK-13062026.md`

## Problem

Two superadmins (Khalid `khalid@admin.com`, Saifzz `saifzz@admin.com`) run independent aircond
businesses on one deployment. Today both are plain `role=admin` and `seesAllData()` returns *every*
row in the database — there is no tenant boundary. Each boss must see only **their own** technicians,
clients, service records, appointments, payments, reports and dropdowns. A technician must see only
clients/jobs within their boss's tenant (and only their own jobs unless granted `view_all_data`).

## Key decisions (locked in brainstorming)

1. **Two bosses, ever.** Khalid + Saifzz are the only admins. Each admin is a tenant root; no
   shared-tenant sub-admins.
2. **Tenant marker:** a single `tenant_id` column stamped on **4 tables** — `users`, `clients`,
   `service_visits`, `appointments`. Single-column `WHERE` everywhere, matching the existing
   `technician_id` pattern.
3. **Technician client visibility** is permission-gated, not assignment-based. Default technician has
   no Clients menu (already done — CHG-006 `adminOnly`). A technician granted `view_clients` sees
   **all** clients in their boss's tenant. No client↔technician assignment table.
4. **Existing data is disposable (Q7).** No backfill/migration of live rows — truncate + reseed.
5. **Serial numbers stay global-monotonic.** Serials are globally unique, so the public portal
   lookup (serial + phone-last-4) needs no tenant context.

## Architecture

The codebase already funnels **all** row scoping through one seam: `Model::visibleTo($user)`
(used in `ServiceVisitController`, `AppointmentController`, `DocumentController`,
`PaymentController`, `DashboardController`, `ClientController`). Multi-tenancy is therefore primarily
a change to that seam plus extending it to `Client`, which currently has none.

### Tenant identity

- Add nullable `tenant_id` (FK → `users.id`, `nullOnDelete`) to `users`.
- A **boss** (admin) has `tenant_id = own user id` (self-root).
- A **technician** has `tenant_id =` the creating boss's `tenant_id`.
- New method on `User`:

  ```php
  /** The tenant this user belongs to. Bosses are their own tenant root. */
  public function tenantId(): ?int
  {
      return $this->tenant_id;
  }
  ```

- Seeder (`DatabaseSeeder`): after `firstOrCreate` of each boss, set `tenant_id = id` if null and
  save. Idempotent.

### Stamping `tenant_id` on create

Stamped **explicitly in controllers** at create time (no model boot hooks — keeps seeders, console
and tests auth-free and predictable, matching how `technician_id` / `created_by` are set today):

| Table | Where stamped | Value |
|-------|---------------|-------|
| `clients` | `ClientController::store` | `$request->user()->tenantId()` |
| `service_visits` | `ServiceVisitController::store` | `$request->user()->tenantId()` |
| `appointments` | `AppointmentController::store` | `$request->user()->tenantId()` |
| `users` (technician) | `UserController::store` | creating boss's `tenantId()` |

All four migrations add `tenant_id` as **nullable** FK → `users.id` (`nullOnDelete`).

### The scope seam

`ServiceVisit::scopeVisibleTo` and `Appointment::scopeVisibleTo` become two-layer:

```php
public function scopeVisibleTo(Builder $query, User $user): Builder
{
    $query->where('tenant_id', $user->tenantId());   // ALWAYS — applies to admins too
    if (! $user->seesAllData()) {
        $query->where('technician_id', $user->id);    // scoped technician: own rows only
    }
    return $query;
}
```

**New** `Client::scopeVisibleTo` — tenant filter only (a granted technician sees all tenant clients):

```php
public function scopeVisibleTo(Builder $query, User $user): Builder
{
    return $query->where('tenant_id', $user->tenantId());
}
```

`seesAllData()` keeps its meaning ("no per-technician filter") but is now **always** bounded by the
tenant `WHERE` that precedes it. No admin — including Khalid/Saifzz — has a cross-tenant view.

## Enforcement points

### Already safe (inherit tenant for free once the seam is updated)
These already call `->visibleTo($user)` on a tenant-stamped model, so cross-tenant access returns
404/403 automatically:
- `ServiceVisitController` show/edit/update/cancel/cash/pay guards
- `AppointmentController` index/store/update/updateStatus guards
- `DocumentController` invoice/receipt
- `PaymentController` show/cash/pay
- `DashboardController` / `ClientController::show` visit + appointment sub-queries

### Must change
- **`Client::scopeVisibleTo`** — add (new). Apply to `ClientController::index` (the global client
  list — currently a cross-tenant leak) and `ClientController::show`.
- **`ServiceVisitController::store`** (line ~89) — replace raw `Client::findOrFail($id)` with
  `Client::visibleTo($user)->findOrFail($id)` so a boss cannot attach a visit to another tenant's
  client.
- **`ClientUnitController`** — the existing `client_id` match guards must also confirm the parent
  client is tenant-visible: load the client via `Client::visibleTo($user)->findOrFail()` before unit
  mutations / JSON index.
- **`ReportService`** — audit every raw query (`receivables`, `transactions`, `kpis`,
  `servicesByType`). Any query not already constrained through `visibleTo`/`tenant_id` must add a
  tenant filter. `DashboardController` / `ReportController` pass `$scopeId` for the technician layer;
  tenant must be applied independently (scoped technician AND admin both get the tenant `WHERE`).
- **`ReminderService::dueList()`** — tenant-filter via the client relationship. Reminders remain
  client-global for scoped technicians (existing v1 decision) but must still be tenant-bounded.

### Left unchanged
- **`PortalController`** — public, keyed by globally-unique serial + phone-last-4. Tenant-agnostic
  by design.

## Dropdowns — closes CHG-015

Technician selector lists (`AppointmentController::index`/`update`, `ServiceVisitController::create`/
`edit`, anywhere building a `technicians` prop) currently return all technicians when
`seesAllData()`. Filter to the tenant:

```php
'technicians' => User::where('role', User::ROLE_TECHNICIAN)
    ->where('tenant_id', $user->tenantId())
    ->orderBy('name')
    ->get(['id', 'name']),
```

A boss only ever sees and assigns technicians in their own tenant.

### User management (CHG-002 rule 1)
- `UserController::index` — list only technicians in the acting boss's tenant.
- `UserController::store` — stamp the new technician's `tenant_id` = boss's `tenantId()`.
- `UserController` show/update/deactivate — guard the target user is in the acting boss's tenant
  (404 otherwise), so one boss cannot edit/deactivate the other boss's technician.

## Data & migrations

- 4 migrations adding nullable `tenant_id` FK (`users`, `clients`, `service_visits`,
  `appointments`).
- Existing data disposable: implementation runs `migrate:fresh --seed` (dev/local) and a plain
  `migrate` + reseed on production. No row backfill logic.
- Seeder sets each boss's `tenant_id = id`. Any technician/client/visit/appointment created
  thereafter is stamped at create time.

## Testing

Build a **two-tenant fixture**: Khalid + Khalid-tech (+ a client, visit, appointment, payment, doc),
Saifzz + Saifzz-tech (same). Assert:

1. **Cross-tenant read isolation** — Khalid (admin) `GET` of a Saifzz client / service record /
   appointment / payment / document → 404 (or 403 where the route already aborts 403).
2. **List isolation** — Khalid's client list, service-record list, appointment list, report KPIs,
   receivables, reminders contain **only** Khalid-tenant rows; counts/totals exclude Saifzz.
3. **Dropdown isolation (CHG-015)** — technician selector for Khalid shows only Khalid's
   technician(s); Saifzz's technician absent.
4. **User management isolation** — Khalid cannot update/deactivate Saifzz's technician (404);
   Khalid's user list excludes Saifzz's technician.
5. **Granted technician** — a tech with `view_clients` sees **all** Khalid-tenant clients, but **0**
   Saifzz clients.
6. **Scoped technician within tenant** — without `view_all_data`, the tech still sees only their own
   jobs/appointments (existing behavior), now additionally tenant-bounded.
7. **Write-path tenant binding** — a boss cannot create a service visit/appointment referencing the
   other tenant's client (`findOrFail` → 404).
8. **Regression** — full suite stays green (currently 233/233).

## Out of scope

- Sub-admins sharing a tenant (decided: two bosses only).
- Client↔technician explicit assignment (visibility is permission-gated).
- Per-tenant serial sequences (serials stay global-unique).
- Cross-tenant "super" god view (no admin sees across tenants).
- FEAT-016 (per-boss payment gateway) — separate item.
