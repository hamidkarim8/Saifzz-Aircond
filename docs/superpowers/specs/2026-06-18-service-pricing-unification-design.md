# Service Pricing Unification — Design (CHG-005 + BUG-002 + FEAT-003)

**Date:** 2026-06-18
**Status:** Approved (brainstorm)
**Feedback items:** CHG-005 (P1, core), BUG-002 (High), FEAT-003 (P2). CHG-006 deferred.

## Problem

Pricing is hardcoded and split across three parallel mechanisms:

- `service_fees` — `(service_type, option, rate, pricing_mode)`, keyed by service-type **name**.
- `service_hp_tiers` — separate table, `(service_type_id, hp_value, price)`, applied as an **additive surcharge** on top of the base fee.
- Hardcoded constants in `StoreServiceVisitRequest`: `UNIT_TYPES = ['Wall Mounted','Cassette']`, `GAS_OPTIONS = ['20 PSI','Half Top-Up','Full Top-Up']`, `UNIT_TYPE_SERVICES = ['Cleaning','Installation','Troubleshoot']`.
- Hardcoded service semantics in code: `'Repair'` = flexible/manual, `'Gas Top-Up'` = gas options (`isGas`).

Khalid's ask (CHG-005): when setting fees, dynamically add unit types and, if HP-based, set the HP→price tiers **per unit type**, all in one form. Each unit type owns its own HP price set — **not** a base + HP surcharge. Plus the flexible/manual case must keep the price editable and allow a description (BUG-002).

## Target model

Every service type has **exactly one** `pricing_mode`:

| mode | meaning | record-time input |
| ---- | ------- | ----------------- |
| `flat` | unit types, each a single price | unit-type dropdown → autofill price |
| `hp_tiered` | unit types, each with its own HP→price rows | unit-type dropdown → HP dropdown → autofill price |
| `flexible` | no fee rows | editable price + description |

Worked example:

```
Cleaning (hp_tiered)
  Wall Mounted:  1.0HP=50, 1.5HP=60, 2.0HP=80
  Cassette:      1.0HP=70, 1.5HP=85, 2.0HP=110

Gas Top-Up (flat)
  20 PSI = 30 · Half Top-Up = 50 · Full Top-Up = 90

Repair (flexible)
  manual price + description
```

Price lookup is **direct**: `(service_type, unit_type, hp_value) → price`. No additive surcharge.

## Schema

### `service_types`
- **Add** `pricing_mode` enum/string: `flat` | `hp_tiered` | `flexible`.
- **Drop** `is_hp_based` boolean (replaced by `pricing_mode`).
- Keep `name`, `requires_next_service`. Stays **global** (no `tenant_id`) — both bosses share the price book, as today.

### `service_fees` (rebuilt — absorbs `service_hp_tiers`)
- `id`
- `service_type_id` — FK → `service_types`, cascade on delete. (Was a string `service_type` name; now a real FK.)
- `unit_type` — string, the dynamic per-service label (also holds former gas options).
- `hp_value` — decimal(3,1) **nullable**; null for `flat`, set for `hp_tiered`.
- `price` — decimal(8,2).
- unique `(service_type_id, unit_type, hp_value)`.

A service's unit-type list = `distinct unit_type` of its fee rows. No constant.

### `service_lines`
- **Drop** `gas_option` (folds into `unit_type`).
- Keep `unit_type`, `hp_value`, `rate`, `repair_desc` (now generic "description" for any flexible service), `units`, `discount`, `next_service_date`, `notes`, `subtotal`.

### Dropped
- `service_hp_tiers` table.
- `service_fees.option`, `service_fees.rate`, `service_fees.pricing_mode` (old columns).
- Constants `UNIT_TYPES`, `GAS_OPTIONS`, `UNIT_TYPE_SERVICES`.

**Data:** existing fee/line data is disposable (Q7). Migrations may rebuild destructively; seeders reseed a valid demo set. Confirm prod fee tables are reseeded after deploy.

## Backend

### Models
- `ServiceType`: `pricing_mode` fillable/cast; `fees(): HasMany`. Drop `is_hp_based`, drop `hpTiers()`. Helper `unitTypes()` (distinct) optional.
- `ServiceFee`: fillable `service_type_id, unit_type, hp_value, price`; casts; `belongsTo(ServiceType)`. Replaces `ServiceHpTier`.
- **Delete** `ServiceHpTier` model.

### Fee write — sync semantics
- `ServiceTypeController` (or a dedicated `ServiceFeeController`) accepts the whole fee set for a service type and **replaces** its rows transactionally (delete-then-insert, or diff). One submit = full price set.
- Request validation: `pricing_mode` valid; for `flat`/`hp_tiered` at least one unit type; `hp_tiered` requires `hp_value` per row, `flat` forbids it; `price >= 0`; no duplicate `(unit_type, hp_value)`. Gated `can:edit_fees`.
- Route surface: keep `service-types.*`; replace `service-hp-tiers.*` + `fees.*` with the unified fee-set endpoint(s). Old routes removed.

### Pricing resolution (`ServiceVisitController::normalizeLine` + `StoreServiceVisitRequest`)
- Branch on `service_type.pricing_mode`, not hardcoded names:
  - `flexible` → `rate` = submitted value; `repair_desc` required; no fee lookup.
  - `flat` → `rate` = `ServiceFee` price for `(service_type_id, unit_type, null)`.
  - `hp_tiered` → `rate` = price for `(service_type_id, unit_type, hp_value)`.
- Validation: `unit_type` must exist in that service's fee rows; `hp_value` required + valid for `hp_tiered`; `repair_desc` required for `flexible`. Remove `Rule::in(UNIT_TYPES/GAS_OPTIONS)` and `UNIT_TYPE_SERVICES` checks.
- Rate stays server-authoritative for `flat`/`hp_tiered`; client-supplied for `flexible`.

## Frontend

### `ServiceTypes/Index.vue` — Fee Schedule tab (CHG-005 + FEAT-003)
- Per service type: a mode selector (Flat / HP-tiered / Flexible) + the dynamic fee editor.
- Flexible → no rows.
- Flat / HP-tiered → repeatable **unit-type blocks**:
  - unit type name input
  - HP-tiered: repeatable `HP | price` rows with add/remove (**FEAT-003**)
  - Flat: single `price`
  - "Add unit type" / "Add HP tier" / remove buttons
- One save posts the full set. Replaces the current split (separate Set-Fee modal + separate HP-tier add rows).
- `FeeModal.vue` and the old HP-tier inline rows are superseded; remove or repurpose.

### `ServiceRecords/Partials/ServiceLineCard.vue` (BUG-002 + generalization)
- Drive off `pricing_mode` from the service-type prop instead of `isRepair`/`isGas` name checks.
- `flexible` → price input **editable** + description field (BUG-002). Applies to any flexible service.
- `flat` → unit-type dropdown (from that service's fee rows) → autofill price.
- `hp_tiered` → unit-type dropdown → HP dropdown (that unit's HP values) → autofill price.
- Props change: pass per-service fee rows (or a `feeMap` keyed by `service_type_id|unit_type|hp_value`) + `pricing_mode`. Drop `gasOptions`, `unitTypeServices`, the global `unitTypes`.

### Controllers feeding the page
- `ServiceVisitController::create()` / `edit()`: pass service types with `pricing_mode` + their fee rows; drop `unitTypes`/`gasOptions`/`unitTypeServices`/`hpTiers` props.
- `CatalogController` already reads fees — update to new shape (display only; CHG-006 grouping deferred).

## Testing
- **Delete/rewrite** `ServiceHpTierTest`, `ServiceFeeTest` to the unified model.
- New coverage:
  - Fee-set save: flat single price, hp_tiered multi-unit-type multi-HP, flexible (no rows), validation rejects (dup row, missing hp on tiered, hp on flat, non-edit_fees 403).
  - Record pricing: flat lookup, hp_tiered lookup per unit type, flexible manual+desc, bad unit_type rejected, missing hp on tiered rejected.
- Update `ServiceTypeSeeder` (+ fee seed) so the suite and demo data are valid under the new schema. Project has **no factories** — fixtures via direct `Model::create()`.

## Out of scope
- CHG-006 (Catalog grouping by HP / unit type) — display concern, separate session. Catalog page updated only enough to not break.
- Per-tenant fee book — stays global by design.
- Reusable/global unit-type dictionary — unit types stay per-service free text (datalist suggestion optional, not required).

## Deploy notes
- `php artisan migrate` (service_types `pricing_mode`, rebuilt `service_fees`, drop `service_hp_tiers`, drop `service_lines.gas_option`).
- Reseed fee/demo data (existing data disposable). Confirm prod fee tables reseeded.
- `npm run build` (Vue changes).
