# Per-Unit Identity Tracking — Design Spec
**Date:** 2026-06-13
**Status:** Approved

## Problem

Current system tracks `units` as a count on `service_lines` (e.g., "3 Wall Mounted cleaned"). There is no identity for individual units — can't answer "when was the bedroom unit last gassed?", can't send a tech with the right refrigerant, can't set per-unit reminders.

## Decisions

| # | Question | Decision |
|---|---|---|
| 1 | What data per unit? | Full identity: label, type, HP, brand, model, serial, refrigerant type |
| 2 | How service lines link to units? | One service line per unit (unit_id FK on service_lines) |
| 3 | Where does next_service_date live? | Moves to unit record — reminders query client_units |
| 4 | When are units added? | Both: client profile tab AND inline during visit creation |
| 5 | Existing clients? | Auto-migrate from service_line data on deploy |

---

## Data Model

### New table: `client_units`

```
id                  bigint PK
client_id           FK → clients (restrict on delete)
label               string            -- "Master Bedroom", "Living Room"
unit_type           string            -- Wall Mounted | Cassette
hp                  decimal(3,1) null -- 0.75 | 1.0 | 1.5 | 2.0 | 2.5
brand               string null       -- LG, Daikin, Panasonic, etc.
model               string null       -- model name/number
serial_no           string null       -- unit's own serial (≠ client serial_no)
refrigerant_type    string null       -- R32 | R410A | R22
next_service_date   date null         -- drives reminders (moved from service_lines)
next_service_type   string null       -- service type due next (set alongside next_service_date)
is_active           boolean default 1 -- false = decommissioned
notes               text null
created_at / updated_at
```

### Modified: `service_lines`

Add column:
```
unit_id   FK → client_units null (nullOnDelete)
```

Existing columns `unit_type`, `units` (count), `next_service_date` are **kept** as-is. For new records with `unit_id` set, these fields will be null. Legacy records with `unit_id = null` continue to use them unchanged.

### Model: `ClientUnit`

- `belongsTo(Client)`
- `hasMany(ServiceLine)` via `unit_id`
- Scope `active()` → `where('is_active', true)`

### Modified: `ServiceLine`

- Add `belongsTo(ClientUnit, 'unit_id')->nullable()`
- `unit_id` null = legacy record; non-null = per-unit record

### Modified: `Client`

- Add `hasMany(ClientUnit)`

---

## Auto-Migration

Runs as a **data migration** (fires automatically on `php artisan migrate`).

**Algorithm per client:**

1. Query `service_lines` through `service_visits` for this client
2. Filter to lines where `next_service_date IS NOT NULL` or `unit_type IS NOT NULL`
3. Group by `unit_type`, take `MAX(units)` as count of units of that type
4. For each `(unit_type, count)` pair: create `count` `client_unit` records
   - `label` = `"{unit_type} {n}"` e.g., "Wall Mounted 1", "Wall Mounted 2"
   - `unit_type` = from service_line
   - `next_service_date` = `MAX(service_line.next_service_date)` for this unit_type/client, applied to the first unit only (unit n>1 gets null — admin assigns later)
   - `next_service_type` = `service_type` of the service_line that has `MAX(next_service_date)` for this unit_type/client
   - `brand`, `model`, `serial_no`, `hp`, `refrigerant_type` = null (can't infer)
5. Lines without `unit_type` → skipped (Repair lines, etc.)

**Result:** all existing clients get auto-generated generic unit records. Labels are generic ("Wall Mounted 1") — admin renames after. No service_line rows are modified.

---

## ReminderService Refactor

Replace the `service_lines`-based `next_service_date` query with a `client_units`-based query.

**New logic:**

```
Query client_units cu
  JOIN clients c ON c.id = cu.client_id
  WHERE c.deleted_at IS NULL
    AND cu.is_active = true
    AND cu.next_service_date IS NOT NULL
  GROUP BY c.id
  HAVING MAX(cu.next_service_date) <= end_of_month
```

**Per-client item fields (sourced from client_units):**

- `next_due` = `MAX(cu.next_service_date)`
- `service_type` = `next_service_type` of unit with `MAX(next_service_date)` (correlated subquery on client_units)
- `units` = `COUNT(cu.id)` of active units with `next_service_date <= end_of_month`
- `last_service_date` = kept from `MAX(sv.visit_date)` join (unchanged)

**Fallback:** clients with no `client_units` rows fall back to the current service_line query (ensures no regressions for any edge case post-migration).

---

## Service Visit — Service Line Creation

### Unit selector on each line

Each service line row in the visit create/edit form gets a **unit selector** dropdown:
- Lists client's active units: `"Master Bedroom (Wall Mounted 1HP)"`
- Selecting a unit auto-fills `unit_type` read-only display
- Selector is optional — line can be saved without unit_id (backward compat)

### "Add line for each unit" button

One-click creates one service line row per active client unit, pre-filled:
- `unit_id` = the unit's id
- `unit_type` = from unit
- `service_type` = blank (tech selects)

### Inline unit add

If client has no units, or tech wants to add a new unit mid-flow:
- `"+ Add unit"` link in the unit selector dropdown
- Opens a compact modal: label (required), unit_type (required), hp, brand, model
- On save: unit created, selector refreshes, new unit pre-selected on the line

---

## Client Profile — Units Section

New section on `Clients/Show.vue`:

**Units tab/card:**
- Table/list of active units: label, type, HP, brand, model, next_service_date
- **Add unit** button → unit modal
- Per unit: **Edit** and **Deactivate** actions (no hard delete — units have service history)
- Per unit: link → filters service history to lines for this unit

**Unit modal fields (add + edit):**
- Label (required)
- Unit type: Wall Mounted / Cassette (required)
- HP: 0.75 / 1.0 / 1.5 / 2.0 / 2.5 (dropdown, optional)
- Brand (text, optional)
- Model (text, optional)
- Serial No (text, optional)
- Refrigerant type: R32 / R410A / R22 (dropdown, optional)
- Notes (textarea, optional)

---

## Permissions

New permission: `manage_units`

- **Admin:** granted by default (implicit via admin role)
- **Technician:** granted by default — techs discover new units on-site and need to add them

Permission gates:
- Add/edit/deactivate unit → `manage_units`
- View units on client profile → any authenticated user (no gate)

---

## API / Routes

All nested under `/clients/{client}/`:

```
POST   /clients/{client}/units              → ClientUnitController@store
PUT    /clients/{client}/units/{unit}       → ClientUnitController@update
PATCH  /clients/{client}/units/{unit}/deactivate → ClientUnitController@deactivate
```

No standalone index route — units are always displayed within the client profile.

---

## Backward Compatibility

| Scenario | Behaviour |
|---|---|
| Existing service_lines (unit_id = null) | Unchanged — still display using unit_type + units count |
| Clients with auto-migrated units | Units show on profile with generic labels; service history unlinked to units until next visit |
| Clients with no units (post-migration edge case) | ReminderService falls back to service_line query |
| New service lines without unit_id | Allowed — unit selector is optional |

---

## Testing

- Migration: creates correct unit count per client from existing service_lines
- ReminderService: reminders sourced from client_units; fallback works for unitless clients
- ClientUnitController: store, update, deactivate — auth gates
- Service visit: unit_id saved on service_line when selected
- Permission: unauthenticated and unpermitted users blocked from manage_units routes

---

## Out of Scope (deferred)

- Per-unit service history page (standalone view) — Phase 2
- Refrigerant type used in gas stock tracking — Phase 2 (field stored now, logic later)
- HP as a ServiceFee lookup key — deferred; fee still keys on service_type + option
- SST per unit — deferred
