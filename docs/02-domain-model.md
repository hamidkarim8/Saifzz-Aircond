# 02 — Domain Model

The data model for Saifzz Aircond. Entities, relationships, derived values, and the
business rules that the implementation must enforce.

## Entity-relationship overview

```
Client (1) ───< (many) ServiceVisit (1) ───< (many) ServiceLine
   │                        │
   │                        └── (1) Transaction ──(1)── Receipt  (when paid)
   │                                          └────(1)── Invoice  (when pending)
   │
   └───< (many) Appointment

ServiceFee   — standalone price book, referenced (snapshotted) by ServiceLine
User         — admin-app accounts (admin | technician) with granular permissions
Reminder     — DERIVED VIEW over Clients + next_service_date (not stored)
```

## Entities

### Client
The customer account. **The serial number is the primary identity** — one serial per client.

| Field | Notes |
|-------|-------|
| `serial_no` | 6-digit, unique, **auto-generated** (e.g. `000148`). Printed on remote sticker. Portal key. |
| `name` | Full name |
| `phone` | Malaysian mobile format (`01X-XXXXXXX`) |
| `address` | Full service address |
| `created_at` | |
| `deleted_at` | Soft-delete (preserve history & financial records) |

A client has many service visits and many appointments.

### ServiceVisit
One "Add Service Record" submission — a single visit that may bundle several services.

| Field | Notes |
|-------|-------|
| `client_serial` | FK → Client |
| `visit_date` | |
| `warranty_months` | 0–6. Applies to the **entire visit**. |
| `warranty_end` | **Derived** = `visit_date + warranty_months`. Null if 0. |
| `total_amount` | **Derived** = sum of line subtotals |
| `created_by` | FK → User (who recorded it) |
| `created_at` | |

Has one or more ServiceLines (minimum 1). Has exactly one Transaction.

### ServiceLine
One service type within a visit.

| Field | Notes |
|-------|-------|
| `visit_id` | FK → ServiceVisit |
| `service_type` | Cleaning \| Gas Top-Up \| Repair \| Installation \| Troubleshoot |
| `unit_type` | Wall Mounted \| Cassette — **null** for Gas Top-Up & Repair |
| `gas_option` | 20 PSI \| Half Top-Up \| Full Top-Up — **Gas only** |
| `units` | Quantity ≥ 1 |
| `rate` | **Snapshot** of the fee at service time (see rule R1) |
| `repair_desc` | Free text — **Repair only** |
| `discount` | RM amount, default 0 |
| `next_service_date` | Cleaning / Installation / Troubleshoot **only** (rule R2) |
| `notes` | Technician notes — hidden/absent for Repair (rule R3) |
| `subtotal` | **Derived** = `max(0, rate * units - discount)` |

### ServiceFee (price book)
Maintained by users with `edit_fees`. Drives auto-fill of `ServiceLine.rate`.

| Field | Notes |
|-------|-------|
| `service_type` | |
| `option` | unit_type (Cleaning/Install/Troubleshoot) **or** gas_option (Gas) |
| `rate` | RM |
| `pricing_mode` | `fixed_per_unit` \| `tiered` \| `flexible` |

Reference rates from the mockup (seed data):

| Service | Option | Rate | Mode |
|---------|--------|------|------|
| Cleaning | Wall Mounted | RM 60 / unit | fixed_per_unit |
| Cleaning | Cassette | RM 90 / unit | fixed_per_unit |
| Gas Top-Up | 20 PSI | RM 80 | tiered |
| Gas Top-Up | Half Top-Up | RM 150 | tiered |
| Gas Top-Up | Full Top-Up | RM 280 | tiered |
| Repair | — | Flexible | flexible |
| Installation | Wall Mounted | RM 120 / unit | fixed_per_unit |
| Installation | Cassette | RM 180 / unit | fixed_per_unit |
| Troubleshoot | Wall Mounted | RM 80 / unit | fixed_per_unit |
| Troubleshoot | Cassette | RM 110 / unit | fixed_per_unit |

### Transaction
Financial record for a visit. **Created for every visit** (rule R4).

| Field | Notes |
|-------|-------|
| `txn_id` | `TXN-YYYYMMDD-NNN` |
| `visit_id` | FK → ServiceVisit (1:1) |
| `amount` | = visit total |
| `method` | DuitNow QR \| Cash |
| `status` | `pending` \| `paid` \| `failed` |
| `gateway_ref` | Gateway transaction id / webhook reference (DuitNow QR) |
| `paid_at` | Set when status → paid |

### Invoice / Receipt
Generated documents. Each has a human-readable number and a PDF rendering.

| Doc | Number format | Created when | Title |
|-----|---------------|--------------|-------|
| Invoice | `INV-YYYYMMDD-NNN` | Transaction pending / unpaid | INVOICE (amount due) |
| Receipt | `RCP-YYYYMMDD-NNN` | Transaction paid | OFFICIAL RECEIPT (total paid) |

Documents snapshot client + line details so reprints stay accurate even if records change.

### Appointment
A planned future job. **Separate from ServiceVisit**, loosely linked to a client.

| Field | Notes |
|-------|-------|
| `client_serial` | FK → Client |
| `datetime` | Date + time |
| `service_type` | Intended service |
| `units` | Estimated |
| `address` / `phone` | Snapshot at booking (editable) |
| `amount` | Estimate |
| `status` | `pending` \| `confirmed` \| `done` \| `cancelled` |
| `contacted_flag` | Used by the reminder workflow |
| `notes` | Optional |

Completing an appointment may **pre-fill** a new ServiceVisit but does not become one.

### User (admin-app account)
See [03-rbac-permissions.md](03-rbac-permissions.md) for the permission model.

| Field | Notes |
|-------|-------|
| `name`, `email` | Email is the login |
| `password_hash` | |
| `role` | `admin` \| `technician` |
| `permissions` | Granular list (technicians); admins implicitly have all |
| `active` | Disable without deleting |

### Reminder — derived, not stored
A computed view, not a table. A client appears when their latest relevant
`next_service_date` falls at or before `now + window`, classified:
- **Overdue** — next_service_date < today
- **Due** — within the current window (e.g. this month)

Each reminder row carries the contacted/not-contacted state and offers WhatsApp +
"set appointment" actions.

## Business rules (must be enforced)

- **R1 — Rate snapshotting.** When a ServiceLine is created, copy the current ServiceFee
  rate onto the line. Later fee edits never rewrite historical lines, totals, or documents.
- **R2 — No next-service for Gas Top-Up & Repair.** Only Cleaning, Installation, and
  Troubleshoot carry a `next_service_date`.
- **R3 — Repair specifics.** Flexible price (entered per job), no `unit_type`, no `notes`
  field; only a description and discount.
- **R4 — Every visit creates a Transaction.** If paid immediately → `paid` + Receipt.
  Otherwise → `pending` + Invoice.
- **R5 — Warranty is per visit.** Single `warranty_months` (0–6) per ServiceVisit;
  `warranty_end` derived; portal shows active / expiring / expired / none.
- **R6 — Serial auto-generation.** 6-digit, zero-padded, unique, monotonic.
- **R7 — Soft-delete clients.** Never hard-delete a client with financial history.
- **R8 — Money math.** All amounts in RM with 2 decimals; subtotal floored at 0 after
  discount; visit total = sum of line subtotals.

## Status vocabularies

- Transaction: `pending`, `paid`, `failed`
- Appointment: `pending`, `confirmed`, `done`, `cancelled`
- Warranty (derived display): `active`, `expiring` (near end), `expired`, `none`
