# Module 9 — Dashboard & Reports — Design

**Date:** 2026-06-11
**Status:** Approved (brainstorming) — ready for implementation plan
**Depends on:** Modules 2 (Clients), 4 (Service Records → `service_lines.visit_date`/`service_type`),
5 (Payments → `transactions.status`/`amount`/`paid_at`), 7 (Appointments → `MonthCalendar` partial),
8 (Reminders → `ReminderService` for the pending-reminders KPI).

---

## Goal

Aggregated read-only insight for the owner: KPI cards, a services-by-type chart, a mini
appointments calendar, a recent-transactions table, and a CSV export of transactions. This is a
read-side consumer — it reads existing module data and adds no new tables.

User story (`docs/04` §9): *As Khalid, I check June revenue and the services-by-type mix at a glance.*

---

## Decisions (locked in brainstorming)

1. **Access — adapt by permission.** `/dashboard` remains every authenticated user's landing page.
   The reporting payload (KPIs, chart, transactions, calendar) is computed and rendered **only**
   for users with `view_reports`; technicians without it keep the current module-launcher view.
   Gating the data server-side (not the route) avoids locking technicians out of their home page.
   CSV export is a separate route gated `export_data`.
2. **One shared period filter.** A single period control — **All time / This month / This week /
   Today** — scopes the services-by-type chart, the transactions table, **and** the CSV export, so
   the export always mirrors what's on screen. Period changes via an Inertia GET round-trip
   (`?period=`), matching the appointments month-nav pattern. The 4 KPI cards are **not**
   period-scoped — they have fixed semantics (see below).
3. **No chart dependency.** Services-by-type renders as CSS horizontal bars (bar width =
   `count / max`), consistent with the mockup. No charting library added.
4. **Scope (YAGNI).** No full paginated transactions index page in v1 — a recent/period table on
   the dashboard plus the export endpoint cover the need. No revenue time-series line chart.
   Deltas shown only where cheap to compute.

---

## Components

### `App\Services\Reports\ReportService`

Read-only service (mirrors `App\Services\Documents\*` and `App\Services\Reminders\*`),
unit-testable. Period → date range helper: `all` = unbounded; `month`/`week`/`today` relative to
`now()` (start-of-month / start-of-week / start-of-day → now).

- `kpis(): array` — four cards:
  - **Total Clients** — `Client` count (excl. soft-deleted) + delta `created_at` within current month.
  - **Revenue this month** — `sum(amount)` of transactions where `status = 'paid'` and `paid_at`
    in the current calendar month, plus MoM % vs the previous month (null/`—` when last month = 0).
  - **All-time Revenue** — `sum(amount)` of all `status = 'paid'` transactions.
  - **Pending Reminders** — `overdue + due_this_month` count from `ReminderService::dueList()`.
- `servicesByType(string $period): array` — `service_lines` count grouped by `service_type`,
  joined through `service_visits`, filtered by `visit_date` within the period. Returns
  `[{ type, count }]` ordered by count desc (zero-count types omitted).
- `transactions(string $period, ?int $limit = 50): array` — transactions within the period
  (bounded by `COALESCE(paid_at, created_at)`), joined to the client via visit, newest first,
  capped at `$limit` (`null` = no cap, used by export). Each row: `txn_id, date, client_name,
  serial_no, amount, method, status`.

### HTTP

- **`DashboardController@index`** — replaces the closure currently on `/dashboard`. Reads `?period=`
  (validated against `all|month|week|today`, default `all`). If the user has `view_reports`, renders
  `Dashboard` with `report` payload (`kpis`, `servicesByType`, `transactions`, `period`) plus
  `appointments` (current month, for the mini-calendar). Otherwise renders the launcher (no report
  payload — Vue falls back to the current module launcher).
- **`ReportController@exportTransactions`** — `GET /reports/transactions/export?period=`, gated
  `export_data`. Streams a CSV (`Symfony StreamedResponse`) of `ReportService::transactions($period,
  null)` (no cap for export). Columns: `Txn ID, Date, Client, Serial, Amount, Method, Status`.
  Filename `transactions-{period}-{Y-m-d}.csv`.

### Routes

```php
// Dashboard (module 9) — landing page; report payload gated view_reports inside the controller.
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

// Reports (module 9) — CSV export, gated export_data.
Route::get('reports/transactions/export', [ReportController::class, 'exportTransactions'])
    ->middleware('can:export_data')->name('reports.transactions.export'); // inside the auth group
```

(The existing `/dashboard` closure in `routes/web.php` is removed in favour of the controller.)

### UI — rewrite `resources/js/Pages/Dashboard.vue`

- **With `view_reports`:**
  - 4 KPI stat cards (Total Clients +delta · Revenue this month +MoM% · All-time Revenue · Pending
    Reminders).
  - Period tabs (All time / This month / This week / Today) — Inertia GET to `dashboard?period=`,
    `preserveState: false`.
  - Mini `MonthCalendar` (reuse `Pages/Appointments/Partials/MonthCalendar.vue`) with a click-to-show
    day panel listing that day's appointments.
  - Services-by-Type: CSS horizontal bars (label, bar width = `count/max`, count).
  - Recent transactions table (date, client + serial, amount, method, status badge) with an
    **Export CSV** `<a>` (plain link to `reports.transactions.export` carrying the current period),
    shown only when `can.export_data`.
- **Without `view_reports`:** the current module launcher (unchanged).

---

## Tests

**`tests/Feature/ReportServiceTest.php`:**
- revenue counts only `paid` transactions and only the current month for the month KPI;
  all-time sums all paid.
- MoM % computed vs previous month; `null` when last month had no revenue.
- clients-this-month delta counts only clients created this month.
- pending-reminders KPI equals `ReminderService` overdue+due count.
- services-by-type returns counts per type and respects the period (today/week/month/all) by
  `visit_date`.
- transactions list respects the period and is newest-first.
- (time frozen via `travelTo` for deterministic period boundaries.)

**`tests/Feature/DashboardTest.php`:**
- guest → redirect to login.
- user with `view_reports` → `Dashboard` renders with `report.kpis` / `servicesByType` / `transactions`.
- technician without `view_reports` → `Dashboard` renders **without** the report payload (launcher).
- export without `export_data` → 403; with it → 200, `text/csv`, header row + a data row;
  `?period=` filters the rows.

Target: full suite stays green (currently 102 passed / 314 assertions) plus the new cases.

---

## Out of scope (v1)

- Full paginated transactions index page (recent table + export cover it).
- Revenue time-series / line chart; per-technician or per-client breakdowns.
- Custom from/to date range (the four preset periods cover v1).
- Caching of aggregates (single-VM scale; revisit under load).
