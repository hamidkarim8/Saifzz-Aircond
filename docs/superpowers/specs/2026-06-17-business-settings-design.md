# Business Settings — Design

Date: 2026-06-17
Status: Approved (brainstorming) — pending spec review
Branch: `dev`

## Goal

Make the system's business-facing details dynamic and admin-editable, per-tenant:

1. **Official logo** swapped in statically everywhere (sidebar, staff login, welcome, portal) + favicon.
2. **Invoice/receipt identity** (business name, address, phone, SSM registration no.) editable through an admin settings page with a **live preview**, and rendered on the documents (including the logo on the PDF).
3. **Google Review QR** — admin-uploadable per tenant; a "Google Review" button on the payment-received view opens a modal showing the QR for the customer to scan.
4. A consolidated **Business Settings** nav hub that also absorbs the existing (already-shipped) Payment Settings page.

All editable settings are **per-tenant** (system is multi-boss: Khalid + Saifzz, each its own tenant root). Mirrors the existing `tenant_gateways` / `PaymentGatewayController` / `PaymentSettings/Index.vue` pattern.

## Non-goals

- Dynamic/uploadable logo (deferred — static swap now; revisit if a boss needs distinct branding).
- Editing the global price book (`service_fees` stays global by design).
- Changing portal-facing customer pages beyond the logo swap.

## Background (current state)

- Invoice/receipt header reads `config('business.name|address|phone')` (global env). No SSM field, no UI. `SnapshotBuilder::forTransaction()` freezes business details into each document snapshot so reprints are stable.
- `resources/views/documents/layout.blade.php` renders a text-only centered header; `documents/invoice.blade.php` + `documents/receipt.blade.php` extend it.
- Logo = `IconAirConditioning` Tabler vector in a primary box; used in `AdminLayout.vue` (sidebar), `GuestLayout.vue` (staff login), `Welcome.vue`, `Portal/Login.vue`.
- `app.blade.php` has no `<link rel="icon">` — browser uses default `/favicon.ico`.
- Payment gateway settings ALREADY shipped (session 41, FEAT-016): `tenant_gateways` table, `PaymentGatewayController` (GET/PUT `/payment-settings`, `can:manage_users`), `PaymentSettings/Index.vue`, nav item "Payment Settings" (`adminOnly`). This page is relocated, not rebuilt.
- Payment-received UI: `ServiceRecords/Show.vue` paid block (~line 150).

## Architecture

### Data layer

New table `business_settings`:

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `tenant_id` | bigint, unique, FK→`users.id` cascadeOnDelete | one row per tenant root |
| `business_name` | string nullable | |
| `address` | text nullable | |
| `phone` | string nullable | |
| `ssm_no` | string nullable | e.g. `202603093151 (003839732-K)` |
| `google_review_url` | string nullable | optional clickable fallback |
| `google_review_qr_path` | string nullable | path on `public` disk |
| timestamps | | |

Model `App\Models\BusinessSetting`:
- `fillable` for all editable columns; FK relation `tenant()`.
- Static `forTenant(?int $tenantId): array` resolver — returns the tenant's row as an array, else falls back to `config('business.*')` (and null QR/URL). Null `$tenantId` → config fallback. Keeps null-tenant test fixtures and the existing suite green; production users always have a tenant.

### Documents (invoice / receipt)

- `SnapshotBuilder::forTransaction()` — replace the three `config('business.*')` reads with `BusinessSetting::forTenant($visit->tenant_id)`; add `ssm_no` to the frozen `business` array.
- `layout.blade.php` header:
  - Add a logo `<img>` above the business name.
  - Add an SSM line under the phone (rendered only when `ssm_no` present).
  - Logo source = a **base64 data-URI** passed in as `$logo` so it renders identically in the HTML view (`documents.invoice` / `documents.receipt`) and in dompdf PDFs. Built once in `DocumentController` (and `PortalController` where it renders documents) from the resized web logo file; cached via a small helper to avoid re-encoding per request.
- Existing already-issued documents reprint with whatever was frozen at issue time (correct — historical accuracy).

### Business Settings page + controller

`App\Http\Controllers\BusinessSettingController`:
- `show()` (GET `/business-settings`) — renders `BusinessSettings/Index.vue` with the current tenant's settings (resolved via `forTenant`), the masked payment-gateway state (reuse whatever `PaymentGatewayController::index` currently exposes), and a public URL for the current QR (`Storage::disk('public')->url(...)`).
- `update()` (PUT `/business-settings`) — validates + saves identity fields (`UpdateBusinessSettingRequest`); `tenant_id` sourced server-side from `auth user` (never trusted from input). Handles QR upload: stored at `qr/tenant-{tenantId}.png` on the `public` disk (overwrites prior), saves path. `google_review_url` validated as nullable URL.
- Both gated `can:manage_users` + `adminOnly` (same gate as Payment Settings).
- **Payment tab**: keep the existing `payment-settings.update` PUT route + `PaymentGatewayController` untouched; the new page simply renders that form under a tab and posts to the existing route. No backend duplication.

Routes (`routes/web.php`): add `business-settings.show` (GET) + `business-settings.update` (PUT), behind the existing admin middleware group. `payment-settings.*` routes stay. The old `payment-settings.index` GET page route may stay as a redirect to `business-settings.show` (or be removed; redirect is safer for any bookmarks).

### Frontend

`resources/js/Pages/BusinessSettings/Index.vue` — tabbed (reuse the tab pattern from `ServiceTypes/Index.vue`):
- **Identity tab**: name / address / phone / SSM inputs + a **live preview pane** — a Vue component (`Partials/InvoicePreview.vue`) that mirrors the blade header styling (logo, name, address, phone, SSM, a couple of dummy service lines + total) and updates reactively as the admin types. Submits identity fields via Inertia PUT to `business-settings.update`.
- **Google Review tab**: optional review URL input, QR file upload (`<input type=file>` → multipart PUT), current-QR thumbnail with empty state.
- **Payment tab**: the 3 password fields relocated from `PaymentSettings/Index.vue`, posting to `payment-settings.update`.

`resources/js/Layouts/AdminLayout.vue`:
- Replace the "Payment Settings" nav item with **"Business Settings"** (`route: 'business-settings.show'`, icon `IconSettings` or `IconBuildingStore`, `adminOnly: true`) in the Settings group.

`ServiceRecords/Show.vue` — paid block (~line 150): add a **"Google Review"** button, shown only when the tenant has a QR set. Click opens a modal (reuse the app `Modal` component) displaying the QR image (from the page-provided QR URL) + the `google_review_url` as a clickable link fallback + a short "Scan to rate us on Google" caption. Controller passes the tenant's QR URL + review URL to the Show page props.

### Static logo + favicon

- Source PNG already copied to `public/img/logo.png` (2.5 MB original).
- Generate resized web variants to avoid shipping 2.5 MB on every page / into every PDF:
  - `public/img/logo-256.png` (~256px) for sidebar/login/welcome/portal + PDF header base64.
  - `public/favicon.png` (32px) + regenerate `public/favicon.ico`.
  - Generation done with an image tool during implementation (ImageMagick / GD / sharp — whichever is available in the Docker container); the resized files are committed as static assets.
- `app.blade.php`: add `<link rel="icon" href="/favicon.ico" sizes="any">` + `<link rel="icon" type="image/png" href="/favicon.png">`.
- Replace the `IconAirConditioning`-in-a-box badge with an `<img src="/img/logo-256.png">` (rounded, sized to match current badge) in `AdminLayout.vue`, `GuestLayout.vue`, `Welcome.vue`, `Portal/Login.vue`. Drop now-unused `IconAirConditioning` imports.
- Note: the logo is a detailed circular illustration; at 16–32px favicon size it reads as a small blue badge. Accepted.

### Seeding

`DatabaseSeeder` (idempotent): for Saifzz's tenant, create/update a `business_settings` row with the real SSM (`202603093151 (003839732-K)`) and seed `google_review_qr_path` pointing at the provided QR (`public/img/google-review-qr.png` copied into the `public` disk QR location, or referenced directly). Khalid's tenant left blank (falls back to config until he fills it). Uses `firstOrCreate`/`updateOrCreate`, passwords/identity safe to re-run.

## Data flow

1. Admin opens **Business Settings** → controller resolves `forTenant`, page renders identity + QR + payment tabs.
2. Admin edits identity → live preview updates → PUT saves row.
3. Admin uploads QR → stored on public disk → thumbnail + Show-page button reflect it.
4. Tech collects payment → record becomes paid → Show paid block shows **Google Review** button → modal QR.
5. Invoice/receipt generated → `SnapshotBuilder` freezes the tenant's identity (incl. SSM) → blade renders logo + identity (+ SSM) in HTML and PDF.

## Error handling / edge cases

- No `business_settings` row (Khalid, fresh tenant) → `forTenant` config fallback; documents still render; Google Review button hidden when no QR.
- Null `tenant_id` (test fixtures, legacy) → config fallback, no crash.
- QR upload validation: image mime, max size (e.g. 2 MB); overwrites prior file deterministically (`qr/tenant-{id}.png`).
- Cross-tenant safety: `tenant_id` always server-sourced; `unique(tenant_id)` prevents dupes; admin-only gate.
- dompdf logo: base64 data-URI avoids file-path/URL resolution differences between HTML and PDF rendering.

## Testing

- `BusinessSettingController`: GET renders for admin / 403 for non-admin; PUT saves identity; PUT with QR file stores + sets path; tenant isolation (boss A cannot read/write boss B's row); `google_review_url` URL validation; `tenant_id` not honored from input.
- `BusinessSetting::forTenant`: returns row when present, config fallback when absent/null tenant.
- `SnapshotBuilder`: freezes per-tenant identity incl. `ssm_no`; falls back to config when no row.
- Document render smoke test: invoice/receipt blade renders with logo + SSM without error.
- Run via `docker exec saifzz-aircond-laravel.test-1 php artisan test`.

## Deployment

- `php artisan migrate` on prod (new `business_settings` table).
- `php artisan db:seed` (or targeted seeder) to set Saifzz identity + QR.
- `npm run build` (Vite — new Vue pages/components).
- Ensure `public` storage symlink exists for QR serving (`php artisan storage:link`).
- New static assets (`logo-256.png`, `favicon.png`, `favicon.ico`) committed and shipped with the build.

## Files (estimated)

New: migration `business_settings`, `BusinessSetting` model, `BusinessSettingController`, `UpdateBusinessSettingRequest`, `BusinessSettings/Index.vue`, `BusinessSettings/Partials/InvoicePreview.vue`, resized logo + favicon assets.
Modified: `SnapshotBuilder`, `documents/layout.blade.php`, `DocumentController`, `PortalController` (logo data-URI), `app.blade.php`, `AdminLayout.vue`, `GuestLayout.vue`, `Welcome.vue`, `Portal/Login.vue`, `ServiceRecords/Show.vue` (+ controller props), `routes/web.php`, `DatabaseSeeder`.
Removed/redirected: `PaymentSettings/Index.vue` (content moved into Business Settings Payment tab; route redirect).
