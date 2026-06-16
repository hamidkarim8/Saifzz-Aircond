# Units feature — PARKED for discussion

**Status:** Frontend hidden (2026-06-16). Backend + DB fully intact.

## Why parked

The per-unit tracking (`ClientUnit`) is a half-built feature. Units live on the
client page but the link to service records feels disconnected — requirement not
mature enough to ship. Hidden until scope is agreed.

## What was hidden (frontend only, `v-if="false"`)

| File | What |
|---|---|
| `resources/js/Pages/Clients/Show.vue` | `UnitsSection` block on client page |
| `resources/js/Pages/ServiceRecords/Partials/ServiceLineCard.vue` | Unit selector dropdown in service line |
| `resources/js/Pages/ServiceRecords/Create.vue` | "+ Add line for each unit" button |

Re-enable = restore the original `v-if` conditions (noted inline at each spot).

## What is untouched (safe — no data loss)

- DB: `client_units` table, `service_lines.unit_id` column (nullable, FK `nullOnDelete`)
- Migrations, `ClientUnit` model, `ClientUnitController`, routes, form requests
- Reminders still work via the **fallback path** (`service_lines.next_service_date`),
  since hidden units means all records use count mode and the date lands on the line.

## Open questions to discuss

- What is a "unit" relative to a service record — required link, or optional?
- Should service lines be per-unit always, or keep count mode as default?
- How do units interact with reminders, warranty, invoices long-term?
- Do clients self-register units via portal, or staff-only?

## Confirmed not broken by hiding

- Invoices/receipts: `SnapshotBuilder` uses `unit_type` (text) + `units` (count), not `unit_id`.
- Service visit create/store: `unit_id` is nullable end to end.
