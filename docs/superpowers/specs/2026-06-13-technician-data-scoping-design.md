# Technician Data Scoping — Design

**Date:** 2026-06-13  
**Status:** Approved

## Summary

Technicians should see their own jobs and personal KPIs. Admins see business-wide data. Most scoping infrastructure already exists; the only real gap is the dashboard gating KPIs behind `view_reports`.

## Decisions

| Question | Decision |
|---|---|
| Client list | All clients visible to all users (no scoping) |
| Revenue visibility | Own jobs only — already enforced by `scopeVisibleTo()` |
| Dashboard KPIs | Always shown, scoped to technician's own data |
| `view_reports` gates | Transactions table + export only (not KPIs) |
| Admin view | Always business-wide (`$scopeId = null`), even if admin records visits |

## What Already Works (No Changes)

- `User::seesAllData()` — true for admins or users with `view_all_data` permission
- `Appointment::scopeVisibleTo(User)` — filters by `technician_id` for scoped techs
- `ServiceVisit::scopeVisibleTo(User)` — same
- `AppointmentController`, `ServiceVisitController` — both use `visibleTo()`
- `ClientController::show()` — scopes visit/appointment relations with `visibleTo()`
- `ReportService::kpis/servicesByType/transactions` — all accept `?int $technicianId`
- `DashboardController` — already computes `$scopeId = seesAllData() ? null : user->id`
- `ReportController` export — same `$scopeId` pattern

## Changes Required

### 1. `DashboardController::index()`

**Remove** the early-return path that sends `canReport: false` with no data.

**Always:**
- Compute `$scopeId`
- Load `kpis($scopeId)`, `servicesByType($period, $scopeId)`, `appointments` (via `visibleTo()`)

**Conditionally:**
- `transactions` only when user has `view_reports`; otherwise pass `[]`

**Pass:**
- `canReport` = `hasPermission('view_reports')` — gates transactions table + export
- `report.kpis`, `report.servicesByType`, `report.transactions` — always present

### 2. `Dashboard.vue`

**Remove** the top-level `v-if="canReport"` / `v-else` split.

**Always render:**
- 4 KPI stat cards (hide "Pending Reminders" card when `kpis.pending_reminders === null` — technicians get null; grid adjusts from 4 to 3 columns)
- Month calendar + day panel
- Services by Type chart

**`v-if="canReport"` wraps only:**
- Recent Transactions table
- Export CSV button

**Remove:**
- Module launcher (`v-else` block) — technicians now have a real dashboard; nav sidebar handles module access

## File Scope

| File | Change |
|---|---|
| `app/Http/Controllers/DashboardController.php` | Remove early return; always compute scoped data; conditionally include transactions |
| `resources/js/Pages/Dashboard.vue` | Remove canReport gate on KPIs/calendar/chart; add gate only on transactions; hide reminders card when null; remove module launcher |

## Out of Scope

- `ClientController::index()` — all clients visible, no change
- `ClientController::lookup()` — same
- `view_all_data` permission — unchanged; granting it to a technician promotes them to business-wide view
- New permissions — none added
