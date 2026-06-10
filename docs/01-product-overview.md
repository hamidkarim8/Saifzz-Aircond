# 01 — Product Overview

## Vision

A single web application that runs the day-to-day operations of **Saifzz Aircond**, a
Malaysian air-conditioning servicing business. It tracks every client and the services
performed for them, collects payment, issues official invoices and receipts, schedules
appointments, surfaces who is due for their next service, and gives clients a simple
self-service portal to check their own history and warranty.

The system targets a small but growing operation: one business, a handful of staff, and a
client base expected to scale into the thousands.

## Actors

### 1. Admin — Khalid (owner / project manager)
Full, unrestricted access. The only user who can create staff accounts and grant or revoke
technician permissions. Works primarily from desktop but the app is fully responsive.

### 2. Technician
Field staff who perform services at client premises. They work **onsite on phones and
iPads**. Their capabilities are a configurable subset of the system, granted by Khalid.
By default a new technician gets the **minimum** permission set (see
[03-rbac-permissions.md](03-rbac-permissions.md)).

### 3. Client (public, no account)
The end customer. Has no login. Receives a sticker bearing a **6-digit serial number** on
their AC remote control. Scans a QR / enters the serial on the public portal to view their
own service history, warranty status, and recommended next-service date, and to reach the
business via WhatsApp.

## Surfaces

Two front-end surfaces backed by one shared backend and database:

| Surface | Audience | Auth | Form factor |
|---------|----------|------|-------------|
| **Admin app** | Khalid + technicians | Email + password, RBAC | Responsive desktop / iPad / mobile |
| **Client portal** | Public | Serial number only (no password) | Mobile-first |

## What the system does (capability summary)

- **Client registry** keyed by auto-generated 6-digit serial — name, phone, address, history.
- **Service records** — a visit captures one or more service lines (type, unit type, units,
  rate, discount), a per-visit warranty, and a recommended next-service date.
- **Pricing** — a maintained price book auto-fills rates: fixed per-unit (Cleaning,
  Installation, Troubleshoot), tiered (Gas Top-Up by PSI/session), and flexible (Repair,
  entered per job).
- **Payments** — DuitNow QR via a real payment gateway (auto-verified by webhook) or Cash.
- **Documents** — official **Invoice** (pending) and **Receipt** (paid), viewable on screen
  and downloadable as PDF.
- **Appointments** — calendar and list views with confirmed / pending / done / cancelled.
- **Reminders** — automatically derived list of clients overdue or due for service, with a
  contacted/not-contacted workflow and one-tap WhatsApp.
- **Dashboard & reports** — client counts, monthly and all-time revenue, services-by-type
  breakdown, recent transactions, CSV export.
- **Client portal** — serial-gated read-only view of the client's own records.

## Service & unit taxonomy

- **Service types:** Cleaning, Gas Top-Up, Repair, Installation, Troubleshoot.
- **Unit types:** Wall Mounted, Cassette.
- **Gas Top-Up options:** 20 PSI, Half Top-Up, Full Top-Up.

Behavioural rules tied to service type (carried from the mockup, see domain model):
- **Gas Top-Up** and **Repair** have **no** next-service date.
- **Repair** uses flexible pricing, has no unit type, and hides the technician-notes field.
- Warranty applies to the **whole visit**, not individual lines.

## Scope boundaries

**In scope (v1):** everything in the capability summary above, for a single business.

**Out of scope (deferred — confirmed):**
- Inventory / spare-parts stock control
- Technician scheduling optimisation, routing, GPS tracking
- Multiple branches / multi-tenant SaaS
- Accounting / tax (SST) integration
- Email delivery
- Multi-language UI (English only for v1)
