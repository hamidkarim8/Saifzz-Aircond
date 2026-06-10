# Saifzz Aircond — Project Status Board

> Quick human reference for what's done / pending / on-hold / deferred / broken.
> Mirror of the assistant's working memory. Update at the end of every work session.
>
> **Last updated:** 2026-06-11 (session 3)

---

## At a glance

| | |
|---|---|
| **Phase** | Feature modules underway — Clients (module 2) done; design system wired |
| **Stack** | Laravel 13 · Inertia + Vue 3 · Tailwind · PostgreSQL · Redis · Sail (Docker) |
| **Auth/RBAC** | Laravel Breeze + policies (not installed yet) |
| **PDF / Pay** | dompdf · DuitNow QR webhook + Cash (not built) |
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

## 🔄 In Progress
- _(none)_

## ⏳ Pending / Next (ordered)
1. Remaining feature modules (`docs/04`): Service Fees (3), Service Records (4), Payments (5), Documents (6), Appointments (7), Reminders (8), Dashboard/Reports (9), Portal (10), Notifications (11), Users mgmt screen (1).
2. PDF (dompdf) invoice + receipt.
3. DuitNow QR payment + webhook auto-verify (queue).
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
