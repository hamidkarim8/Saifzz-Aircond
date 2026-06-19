# Appointment client selection + universal Add Record — design

**Date:** 2026-06-19
**Status:** approved, ready for plan
**Feedback origin:** 19-Jun ad-hoc (Hamid relaying Khalid)

## Problem

Two related rough edges in the appointment flow:

1. **Confusing modal.** The new-appointment modal shows a client search picker AND a
   separate "Customer name" text box (the latter appears only when no client is picked).
   Two overlapping ways to name a customer reads as confusing. The service-record form
   already solved this cleanly with an explicit two-card toggle (Existing client | New
   client) in `ServiceRecords/Partials/ClientPicker.vue`. The appointment modal should
   adopt the same explicit pattern.

2. **Add Record missing for walk-ins.** The appointments table "Add Record" action is
   gated `v-if="row.client"` (`Appointments/Index.vue`), so walk-in / non-client
   appointments have no Add Record button. When a walk-in appointment already carries
   `customer_name` + phone + address, clicking Add Record should pre-fill the service
   record's **new client** form from that appointment data — just as a client-backed
   appointment pre-fills the **existing client**.

## Decisions (locked)

- **Manual appointment mode = walk-in only.** Manual entry stores `customer_name` /
  `phone` / `address` on the appointment row. NO client is created at appointment time.
  The client is created later, when Add Record is clicked and the service record is
  submitted. (Matches the existing `appointments.customer_name` schema from session 48.)
- **Back-link on record submit.** When a walk-in appointment's Add Record creates the
  new client, set `appointment.client_id` to that new client so the row stops reading
  "Non client".
- **Add Record routes via appointment id alone.** The link from an appointment row
  passes `appointment={id}` (+ `technician_id`); the controller derives the client (or
  walk-in data) from the appointment. The separate `client=` param is kept ONLY for the
  unrelated "Add Record from a client profile" path (`Clients/Show`).

## Changes

### 1. Modal: explicit two-mode toggle — `AppointmentModal.vue`

Replace the implicit picker + conditional `customer_name` box with the two-card toggle
mirroring `ServiceRecords/Partials/ClientPicker.vue`:

- **Existing client** card → search box → chosen card (name + `#serial_no`); selecting a
  client prefills `phone` / `address`. Sets `form.client_id`.
- **Walk-in** card → manual `customer_name`, `phone`, `address` fields.
- New local `client_mode` state (`'existing' | 'walk_in'`), display-only.
- **Submit contract unchanged.** Existing mode → send `client_id` (server already nulls
  `customer_name` when `client_id` is set in `StoreAppointmentRequest::appointmentData()`).
  Walk-in mode → send `customer_name`, null `client_id`.
- **Edit hydrate:** `client_mode = appointment.client_id ? 'existing' : 'walk_in'`.
- **Label "Walk-in"** (not "New client") — no client is created at appointment time.

No backend/route change for the modal. No appointment migration.

### 2. Add Record on every row — `Appointments/Index.vue`

- Remove the `v-if="row.client"` gate on the Add Record link (desktop + mobile card).
- Link target: `route('service-records.create', { appointment: row.id, technician_id: row.technician_id })`.
  Drop the `client` param from this link — the controller derives the client from the
  appointment.
- Serial column / "Non client" display unchanged (it already falls back correctly).

### 3. Controller `create()` derives prefill — `ServiceVisitController.php`

Resolve the tenant-scoped appointment once (it already loads `presetAppointmentId`):

- If the appointment has `client_id` → build `presetClient` from that client (existing
  path; also load `presetClientUnits`).
- If the appointment is a walk-in → new prop
  `presetNewClient = { name: customer_name, phone, address }`.
- Keep the standalone `client` request param as a fallback source for `presetClient` (the
  client-profile Add Record path).
- `presetClient` and `presetNewClient` are mutually exclusive; `presetClient` wins if both
  somehow resolve.

### 4. Prefill the new-client form — `Create.vue` + `ClientPicker.vue`

- `Create.vue`: new prop `presetNewClient: { type: Object, default: null }`. When present,
  initialise `form.client_mode = 'new'` and `form.new_client = { name, phone, address }`.
- `ClientPicker.vue`: when the form arrives in `new` mode with populated `new_client`,
  render the New client card pre-filled (the component already renders `new_client` fields
  in `new` mode; ensure the preset `client_mode` is respected on mount).

### 5. Back-link — `store()` (inside the existing DB transaction)

After creating the client in `new` mode, if `appointment_id` is present, set that
appointment's `client_id` to the new client. The appointment is already tenant-validated
by `StoreServiceVisitRequest` (`appointment_id` exists-rule scoped to tenant), so an
`Appointment::whereKey(...)->update(['client_id' => $client->id])` inside the transaction
is safe. No-op for existing-client records.

### 6. Tests — `tests/Feature/ServiceVisitTest.php` (or AppointmentTest)

- `create()` from a walk-in appointment passes `presetNewClient` with the appointment's
  name/phone/address and a null `presetClient`.
- `create()` from a client-backed appointment passes `presetClient` (unchanged).
- `store()` with `client_mode=new` + `appointment_id` creates the client AND sets that
  appointment's `client_id` to the new client.
- `store()` with `client_mode=existing` does not touch any appointment's `client_id`
  beyond the linked visit.

## Out of scope / non-goals

- No appointment migration; `customer_name` already exists.
- No change to the appointment status / payment-completion flow.
- No change to the client-profile Add Record path beyond it continuing to pass `client=`.
- Units selector stays parked (`docs/UNITS-TODO.md`).

## Deploy

Frontend + controller + test. No migration. `npm run build` on deploy.
