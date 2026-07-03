# Set next service date on a paid service line

**Date:** 2026-07-02
**Trigger:** Khalid often forgets to select the next-service month while creating a visit. If the record is still `pending` he can fix it via the normal Edit flow. Once payment is collected (`status = paid`), `ServiceVisitController::edit/update` hard-gate to `pending` (`app/Http/Controllers/ServiceVisitController.php:215,242`), so the field stays permanently null with no way to fill it in. Khalid needs a narrow way to set it after the fact, without reopening the rest of the paid record.

## Current behavior (verified in code)

- `next_service_date` lives in two places:
  - `service_lines.next_service_date` — per-line-item, set at visit creation/edit time (`database/migrations/2026_06_11_000040_create_service_lines_table.php:21`, `app/Models/ServiceLine.php:23,36`).
  - `client_units.next_service_date` + `next_service_type` — per-unit, rolled-up/canonical value shown on the Client's Units section (`database/migrations/2026_06_13_000100_create_client_units_table.php:21-22`, `app/Models/ClientUnit.php:13,21`).
- Sync logic (identical in `store()` and `update()`) pushes each line's date onto its unit whenever `unit_id` and `next_service_date` are both present, using the line's own `service_type` as `next_service_type` (`ServiceVisitController.php:158-169`, `271-280`). There is no separate "type" column on `service_lines` — the line's `service_type` string doubles as the type value on sync.
- `ServiceLine` has no `next_service_type` column at all (`app/Models/ServiceLine.php:13-26`) — only `next_service_date`.
- Both `edit()` (line 215) and `update()` (line 242) `abort_unless($serviceRecord->transaction?->status === 'pending', ...)`. This blocks the entire visit edit form, including next-service resync, once paid.
- `ServiceRecords/Show.vue` only renders the Edit link `v-if="txn?.status === 'pending'"` (line 78); paid records show a Void action instead, no edit affordance.
- `service_type` records carry a `requires_next_service` flag (`ServiceType` model, surfaced in `ServiceVisitController::edit()` line 229) that determines whether a line should even prompt for a next-service date in the first place.

## Design

### Scope: one field, exempt from the paid lock

A single new endpoint updates only `service_lines.next_service_date` for one line, deliberately separate from the full visit `update()` so no other part of a paid record becomes editable.

```
PATCH /service-records/{serviceRecord}/lines/{line}/next-service-date
```

New controller method on `ServiceVisitController` (e.g. `updateNextServiceDate`):
- `abort_unless(ServiceVisit::whereKey($serviceRecord->getKey())->visibleTo(request()->user())->exists(), 403)` — same visibility check as `show()`/`edit()`.
- `abort_unless($line->visit_id === $serviceRecord->id, 404)` — line must belong to this record.
- Validate `next_service_date` as `nullable|date`.
- No status check — this is the one field intentionally exempt from the `pending`-only lock, regardless of whether the transaction is `pending`, `paid`, or `void`.
- `DB::transaction`:
  - `$line->update(['next_service_date' => $data['next_service_date']])`.
  - If `$line->unit_id` is set: resync `client_units` — same snippet as `store()`/`update()` (`->where('id', $line->unit_id)->where('client_id', $serviceRecord->client_id)->update(['next_service_date' => ..., 'next_service_type' => $line->service_type])`). Skipped if `next_service_date` was cleared to null (matches existing sync's `!empty()` guard — nulling a line doesn't blank out the unit's value).

Always editable (not just when currently null) — Khalid may also need to correct a wrong date, not just fill a blank one.

### Frontend — `resources/js/Pages/ServiceRecords/Show.vue`

- For each line whose `service_type` requires next-service (matched against the same `requires_next_service` list used in `edit()`), show the next-service date next to a small edit affordance (pencil → native date input → Save), regardless of transaction status.
- Save calls the new route via Inertia `router.patch`, partial reload of the line's date only.
- Lines without a `unit_id`, or whose type doesn't require next-service, render the existing read-only display (no change).

## Out of scope

- Editing any other field on a paid record (visit date, technician, warranty, amounts) — stays `pending`-only, unchanged from `2026-07-02-void-paid-service-record-design.md`.
- Backfilling/rewriting `client_units.next_service_date` independent of a line edit (no standalone "edit unit" UI).
- Audit trail (who/when changed the date) — not requested.
- Restricting the edit to null-only values — Khalid confirmed he wants it always editable.

## Testing

Feature tests:
- Can set `next_service_date` on a line belonging to a `paid` record; `client_units.next_service_date`/`next_service_type` resync correctly when `unit_id` present.
- Can overwrite an already-set `next_service_date` on a paid line (not null-only).
- Clearing to null updates the line but does not blank the unit's existing value.
- 404 when line doesn't belong to the given service record.
- 403 when record isn't visible to the acting user (tenant scoping).
- No other fields on the paid record are reachable/mutated via this endpoint.
