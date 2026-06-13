# Receivables / Aging Report — Design Spec

**Date:** 2026-06-13  
**Status:** Approved

---

## Summary

Embed an outstanding-receivables section into the existing Dashboard page. Shows all unpaid service visits grouped into 4 aging buckets (0–30 / 31–60 / 61–90 / 90+ days), plus a flat detail table linking to each service record. Gated by `collect_payment` permission; respects existing technician scoping.

---

## Data Layer

### `ReportService::receivables(?int $technicianId = null): array`

Single DB query:

```
transactions (status = 'pending')
  JOIN service_visits  ON sv.id = t.visit_id   → visit_date, technician_id, client_id
  JOIN clients         ON c.id  = sv.client_id  → name, serial_no
```

- `days_outstanding = CURRENT_DATE - sv.visit_date` (age from service date, not transaction created_at)
- When `$technicianId` set → `WHERE sv.technician_id = ?`

Returns:

```php
[
  'buckets' => [
    ['label' => 'Current',  'days_from' => 0,  'days_to' => 30,  'count' => int, 'total' => float],
    ['label' => 'Overdue',  'days_from' => 31, 'days_to' => 60,  'count' => int, 'total' => float],
    ['label' => 'Late',     'days_from' => 61, 'days_to' => 90,  'count' => int, 'total' => float],
    ['label' => 'Critical', 'days_from' => 91, 'days_to' => null,'count' => int, 'total' => float],
  ],
  'items' => [
    [
      'visit_id'         => int,
      'txn_id'           => string,
      'client_name'      => string,
      'serial_no'        => string,
      'visit_date'       => 'YYYY-MM-DD',
      'amount'           => float,
      'days_outstanding' => int,
    ],
    // ... sorted by days_outstanding DESC
  ],
  'total_outstanding' => float,  // sum of all pending amounts
]
```

Buckets computed via SQL `CASE` on `(CURRENT_DATE - sv.visit_date)` — single query, no PHP-side bucketing.

---

## Backend: DashboardController

`DashboardController::index()` already builds `$report` conditionally. Add:

```php
$report['receivables'] = $user->can('collect_payment')
    ? $this->reports->receivables($user->seesAllData() ? null : $user->id)
    : null;
```

- No new route, no new controller.
- `DashboardController` already injects `ReportService`.
- Frontend receives `report.receivables = null` when user lacks `collect_payment` → section hidden via `v-if`.

---

## Frontend: Dashboard.vue

New section at bottom, `v-if="report.receivables"`.

### Aging Bucket Cards

4 `StatCard`-style cards in a row:

| Card | Label | Value | Sub | Color accent |
|---|---|---|---|---|
| 1 | Current (0–30d) | RM X,XXX | N visits | green |
| 2 | Overdue (31–60d) | RM X,XXX | N visits | yellow |
| 3 | Late (61–90d) | RM X,XXX | N visits | orange |
| 4 | Critical (90+d) | RM X,XXX | N visits | red |

### Receivables Table

Columns: **Client** · **Serial** · **Visit Date** · **Days Outstanding** · **Amount** · *(action)*

- Days Outstanding rendered as a colored badge (same green/yellow/orange/red scale as bucket cards)
- Each row links to `/service-records/{visit_id}` (→ service record where payment can be collected)
- Sorted by `days_outstanding DESC` (worst first)
- No pagination for v1 (outstanding list is expected to be small)
- Empty state: "No outstanding payments."

### Section Header

```
Outstanding Receivables — RM 4,250.00
```

Total pulled from `report.receivables.total_outstanding`.

---

## Permission & Scoping

| User type | Gate | Data seen |
|---|---|---|
| Admin | `collect_payment` (implicit) | All pending visits |
| `view_all_data` tech | `collect_payment` (if granted) | All pending visits |
| Scoped tech | `collect_payment` (if granted) | Own pending visits only |
| No `collect_payment` | — | Section hidden (`null`) |

Scoping reuses `$user->seesAllData()` — same pattern as KPIs, transactions, and appointments.

---

## Testing

### `ReportServiceReceivablesTest` (unit)

- Returns empty buckets + items when no pending transactions exist
- Correctly buckets visits by age into 0–30, 31–60, 61–90, 90+ day groups
- Scoped tech (`technicianId` set) sees only own pending visits
- All-data user (`technicianId = null`) sees all pending visits
- `total_outstanding` sums only pending transactions (not paid)

### `DashboardReceivablesTest` (feature, `GET /dashboard`)

- User with `collect_payment` gets `report.receivables` (not null)
- User without `collect_payment` gets `report.receivables = null`
- Scoped tech receivables filtered to own visits only
- Admin sees all pending visits regardless of technician assignment

---

## Out of Scope (v1)

- Write-off / bad-debt marking
- Export to CSV (use existing export endpoint if needed)
- Pagination (list expected to be small; add if needed later)
- Push notifications for overdue receivables
