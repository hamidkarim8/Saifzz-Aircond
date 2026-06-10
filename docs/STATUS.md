# Saifzz Aircond — Project Status Board

> Quick human reference for what's done / pending / on-hold / deferred / broken.
> Mirror of the assistant's working memory. Update at the end of every work session.
>
> **Last updated:** 2026-06-11 (session 3)

---

## At a glance

| | |
|---|---|
| **Phase** | Domain layer built (migrations + models + seed) — RBAC + features next |
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

## 🔄 In Progress
- _(none)_

## ⏳ Pending / Next (ordered)
1. RBAC — owner superuser + per-technician granular permissions (`docs/03-rbac-permissions.md`). Adds `role`/`permissions`/`active` to users + policies.
2. Feature modules (`docs/04-feature-modules.md`), 11 modules.
3. PDF (dompdf) invoice + receipt.
4. DuitNow QR payment + webhook auto-verify (queue).
5. Public client portal.

## ⏸ On Hold
- _(none)_

## 📋 Deferred (decided, not now)
- Deployment doc (Dockerfile/compose for prod, GCP free VM → Linux VPS) — write AFTER core app stable.
- WhatsApp Meta Cloud API — using `wa.me` click-to-chat for v1; full API later.

## 🐞 Bugs
- _(none)_

## 🎨 Cosmetic / Polish
- Mockup `index.html` visual is improvable (flows authoritative, look can be upgraded during UI build).

## 🚫 Out of scope (v1)
Inventory/parts · technician routing/GPS · multi-branch · accounting/SST · email · multi-language.

---
_See `docs/SESSION-LOG.md` for the chronological history of how we got here._
