# Saifzz Aircond — Session Log

> Chronological journal of work sessions (newest first). One entry per working session.
> Captures: what was done, decisions made, problems hit, and the next step.
> Companion to `docs/STATUS.md` (the live board).

---

## Session 4 — 2026-06-11 — RBAC (roles, permissions, gates)

**Goal:** Implement role + granular permission access control from `docs/03-rbac-permissions.md`.

**Done**
- Migration adds `role` (default technician), `permissions` (json), `active` (default true) to `users`.
- `User` model: 9-permission catalogue, `ROLE_*` consts, `DEFAULT_TECHNICIAN_PERMISSIONS`; `isAdmin()`, `hasPermission()`, `grantPermission()`, `revokePermission()`. New technicians default to the minimum 3 perms via creating event.
- `AppServiceProvider` registers one Gate per permission + `Gate::before` so admins implicitly pass every gate (P3).
- `LoginRequest` rejects inactive users after a valid attempt (P4).
- Seeded user is now admin Khalid (`admin@saifzz.test`).

**Rules enforced**
- P1 — `manage_users` admin-only; `grantPermission`/`hasPermission` refuse it for technicians.
- P3 — gates are the server-side enforcement points (UI hiding comes later).
- P4 — inactive users cannot log in even with valid credentials.

**Verified (tinker)** — technician default perms = view_clients/record_service/set_appointment; admin passes manage_users; tech collect_payment/manage_users denied; grant manage_users is a no-op (P1); grant view_reports works; gates resolve correctly for admin vs technician.

**Next**
- Feature modules from `docs/04-feature-modules.md`: controllers + Inertia pages, applying `can:` gates per action.

---

## Session 3 — 2026-06-11 — Domain layer (migrations + models + seed)

**Goal:** Build the domain layer from `docs/02-domain-model.md` — schema, Eloquent models, ServiceFee seed.

**Done**
- 8 migrations: clients, service_fees, service_visits, service_lines, transactions, invoices, receipts, appointments.
- 8 models with relations, casts, and business-rule events:
  - R6 — `Client` auto-generates 6-digit zero-padded monotonic `serial_no` (`max(serial_no)+1`, withTrashed).
  - R5 — `ServiceVisit` derives `warranty_end` = `visit_date + warranty_months` (null when 0).
  - R8 — `ServiceLine` derives `subtotal = max(0, rate*units - discount)`; `ServiceVisit::recalculateTotal()` sums lines.
  - R1 — `rate` stored as a snapshot column on `service_lines`.
- `ServiceFeeSeeder` seeds the 10-row price book (Repair = null rate, flexible); wired into `DatabaseSeeder`.
- `migrate --seed` clean on Postgres; tinker-verified serial gen, warranty_end, subtotal, total, Repair null rate. Test rows removed.

**Decisions**
- **Client key:** `id` PK + unique `serial_no` (FKs use `client_id`), not serial-as-PK — more idiomatic, simpler soft-delete. Docs' `client_serial` is the UI/portal identity, not the DB FK.
- Derived values computed in model `saving` events; `total_amount` recalculated explicitly after line changes (not auto, to avoid N+1 on bulk insert).

**Next**
- RBAC: add `role`/`permissions`/`active` to users + policies (`docs/03-rbac-permissions.md`).

---

## Session 2 — 2026-06-11 — Status docs + Breeze auth

**Goal:** Add in-repo status tracking docs, then install Breeze (auth + Vue/Inertia frontend).

**Done**
- Added `docs/STATUS.md` (status board) + `docs/SESSION-LOG.md` (this journal).
- Installed Laravel Breeze with **Vue + Inertia** (`breeze:install vue`): auth scaffold, profile, dashboard.
- Built frontend assets (`npm run build`) successfully.
- Verified: `/login` & `/register` → 200, `/` → 200, `/dashboard` → redirects to login (auth guard works).

**Problems hit & fixes**
- `vite build` failed: `app.js` imported `./bootstrap` which Laravel 13 no longer ships. Created `resources/js/bootstrap.js` (axios setup) and added `axios` dev dep → build passes.

**Decisions**
- Maintain `STATUS.md` + `SESSION-LOG.md` each session as the human-readable mirror of working memory.

**Next**
- Build the domain layer (migrations + models) from `docs/02-domain-model.md`.

---

## Session 1 — 2026-06-10 — Foundation & dev environment

**Goal:** Lock tech stack, set up version control + local dev environment.

**Done**
- Reviewed locked product/architecture decisions and product docs (`docs/01`–`06`).
- **Tech stack decision:** chose **Laravel** (PHP) over Next.js and Node+React SPA.
  - Reasoning: app is CRUD-heavy invoicing/CRM — Laravel's sweet spot. Built-in auth, RBAC, queues, validation, PDF, scheduler = fastest delivery. Performance is fine for low–mid traffic / thousands of clients on a single small VM.
- **Git:** `git init` (main), added `.gitignore` (Laravel-aware), wired remote `origin` → GitHub, pushed.
  - Commits: `66e064d` (docs + mockup), `d3451a8` (Laravel scaffold).
- **Dev environment (Docker-first):**
  - Bootstrapped Laravel v13.8 via `laravelsail/php84-composer` image (no native PHP).
  - Installed Sail + `sail:install --with=pgsql,redis` (Sail not bundled in Laravel 13).
  - Moved app to repo root; configured `.env` (APP_NAME=Saifzz, DB=saifzz).
  - Ran migrations on Postgres; app returns **HTTP 200**.

**Problems hit & fixes**
- Host port **5432** already allocated → remapped `FORWARD_DB_PORT=5433`.
- Host port **80** then **8080** blocked/taken → `APP_PORT=8000`.
- **500 `tempnam()`** error → `storage/` + `bootstrap/cache` not writable by container user. Fixed perms + set `WWWUSER/WWWGROUP=1000` in `.env`.

**Decisions / preferences captured**
- No `Co-Authored-By: Claude` trailer in commits or PRs (applies from `d3451a8` onward).
- Keep an in-repo status board (`STATUS.md`) + this session log for easy human reference.

**Next**
- Install Laravel Breeze (Inertia + Vue + Tailwind), then build domain layer from `docs/02-domain-model.md`.

---
<!-- Template for new sessions (copy above this line, newest on top):

## Session N — YYYY-MM-DD — <title>

**Goal:**

**Done**
-

**Problems hit & fixes**
-

**Decisions**
-

**Next**
-
-->
