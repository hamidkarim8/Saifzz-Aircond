# FEAT-018: HP-Based Pricing

## Goal

A service type can be marked HP-based. When enabled, the boss configures HP value → price tiers in the Fee Schedule tab. In the service record form, selecting an HP-based service type shows an HP dropdown that auto-fills the price. HP value is stored on the service line for invoice display.

## Data Layer

### `service_types` table changes

Add `is_hp_based boolean default false`.

`ServiceType` model: add `is_hp_based` to `$fillable`, cast as `boolean`.

### New table: `service_hp_tiers`

| column | type | notes |
|--------|------|-------|
| id | bigint PK | |
| service_type_id | bigint FK→service_types cascadeOnDelete | |
| hp_value | decimal(3,1) | e.g. 1.0, 1.5, 2.0 |
| price | decimal(8,2) | |
| created_at | timestamp | |
| updated_at | timestamp | |

Unique constraint: `(service_type_id, hp_value)`.

`ServiceHpTier` model: `$fillable = ['service_type_id','hp_value','price']`. Belongs to `ServiceType`.

`ServiceType` model: `hasMany(ServiceHpTier::class)`.

### `service_lines` table changes

Add `hp_value decimal(3,1) nullable` — stores the selected HP for the line (for invoice/receipt display). Null when service type is not HP-based.

## Pricing Rule

`rate on service_line = base_fee_rate + hp_tier_price`

- If type is HP-based AND has a base fee in `service_fees` → rate = base fee rate + HP tier price
- If type is HP-based AND has no base fee → rate = HP tier price only
- Calculation is done client-side; final `rate` stored on the line

## Controllers & Routes

### `ServiceTypeController::update()`

Add `is_hp_based` to validated fields. Save on the `ServiceType` row via existing `PUT /service-types/{id}`.

### New `ServiceHpTierController`

- `store(Request $request)` — `can:edit_fees`. Validates: `service_type_id` (exists in service_types), `hp_value` (numeric, min 0.5, max 20.0), `price` (numeric, min 0). Creates row (upsert on conflict to handle duplicate HP values gracefully).
- `destroy(ServiceHpTier $tier)` — `can:edit_fees`. Deletes row.

Routes:
```
POST   /service-hp-tiers          → ServiceHpTierController@store   (name: service-hp-tiers.store)
DELETE /service-hp-tiers/{tier}   → ServiceHpTierController@destroy (name: service-hp-tiers.destroy)
```

### `ServiceTypeController::index()`

Add `hpTiers` to page props: `ServiceHpTier::orderBy('hp_value')->get()->groupBy('service_type_id')`.

### `ServiceVisitController::create()` and `edit()`

Add `hpTiers` prop: same grouped query as above. Passed to `ServiceRecords/Create.vue` and `Edit.vue`.

## UI: Fee Schedule Tab (`ServiceTypes/Index.vue`)

Each service type card in the Fee Schedule tab gains:

1. **HP-based pricing toggle** — checkbox/switch at card top. On change → `PUT /service-types/{id}` with `{ is_hp_based: bool }`. Optimistic UI.

2. **HP tiers section** (visible when `is_hp_based = true`):
   - Table: HP (HP) | Price (RM) | Delete
   - Rows: existing tiers for this service type, sorted by hp_value
   - Delete button per row → `DELETE /service-hp-tiers/{id}`
   - **Add HP tier row**: hp_value input with `<datalist>` of standard values (1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 5.0) + price input + Save → `POST /service-hp-tiers`
   - Toggling OFF hides section; tiers kept in DB (restored on toggle back ON)

## UI: Service Record Form (`ServiceRecords/Create.vue` + `Edit.vue`)

Per service line:

- Detect when selected service type has `is_hp_based = true` (from `serviceTypes` prop)
- Show HP dropdown: options from `hpTiers[service_type_id]`, sorted by hp_value
- On HP select:
  - Look up base fee from `feeMap[service_type]` (existing autofill logic)
  - `rate = (base_fee ?? 0) + hp_tier_price`
  - Set `line.hp_value = selected hp_value`
- When service type changes: clear `hp_value`, re-run autofill
- `hp_value` included in line data sent to backend

## Backend: `ServiceVisitController` line normalization

`normalizeLine()` — add `hp_value` to fillable fields on `ServiceLine`. Store as-is (null if not HP-based).

`ServiceLine` model: add `hp_value` to `$fillable`, cast as `decimal:1` nullable.

## Migrations

1. `add_is_hp_based_to_service_types_table` — add boolean column
2. `create_service_hp_tiers_table` — new table as above
3. `add_hp_value_to_service_lines_table` — add nullable decimal column

## Testing

- Toggle `is_hp_based` on a service type → saved, reflected in UI
- Add HP tier → row created, appears in tier table
- Delete HP tier → row removed
- Duplicate HP value → upsert (update price, not error)
- Service record: HP-based type selected → HP dropdown appears, rate auto-fills
- Service record: non-HP type selected → no HP dropdown
- Service record: HP-based type, no tiers configured → HP dropdown empty (admin must configure tiers first)
- `hp_value` stored on service line, visible on invoice/receipt line

## Deployment

Run `php artisan migrate` on prod after deploy. No reseed needed.
