# 04 — Feature Modules

The system decomposes into 11 bounded modules. Each has one clear responsibility, a
well-defined interface, and can be understood and tested independently.

**Dependency direction:** Fees → Service Records → Payments → Documents. Clients underpin
most modules. Dashboard, Reminders, and Portal are read-side consumers. Notifications is a
leaf service.

---

## 1. Auth & Users
**Responsibility:** Authentication, sessions, RBAC enforcement, staff management.
- Login / logout (email + password), session handling.
- Admin: create/disable users, assign role, toggle granular permissions.
- Enforce permissions server-side on every protected action.

**Stories**
- As Khalid, I create a technician account and grant only the permissions they need.
- As a technician, I log in and see only the sections I'm allowed to use.

---

## 2. Clients
**Responsibility:** Client registry keyed by serial.
- Create client with **auto-generated 6-digit serial**.
- Search by name / serial / phone; filter by service type.
- View full client profile + service history; edit; soft-delete.

**Stories**
- As staff, I add a new client and the system assigns the next serial automatically.
- As staff, I search "000148" and open Zainab's full history.

---

## 3. Service Fees
**Responsibility:** The price book that drives rate auto-fill.
- CRUD fee entries per service type × option.
- Support fixed-per-unit, tiered (Gas PSI/session), and flexible (Repair) modes.
- Editing a fee affects only **future** service lines (rates are snapshotted).

**Stories**
- As Khalid, I raise the Cleaning (Cassette) rate; past receipts are unchanged.

---

## 4. Service Records
**Responsibility:** The "Add Service Record" flow — the operational heart.
- Choose new vs existing client (existing = search by serial).
- Multi-service builder: add/remove service lines; each line adapts its fields to the
  service type (unit type, PSI option, repair description, units, next-service, notes,
  discount).
- Live subtotal per line and a running grand total (units + amount).
- Per-visit warranty (0–6 months) with computed end date.
- On submit → create visit + lines, snapshot rates, create Transaction, proceed to payment.

**Stories**
- As a technician onsite, I record a 2-unit Cleaning + Gas Top-Up in one visit, set a
  3-month warranty and a next-service date, and the total computes live.

---

## 5. Payments
**Responsibility:** Collecting and verifying payment.
- DuitNow QR via payment gateway: generate/display QR, await webhook, auto-mark paid.
- Cash: manual confirm by staff with `collect_payment`.
- Update Transaction status; trigger Receipt on success.
- Handle pending/failed states gracefully (retry, leave as invoice).

See [06-integrations.md](06-integrations.md) for the gateway contract.

**Stories**
- As staff, I show the DuitNow QR; once the client pays, the status flips to Paid
  automatically and a receipt is generated.

---

## 6. Documents (Invoice & Receipt)
**Responsibility:** Official document generation.
- Generate INV / RCP numbers.
- Render on-screen view (matching the mockup layout) **and** a downloadable PDF.
- Snapshot client + line + payment details so reprints are stable.

**Stories**
- As staff, I download a receipt PDF and share it with the client.
- As staff, I open a pending invoice PDF for an unpaid installation.

---

## 7. Appointments
**Responsibility:** Scheduling.
- Calendar view (month) + list view; highlight dates with appointments.
- Click a date → see that day's appointments.
- Create/edit appointment (client, datetime, service, address, phone, notes).
- Status lifecycle: pending → confirmed → done / cancelled.
- Summary stats (this month, today).

**Stories**
- As staff, I click 16 June and see Kavitha's pending installation.
- As staff, I set an appointment from a reminder in two taps.

---

## 8. Reminders
**Responsibility:** Surface clients due/overdue and drive follow-up.
- Derived list (not stored) from next-service dates: overdue vs due-this-month.
- Per-client card: contact info, last service, one-tap **WhatsApp**, **set appointment**.
- Contacted / not-contacted toggle.

**Stories**
- As staff, I see 5 clients to follow up, WhatsApp Kavitha, and mark her contacted.

---

## 9. Dashboard & Reports
**Responsibility:** Aggregated read-only insight.
- KPI cards: total clients, revenue (this month), all-time revenue, pending reminders.
- Appointments calendar (mini) + services-by-type chart with time filters
  (all / month / week / today).
- Recent transactions table.
- CSV export of transactions (gated by `export_data`).

**Stories**
- As Khalid, I check June revenue and the services-by-type mix at a glance.

---

## 10. Client Portal
**Responsibility:** Public, serial-gated self-service.
- Enter 6-digit serial (from remote sticker / QR) — no password.
- Show client header, next recommended service date, and full service history with
  per-record warranty status (active / expiring / expired / none).
- Download receipts; contact via WhatsApp; "set appointment" via WhatsApp.
- Mobile-first.

**Stories**
- As a client, I scan my sticker, enter 000148, and see my 3 past services and warranty.

---

## 11. Notifications (thin abstraction)
**Responsibility:** One interface over outbound messaging.
- A `notify()` / messaging interface used by Reminders and Appointments.
- **v1 implementation:** `wa.me` click-to-chat links with pre-filled text (free, manual send).
- **Future implementation:** Meta WhatsApp Cloud API for automated templated reminders —
  dropped in behind the same interface, no caller changes.

**Stories**
- As staff, I tap WhatsApp and a chat opens pre-filled with the client's reminder message.
