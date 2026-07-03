# Saifzz Aircond — Session Log

> Chronological journal of work sessions (newest first). One entry per working session.
> Captures: what was done, decisions made, problems hit, and the next step.
> Companion to `docs/STATUS.md` (the live board).

---

## Session 46 — 2026-07-02 — Set next service date on a paid record (FEAT-020)

**Goal:** Khalid often forgets to pick the next-service month while creating a visit. If still `pending` he can fix it via Edit, but once paid, `ServiceVisitController::edit/update` are hard-gated to `pending` and the field stays permanently null. Add a narrow way to set/correct it after payment.

**Done:**
- New endpoint `PATCH /service-records/{serviceRecord}/lines/{line}/next-service-date` (`ServiceVisitController::updateNextServiceDate`) — updates one `service_lines.next_service_date`, deliberately exempt from the paid-lock (no status check), always editable (not null-only). Resyncs `client_units.next_service_date`/`next_service_type` when the line has a `unit_id`, reusing the exact sync pattern from `store()`/`update()`. Clearing to null updates the line but doesn't blank an already-set unit value.
- `ServiceRecords/Show.vue` gets an inline pencil-edit control (date input → Save) next to the next-service-date display, shown for lines whose service type has `requires_next_service` and a `unit_id`; new prop `requiresNextServiceTypes` on `show()`.
- Executed via subagent-driven-development: 2 tasks (backend endpoint, frontend UI), each with a fresh implementer + independent task reviewer, plus a final whole-branch review — all clean, only cosmetic Minors (import order, no explicit pending/void status test though behavior is status-independent by inspection, no onError/loading state on Save matching existing file convention).
- Design: `docs/superpowers/specs/2026-07-02-paid-record-next-service-date-design.md`. Plan: `docs/superpowers/plans/2026-07-02-paid-record-next-service-date.md`.
- Full suite: 355/355 passed.
- `FEEDBACK-02072026.md`: added FEAT-020, OPEN → TESTING.
- Commits `32632dd`..`5656117` on `dev`, left un-pushed — Khalid pushing himself.

**Next:** Khalid pushes `dev`, confirms/opens PR to `main`, tests FEAT-020 alongside the still-pending FEAT-019/CHG-024/CHG-025/BUG-002 batch.

---

## Session 45 — 2026-07-02 — Local DB restore, mobile filter fix (BUG-002), CI date-rot fix

**Goal:** Restore a DBeaver prod backup into the local dev DB, then fix a mobile/PWA layout bug Khalid flagged on the new Transactions date-range filter (CHG-025), and unblock the dev→main PR CI.

**Done:**
- Restored a plain-SQL DBeaver prod backup into local Postgres. Hit two snags along the way: (1) `DROP DATABASE` fails while connected to it in DBeaver — worked around by targeting `postgres` as the active DB first; (2) post-restore `artisan migrate` failed with `no schema has been selected to create in` — the `public` schema was missing (dropped along with the DB), fixed with `CREATE SCHEMA public; GRANT ALL ON SCHEMA public TO sail;`. After restore, `void_reason` column was missing because the prod dump predates the void feature (Session 44) — resolved by re-running `artisan migrate` to apply the pending migration on top of the restored data.
- **BUG-002 (mobile):** period chips + date-range picker (CHG-025) were inside `AdminLayout`'s sticky header, which has a fixed `h-16`. On narrow viewports the content needed to wrap but the header couldn't grow, clipping/overlapping the bell and user-menu icons. Moved into a normal-flow filter row above the stat cards (`resources/js/Pages/Transactions/Index.vue`), matching the existing Service Records pattern (title in sticky header only, filters below in page flow). Commit `b3e665e`.
- **CI unblock:** `TechnicianScopingTest::test_appointment_index_scoped_to_own` — flagged as pre-existing date-rot in Session 44, actually broke the dev→main PR's CI gate today. Root cause: the appointment index defaults to the current month, and the test fixture hardcoded `2026-06-20`; once the calendar rolled into July the fixture appointment fell out of range. Fixed by anchoring the fixture to `now()->startOfMonth()->addDays(19)` so it can't rot again. Commit `eae85f1`. Full suite 349/349.
- Cleaned up 42 untracked `.superpowers/sdd/*` scratch files (task briefs/reports/review diffs) left over from Session 44's subagent-driven-development run — feature already merged and documented, scratch no longer needed. Restored the one file among them that was actually tracked (`task-6-fix-report.md`, committed in `ec29e6c`).
- Verified the dev→main merge is safe for prod: `origin/main` was 20 commits behind `dev`, with exactly one new migration (`add_void_fields_to_transactions_table`) — purely additive nullable columns, no drops. CD only runs `migrate --force`, never seeds/truncates on prod.
- `FEEDBACK-02072026.md`: added BUG-002, OPEN → TESTING.
- Pushed `dev` (`b3e665e`, `eae85f1`) to origin for the PR's CI to re-run.

**Next:** confirm CI is green on the PR, then merge dev→main. Khalid to test FEAT-019/CHG-024/CHG-025/BUG-002 on next deploy.

---

## Session 44 — 2026-07-02 — Void paid service records + filters (FEAT-019/CHG-024/025)

**Goal:** Khalid mistakenly created + fully paid a service record on 1-Jul with no way to undo it. Added a Void action for paid records (Cancel already existed for pending), plus two related filters he asked for in the same conversation.

**Done:**
- `transactions` gains `void_reason`/`voided_at`/`voided_by`; new `status='void'` alongside `pending|paid|failed|cancelled`. No row is ever hard-deleted — Invoice/Receipt stay in the DB for audit.
- `PaymentService::voidPaid()` flips the transaction to void (reason required, actor + timestamp recorded) and reopens a linked appointment if this payment had auto-completed it (deliberate bypass of `Appointment::canTransitionTo` — that machine treats `completed` as terminal by design for the booking flow, void is a billing correction outside it).
- `ServiceVisitController::destroy()` branches: `pending` → unchanged Cancel (no reason), `paid` → new Void (reason required), anything else → 422.
- Portal: voided transactions 404 on receipt access; `PortalService::accountFor()` excludes both `void` and `cancelled` visits from the customer's service history (cancelled was already reachable pre-existing, folded into this change) — via `whereDoesntHave`, not `whereHas`, after full-suite verification caught that `whereHas` wrongly excluded transaction-less visits too (fixed same session, see below).
- New status chip filter (All/Paid/Pending/Cancelled/Void) on Service Records index; new custom date-range filter (From/To) alongside the existing Today/Week/Month/All chips on Transactions.
- Executed via subagent-driven-development: 13 plan tasks, each with a fresh implementer + independent task reviewer, plus one regression fix (Task 6's `whereHas` semantics) caught by a full-suite run after all tasks passed individually — `PortalAccountTest`'s pre-existing transaction-less visit fixture broke under the original filter; fixed with `whereDoesntHave`/`whereIn` inversion, re-reviewed clean.
- Design: `docs/superpowers/specs/2026-07-02-void-paid-service-record-design.md`. Plan: `docs/superpowers/plans/2026-07-02-void-paid-service-record.md`.
- Full suite: 348 passed, 1 pre-existing unrelated failure (`TechnicianScopingTest` — hardcoded `2026-06-20` fixture with no `travelTo()`, now stale since system date passed it; not touched, not this feature's).
- `FEEDBACK-02072026.md`: FEAT-019/CHG-024/CHG-025 OPEN → TESTING (only Khalid closes to DONE).

**Next:** push for Khalid to test; once confirmed, close out. Separately worth a look sometime: `TechnicianScopingTest`'s stale date fixture (pre-existing, unrelated to this session).

---

## Session 43 — 2026-06-19 — PWA toast clipped by notch (BUG-004)

**Branch:** `dev`, NOT pushed. 1 file. Frontend-only (CSS).

- **BUG-004 (toast under notch, PWA standalone):** SweetAlert2 toasts (`position: top-end`) pin their container to `top:0`. In a browser tab that's fine, but in the home-screen PWA the container sits behind the phone's top notch. Added global CSS in `resources/css/app.css` — `.swal2-container.swal2-top{,-start,-end}` get `padding-top: calc(0.625em + env(safe-area-inset-top))` (keeps SweetAlert2's default 0.625em pad, adds the notch inset; 0 on notchless devices). Matches the `env(safe-area-inset-top)` convention already in `AdminLayout.vue`.

**Prod on merge:** `npm run build`. No migrations.

---

## Session 42 — 2026-06-19 — 19-Jun feedback batch 2: tz off-by-one, portal WhatsApp, catalog redesign

**Branch:** `dev`, NOT pushed. Suite 334/334, Vite build clean. Done inline. Feedback `docs/FEEDBACK-19062026.md` (3 bugs · 2 changes · 2 features → all TESTING).

- **BUG (tz off-by-one, dashboard + client profile):** evening appointments showed on the next day. `Dashboard.vue` `dayList` used `new Date(a.datetime).getDate()` → UTC+8 pushed late times to the next day, while `MonthCalendar` dot raw-parses (mismatch: dot on 19, click-list on 20). Fixed by raw-parsing `YYYY-MM-DD` against `props.month`. `Clients/Show.vue` + `Portal/Show.vue` `fmtDate` switched from `new Date().toLocaleDateString` to raw-parse (MONTHS array). Frontend-only.
- **BUG (portal "Set Appointment" → wa.me 404):** `config('business.phone')` carries two numbers (`016-… / 016-…`); `WhatsApp::normalize` strips all non-digits → mashes both into one 20-digit invalid number. Fixed + productised: new per-tenant **WhatsApp tab** in Business Settings.
- **FEAT (per-tenant WhatsApp number):** `whatsapp_phone` column on `business_settings` (migration `2026_06_19_000002`); model fillable + `forTenant`; request rule; controller `show`/`update`; new "WhatsApp" tab (`BusinessSettings/Index.vue`) with label/placeholder + live `wa.me/60…` preview (via `lib/whatsapp` `waNumber`). `PortalController::business()` now tenant-aware (`BusinessSetting::forTenant($client->tenant_id)`); wa priority = `whatsapp_phone` → first listed display phone → config. Never 404s.
- **CHG (invoice/receipt):** payment method **"Manual QR" → "Duitnow QR Code"** (display map only in `receipt.blade` + `invoice.blade`; internal label kept so admin still distinguishes manual vs gateway). `documents/layout.blade.php` header: **SSM moved above phone**, phone gets **"Phone Number:"** label. Blade-only.
- **FEAT (reorder service sequence):** `sort_order` column on `service_types` (migration `2026_06_19_000001`, backfilled from alphabetical). All 7 `orderBy('name')` → `orderBy('sort_order')->orderBy('name')` (Catalog/ServiceType/Client controllers + ServiceVisit ×2) so the order applies **everywhere** (incl. service dropdowns). `ServiceTypeController::reorder` + `PUT service-types/reorder` (declared **before** `{serviceType}` to avoid model-binding capture; gated `manage_service_types`). New **dependency-free** `Components/DragList.vue` — Pointer Events, mouse+touch, nearest-centre target. Drag handles on Service Types tab + Catalog.
- **CHG (catalog redesign):** rejected card-grid twice (too templated / compact-centred). Final = **rate sheet** — full-width hairline-divided rows: name + mode tag + next-service flag on the left, pricing as inline **tier chips** (HP→price / unit→price) on the right; flexible reads "Quoted per job" inline. `CatalogRow.vue` replaces `CatalogCard.vue`. Drag handle fades in on row hover.

**Problems hit & fixes**
- `DragList` first used the **default** slot while consumers passed `#item` → blank lists. Fixed: named slot `item`.
- **npm install blocked** by a corrupted `node_modules/.bin/tree-kill` symlink over the WSL mount (EISDIR) → could not add a drag lib; went dependency-free.
- **Toolchain note:** node/npm usable only **inside** the app container (`saifzz-aircond-laravel.test-1`, workdir `/var/www/html`) — host Windows npm + bare WSL have no usable node; `node_modules` are linux. Build via `docker exec … sh -lc 'cd /var/www/html && npx vite build'`.

**Prod on merge:** 2 migrations (`sort_order`, `whatsapp_phone`) + `npm run build`. No reseed.

---

## Session 41 — 2026-06-19 — Prod 500 on receipt/invoice PDF: GD missing in prod image

**Branch:** `dev`, NOT pushed. 1 commit. Hotfix — found via prod log.

- **Symptom:** Khalid hit a 500 around the Google Review QR flow on prod. Tailed `storage/logs/laravel-2026-06-19.log` → real fault is **`DocumentController->receiptPdf` → dompdf**, not the upload: `production.ERROR: The PHP GD extension is required, but is not installed. (Cpdf.php:6226)`. dompdf needs **GD to embed the PNG QR** into the receipt/invoice PDF → any doc carrying the QR 500s.
- **Root cause:** `Dockerfile.prod` ext list (`install-php-extensions ...`) never included `gd`. Local Sail image has GD (used it in session 40 to generate icons), so it only bit prod.
- **Fix:** added `gd` to the `install-php-extensions` line in `Dockerfile.prod`. `install-php-extensions` pulls libpng/jpeg/freetype deps itself — no extra apk.
- **Immediate prod unblock (live, no rebuild):** `docker compose exec app install-php-extensions gd && docker compose restart app worker` — survives `restart`, dies on next image rebuild (hence the Dockerfile change bakes it permanently).
- **Prod on merge:** image rebuild (CD) — `gd` now compiled in. No migrations, no Vite.

---

## Session 40 — 2026-06-19 — PWA home-screen icons + Business Settings live preview

**Branch:** `dev`, NOT pushed. 2 commits. PHP `BusinessSettingTest` 15/15 (incl. 5 new). Vite build clean.

- **PWA add-to-home-screen (commit 1):** mobile browsers (iOS Safari + Android Chrome) now get a proper app icon + name when "Add to Home Screen". Generated `icon-192.png`, `icon-512.png` (transparent) + `apple-touch-icon.png` 180px (**white-flattened — iOS renders alpha as black**) from `public/img/logo.png` via container GD. New `public/site.webmanifest` (name "Saifzz Aircond", short "Saifzz", standalone, theme `#1e3a8a`, maskable icon). `app.blade.php` head += apple-touch-icon, manifest link, theme-color, apple/mobile web-app meta. **Prod note:** web server must serve `.webmanifest` as `application/manifest+json` or Android skips the icon — verify nginx mime on deploy.
- **Business Settings preview overhaul (commit 2):** Khalid flagged the Identity tab as off. Investigated all 6 points →
  - **Address field removed** — it was saved to DB + frozen in snapshot but **rendered nowhere** on invoice/receipt (only *client* address shows; business header is name/phone/SSM only). Dropped from `Index.vue` form, `UpdateBusinessSettingRequest` rule, controller `update()`/`show()`. DB column + `forTenant`/snapshot left intact (harmless, reversible).
  - **Placeholder "Business Name / Phone number" fixed at root** — `show()` prefilled from the raw DB row (`$row?->business_name`, no fallback), so a fresh/unsaved tenant got an empty form → mock showed literal placeholders. Now prefills from `BusinessSetting::forTenant()` — the **same source the documents read**, incl. `config('business.*')` fallback. Page + doc can't diverge.
  - **Preview ≠ real doc fixed (replaces session-39 CHG-019 mock)** — the `InvoicePreview.vue` Tailwind mock was a hand-built reimplementation that drifted from `documents/*.blade.php` (missing Due Date/Status, different total label/colour, etc.). **Deleted it.** New admin-gated `GET business-settings/preview/{type}` route renders the **real** `invoice.blade`/`receipt.blade` with a fixed sample snapshot + live-typed identity (query params), loaded in an `<iframe>` on the Identity tab. Invoice/Receipt toggle, 400ms-debounced refresh as you type. Single source of truth — covers both docs, can't drift again.
  - **Label fix** — preview now a proper `Card title="Live document preview"` (was a bare floating `<div>` next to the form Card).
  - Tests: +5 in `BusinessSettingTest` (live invoice identity, receipt template, bad type → 404, saved-identity fallback, non-admin → 403).
- **Real-fresh seeder:** `DatabaseSeeder` now seeds **only the 2 boss accounts (self-rooted tenants)** — commented out the Saifzz business-identity + Google Review QR block and the `ServiceType`/`ServiceFee` seeder calls. Bosses configure identity/services/fees from the live UI. Seeder classes left intact (uncomment to restore defaults). Local reset verified: `users=2 tenants=2`, everything else 0. **Fresh reset:** `php artisan migrate:fresh --seed` then `storage:link` + `chown -R sail:sail storage/app/public` (prod: `--force` + uid `82`).
- **Prod on merge:** `npm run build` (Vite) + verify `.webmanifest` mime. No migrations.

### Fixes (same session)
- **Service-record validation messages** — errors leaked raw keys (`The lines.0.service_type field is required.`). Added `attributes()` + `messages()` to the shared `ValidatesServiceLines` trait (used by Store + Update requests; neither defined them, so trait methods apply). Per-line messages use the 1-based `:position` placeholder → "Select a service type for line 1." +1 test (`ServiceVisitTest`).
- **Fee Schedule flexible mode had no Save** — the "Save fees" button was inside the non-flexible `v-else` block, so picking **Flexible** gave no way to persist; the type stayed at its DB default `pricing_mode='flat'` (migration `000010`) with zero fees → service records then showed "ask admin to set fees". Moved Save into an always-visible footer in `ServiceTypes/Index.vue`; "Add unit type" stays in the non-flexible block. Server (`syncFees`/`SyncServiceFeesRequest`) already accepted flexible + empty fees. Frontend-only, `npm run build`.
- **Reminder/context note:** invoice/receipt "defaults" come from `config('business.*')` (env-backed) via `BusinessSetting::forTenant` fallback when no `business_settings` row exists — name "Saifzz Aircond Services" + phone "016-635 4563 / 016-281 5887"; SSM is DB-only (blank until saved). Logo from `BrandAssets::logoDataUri()` (`logo-256.png`). Intentional safety net so docs never render blank pre-setup.

---

## Session 39 — 2026-06-19 — Google-review warranty bonus (FEAT-005/006)

**Branch:** `dev`, NOT pushed. Build clean. FEAT-005 + FEAT-006 turned out to be the **same feature** (not a voucher entity) → both → TESTING. **17-Jun feedback now has zero OPEN items.**

- **Scope (confirmed by Khalid via Q&A):** when a customer leaves a Google review, the technician clicks a toggle button instead of fiddling the Warranty dropdown. Toggle on = +1 month; toggle off = −1. Capped at 6. No voucher object, no tracking flag (pure dropdown bump) — "for now". On **both** Create + Edit (Edit covers the "client decides to review after the record was submitted" case).
- **Impl (frontend-only):** `ServiceRecords/Create.vue` + `Edit.vue` — added `reviewBonus` ref, `atWarrantyCap` computed, `toggleReviewBonus()`, and a `watch(form.warranty_months)` that clears the bonus state on any manual dropdown change (via a `suppressBonusWatch` flag so the toggle's own write doesn't trip it — avoids subtracting from a hand-set value). Button sits under the Warranty `<select>`; green `border-ok/bg-ok-bg/text-ok` "applied" state, `IconStar`. Disabled + "Max warranty is 6 months" hint at cap.
- No backend, no migration. PHP suite unaffected (warranty_months already persisted/tested). Vite build clean.
- **UI follow-up (per Khalid):** moved the review-bonus toggle OUT of the warranty grid and INTO the Google Review card on `Create.vue`, directly under the QR image (tech no longer scrolls up). Shows "Covered until {date}" under the button when applied; the warranty dropdown up top still reflects the +1 and its own covered-until. `Edit.vue` keeps its button under the dropdown (no QR card there).
- **QR-not-displaying bug (env, not code) — fixed:** two stacked issues. (1) `public/storage` symlink was missing → all `/storage/*` 404'd → ran `storage:link`. (2) `storage/app/public/qr` was `root:root` while web PHP runs as `sail` → `Storage::disk('public')->put()` failed SILENTLY (`throw=>false`), DB path saved but no file written. Confirmed via temp logging (`bytes_read=4380, put=false` → after `chown -R sail:sail qr payment-qr` → `put=true`). Cleared one stale DB path (file gone) during diagnosis. **Durable:** added a post-seed `chown -R 82:82 storage` to both deploy blocks in `docs/DEPLOYMENT.md` + a reseed warning (the seeder writes the bundled QR, can recreate `qr/` root-owned).
- **QR preview not auto-refreshing after save — fixed:** the QR filename is fixed (`qr/tenant-{id}.png`), so a re-upload leaves the DB path string unchanged → Eloquent isn't dirty → `updated_at` never bumps → any version query built from it was identical → browser kept the cached image until a manual reload. Fix: new `BusinessSettingController::qrUrl()` helper versions the URL by the **file's `lastModified` mtime** (changes every upload) and returns null when the file is missing (shows "No QR uploaded yet" instead of a broken img). Backend-only, no rebuild.
- **Prod on merge:** `npm run build`. No migrations.

---

## Session 38 — 2026-06-19 — 6 remaining 17-Jun P2/P3 items (CHG-006/012/016/019/020 + FEAT-010)

**Branch:** `dev`, NOT pushed. Commit `5a26e84` (base `116d9c8`). Suite **324/324**, build clean. Done inline (small, independent items). All 6 → TESTING. 17-Jun now down to only FEAT-005/006 (warranty theory, need scope).

- **CHG-006 (P2, Catalog)** — price-set grouping by HP / unit type was already shipped in the CHG-005 catalog rebuild: `Catalog/Index.vue` groups `hp_tiered` fees under each unit type with HP rows, lists `flat` per unit type, shows `flexible` as per-job. Verified vs the ask — no code change.
- **CHG-012 (P3, Clients)** — filter chips now sourced from the live `service_types` table: `ClientController::index` passes `ServiceType::orderBy('name')->pluck('name')` (was a stale hardcoded const still listing killed `Gas Top-Up`/`Repair`); removed the `SERVICE_TYPES` const. `Clients/Index.vue` falls back to a `<select>` dropdown when >6 types (`asDropdown` computed; chip + dropdown share `applyType()`).
- **CHG-016 (P3, Business Settings)** — `config/business.php` phone default → `016-635 4563 / 016-281 5887` (was `012-9876543`). Already editable per tenant via Business Settings → Identity (`BusinessSetting::forTenant` overrides the config fallback). The number was never actually hardcoded in code — only the default needed setting.
- **CHG-019 (P2, Business Settings)** — `InvoicePreview.vue` rewritten to mirror the real `documents/layout.blade.php`: invoice no/date kv rows, Bill-to block, services box, navy total block, PENDING status pill, footer line. Still live-updates from the Identity tab fields (name/phone/SSM).
- **CHG-020 (P2, Business Settings)** — new shared `BusinessSettings/Partials/ImageUploadField.vue`: `v-model` File, styled Choose/Change button, selected filename, clear (×), and an object-URL thumbnail of the picked image before save (revokes URLs on change/unmount). Wired into both the Google Review QR and Manual QR inputs (replaced the raw `<input type=file>`).
- **FEAT-010 (P3, Clients)** — each service line in `Clients/Show.vue` service history shows a "Next service: DD Mon YYYY" badge when `l.next_service_date` is set (date, not a countdown).
- Frontend + `config/business.php` + `ClientController` only. No migrations. Full suite stayed 324/324 (Client tests didn't assert the old const).
- **Prod on merge:** `npm run build` (Vite). No migrations.

---

## Session 37 — 2026-06-18 — Service record: payment selector → Google Review (CHG-007/008)

**Branch:** `dev`, NOT pushed. 7 commits `233c491`→`116d9c8` (base `a6cb276`). Suite **324/324**, build clean. Brainstorm → spec → plan → subagent-driven execution (backend + frontend impl, reviewer pass). Both 17-Jun P2 → TESTING.

- **CHG-008** — removed the redundant payment-method selector from the service-record create + edit forms. The collect-payment screen already selects the method and `PaymentService` overwrites `transaction.method` at collection, so the form only needs a pending placeholder.
  - `store()` writes pending placeholder `'DuitNow QR'`; `update()` no longer touches `method` (preserves existing), updates `amount` only.
  - Dropped the `payment_method` rule from `StoreServiceVisitRequest` + `UpdateServiceVisitRequest`.
  - Removed the now-dead cash-at-creation guard from `ValidatesServiceLines` trait (it read a `payment_method` field the forms no longer send). Safe: all payment routes (`payments.cash`/`manualQr`/`pay`) are gated `can:collect_payment`, so cash recording stays permission-protected. Dropped its obsolete test `test_cash_method_blocked_without_collect_payment`.
- **CHG-007** — Google Review surfaced **before** payment. `create()` now passes `googleReview {qrUrl,url}` (same `BusinessSetting` lookup as `show()`); `Create.vue` renders a Google Review card (QR image + open-review link) where the payment-method card was, gated on a configured QR. Edit form gets no review card.
- Reviewer initially flagged guard removal as "critical security hole" — verified false (no new capability; routes already gated). Cleaned the dead guard instead.
- Spec `2026-06-18-service-record-payment-greview-design.md`, plan `2026-06-18-service-record-payment-greview.md`.
- **Prod on merge:** `npm run build` (Vite). No migrations.

---

## Session 36 — 2026-06-18 — Reminder contacted filter (FEAT-011)

**Branch:** `dev`, NOT pushed. Commit `cfed333`. Frontend-only (no PHP tests touched); vite build clean. 17-Jun cluster B → TESTING.

- `Reminders/Index.vue`: client-side Status filter chips (All / Contacted / Not contacted) above the card grid. Combines with the existing Due/Overdue tab + name/phone/serial search through `filterItems`.
- No backend change — `dueList()` already overlays the `contacted` flag (from `reminder_contacts`) on every row, and the stat cards already carry the contacted count.
- **Prod on merge:** `npm run build` (Vite). No migrations.

---

## Session 35 — 2026-06-18 — Transaction filters (FEAT-008 method + FEAT-009 status)

**Branch:** `dev`, NOT pushed. Commit `09dab34`. Frontend-only (no PHP tests touched); vite build clean. 17-Jun cluster A, both → TESTING.

- `Transactions/Index.vue`: client-side filter chips in the table-card header. **Method** (All / Cash / DuitNow QR / Manual QR) and **Status** (All / paid / pending / failed / cancelled).
- Both filters combine through a `filtered` computed; `rows` and the summary StatCards (`totalPaid` / `pendingCount` / `pendingAmount`) now derive from `filtered`, so the dashboard recomputes live on filter (FEAT-008 asks the summary dashboard be filterable, not just the table).
- No backend change — the controller already loads the full list per period (`limit=null`). Method values are canonical (`Cash`/`DuitNow QR` from `payment_method` `Rule::in`; `Manual QR` set on confirm); statuses pending/paid/failed/cancelled.
- **Prod on merge:** `npm run build` (Vite). No migrations.

---

## Session 34 — 2026-06-18 — FEAT-007 edit service lines + FEAT-004 Manual QR payment (both P1)

**Branch:** `dev`, NOT pushed. Suite **322/322**, build clean. Two 17-Jun P1 feedback items, each: brainstorm → spec → plan → subagent-driven execution (impl + spec review + code-quality/security review per backend). Both → TESTING.

### FEAT-007 — edit a service record's service lines (suite 307→316)
Spec `2026-06-18-edit-service-lines-design.md`, plan `2026-06-18-edit-service-lines.md`. Commits `a48eaae`→`5392e9d`.
- Edit page was lines-read-only; now full `ServiceLineCard` editor (add/remove/change lines) + sticky grand-total bar, identical to Create. Client stays fixed (read-only).
- `update()` rewritten transactional: delete-then-recreate lines via existing `normalizeLine()` (server-authoritative fee re-snapshot), `recalculateTotal()`, **+ sync `transaction.amount`** (latent stale-amount bug fixed). Re-syncs unit next-service. Pending-only guard unchanged (paid→422, non-visible→403).
- Validation: extracted shared per-line + cash-permission block into `app/Http/Requests/Concerns/ValidatesServiceLines.php` trait (used by both Store + new `UpdateServiceVisitRequest`; Store behaviour identical). `unit_id` existence scoped to the route record's `client_id`.
- Invoice = live snapshot (`SnapshotBuilder` reads lines at gen time; no frozen copy while pending) → edits reflect automatically, no extra work.
- Tests: `ServiceVisitUpdateTest` (9). No migrations.

### FEAT-004 — Manual QR payment method (suite 316→322)
Spec `2026-06-18-manual-qr-payment-design.md`, plan `2026-06-18-manual-qr-payment.md`. Commits `589599f`→`afdd927`.
- "Manual QR" = per-tenant static QR image (admin's DuitNow/bank QR) uploaded in Business Settings → Payment tab. Mechanically like Cash: admin shows QR, customer transfers, admin confirms → paid + receipt, no gateway/webhook. **Admin-only** at collection.
- Schema: migration `2026_06_18_000030` adds nullable `payment_qr_path` to `business_settings` (mirrors `google_review_qr_path`). `transactions.method` is a free string → `'Manual QR'` set server-side only, NO transactions migration, Create/Edit form enums unchanged.
- Upload: `UpdateBusinessSettingRequest` validates `payment_qr` (image, 2MB); `BusinessSettingController::update` stores `payment-qr/tenant-{id}.png` public disk; `show()` exposes `paymentQrUrl`. Business Settings Payment tab gets a Manual QR card (upload + preview) above the BayarCash card.
- Collection: `PaymentService::confirmManualQr()` (mirror of `confirmCash`, method='Manual QR', idempotent, completes linked appointment). Route `POST payments/{transaction}/manual-qr` (`payments.manualQr`, `can:collect_payment`). `PaymentController::manualQr()` = `authorizeVisitScope` (tenant/visibility 403) THEN `abort_unless(isAdmin,403)`. `Payments/Show.vue` third method button, shown only when `isAdmin && manualQrUrl`, renders the real uploaded QR.
- Cash unchanged (stays `collect_payment`-gated; CHG-016 not reversed).
- Tests: `BusinessSettingTest` +1 upload; `PaymentTest` +5 (admin paid/receipt, idempotent, non-admin-collector 403, no-collect_payment 403, cross-tenant 403).

**Problems hit & fixes**
- FEAT-007: `update()` never synced `transaction.amount` — would have gone stale once lines became editable. Fixed in rewrite.
- FEAT-004: plan's cross-tenant test hardcoded `tenant_id => 9002`, but `tenant_id` is a FK to `users.id` → FK violation. Implementer fixed to a second self-root admin (real isolation assertion).

**Reviews:** backend of each feature got a combined spec + code-quality/security subagent review → both COMPLIANT, no blockers (only cosmetic notes: inline FQCN style, test coverage boundary). Frontend = pattern-copy of existing UI, build-verified.

**Prod deploy on merge:** `php artisan migrate` (FEAT-004 `payment_qr_path` only; FEAT-007 none) + `npm run build`. Still pending from sessions 47/48/50/51: their migrations + price-book reseed.

---

## Session 33 — 2026-06-18 — Appointment flow cluster (BUG-003/004, CHG-002/003/004, FEAT-001/002)

**Branch:** `dev`, NOT pushed. Suite **307/307**, build clean. Whole-branch review "ready to merge" (one minor security finding caught + fixed). Spec `docs/superpowers/specs/2026-06-18-appointment-flow-cluster-design.md`, plan `docs/superpowers/plans/2026-06-18-appointment-flow-cluster.md`. Subagent-driven (9 tasks + spec/quality review each + final review). Commits `9f3680e`→`6e3d708`.

Closes 7 feedback items (all → TESTING):
- **CHG-004 (P1):** appointment status enum collapsed `pending→confirmed→done/cancelled` ⇒ `pending→completed/cancelled`. Data migration `2026_06_18_000020` maps `confirmed→pending`, `done→completed`. Collecting payment now auto-Completes the appointment.
- **Appointment↔payment link:** migration `2026_06_18_000021` adds nullable `appointment_id` FK on `service_visits` (nullOnDelete). Threaded: appointment "Add Record" link passes `appointment=row.id` → `ServiceVisitController::create` resolves `presetAppointmentId` via `Appointment::visibleTo` → `Create.vue` form carries `appointment_id` → `StoreServiceVisitRequest` validates tenant-scoped → `store()` persists. `PaymentService::completeLinkedAppointment()` (cash **+ webhook** paths) completes the linked appointment using the `Appointment` state machine (`canTransitionTo('completed')`) + tenant guard. Idempotent, skips cancelled/terminal.
- **BUG-003/004:** root cause = `AppointmentModal` open-watcher not `immediate`, so modal opened during Index setup never autofilled until a second open. Fixed with `{ immediate: true }` (body early-returns on `!open`). Cancel/close from a client booking now returns to the client profile (`saved` vs `close` emit split avoids double-nav on save).
- **CHG-002:** per-row actions = Add Record / Edit / Cancel Appointment (dropped Confirm/Mark-done). "Confirmed" stat → "Completed" (`month_completed`).
- **CHG-003:** technician dropdown defaults to current admin, "Unassigned" removed; `AppointmentController` technicians prop now includes the current all-data user (so self-default resolves to a real option).
- **FEAT-001:** Add Record autofills client + technician + appointment_id.
- **FEAT-002:** dedicated Serial column (hyperlink to client / "Non client" for walk-ins). Removed serial sub-line under client name.
- **Status-edit override:** `UpdateAppointmentRequest` accepts `status` (admin override, no transition guard) — gated to `seesAllData()` users (scoped techs cannot override; regression-tested). CREATE always forces `pending`. `updateStatus()` quick-action keeps its transition guard.

**Bugs caught in review:** latent `Link`-not-imported in Index.vue (was broken), `orWhereKey` typo (not a real Eloquent method → 500), scoped-tech status-override hole.

**Tests:** new `AppointmentPaymentCompletionTest` (8: persist + cross-tenant reject + cash/webhook completion + cancelled-stays + no-op + tenant-mismatch guard). `AppointmentTest` + `TechnicianScopingTest` updated to new enum; +scoped-tech-cannot-override-status. Net 297→307.

**Prod deploy on merge:** `php artisan migrate` (2: status collapse + appointment_id). No reseed for this cluster. `npm run build`. (Still pending from sessions 47/48/50: their migrations + price-book reseed.)

---

## Session 32 — 2026-06-18 — CHG-005 service pricing unification (HP overhaul)

**Goal:** 17-Jun feedback CHG-005 cluster — restructure service fees so each unit type owns its own HP→price set, set dynamically in one form. Includes BUG-002 (flexible editable price + description) + FEAT-003 (HP tier add/edit). Subagent-driven, 7 tasks, spec+plan in `docs/superpowers/`.

**Model (full unification):** each `service_type` has ONE `pricing_mode` ∈ {flat, hp_tiered, flexible}. Single rebuilt `service_fees(service_type_id FK, unit_type, hp_value nullable, price)` (unique service_type_id+unit_type+hp_value) ABSORBS the old `service_hp_tiers` table. Price = direct `(unit_type, hp_value)` lookup — NOT base+surcharge (the old additive model). Killed all hardcoded pricing: `is_hp_based`, `UNIT_TYPES`/`GAS_OPTIONS`/`UNIT_TYPE_SERVICES` constants, `'Repair'`=flexible / `'Gas Top-Up'`=gas name-checks, `service_lines.gas_option` column. `service_types`/`service_fees` stay GLOBAL (no tenant_id).

**Done** (suite 299→**297** — `ServiceHpTierTest` removed (8), `ServiceFeeTest` rewritten, `ServicePricingTest` added (6); build clean; NOT pushed):
- **Schema** (4 migrations `2026_06_18_000010-000013`): `pricing_mode` on service_types (backfill, drop is_hp_based); rebuild service_fees; drop service_hp_tiers; drop service_lines.gas_option. Seeders updated to new shape (Cleaning=hp_tiered, Gas/Install/Troubleshoot=flat, Repair/Dismantle=flexible).
- **Fee-sync endpoint**: `PUT service-types/{id}/fees` → `ServiceTypeController::syncFees` (transactional delete-then-insert of whole price set), `SyncServiceFeesRequest` (per-mode validation + duplicate guard). Deleted `ServiceFeeController`/`ServiceHpTierController`/`StoreServiceFeeRequest`/`UpdateServiceFeeRequest`.
- **Record pricing**: `normalizeLine()` + `StoreServiceVisitRequest` resolve rate by `pricing_mode` (server-authoritative for flat/hp; manual for flexible). BUG-002: flexible = editable rate + required description.
- **Controllers**: index/create/edit/catalog pass `serviceTypes` with eager-loaded `fees`; dropped old props. `gas_option` out of `SnapshotBuilder`/`PortalService`/invoice+receipt blades.
- **Frontend**: `ServiceTypes/Index.vue` dynamic per-service fee editor (mode select → repeatable unit-type blocks → HP/price tiers; one "Save fees" PUT). `ServiceLineCard.vue` driven by pricing_mode (unit-type dropdown from fees, HP dropdown filtered by unit_type, flexible editable+desc, empty-state hint). `FeeModal.vue` deleted. `Catalog/Index.vue` new shape.
- **Tests**: fixed stale fee/line fixtures (ServiceVisitTest/MultiTenant/TechnicianScoping/ClientUnit) to new schema.

**Reviews:** each task got spec + code-quality subagent review; review-driven fixes applied (null-safe hp rule, stale-editor re-sync, stable v-for keys, empty-state hint). Final opus whole-branch review: ready to merge, no blockers.

**Hotfix (same session, eyeball-found):** adding a service type then opening Fee Schedule tab rendered blank — `editors` map was built once at component init and not re-synced when Inertia replaced `props.serviceTypes`, so the new type had no editor and the tab threw on `undefined.pricing_mode`. Fixed with `watch(() => props.serviceTypes, …, {immediate:true})` that builds editors for new types + prunes removed ones (existing editors untouched so unsaved edits survive). `ServiceTypes/Index.vue`, build clean.

**Decisions:** one axis per service (a unit type owns its HP set; NOT a 2D matrix and NOT additive base+surcharge) — confirmed by Khalid via Hamid. CHG-006 (catalog grouping polish) deferred.

**Prod deploy on merge:** `php artisan migrate` (4 migrations) + RESEED price book (`db:seed --class=ServiceTypeSeeder` + `--class=ServiceFeeSeeder`) — service_fees rebuilt destructively, data disposable. + `npm run build`.

**Next:** push sessions 47+48+49+this for Khalid. Then remaining 17-Jun: appointment flow (CHG-002/003/004), FEAT-001/002, payment Manual-QR (FEAT-004), FEAT-007 (edit-record-edits-services), transaction/reminder filters.

---

## Session 31 — 2026-06-18 — BUG-001 appointment date off-by-one (timezone)

**Goal:** Fix 17-Jun feedback BUG-001 — picking 17/6 recorded/displayed as 18/6.

**Root cause:** `Appointment.datetime` cast→Carbon→serialized as UTC-tagged ISO (`...Z`). Frontend `new Date(a.datetime)` converts to browser tz (Malaysia UTC+8), bumping afternoon/evening appointments to the next calendar day. The edit modal already dodged it with `slice()`; the calendar + day-panel + table date did not.

**Done:**
- `Appointments/Index.vue` — added `wallDate()` helper (parses raw `YYYY-MM-DD` parts, no tz conversion); applied to `dayList` filter + `fmtDate`. `fmtTime` already `slice`d — untouched.
- `Appointments/Partials/MonthCalendar.vue` — `byDay` grouping parses raw date parts instead of `new Date()`.
- Frontend-only, no backend/migration/test change. Needs `npm run build` (CI builds on PR merge).
- `FEEDBACK-17062026.md`: BUG-001 OPEN → TESTING (only Khalid closes to DONE).

**Next:** push sessions 47+48+this for Khalid; then CHG-005 HP overhaul cluster.

---

## Session 30 — 2026-06-17 — Business Settings hub (dynamic identity, Google Review QR, logo)

**Goal:** Make business-facing details dynamic + admin-editable per-tenant: official logo swap + favicon, dynamic invoice/receipt identity (name/address/phone/SSM) with live preview, Google Review QR on payment-received, all under one consolidated nav hub. Also answered: per-tenant payment API token setting was already shipped (session 41 in memory numbering) — relocated into the new hub.

**Done** (subagent-driven, 10 tasks, suite 290→**299**, build clean, NOT pushed)
- `business_settings` table (per-tenant, unique `tenant_id` FK, mirrors `tenant_gateways`). `BusinessSetting::forTenant(?int)` resolver → row or `config('business.*')` fallback (null-tenant safe).
- `SnapshotBuilder` freezes per-tenant identity + `ssm_no` into each document.
- Logo on invoice/receipt PDFs via `App\Support\BrandAssets::logoDataUri()` (base64 data-URI, per-request cached, null-safe if asset missing). Wired into `DocumentController` (invoice+receipt) + `PortalController` (receipt).
- `BusinessSettingController` (GET show / PUT update), `can:manage_users` route group, `tenant_id` server-sourced. QR upload → public disk `qr/tenant-{id}.png`. `UpdateBusinessSettingRequest` (nullable identity + `url` + `image` max 2MB).
- `BusinessSettings/Index.vue` — 3 tabs: Identity (fields + live `InvoicePreview.vue`) / Google Review (URL + QR upload + thumbnail) / Payment (BayarCash creds, posts to existing `payment-settings.update`).
- Nav: "Business Settings" (`IconBuildingStore`, adminOnly) replaces "Payment Settings"; `PaymentSettings/Index.vue` deleted; `/payment-settings` GET → redirect to hub (route name kept). `PaymentGatewayController` untouched.
- Google Review button on `ServiceRecords/Show.vue` paid block → `Modal` (`:show`/`@close`) with QR + review link. Controller passes `googleReview:{qrUrl,url}` via `forTenant($visit->tenant_id)`.
- Official logo: source `public/img/logo.png` (2.5MB) → GD-resized `logo-256.png` (107KB) + `favicon.png`/`.ico`. Swapped `IconAirConditioning` → `<img>` in AdminLayout, GuestLayout, Welcome, Portal/Login; favicon links in `app.blade.php`.
- Seeder: Saifzz tenant business identity + SSM `202603093151 (003839732-K)` + bundled Google Review QR (idempotent `updateOrCreate`).
- Tests: `BusinessSettingTest` (9 cases — resolver, snapshot, view/save, QR upload, tenant_id-not-honored, non-admin 403, Show props). `PaymentGatewaySettingsTest` made redirect-aware.

**Problems hit & fixes**
- No model factories exist → tests use direct `Model::create()` (Client needs name/phone/address; ServiceVisit client_id/visit_date; Transaction txn_id/visit_id/amount/method).
- `/payment-settings` redirect broke 2 existing payment tests → updated to `assertRedirect`/hit new hub.

**Decisions**
- Logo static-swap now (dynamic upload deferred); logo DOES render on PDFs; per-tenant identity + QR (consistent with payment gateway); live Vue preview (not server-rendered).
- Spec `docs/superpowers/specs/2026-06-17-business-settings-design.md`, plan `docs/superpowers/plans/2026-06-17-business-settings.md`.

**Deploy needs (on merge):** `php artisan migrate` (business_settings), `db:seed` (Saifzz identity+QR), `storage:link` present, `npm run build`.

**Next**
- Push for Khalid testing (incl. new Business Settings + logo). Discuss Units scope. SMTP, DB backups.

---

## Session 29 — 2026-06-16 — Park Units feature (frontend hidden)

**Goal:** Units feature feels half-built — unit lives on client page but link to service records is unclear. Hide until requirement matures, without breaking anything.

**Done**
- Confirmed hiding is safe: `service_lines.unit_id` nullable end-to-end (FK `nullOnDelete`), `StoreServiceVisitRequest` validates `unit_id` nullable, `SnapshotBuilder` (invoices/receipts) uses `unit_type`+`units` count — not `unit_id`. Reminders keep firing via fallback path (`service_lines.next_service_date`) since count mode lands the date on the line.
- Hid 3 frontend spots with `v-if="false"` + inline re-enable notes: `Clients/Show.vue` (UnitsSection), `ServiceRecords/Partials/ServiceLineCard.vue` (unit selector), `ServiceRecords/Create.vue` ("+ Add line for each unit").
- Backend, DB, migrations, model, controller, routes all left intact — no data loss, reversible.
- Wrote `docs/UNITS-TODO.md` — why parked, what's hidden, what's intact, open questions for discussion.

**Decisions**
- Units PARKED, not removed. Re-enable = restore original `v-if` conditions (documented inline + in UNITS-TODO).

**Next**
- Discuss Units requirement with Khalid (is unit→record link required or optional? per-unit vs count mode default? reminders/warranty/invoice interaction? portal self-register?).
- Still pending: Khalid visual review of public UI, SMTP, DB backups.

---

## Session 28 — 2026-06-16 — Public-facing UI redesign + responsive audit

**Goal:** Make the landing + login pages look like a real product (were plain/boring), unify branding on the aircond logo, improve the customer portal, then audit mobile/iPad responsiveness across all pages.

**Done**
- **Logo unified** — `IconAirConditioning` in a primary box replaces the plain `S` everywhere (landing header, `GuestLayout`, portal badge), matching the sidebar.
- **Landing (`Welcome.vue`)** — rebuilt: navy gradient hero with airflow glow blobs + faint grid texture, badge → big gradient headline ("Cool comfort, fully tracked.") → service pills (Cleaning/Gas/Repair/Installation); two entry cards overlap the hero edge with hover-lift + expanding glow.
- **Staff login (`GuestLayout.vue` + `Auth/Login.vue`)** — new `branded` prop on GuestLayout → desktop two-pane split (navy brand panel: logo, headline, 3 trust marks with icon tiles + form pane); mobile gets a centered logo badge, soft glow, and the form in a surface card. Default GuestLayout (forgot/reset/verify) unchanged except logo.
- **Portal login (`Portal/Login.vue` + `PortalLayout.vue`)** — added `center` prop → login vertically centered (was top-anchored); aircond icon badge + business name header above the card; dark top bar hidden on the centered login (badge carries the brand); airflow glow added to the portal navy bg. `Portal/Show.vue` untouched (no prop).
- **Dashboard responsive (`Dashboard.vue`)** — Outstanding Receivables raw table now `md:block`; added `md:hidden` mobile card stack (client+serial+date, amount, aging badge, View →). No more horizontal scroll on phones.

**Responsive audit (all 43 pages)** — foundation is solid: DataTable (table on `md+` / card slot on mobile, all 6 consumers have `#card`), modals (bottom-sheet mobile / centered desktop / `max-h-[92vh]` scroll), responsive stat grids (`sm:grid-cols-*`), truncating headers. Only real gap was the Dashboard receivables table (fixed above). Considered adding `md:grid-cols-2` to `lg:grid-cols-3` blocks for iPad portrait but **withdrew** — those are layout splits with `lg:col-span-2`; forcing 2 cols at 768 breaks the 2/3+1/3 ratios. Stat rows already use `sm:` so iPad portrait already gets multi-column.

**Notes**
- App runs in **manifest mode** by default (no vite `public/hot`) — visual changes need `npm run build` OR a running `npm run dev` (writes the hot file → HMR). Caused a "change not showing" confusion mid-session until rebuilt.

**Tests:** none touched (view-only changes).

**Next:** Owner visual review of new landing/login/portal; SMTP; DB backups.

---

## Session 27 — 2026-06-16 — Creator attribution + level-based dashboard gating

**Goal:** Surface who handled each service/transaction, and make the dashboard L3-only with level-based menus for L1/L2.

**Done**
- **Creator columns** — "Created by: Name (Role)" added to Service Records table + Dashboard recent-transactions (admin/all-data only). Data already stored (`service_visits.created_by`); eager-loaded `creator:id,name,role`; `ReportService::transactions` joins `users` for `created_by`/`created_by_role`.
- **Reminders "Last service by"** — handler (technician of the latest visit) shown under the Last-service date. Two correlated subqueries on both unit + legacy queries. Fixed latent inconsistency: legacy `last_service_date` now uses the all-visits subquery so date + handler always reference the same newest visit.
- **Dashboard gated `view_reports`** — was `auth`-only with `permission: null` nav. Now L1/L2 have no dashboard; `/dashboard` redirects to Appointments (Catalog fallback). Nav link hidden.
- **Level-based menus** — dropped `adminOnly` from Reminders/Clients/Services/Transactions nav (now pure permission gates), so granted technicians see them.
- **Preset defaults** — `manage_service_types` moved to L3-only (out of L1/L2). `DEFAULT_TECHNICIAN_PERMISSIONS` aligned to L1 (added `manage_units`, dropped `manage_service_types`).
- **Own-clients scoping** — non-`view_all_data` users see only clients they serviced on the Clients registry (`Client::scopeOwnedBy`) and Reminders (`dueList` technician param + badge). Service picker stays tenant-wide.

**Decisions**
- Landing for L1/L2 = Appointments (Catalog fallback).
- `manage_service_types` = L3 only.
- "Own clients" scoping applies to registry + reminders, NOT the record-service picker (techs must be able to service any client).
- Per-technician customization unchanged (UserModal checkboxes + editable L1/L2/L3 baselines) — fully dynamic.

**Tests:** 290 passed / 1101 assertions. Rewrote 5 DashboardTest + 1 ReminderTest + 1 ServiceTypeTest to the new model; added 3 scoping tests.

**Next:** Owner visual review (eyeball L1 vs L3 menus via `npm run dev`).

---

## Session 18 — 2026-06-12 — Hot fixes (migration + soft-deleted client crash)

**Goal:** Fix runtime errors found during first visual review of the live app after technician-scoping ship.

**Problems hit & fixes**
- `SQLSTATE[42703]: Undefined column sv.technician_id` — migration `2026_06_12_000110_add_technician_scoping` was pending in the live DB (had only run in the test DB). Fixed: `docker exec saifzz-aircond-laravel.test-1 php artisan migrate`.
- `TypeError: Cannot read properties of null (reading 'id')` on `ServiceRecords/Show` — `visit.client` was null because the client referenced by some visits had been soft-deleted. `ServiceVisit::client()` and `Appointment::client()` relations both had plain `belongsTo` — added `->withTrashed()` so historical records always resolve their client.

**Tests:** 187 passed / 727 assertions (unchanged — fixes were data/relation level, no new tests needed).

**Next:** Owner visual review of scoping UI. Pending Round 1 polish: MonthCalendar dots, Fees Repair option-field, Reminders card fields.

---

## Session 17 — 2026-06-12 — Technician data scoping

**Goal:** Row-level data ownership — technicians see only their own jobs/revenue/appointments; admins + `view_all_data`-granted users see everything. Brainstormed → spec'd → planned → implemented (subagent-driven TDD, 12 tasks).

**Decisions**
- **Hybrid design:** new `technician_id` owner column on `service_visits` + `appointments` (distinct from `created_by` = recorder). Single scoping seam: `scopeVisibleTo($q, $user)` on both models, applied at the query layer. Keeps client LIST global (techs need to find any client) but scopes visit/appointment history within client profiles.
- **`view_all_data` permission** — grantable, non-default, NOT admin-only (admins implicit via `Gate::before`). `User::seesAllData()` = `hasPermission('view_all_data')`.
- Write path: scoped techs are forced to self as `technician_id`; all-data users can assign another tech via selectors.
- **`pending_reminders` KPI → null for scoped techs** (reminders are client-global — v1 decision).
- Visits backfilled: `technician_id = created_by` for all existing rows.
- Client list global; client-profile history scoped.
- Spec: `docs/superpowers/specs/2026-06-12-technician-data-scoping-design.md`. Plan: `docs/superpowers/plans/2026-06-12-technician-data-scoping.md`.

**Done**
- Migration: `technician_id` nullable FK on `service_visits` (after `created_by`) + `appointments` (after `client_id`), with backfill.
- `User`: `view_all_data` in `PERMISSIONS`; `seesAllData()` helper.
- `ServiceVisit` + `Appointment`: `technician_id` fillable, `technician()` relation, `scopeVisibleTo`.
- `ServiceVisitController`: index scoped; store forces self; show 403 guard; create passes `technicians` prop.
- `AppointmentController`: all 3 index queries scoped; store + update + updateStatus scoped + guarded.
- `DashboardController` + `ReportController`: scoped KPIs/chart/transactions via `$scopeId`.
- `ReportService::kpis/servicesByType/transactions`: all accept `?int $technicianId` — null = global, non-null = filtered.
- `PaymentController` + `DocumentController`: private `authorizeVisitScope` helper, 403 on each single-resource route.
- `ClientController::show`: eager loads for visits + appointments get `->visibleTo($user)`.
- Frontend: technician selector on `ServiceRecords/Create` + `AppointmentModal`; "My Jobs" / "Service Records" dynamic title on `ServiceRecords/Index`; `view_all_data` label in `UserModal`.
- 23 new tests in `TechnicianScopingTest`; regressions fixed in ServiceVisit/Appointment/Payment/Document/Dashboard test files.

**Tests:** 187 passed / 727 assertions.

**Notes / bugs caught in review**
- `AppointmentController::update` + `updateStatus` were unguarded — scoped tech could PATCH any appointment. Fixed with `abort_unless(...visibleTo()...exists(), 403)`.
- `ClientController::show` leaked full visit + appointment history to scoped techs. Fixed with `->visibleTo($user)` in eager-load constraints.
- `AppointmentController::update` dropped `technician_id` (not in `appointmentData()`). Fixed with explicit assignment (all-data = submitted, scoped = keep existing).
- Real test runner discovery: agent shell is Git Bash (no PHP); tests only run via `docker exec saifzz-aircond-laravel.test-1 php artisan test`. Saved to memory.

**Next:** Migration to live DB + owner visual review.

---

## Session 16 — 2026-06-12 — UI/UX Upgrade Round 1

**Goal:** Raise the live app from ~60% visual match with the mockup (`index.html`, Service System v4) to a close, consistent match across all admin pages + the client portal — responsive on phone/iPad/desktop, full-feature datatables, polished toast/confirm, proper error display.

**Decisions**
- Spec + plan: `docs/superpowers/specs/2026-06-12-ui-ux-upgrade-round-1-design.md`, `docs/superpowers/plans/2026-06-12-ui-ux-upgrade-round-1.md`.
- Datatable: hybrid reusable `<DataTable>` — client-side sort/search/paginate by default, server mode (sort/dir/per_page/search params) for large/growing tables (service records, appointments).
- Toast + confirm: **SweetAlert2** themed to the navy design system (`lib/swal.js`), flash→toast bridge composable; native `confirm()` removed.
- Icons: adopted `@tabler/icons-vue` (mockup uses Tabler); hand-rolled SVG paths retired from the shell.
- Mobile tables: adaptive — table on md+, stacked cards on phones (DataTable `#card` slot).
- Execution: subagent-driven; foundation contracts written inline by the controller, page refactors delegated to per-task subagents with review.

**Done**
- Shared layer: `lib/swal.js` (toast + confirmDanger/confirmAction), `composables/useFlashToast.js`, `lib/badges.js`, `Components/DataTable.vue` (hybrid), `StatCard`, `Badge`, `WarrantyPill`, `Card`, `PageHeader`, `FormErrorSummary`, restyled `InputError`.
- Shell: `AdminLayout` rebuilt — sidebar sections (Main/Management/Portal), Tabler icons, bottom user block, Reminders nav badge; `HandleInertiaRequests` shares `reminderCount` (gated `view_clients`).
- Backend (TDD): `ClientController@index` enriched per-row (last service, service types, units, next service, amount, warranty state/label) + server sort/per_page — **fixed a latest-visit eager-load bug** (`->limit(1)` limited to one row globally) via a `latestVisit` `latestOfMany` relation; `ServiceVisitController@index` + `AppointmentController@index` gained server search/sort/per_page (calendar/stats stay full-month, table paginates).
- Pages refactored onto the shared layer: Clients (Index rich DataTable, Show/Create/Edit), Users, Fees, ServiceRecords (Index server table, builder, Show), Appointments (calendar + server table), Reminders, Payments (Show/Return), Dashboard (KPIs/period/CSS bars/txn table, launcher fallback kept), Documents (invoice/receipt Blade), Portal (Login/Show/PortalLayout, mobile-first; security behavior unchanged).
- Confirmations use SweetAlert; validation errors via restyled `InputError` + `FormErrorSummary`.

**Tests:** 161 passed / 630 assertions (was 151/458 — +10 tests: reminderCount share, clients enrichment + eager-load regression, service-visit table params, appointment table params).

**Notes / follow-ups**
- Visual fidelity not yet eyeballed by a human — owner to review via `npm run dev` at phone/iPad/desktop widths.
- Light spots to check in the visual pass: MonthCalendar dot polish, Fees Repair-option field hidden (form submit), Reminders card fields (`address`/`service_type`/`units`) render only if `ReminderService` provides them.

**Next:** Owner visual review of Round 1; then brainstorm dashboard logic + service-assignment + technician data scoping (revenue visibility) as a dedicated session. BayarCash go-live + deployment still pending.

---

## Session 15 — 2026-06-12 — Users Management (module 1, last feature module)

**Goal:** Build module 1 — admin-only staff management screen; the final feature module.

**Decisions**
- One page, modal CRUD (`Users/Index` + `UserModal`) — no separate show page (YAGNI).
- New `UserController`; temp password set by admin at create, self-serve change via Profile.
- Permission editing via checkbox grid of `User::PERMISSIONS` minus `ADMIN_ONLY_PERMISSIONS` (8 grantable permissions); server re-filters via `grantPermission()` — P1 silently drops `manage_users`.
- Guard rails: cannot deactivate/demote self (422); cannot edit another admin (403); admins are immutable in this UI (single-admin assumption).
- No delete — deactivate only (`active=false`); preserves `created_by` history on visits.
- `abort_if(422)` used for self-deactivation (not `ValidationException`) — Inertia middleware conflict with the latter causes a PHP runtime error in non-JSON test context; `abort_if` returns a bare 422 which Inertia re-renders page state on (toggle snaps back — acceptable UX for this edge case).
- Work directly on `main` — no feature branches (user preference).

**Done**
- `UserFactory` `admin()` and `technician()` states added.
- `StoreUserRequest` + `UpdateUserRequest` (validate permissions against all `User::PERMISSIONS`; model layer filters admin-only at `grantPermission()`).
- `UserController` — `index` (list all users + grantablePermissions prop), `store` (creates technician; re-grants explicit permissions through `grantPermission()` so admin-only entries are silently dropped, and empty array overwrites defaults), `update` (403 on admin target), `toggleActive` (422 on self-deactivation).
- Routes under `can:manage_users` middleware group.
- `Pages/Users/Index.vue` — staff table (name/email/role badge/permissions count/active toggle switch/edit button for technicians).
- `Pages/Users/Partials/UserModal.vue` — create/edit modal; name + email + password (create only) + 8-permission checkbox grid with human labels; `useForm` pattern matching FeeModal.
- Users nav item in AdminLayout (after Clients, gated `manage_users` — admin-only).
- 13 feature tests: authorization (guest/technician-with-all-grantable → 403), index, store (default/custom/silently-dropped/dupe-email/empty-permissions), update (name+perms, cannot-update-admin), toggleActive (flip×2, self-deactivation 422), P4 regression (deactivated user login blocked).

**Tests:** 151 passed / 458 assertions.

**Next:** BayarCash go-live integration + deployment.

---

## Session 14 — 2026-06-11 — History cleanup, auth UI rebrand, Notifications (module 11)

**Goal:** Post-portal housekeeping (strip co-author trailer from git history), replace the default
Breeze/Laravel auth + landing UI with the design system, and close module 11 (Notifications, v1).

**Decisions**
- **Portal access stays serial + phone-last-4 for v1.** Discussed alternatives (random serial —
  rejected: fixes enumeration but not secrecy of a printed 6-digit value; capability-URL QR token —
  the right long-term fix; OTP — overkill). Ship current, demo to Khalid, amend if needed.
- **Public registration flagged, not fixed** — `/register` self-serve grants default technician
  permissions (sees client data). Logged under 🔒 Security in STATUS; module 1 closes it.
- **Module 11 kept thin** — no DB/routes; one WhatsApp builder per side (PHP + JS), Cloud API
  lands behind the PHP service later.

**Done**
- **History rewrite:** `git filter-branch --msg-filter` stripped the `Co-Authored-By` trailer from
  the root commit (all 52 SHAs rewritten); force-pushed; backup refs + reflog purged.
- **Auth UI rebrand:** `GuestLayout`, `Auth/Login`, `Auth/Register` onto design tokens;
  `Welcome.vue` → branded landing with **Customer portal** + **Staff sign in** entry cards;
  staff login links customers to the portal.
- **Module 11 — Notifications:** `App\Services\Notifications\WhatsApp` (`normalize`/`link`,
  WhatsAppTest ×5) + JS mirror `resources/js/lib/whatsapp.js`; Reminders/Index, Clients/Show,
  Portal/Show, `PortalController::business()` all refactored onto the shared builders.
- **Sail build perms fixed** (root-owned `node_modules/.vite-temp` + `public/build` chowned).

**Tests:** 138 passed / 427 assertions.

**Next:** Users mgmt screen (module 1) — last module; also closes the public-registration hole.

---

## Session 13 — 2026-06-11 — Client Portal module (module 10)

**Goal:** Build Module 10 — public, unauthenticated self-service portal: serial + phone-last-4
gated login, client account page (next-service banner + visit history + warranty), receipt
download, WhatsApp contact/appointment links (`docs/04` §10).

**Decisions**
- **Two-factor gate (serial + phone-last-4)** because client serials are monotonic and therefore
  enumerable. The second factor (last 4 digits of the phone number on file, digits-only match)
  makes enumeration impractical. No password; no portal-user table.
- **Generic "no matching record" error** — same message whether the serial doesn't exist or the
  phone-last-4 is wrong; no oracle that tells an attacker which factor failed.
- **Rate-limited** (`throttle:5,1`) on the login POST — 5 attempts per minute, then 429.
- **Session with id-regeneration on auth** (session fixation defense); logout clears the portal
  session key completely.
- **Receipts session-scoped + paid-only** — `PortalController` re-checks that the requested txn
  belongs to the session client before rendering; cross-client and unpaid both 404 (no oracle).
  Reuses the new shared `DocumentService::receiptViewModel()` (extracted from `DocumentController`
  to avoid duplication).
- **WhatsApp links** point to the business number (`config/business.php`), prefilled for contact
  or appointment — same `wa.me` pattern as staff modules.
- **Own mobile-first layout** (`Pages/Portal/PortalLayout.vue`) — not AdminLayout; the portal is
  a separate user-facing area, needs no sidebar nav, designed for phone screens.
- **No portal-side DB writes** — read-only; contacted/appointment state lives in the staff modules.

**Done**
- **Service:** `App\Services\Portal\PortalService` — `authenticate(serial, phone4)` (digits-only
  normalisation, constant-time-safe comparison via `hash_equals`); `accountFor(client)` (history
  rows with warranty status + `next_service_date = MAX` over lines, ignoring nulls).
- **Middleware:** `App\Http\Middleware\EnsurePortalClient` registered as `portal.auth` — reads
  `session('portal_client_id')`, resolves client, shares to request; redirects to
  `portal.login` on miss.
- **HTTP:** `PortalController` — `showLogin` / `login` (rate-limited, authenticate, regenerate,
  store id, redirect to account) / `showAccount` / `logout` / `receipt` / `receiptPdf`.
  Routes prefixed `/portal` (guest login pair + `portal.auth`-gated account/logout/receipts).
- **DocumentService:** extracted `receiptViewModel(Receipt)` from `DocumentController` so both
  the staff and portal receipt views share one snapshot-to-view-data path.
- **UI:** `Pages/Portal/Login.vue` (serial + phone-last-4 form, generic error), `Pages/Portal/Show.vue`
  (client header with serial, next-service banner, history cards with warranty badges and
  per-paid-visit receipt links, WhatsApp contact + appointment CTAs), `Pages/Portal/PortalLayout.vue`
  (mobile-first shell, no sidebar).
- **Tests:** `PortalServiceTest` ×5, `PortalAuthTest` ×6, `PortalAccountTest` ×3, `PortalReceiptTest` ×5.
  **Full suite: 133 passed / 421 assertions** on Postgres. Pint clean.

**Notes**
- `DocumentService::receiptViewModel()` extraction was a non-breaking refactor — `DocumentController`
  now delegates to it; all existing document tests remained green.

**Next**
- Module 11 — Notifications: WhatsApp abstraction layer, scheduled/triggered reminders.

---

## Session 12 — 2026-06-11 — Dashboard & Reports module (module 9)

**Goal:** Build Module 9 — aggregated read-only insight: KPI cards, services-by-type chart,
mini appointments calendar, recent-transactions table, transactions CSV export (`docs/04` §9).
Brainstormed → spec'd (`docs/superpowers/specs/2026-06-11-dashboard-reports-design.md`) →
implemented directly → tests → eyeballed.

**Decisions (brainstorm)**
- **Access = adapt by permission.** `/dashboard` stays everyone's landing page; the reporting
  payload (KPIs/chart/txns/calendar) renders only for `view_reports`, else the module launcher.
  Data gated server-side, not the route — technicians keep their home page. CSV export = own
  route gated `export_data`.
- **One shared period filter** (All time / This month / This week / Today) scopes the
  services-by-type chart, the transactions table, **and** the CSV export, so the export always
  mirrors the screen. Period changes via Inertia GET round-trip (`?period=`); the mini-calendar
  month nav uses a separate `?month=` param (both preserved across each other).
- **No chart dependency** — services-by-type renders as CSS horizontal bars (mockup-style).
- **Scope:** no full paginated transactions index page, no revenue line chart (recent table +
  export cover v1).

**Done**
- **Service:** `App\Services\Reports\ReportService` (injects `ReminderService`). `kpis()` —
  Total Clients (+this-month delta), Revenue this month (paid-only by `paid_at`) + MoM % (null
  when no prior month), All-time Revenue, Pending Reminders. `servicesByType(period)` — service
  line counts by `service_type` scoped by `visit_date`. `transactions(period, ?limit)` — joined
  to client via visit, windowed by `COALESCE(paid_at, created_at)`, newest first (`null` limit
  = export, no cap). Private `range()` maps period → Carbon bounds.
- **HTTP:** `DashboardController@index` replaces the `/dashboard` closure (reads `?period`/`?month`,
  validates, branches on `view_reports`). `ReportController@exportTransactions` streams a CSV
  (`Txn ID, Date, Client, Serial, Amount, Method, Status`) via `streamDownload`, gated
  `export_data`, filename `transactions-{period}-{date}.csv`. Route `reports.transactions.export`
  added inside the auth group.
- **UI:** rewrote `Dashboard.vue` — `canReport` branch: 4 KPI stat cards (Pending Reminders card
  links to `reminders.index`), period tabs, reused `Appointments/Partials/MonthCalendar` (mini)
  + day panel, Services-by-Type CSS bars (width = count/max, per-type colour), transactions table
  with status badges + Export CSV `<a>` (gated `export_data`, carries period). Launcher fallback
  for non-reporting users now has live Clients/Service-Records/Appointments links (was a dead
  placeholder).
- **Tests:** `ReportServiceTest` ×6 (paid-only + month revenue + MoM, MoM null, clients delta,
  pending-reminders KPI, services-by-type per period, transactions period + newest-first) +
  `DashboardTest` ×6 (guest redirect, `view_reports` payload, technician launcher, export
  `export_data` 403/200, CSV header+row, period filter). Time frozen via `travelTo`. **Full suite:
  114 passed / 365 assertions** on Postgres. Pint clean.

**Notes**
- HMR confirmed working on WSL/ext4 (dev server `npm run dev`, `public/hot` present) — a live
  `Dashboard.vue` tweak hot-pushed without reload. Clarified the model: HMR live-updates only an
  already-connected tab on **frontend** edits; backend/route/prop changes and first visits to a
  new page still need a navigation/reload.
- PHP 8.4 `fputcsv` quotes the `"Txn ID"` header (contains a space) — test asserts the unquoted
  remainder.
- Demo `Demo …` clients (from session 11) still seeded in the dev DB for eyeballing.

**Next**
- Module 10 — Client Portal: public, serial-gated (no password), client header + next service
  date + history with warranty status, receipt download, WhatsApp (`docs/04` §10). Reuses the
  Documents routes/templates and the Reminders next-service logic.

---

## Session 11 — 2026-06-11 — Reminders module (module 8)

**Goal:** Build Module 8 — surface clients due/overdue for service and drive follow-up
(`docs/04` §8). Brainstormed → spec'd (`docs/superpowers/specs/2026-06-11-reminders-design.md`)
→ implemented directly (user opted to finish implementation first, then tests), then added the
test suite + eyeballed with seed data.

**Decisions (brainstorm)**
- **Contacted state → dedicated `reminder_contacts` table** (one row per client = contacted;
  `contacted_at` + `contacted_by`). The due list stays derived; contacted is a separate overlay
  fact. Chosen over a `clients.last_contacted_at` column (no audit) or ephemeral page state.
- **Gated by `view_clients`** — reminders is a read-side list of clients to follow up; default
  technicians hold it; matches how Documents reused it. The contacted toggle is a light write
  under the same gate.
- **Due-date basis = `MAX(next_service_date)` across all of a client's service lines** — latest
  recommendation wins and self-clears when a newer visit sets a later date; null dates (Repair/Gas
  strip them, R2) don't contribute, so a client still surfaces from an earlier cleaning's
  recommendation. Chosen over "latest visit's date only".
- **WhatsApp = v1 inline `wa.me`** prefilled text (same pattern as `Clients/Show`); module 11
  (Notifications) abstracts it later. No automated sending in v1. Per-**client** reminders, no
  auto-reset cycle.

**Done**
- **Schema/model:** `reminder_contacts` migration (unique `client_id` cascade, `contacted_at`,
  `contacted_by` nullOnDelete) + `ReminderContact` model (`client`, `contactedBy`); `Client
  hasOne reminderContact`.
- **Service:** `App\Services\Reminders\ReminderService::dueList()` — pg aggregate query
  (`service_lines`→`service_visits`→`clients`, left-join `reminder_contacts`), per-client
  `next_due = MAX(next_service_date)`, `havingRaw` ≤ end-of-month, partition overdue vs
  due-this-month in PHP, sort by `next_due` asc, returns `{overdue, due_this_month, stats}`.
- **HTTP:** `ReminderController@index` (renders `Reminders/Index`) + `@toggleContacted`
  (row present→delete, absent→create with `auth()->id()`). Routes `reminders.index` (GET),
  `reminders.contacted` (PATCH), gated `can:view_clients`.
- **UI:** `Reminders/Index.vue` — 3 stat cards, Overdue (danger accent) + Due-this-month (warn
  accent) card sections, per-card WhatsApp / Set-appointment (`appointments.index?client=ID`,
  module-7 preset modal) / Mark-contacted–Undo, empty state. Nav item (bell icon) gated
  `view_clients`. Date formatting via string-slice to dodge tz drift.
- **Tests:** `ReminderServiceTest` ×6 (partition + future-excluded, MAX-wins, null-next excluded,
  soft-delete excluded, contacted flag, last-service = latest visit) + `ReminderTest` ×4 (guest
  redirect, `view_clients` gate, derived-list render, contacted toggle create→delete). Time frozen
  via `travelTo`. **Full suite: 102 passed / 314 assertions** on Postgres. Pint clean.

**Notes**
- HMR clarified: app had been served via `npm run build` (static) — new nav item needed a manual
  reload. Moved to `npm run dev` (Vite HMR, ready in ~0.5s on ext4) as the default for eyeballing
  now that the project lives on WSL native filesystem (session-6's "Vite slow on Windows" note is
  stale). Captured as a working preference.
- Seeded `Demo …` clients in the dev DB for eyeballing (overdue ×2, due ×2, future hidden,
  one contacted) — remove when no longer needed.

**Next**
- Module 9 — Dashboard & Reports: KPI cards (clients, revenue, pending reminders), services-by-type
  chart, recent transactions, CSV export (gated `export_data`) (`docs/04` §9). Pending-reminders
  KPI reuses this module's `ReminderService`.

---

## Session 10 — 2026-06-11 — Appointments module (module 7)

**Goal:** Build Module 7 — scheduling: month calendar + list view, create/edit, status lifecycle, summary stats (`docs/04` §7). Followed the locked per-module pattern (controller + requests + `can:` gates + Inertia pages + feature tests), TDD red→green.

**Decisions**
- Whole module gated by **`set_appointment`** (the catalogue's create/edit-appointments permission). Viewing the calendar = same gate; no separate "view appointments" permission exists.
- **`clients.lookup` gate relaxed `record_service` → `view_clients`** so appointment-setters (default tech has `view_clients`, not necessarily `record_service`) can search clients in the modal. Safe: the recorder test already grants `view_clients`, so no breakage.
- **Client is optional** on an appointment (migration FK is nullable / "loosely linked") — a prospective lead can be booked before any client record exists. The modal lets you pick an existing client (prefills phone/address) or type details manually.
- **Modal-based create/edit** (like Fees), matching the mockup `apptModal`, rather than a full page — the Index already holds the appointment objects so no separate edit GET is needed.
- `date` + `time` are two user-facing fields folded server-side into one `datetime` (precise per-field validation messages).

**Done**
- **Model:** `Appointment` gains `SERVICE_TYPES`, `STATUSES`, `TRANSITIONS` (pending→confirmed→done/cancelled; done/cancelled terminal) + `canTransitionTo()` + `scopeForMonth('YYYY-MM')`.
- **HTTP:** `AppointmentController` — `index` (month-scoped list + today's list + summary stats), `store`, `update`, `updateStatus` (validates target ∈ catalogue, `abort_unless($appt->canTransitionTo($target), 422)`). `StoreAppointmentRequest` (+ `UpdateAppointmentRequest` subclass) authorize `set_appointment`, MY phone regex, `client_id` nullable-exists, expose `datetime()`/`appointmentData()` helpers. Store/update redirect to the new appointment's month so it's visible.
- **Routes:** `appointments` group gated `can:set_appointment` (index/store, `{appointment}` put, `{appointment}/status` patch).
- **UI:** `Appointments/Index.vue` (calendar + selected-day panel + stat cards + month table with status/type badges and inline lifecycle buttons + prev/next month nav), `Partials/MonthCalendar.vue` (self-contained month grid, day dots, today ring), `Partials/AppointmentModal.vue` (debounced client search via `clients.lookup`, date/time, optional units/amount, prefill on edit via string-slice to dodge tz drift). Nav item (calendar icon) gated `set_appointment`. "New appointment" CTA on `Clients/Show` → `appointments.index?client=ID` (auto-opens modal with preset client).
- **Tests:** `AppointmentTest` ×11 — gate/guard, store (combined datetime, pending default, client-less), validation, bad phone, update, legal transition, illegal transition→422, month scope + stats. **Full suite: 92 passed / 275 assertions** on Postgres. Pint clean.

**Next**
- Module 8 — Reminders: derived overdue/due-this-month list from next-service dates, per-client WhatsApp + "set appointment", contacted toggle (`docs/04` §8). Reuses this module's preset-client appointment flow.

---

## Session 9 — 2026-06-11 — Documents module (module 6)

**Goal:** Build Module 6 — invoice + receipt as an on-screen view **and** a downloadable PDF, from frozen snapshots, matching the mockup. Brainstormed → spec'd → planned → executed TDD (`docs/superpowers/specs/2026-06-11-documents-pdf-design.md`, `docs/superpowers/plans/2026-06-11-documents-pdf.md`).

**Decisions (brainstorm)**
- Invoice generated **lazily** (firstOrCreate on first view/download of a pending txn), mirroring how Receipt is issued. Receipts still created by Payments on success.
- **Single source of truth:** one Blade per doc type — the *view* route returns it as HTML, the *download* route runs the **same** Blade through dompdf. No Vue re-implementation → no drift.
- **Links only**, no Documents index page in v1.
- Gated by **`view_clients`** (documents = read access).

**Done**
- **Dependency/config:** added `barryvdh/laravel-dompdf` (v3.1); `config/business.php` (`BUSINESS_*`) supplies the issuer header, frozen into each snapshot so later detail changes don't mutate old docs.
- **SnapshotBuilder:** extracted the snapshot out of `PaymentService` into `App\Services\Documents\SnapshotBuilder::forTransaction` (injected into `PaymentService`, used by both doc types). Completed it with `warranty_months` + per-line `next_service_date` + `business` — the keys the mockup needs that the old receipt snapshot lacked. Blades render defensively (missing key → row omitted) so legacy receipts still render.
- **DocumentService:** `invoiceFor` mints one Invoice per txn (`INV-YYYYMMDD-NNN`, daily sequence, idempotent), freezing the snapshot.
- **HTTP:** `DocumentController` — invoice/receipt × view/pdf (4 routes, gated `can:view_clients`). Invoice renders for any txn; receipt **404s** when unpaid. PDFs download as `{number}.pdf`. Renders strictly from the snapshot.
- **Blades:** `documents/{layout,invoice,receipt}.blade.php` — dompdf-safe (table-based, CSS 2.1, no Tailwind/flex/emoji), matching the mockup `.rc` card.
- **UI:** View/Download links on `ServiceRecords/Show` (invoice when pending, receipt when paid), `Payments/Return` (replaced the "PDF coming" notice), and `Clients/Show` history rows. Plain `<a>` (routes return Blade/PDF, not Inertia). Assets build clean.
- **Tests:** SnapshotBuilderTest (1), InvoiceGenerationTest (1), DocumentControllerTest (7 — HTML view has number, PDF `%PDF`+attachment, receipt 404 unpaid, `view_clients` gate, guest redirect). **Full suite: 81 passed / 228 assertions** on Postgres.

**Notes**
- One transient full-suite failure (`AuthenticationTest` — "Vite manifest not found") occurred when tests ran *during* `npm run build`; re-ran after the build finished → clean. Not a code issue.
- `public/build` is gitignored (assets built at deploy), so the asset rebuild isn't in the commit — consistent with prior modules.

**Next**
- Module 7 — Appointments: month calendar + list, create/edit, status lifecycle (`docs/04` §7).

---

## Session 8 — 2026-06-11 — Payments module (module 5)

**Goal:** Build Module 5 — Cash manual confirm + a BayarCash (DuitNow QR) redirect flow behind a swappable gateway interface, shipped with a working stub so go-live = fill creds + flip one env var. Executed `docs/superpowers/plans/2026-06-11-payments-bayarcash-stub.md` task-by-task (TDD).

**Done**
- **Gateway seam:** `PaymentGateway` interface + two drivers — `FakeBayarCashGateway` (active stub) and `BayarCashGateway` (scaffolded live, inert without creds) — bound by `config('services.bayarcash.driver')` via `PaymentServiceProvider`. DTOs (`PaymentIntentData`/`Result`, `CallbackResult`), `PaymentStatus` enum, `Checksum` (HMAC-SHA256 make/verify), shared `CallbackParser`. Go-live constants centralized + `TODO(go-live)` marked. (Tasks 1–2, committed prior session start.)
- **Cash path:** `PaymentService::confirmCash` marks paid + issues a Receipt (`RCP-YYYYMMDD-NNN`, daily sequence, one-per-txn via `firstOrCreate`, frozen client+lines snapshot). Idempotent. Gated `collect_payment`.
- **Gateway path:** `startGateway` creates an intent (persists `gateway_ref`, method `DuitNow QR`), redirects to the hosted page. `HandleGatewayCallback` action applies a verified callback idempotently — row-locked, amount-guarded, already-paid short-circuit, unknown-order ignored, failed→failed (no receipt).
- **HTTP:** `PaymentController` (show/cash/pay/return), `PaymentWebhookController` (verify → 403 on bad sig, else 200), `StubGatewayController` (hosted blade page + `simulate` that fires a signed callback through the REAL webhook path). Routes: payment routes gated `collect_payment`; public CSRF-exempt `webhooks/bayarcash`; stub routes guarded to `driver=fake`. CSRF exempt `webhooks/*` + `dev/bayarcash/*`.
- **UI:** `Payments/Show` (method chooser) + `Payments/Return` (result + receipt number, retry on failed); gated "Collect payment" CTA + "Paid · View receipt" replacing the old static notice on `ServiceRecords/Show`. Assets build clean.
- **Env:** `BAYARCASH_*` added to `.env`/`.env.example` (driver=fake, stub secret; live creds commented).
- **Tests:** new ChecksumTest (2), FakeBayarCashGatewayTest (3), PaymentTest (6), PaymentWebhookTest (6), StubGatewayTest (3). **Full suite: 72 passed / 202 assertions** on Postgres.

**Notes**
- Deviation from spec (documented in plan): `ServiceVisitController@store` redirect left as `service-records.show` — a `record_service`-only tech lacks `collect_payment`, so auto-redirecting to the gated payment page would 403; the gated CTA covers it instead.
- Receipt **record** exists now; receipt/invoice **PDF** is Documents (module 6).

**Next**
- Module 6 — Documents: invoice + receipt PDF (dompdf), rendering the snapshot the Receipt already stores.

---

## Session 7 — 2026-06-11 — Service Records module (module 4)

**Goal:** Build the "Add Service Record" flow — the operational heart.

**Done**
- **Backend:** `ServiceVisitController` (index/create/store/show). `StoreServiceVisitRequest` validates client mode (existing id or inline new client w/ MY phone), visit meta, payment method, and a nested `lines[]` array; `withValidator` adds per-line conditional rules — unit_type required for Cleaning/Installation/Troubleshoot, gas_option for Gas, repair_desc + manual rate for Repair, and a fee-must-exist check for fee-driven lines.
- **Business rules enforced server-side:** R1 rate snapshotted from the fee book (client-sent rate ignored except Repair flexible); R2 next_service stripped for Gas/Repair; R3 unit_type/notes stripped for Repair; R4 visit+lines+Transaction created in one DB transaction (status pending, `TXN-YYYYMMDD-NNN` daily sequence); R5 warranty_end derived; R8 subtotal/total.
- **`ClientController@lookup`** — JSON client search (name/serial/phone, `ilike`) for the picker, gated `can:record_service`; route ordered before `clients/{client}`.
- **UI:** `Create` builder — `ClientPicker` (existing async search via lookup, or new inline), adaptive `ServiceLineCard` (fields appear per service type, rate auto-fills from fee map and is read-only except Repair, live subtotal), sticky grand-total bar, warranty (0–6 w/ live end date) + payment-method selector. `Index` (recent records, table→card) and `Show` (navy summary, lines, totals, warranty/payment badges). Sidebar nav item (gated `record_service`).
- **Tests:** `ServiceVisitTest` — 9 / 34 assertions green, incl. rate-tamper resistance (sends rate=5, stored 60), Repair field stripping, Gas next-service strip, warranty derive, conditional validation, new-client creation, lookup JSON.

**Next**
- Module 5 — Payments: cash confirm (`collect_payment`) + DuitNow QR generate/await webhook → flip Transaction to paid, trigger Receipt (with module 6). See `docs/06-integrations.md`.

---

## Session 6 — 2026-06-11 — Service Fees module (module 3)

**Goal:** Build the price-book management module + fix dashboard to use the admin shell.

**Done**
- Dashboard now renders inside `AdminLayout` with a module launcher (was the Breeze starter page).
- **Service Fees backend:** `ServiceFeeController` (index/store/update/destroy); `StoreServiceFeeRequest` (type/mode in allowed sets, `rate` `required_unless:pricing_mode,flexible`, duplicate type+option rejected via `withValidator`); `UpdateServiceFeeRequest` (mode + rate only — identity is immutable). Update nulls `rate` when switched to flexible. All routes gated `can:edit_fees`.
- **Service Fees UI:** `Fees/Index` — price book grouped by service type with left-accent colour, mode badges, per-row edit/remove; `FeeModal` partial reused for add + edit (service_type/option locked on edit, rate hidden when flexible). Sidebar nav item added (gated `edit_fees`).
- **Tests:** `ServiceFeeTest` — 10 / 18 assertions green (gate enforcement, add, rate-required-unless-flexible, flexible null rate, duplicate rejection, update rate, switch-to-flexible nulls rate, delete).

**Notes**
- Browsing on built assets (`npm run build`) — Vite dev server is slow on Docker/Windows; only run `npm run dev` while actively editing.

**Next**
- Module 4 — Service Records (the operational heart): multi-line visit builder, rate auto-fill from fees (R1 snapshot), per-visit warranty, creates Transaction (R4).

---

## Session 5 — 2026-06-11 — Design system + Clients module (module 2)

**Goal:** Wire the design system, then build the Clients feature module end-to-end.

**Done**
- **Design tokens** (`docs/05`): `tailwind.config.js` — navy/blue scale, semantic (ok/warn/danger/wa/invoice), service-type colours, radii (ra/ral/rax), shadows (card/lift); fonts Plus Jakarta Sans + JetBrains Mono via Google Fonts in `app.css`; base bg/text.
- **`AdminLayout.vue`** — navy sidebar, off-canvas drawer < lg, sticky top bar, user menu, flash toast; nav is data-driven and permission-gated.
- **Inertia share** (`HandleInertiaRequests`): `auth.can` (effective permission map, admin-implies-all), `auth.isAdmin`, `flash.success/error`.
- **Clients backend:** `ClientController` (full resource minus API), `StoreClientRequest`/`UpdateClientRequest` (MY mobile regex `^01\d-?\d{7,8}$`), routes gated `can:view_clients` (read) / `can:edit_client` (write).
- **Clients UI:** `Index` (debounced search over name/serial/phone, service-type filter tabs, desktop table + mobile card reflow, pagination), `Create`/`Edit` (shared `ClientForm` partial), `Show` (navy profile header + WhatsApp link, service history with warranty + payment badges, appointments).
- **Tests:** `tests/Feature/ClientTest.php` — 8 tests / 33 assertions, all green on Postgres (guest redirect, gate enforcement, serial gen R6, phone validation, `ilike` search, soft-delete R7).

**Notes**
- Search uses Postgres `ilike` → tests must run on pg (testing DB already existed), not sqlite.
- `assets build clean`; `AdminLayout` will also host future modules' nav items as they ship.

**Next**
- Module 3 (Service Fees) then module 4 (Service Records) per `docs/04` dependency order.

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
