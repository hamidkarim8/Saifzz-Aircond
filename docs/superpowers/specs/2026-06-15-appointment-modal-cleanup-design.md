# CHG-007 — Appointment Modal / Data Cleanup

**Date:** 2026-06-15
**Feedback item:** CHG-007 (P1) — "Redesign appointment modal per Khalid's mockup"
**Status:** design approved

## Background

CHG-007's headline requirements were already delivered across prior sessions:

- **CHG-009/010 (session 30):** removed Service Type + Units fields from the appointment form and the Unit/Service Type columns from the table.
- **CHG-008/032 (session 32):** `+ Service record` link passes `technician_id`; preset client/technician wiring.
- **AppointmentModal.vue** already implements: client search + autofill, preset-client autofill, tenant-filtered technician selector (admins only), and the backend forces the technician to self for scoped techs (`AppointmentController::store` line 105).

What remains is **cleanup of the `service_type`, `units`, `amount` fields** that CHG-009/010 removed from the *form* but left behind in the *data layer and other UI*, where they now render empty or sit dead:

- `StoreAppointmentRequest` still validates + persists all three (always null on create now).
- `Appointment` model still lists them in `$fillable` / `$casts`.
- `Index.vue` table still has an **Amount** column → always `—`.
- `Index.vue` day-panel + Today sidebar render `a.service_type` **badges** → always-empty pills.
- `Dashboard.vue:216` renders `— {{ a.service_type }}` on the upcoming-appointment row → trailing empty dash.

The modal itself needs no redesign; the mockup field set (Client Name, Serial, Phone, Address, Date+Time, Notes) already matches, with Service Type intentionally omitted per the locked Q&A decision.

## Decisions

- **Retire columns via migration** (drop `service_type`, `units`, `amount` from `appointments`). Data is disposable (Q7), so safe. Reversible `down()` re-adds them nullable. Run `php artisan migrate` on prod after deploy.
- **Keep client-search-only.** No free-text walk-in name field. Client-less appointments continue to render as "Walk-in". No new column.

## Changes

### 1. Migration
`database/migrations/2026_06_15_000001_drop_service_type_units_amount_from_appointments.php`
- `down()` then `up()`: drop `service_type`, `units`, `amount`.
- `down()`: re-add `service_type` (string nullable), `units` (integer nullable), `amount` (decimal 10,2 nullable).

### 2. Model — `app/Models/Appointment.php`
- Remove `service_type`, `units`, `amount` from `$fillable`.
- Remove `units`, `amount` casts.

### 3. Requests
- `StoreAppointmentRequest`: drop the 3 validation rules (lines 25–27) and the 3 keys from `appointmentData()`.
- `UpdateAppointmentRequest`: same if it mirrors (verify during implementation).

### 4. Controller — `AppointmentController::index`
- Stop passing the `serviceTypes` prop to `Appointments/Index` (modal has no service-type select).

### 5. Frontend — `resources/js/Pages/Appointments/Index.vue`
- Remove the **Amount** column from `columns` + its `#cell-amount` template + the `money` helper if unused elsewhere.
- Remove the empty `service_type` `<Badge>` from the day-panel (~line 182) and Today sidebar (~line 202); keep client name + time.
- Drop the `serviceTypes` prop and `serviceVariant` import if unused after the badge removal.
- Stop binding `:service-types` to `<AppointmentModal>`.

### 6. Frontend — `resources/js/Pages/Appointments/Partials/AppointmentModal.vue`
- Drop the unused `serviceTypes` prop.

### 7. Frontend — `resources/js/Pages/Dashboard.vue`
- Line ~216: remove `— {{ a.service_type }}` from the upcoming-appointment row (keep client name).
- Leave all other `service_type`/`amount` usages — those are transaction columns, unaffected.

### 8. Tests
- Update any appointment factory / feature test asserting `service_type`/`units`/`amount`.
- Add/adjust a `store` test confirming those inputs are ignored (not persisted).
- Full suite expected green (~258).

## Out of scope
- No modal redesign (already matches mockup).
- No walk-in name field.
- No changes to transaction `amount`/`service_type` (different domain).

## Deploy note
Run the new migration on production after merge (`php artisan migrate` via docker exec).
