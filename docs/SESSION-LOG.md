# Saifzz Aircond — Session Log

> Chronological journal of work sessions (newest first). One entry per working session.
> Captures: what was done, decisions made, problems hit, and the next step.
> Companion to `docs/STATUS.md` (the live board).

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
