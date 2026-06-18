# Appointment Flow Cluster — Design

**Date:** 2026-06-18
**Feedback source:** `docs/FEEDBACK-17062026.md`
**Items closed:** BUG-003, BUG-004, CHG-002, CHG-003, CHG-004, FEAT-001, FEAT-002

## Goal

Tighten the appointment → service-record → payment flow: fix the client-autofill
bug, simplify the status model, link a paid record back to its appointment, and
polish the table actions/columns.

## Decisions (locked with Hamid)

- CHG-002 + CHG-004 done together: full status-model overhaul, not just a relabel.
- Appointment ↔ payment link via a nullable `appointment_id` FK on `service_visits`,
  set when a record is created from an appointment row; payment success completes it.
- Booking from a client page keeps the navigate-then-modal flow, but Cancel returns
  to the client profile.
- Technician dropdown defaults to the current admin; the "— Unassigned —" option is
  removed entirely (every appointment gets a technician).
- A dedicated Serial column is added; the serial line under the client name is removed.
- Existing appointment data is disposable (Q7) — the status migration maps in place
  but no data-preservation guarantees are needed.

## 1. Status model overhaul (CHG-002 + CHG-004)

**Enum.** `Appointment::STATUSES` becomes `['pending', 'completed', 'cancelled']`
(drop `confirmed`, `done`).

**Transitions.** `Appointment::TRANSITIONS`:
```
'pending'   => ['completed', 'cancelled'],
'completed' => [],
'cancelled' => [],
```
`canTransitionTo()` unchanged in logic.

**Migration** (`2026_06_18_0000XX_collapse_appointment_statuses`):
- Map existing rows: `confirmed → pending`, `done → completed` (raw `UPDATE`s).
- No enum/constraint at the DB level today (status is a plain string column) — verify;
  if a check constraint or Postgres enum exists, alter it. Reversible `down()` maps back
  (`completed → done`, leaves pending as pending).

**Controller.**
- `store()` already sets `'status' => 'pending'` — unchanged.
- `updateStatus()` validates against the new `STATUSES` and the new `TRANSITIONS`. The
  success message string stays generic (`"Appointment marked {$target}."`).
- `index()` stat cards: `month_confirmed` no longer meaningful. Replace the "Confirmed"
  stat card with **"Completed"** (`->where('status', 'completed')`). Keep `month_pending`.

**Frontend — `Appointments/Index.vue`.**
- Per-row actions (desktop `#cell-actions` + mobile `#card`): exactly three —
  **Add Record** (renamed from "+ Service record"), **Edit**, **Cancel Appointment**.
- "Cancel Appointment" shows only when `transitions[row.status]` includes `'cancelled'`
  (i.e. status is `pending`). It calls `setStatus(row, 'cancelled')`.
- Remove the `v-for` over transitions that rendered Confirm / Mark done / Cancel; replace
  with a single explicit Cancel button gated as above.
- Stat card label "Confirmed" → "Completed", bound to the new stat key.

**Frontend — `AppointmentModal.vue`.**
- Add a **Status** select (Pending / Completed / Cancelled) visible only in edit mode
  (`isEdit`). Bound to `form.status`. New appointments are always `pending` (no field).
- `submit()` includes `status` on the update payload. `UpdateAppointmentRequest` accepts
  `status` (validated `Rule::in(STATUSES)`).
- **Status on the edit path is a direct admin override — no transition guard.** Admin may
  set any of the three statuses from the edit modal. The `updateStatus()` quick-action
  (Cancel button) keeps its transition guard; the two paths are intentionally different.

## 2. Appointment ↔ payment link (CHG-004)

**Migration** (`2026_06_18_0000YY_add_appointment_id_to_service_visits`):
- `appointment_id` nullable, FK → `appointments`, `nullOnDelete`. Indexed.

**Create-from-appointment path.**
- `Appointments/Index.vue` "Add Record" link adds `appointment: row.id` to the
  `service-records.create` route params (alongside existing `client`, `technician_id`).
- `ServiceVisitController::create()` reads `request('appointment')` → `presetAppointmentId`
  prop (int or null). Validate the appointment is visible to the user (`visibleTo`); if not,
  pass null.
- `ServiceRecords/Create.vue`: `presetAppointmentId` prop; `form.appointment_id` initialised
  from it; hidden (no UI), submitted with the record.
- `StoreServiceVisitRequest`: `appointment_id` nullable, `integer`, must exist in
  `appointments` AND be tenant-visible to the user (closure/`Rule::exists` scoped by
  `tenant_id`). Mirrors the existing technician_id tenant guard.
- `ServiceVisitController::store()` persists `appointment_id` on the visit.

**Payment completion.**
- Central helper (e.g. `PaymentService::completeLinkedAppointment(ServiceVisit $visit)`):
  if `$visit->appointment_id` set, load the appointment; if its status is not `cancelled`,
  set `status = 'completed'`. No-op otherwise. Idempotent.
- Call it from BOTH payment-success paths:
  - Cash collection (wherever `issueReceipt` / status→paid happens for cash).
  - Webhook (`PaymentWebhookController`) after a transaction transitions to `paid`.
- Tenant safety: the appointment is reached via the visit's own FK, so no cross-tenant
  leak; still assert the appointment shares the visit's `tenant_id` before updating.

## 3. Autofill + cancel redirect (BUG-003 + BUG-004)

**Autofill (root cause).** `AppointmentModal.vue` rehydrate watcher is not immediate, so
when `Index.vue` opens the modal during setup (`if (props.presetClient) openNew()`), the
modal's initial `open=true` never fires the watcher → preset client not applied. Symptom:
only autofills after Cancel→reopen.

- Fix: `watch(() => props.open, handler, { immediate: true })`. The handler already early-
  returns on `!open`, so the normal (closed) initial state is safe.

**Cancel redirect.** When the modal was opened from a client (presetClient present), Cancel
or backdrop-close should return to that client's profile rather than sit on Appointments.

- `Appointments/Index.vue`: pass a `returnTo` (the `clients.show` URL for `presetClient.id`)
  into the modal, or handle in the `@close` callback: if `props.presetClient`,
  `router.visit(route('clients.show', props.presetClient.id))`; else just close the modal.
- Successful save (`onSuccess`) already redirects server-side to `appointments.index`; that
  is fine — only the cancel/close path needs the client redirect. Guard so a successful
  submit doesn't double-navigate.
- This covers the reminder "Set Appointment" entry (BUG-004) automatically, since it uses
  the same `appointments.index?client=` → presetClient mechanism.

## 4. Technician default (CHG-003)

`AppointmentModal.vue` (technician block, all-data users only):
- Remove the `<option :value="null">— Unassigned —</option>`.
- On new appointments, default `form.technician_id` to the current user id
  (`page.props.auth.user.id`). On edit, keep the appointment's stored `technician_id`.
- Server: `store()` for all-data users should fall back to the current user id if somehow
  null; scoped (non-all-data) techs continue to self-assign server-side as today.

## 5. Serial column (FEAT-002)

`Appointments/Index.vue` DataTable:
- Add a `{ key: 'serial', label: 'Serial' }` column (after Client).
- `#cell-serial`: if `row.client` → `<Link :href="route('clients.show', row.client.id)">#{{ row.client.serial_no }}</Link>`
  (primary, hover underline); else a muted `Non client` label.
- Remove the `<div v-if="row.client">#{{ serial_no }}</div>` line under the client name in
  both `#cell-client` and the mobile `#card` (replace mobile with the same serial/Non-client
  treatment inline).
- `index()` already eager-loads `client:id,serial_no,name` — no controller change.

## Testing

Backend (`docker exec saifzz-aircond-laravel.test-1 php artisan test`):
- `AppointmentTest`: update to new `STATUSES` / `TRANSITIONS`; pending→completed and
  pending→cancelled legal, completed/cancelled terminal. Fix any fixtures referencing
  `confirmed`/`done`.
- New `AppointmentPaymentCompletionTest`: creating a record with `appointment_id` then
  collecting cash sets the appointment `completed`; webhook path same; cancelled appointment
  stays cancelled; visit with no `appointment_id` is a no-op.
- `StoreServiceVisitRequest`: `appointment_id` rejected when cross-tenant / nonexistent;
  accepted when valid; nullable.
- Sweep the suite for fixtures asserting old statuses (MultiTenant, ServiceVisit, etc.).

Frontend (manual verify after `npm run build`):
- Booking from a client autofills on first open; Cancel returns to the client.
- Reminder "Set Appointment" autofills.
- Per-row actions show Add Record / Edit / Cancel Appointment only.
- Technician defaults to own name; no Unassigned option.
- Serial column links to client; "Non client" for walk-ins.

## Deployment (on merge to main)

- `php artisan migrate` (2 migrations: status collapse + `appointment_id`).
- No reseed required (appointment data disposable; existing rows mapped by the migration).
- `npm run build` (frontend changes).

## Out of scope

- CHG-006 (catalog HP grouping), CHG-007/008 (service-record Google-review move + method
  selector), FEAT-004 (Manual QR), FEAT-007 (edit-record-edits-services), transaction/
  reminder filters — separate clusters.
