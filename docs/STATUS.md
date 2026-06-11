# Saifzz Aircond — Project Status Board

> Quick human reference for what's done / pending / on-hold / deferred / broken.
> Mirror of the assistant's working memory. Update at the end of every work session.
>
> **Last updated:** 2026-06-11 (session 8)

---

## At a glance

| | |
|---|---|
| **Phase** | Feature modules underway — Clients (2), Fees (3), Service Records (4), Payments (5) done |
| **Stack** | Laravel 13 · Inertia + Vue 3 · Tailwind · PostgreSQL · Redis · Sail (Docker) |
| **Auth/RBAC** | Laravel Breeze + policies (not installed yet) |
| **PDF / Pay** | Cash + BayarCash (DuitNow QR) stub behind swappable interface **done**; receipt record on paid · invoice/receipt PDF (dompdf) not built |
| **Run** | `docker compose up -d` → http://localhost:8000 |
| **Repo** | https://github.com/hamidkarim8/Saifzz-Aircond |

Legend: ✅ done · 🔄 in progress · ⏳ pending/next · ⏸ on hold · 📋 deferred · 🐞 bug · 🎨 cosmetic

---

## ✅ Completed
- Product docs written (`docs/01`–`06`) + clickable mockup (`index.html`, Service System v4).
- Product & architecture decisions locked (2026-06-10).
- Tech stack chosen: Laravel + Inertia/Vue + Postgres (Docker-first).
- Git: repo init, remote wired, initial docs commit + scaffold commit pushed.
- Laravel 13 scaffolded with Sail (pgsql + redis). App serves **HTTP 200**, migrations run on Postgres.
- Laravel Breeze installed (Inertia + Vue 3 + Tailwind). Auth working: `/login` `/register` → 200, `/dashboard` guarded. Assets build clean.
- Domain layer built (`docs/02-domain-model.md`): 8 migrations (clients, service_fees, service_visits, service_lines, transactions, invoices, receipts, appointments) + Eloquent models with relations/casts. Business rules in models: R6 serial auto-gen, R5 warranty_end, R8 subtotal/total. ServiceFee price book seeded (10 rows). Migrated + verified on Postgres.
- RBAC built (`docs/03-rbac-permissions.md`): `role`/`permissions`/`active` on users; 9-permission catalogue + helpers on `User`; one Gate per permission with admin-implies-all (`Gate::before`); P1 (manage_users admin-only), P4 (inactive users blocked at login). Seeded admin = Khalid (`admin@saifzz.test`). Tinker-verified.
- Design system wired (`docs/05`): Tailwind tokens (navy/blue scale, semantic, service-type colours), Plus Jakarta Sans + JetBrains Mono fonts, radii/shadows. New `AdminLayout.vue` — navy sidebar, responsive drawer, top bar, flash toast, permission-gated nav. Inertia shares `auth.can` map + `flash`.
- **Module 2 — Clients** done: `ClientController` (index/create/store/show/edit/update/destroy), Store/Update requests (MY phone validation), routes gated `can:view_clients`/`can:edit_client` (P3). Vue pages Index (search + service-type filter + table→card reflow + pagination), Create/Edit (shared form), Show (profile + service history w/ warranty & payment status + appointments). 8 feature tests pass (33 assertions) on Postgres.
- **Module 3 — Service Fees** done: `ServiceFeeController` (index/store/update/destroy), Store/Update requests (rate required unless flexible, duplicate type+option blocked), all routes gated `can:edit_fees`. Vue Index (price book grouped by service type, add/edit modal, mode badges) + nav item. Edits affect future lines only (R1 snapshot). 10 feature tests pass (18 assertions). Dashboard now uses `AdminLayout`.
- **Module 4 — Service Records** done (operational heart): `ServiceVisitController` (index/create/store/show) + `StoreServiceVisitRequest` (per-line conditional rules R2/R3, fee-existence check); `ClientController@lookup` JSON search (gated `record_service`). Server snapshots rate from fee book — client-sent rate ignored (R1); strips next-service for Gas/Repair (R2), unit_type/notes for Repair (R3); creates visit+lines+Transaction (pending, `TXN-YYYYMMDD-NNN`) in one DB transaction (R4); warranty_end derived (R5); totals (R8). Vue: builder (ClientPicker existing-search/new, adaptive ServiceLineCard with live rate auto-fill + subtotal, sticky grand-total bar, warranty + payment method), Index (recent records), Show (summary). 9 feature tests pass (34 assertions). Nav item added (gated `record_service`).
- **Module 5 — Payments** done: `PaymentGateway` interface with two drivers (`FakeBayarCashGateway` active stub, `BayarCashGateway` scaffolded live) selected by `config('services.bayarcash.driver')` via `PaymentServiceProvider` — going live = fill creds + flip `BAYARCASH_DRIVER=live`. **Cash** path (`PaymentController@cash`, gated `collect_payment`) marks paid + issues a Receipt record (`RCP-YYYYMMDD-NNN`, one-per-txn, frozen snapshot). **BayarCash redirect flow**: `startGateway` → stub hosted page (`dev/bayarcash`, fake-driver-only) → checksum-signed callback → public CSRF-exempt webhook (`webhooks/bayarcash`) → `HandleGatewayCallback` (idempotent, amount-guarded, locks row) marks paid + Receipt. `Checksum` HMAC-SHA256 + shared `CallbackParser` (go-live constants centralized, `TODO(go-live)`). Vue: `Payments/Show` (method chooser), `Payments/Return` (result + receipt no.); gated "Collect payment" CTA on the service-record page. **Full suite: 72 tests / 202 assertions green** (new: Checksum, FakeBayarCashGateway, Payment, PaymentWebhook, StubGateway). Receipt **PDF** deferred to Documents (module 6) — the record already exists.

## 🔄 In Progress
- _(none)_

## ⏳ Pending / Next (ordered)
1. Remaining feature modules (`docs/04`): **Documents (6)** ← next (invoice/receipt PDF — Receipt record already created by Payments), Appointments (7), Reminders (8), Dashboard/Reports (9), Portal (10), Notifications (11), Users mgmt screen (1).
2. PDF (dompdf) invoice + receipt (Documents module).
3. BayarCash go-live: confirm v3 callback field names / status codes / checksum ordering (`TODO(go-live)` markers), fill creds, flip `BAYARCASH_DRIVER=live`; consider moving webhook handling to a queue under load.
4. Public client portal (unauthenticated, serial-gated — P5).

## ⏸ On Hold
- _(none)_

## 📋 Deferred (decided, not now)
- Deployment doc (Dockerfile/compose for prod, GCP free VM → Linux VPS) — write AFTER core app stable.
- WhatsApp Meta Cloud API — using `wa.me` click-to-chat for v1; full API later.
- Redis — container runs (Sail `--with=redis`) but **unused**: cache/queue/session all on `database` driver, fine for v1 single-VM scale. Switch queue (then cache) to Redis when the DuitNow webhook queue lands or load demands. `phpredis` already present; flip `CACHE_STORE`/`QUEUE_CONNECTION`/`SESSION_DRIVER=redis` in `.env`. Verify: `docker compose exec redis redis-cli DBSIZE`.

## 🐞 Bugs
- _(none)_

## 🎨 Cosmetic / Polish
- Mockup `index.html` visual is improvable (flows authoritative, look can be upgraded during UI build).

## 🚫 Out of scope (v1)
Inventory/parts · technician routing/GPS · multi-branch · accounting/SST · email · multi-language.

---
_See `docs/SESSION-LOG.md` for the chronological history of how we got here._
