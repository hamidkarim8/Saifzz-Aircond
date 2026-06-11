# Module 8 — Reminders — Design

**Date:** 2026-06-11
**Status:** Approved (brainstorming) — ready for implementation plan
**Depends on:** Modules 2 (Clients), 4 (Service Records — supplies `service_lines.next_service_date`),
7 (Appointments — supplies the preset-client modal flow reused by "set appointment").

---

## Goal

Surface clients who are **due** or **overdue** for service and drive follow-up. The list is
**derived** from service-line next-service dates (not a stored queue). Each client card offers
one-tap **WhatsApp**, **set appointment**, and a persisted **contacted** toggle.

This module is a read-side consumer. It does **not** change how service records, payments, or
appointments work — it only reads their data and stores a light "contacted" overlay.

User story (`docs/04` §8): *As staff, I see 5 clients to follow up, WhatsApp Kavitha, and mark
her contacted.*

---

## Decisions (locked in brainstorming)

1. **Contacted state — dedicated `reminder_contacts` table.** The due/overdue list stays
   derived; "contacted" is a separate overlay fact. One row per client = contacted. Toggle on
   = `updateOrCreate` (sets `contacted_at = now()`, `contacted_by = auth id`); toggle off =
   delete the row. Keeps the `clients` table clean and records who/when (light audit). Chosen
   over a `clients.last_contacted_at` column (no audit, conflates general contact) and over
   ephemeral page-only state (lost on reload — violates spec intent).
2. **Access gating — `view_clients`.** Reminders is a read-side list of clients needing
   follow-up. Default technicians hold `view_clients`; matches how Documents reused it. The
   contacted toggle is a light follow-up write under the same gate. Admin implies all.
3. **Due-date basis — `MAX(next_service_date)` across all of a client's service lines.** Latest
   recommendation wins and self-clears when a newer visit sets a later date. Null next-service
   dates (Repair/Gas strip them per R2) don't contribute, so a client still surfaces from an
   earlier cleaning's recommendation even if their most recent visit was a Repair. Chosen over
   "latest visit's date only" (would hide a client whose newest visit stripped the date).
4. **Notifications — v1 inline `wa.me`, no abstraction yet.** WhatsApp is a click-to-chat
   `wa.me` link with prefilled reminder text, built frontend — same pattern as `Clients/Show`.
   Module 11 (Notifications) later extracts this behind an interface with no caller change.
   No automated/templated sending in v1.
5. **Granularity — per client, not per line.** One card per client. Contacted has no automatic
   per-cycle reset: a client drops off the list when a newer visit pushes `next_due` into the
   future, or when staff hit Undo.

---

## Data model

New migration + `ReminderContact` model:

```
reminder_contacts
  id
  client_id     FK clients  (cascade on delete), UNIQUE   ← presence of row = contacted
  contacted_at  timestamp
  contacted_by  FK users    (null on delete)
  timestamps
```

- `Client hasOne ReminderContact` (relation `reminderContact`).
- `ReminderContact belongsTo Client`, `belongsTo User as contactedBy`.
- Unique `client_id` enforces one-per-client; toggle uses `updateOrCreate` / `delete`.

No other schema changes. Soft-deleting a client cascades the contact row away.

---

## Derivation — `App\Services\Reminders\ReminderService`

Read-only service (mirrors the `App\Services\Documents\*` precedent), unit-testable in
isolation. Method `dueList(): array` returns the partitioned, view-ready payload.

Query shape:

- Aggregate `service_lines` → per `client_id`, `next_due = MAX(next_service_date)`
  (joins through `service_visits` to reach `client_id`; null next-service dates excluded by
  `MAX`).
- Join `clients` (exclude soft-deleted), left-join `reminder_contacts` (→ `contacted` bool),
  and the client's latest visit summary (`last_service_date` = most recent `visit_date`).
- Partition by `next_due` relative to today (server timezone):
  - `overdue`        — `next_due < today`
  - `due_this_month` — `today <= next_due <= endOfMonth(today)`
  - future / no date — **excluded**
- Sort each bucket by `next_due` ascending (most overdue first).

Item shape:

```
{ client_id, serial_no, name, phone, address,
  next_due (Y-m-d), last_service_date (Y-m-d|null), contacted (bool) }
```

Return:

```
{
  overdue:        Item[],
  due_this_month: Item[],
  stats: { overdue: n, due_this_month: n, contacted: n }   // contacted = count flagged within the two buckets
}
```

---

## HTTP

`ReminderController`:

- `index()` → `Inertia::render('Reminders/Index', $reminderService->dueList())`.
- `toggleContacted(Client $client)` → if a row exists, delete it; else `updateOrCreate`
  (`contacted_at = now()`, `contacted_by = auth()->id()`). Idempotent per resulting state.
  Redirect back with a flash (`"Marked contacted."` / `"Reminder reopened."`). No request body
  → no FormRequest needed; `Client` resolved by route-model binding.

Routes (inside the `auth` group, gated `can:view_clients`):

```php
Route::middleware('can:view_clients')->group(function () {
    Route::get('reminders', [ReminderController::class, 'index'])->name('reminders.index');
    Route::patch('reminders/{client}/contacted', [ReminderController::class, 'toggleContacted'])
        ->name('reminders.contacted');
});
```

---

## UI — `resources/js/Pages/Reminders/Index.vue`

- **Stat cards:** Overdue · Due this month · Contacted.
- **Two sections** — Overdue (danger accent) and Due this month (warn accent) — each a list of
  client cards. Empty state when both buckets are empty.
- **Client card:** serial + name, phone + address, last service date, next-due date with an
  overdue badge when applicable, contacted badge when flagged. Actions:
  - **WhatsApp** — `wa.me/<intl phone>?text=<prefilled reminder>` (phone normalized like
    `Clients/Show`: strip non-digits, leading `0` → `60`). Opens in a new tab.
  - **Set appointment** — `Link` to `appointments.index?client=<id>` (module-7 preset modal
    auto-opens). Requires `set_appointment`; shown when `can.set_appointment`.
  - **Mark contacted / Undo** — `PATCH reminders.contacted` (Inertia, preserves scroll).
- **Nav item** — bell icon in `AdminLayout`, gated `view_clients`.

Prefilled WhatsApp text (frontend constant), e.g.:
`Hi {name}, this is Saifzz Aircond. Your aircond service (#{serial}) is due on {date}. Reply to schedule a visit.`

---

## Tests

**`tests/Unit/ReminderServiceTest.php`** (or `Feature` if it needs the DB — it does; Postgres):

- overdue vs due-this-month partition by `next_due`.
- `MAX(next_service_date)` wins over an older line for the same client.
- client whose only lines are Repair/Gas (null next-service) is **excluded**.
- client with a future-month `next_due` is **excluded**.
- soft-deleted client is **excluded**.
- `contacted` flag surfaces true when a `reminder_contacts` row exists.
- stats counts correct.

**`tests/Feature/ReminderTest.php`:**

- guest → redirect to login; user without `view_clients` → 403.
- index renders `Reminders/Index` with `overdue` / `due_this_month` / `stats` props.
- `toggleContacted` creates a row (first call) and deletes it (second call) — idempotent per
  state; persists `contacted_by`.

Target: full suite stays green (currently 92 passed / 275 assertions) plus the new cases.

---

## Out of scope (v1)

- Automated/templated WhatsApp sending (module 11).
- Per-line or per-unit reminders; reminder scheduling/queues.
- Auto-reset of the contacted flag on a new due cycle.
- A reminders "history" or audit screen beyond the single `contacted_by`/`contacted_at` fields.
