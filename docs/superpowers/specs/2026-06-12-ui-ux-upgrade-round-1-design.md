# UI/UX Upgrade — Round 1 (Design Spec)

**Date:** 2026-06-12
**Status:** Approved (design), pending implementation plan
**Reference mockup:** `C:\Saifzz-Aircond\index.html` (Service System v4) — authoritative for look & feel.

## Goal

Raise the live app from ~60% visual match with the mockup to a close, consistent match across **all admin pages + the client portal**, on **phone / iPad / desktop**. Specifically:

1. Match the mockup's look (sidebar, stat cards, tables, badges, modals, toasts, portal).
2. Fully responsive and dynamic on mobile, iPad, and desktop.
3. Error/validation messages display properly and legibly on all devices.
4. **Every table** becomes a full datatable: search + pagination + sorting + filtering.
5. All confirmations and notifications use a polished toast/dialog UI (not native `confirm()` / hand-rolled flash).

This is **round 1** of the UI/UX work. A later, separate session will brainstorm dashboard logic + service-assignment + technician data scoping (NOT in this spec).

## Locked decisions

| Decision | Choice |
|---|---|
| Datatable strategy | **Hybrid** reusable `<DataTable>` component — client-side sort/search/paginate by default; server mode for large/growing tables (transactions, service records) |
| Scope | **All admin pages + client portal** |
| Toast + confirm | **SweetAlert2**, themed to the navy design system |
| Icons | Adopt **`@tabler/icons-vue`** (mockup uses Tabler; current code hand-rolls SVG paths) |
| Mobile tables | **Adaptive** — full table on md+; stacked cards on phones; iPad gets the table |
| Topbar | Keep current **user-menu** (mockup's search/bell icons are non-functional decoration) |

## Existing stack (grounding)

- Laravel 13 · Inertia + Vue 3 (`@inertiajs/vue3` ^2) · Tailwind v3 · Ziggy routes · axios present.
- Design tokens already in `tailwind.config.js` and match the mockup palette: `navy.900/800/700`, `primary` (+hover/300/50), `appbg`, `surface(.muted)`, `line(.strong)`, `ink(.soft/.muted)`, `ok/warn/danger` (+`bg`), `wa`, `invoice` (+`bg`); radii `ra/ral/rax`; shadows `card/lift`; fonts Plus Jakarta Sans + JetBrains Mono.
- `AdminLayout.vue` already has: fixed navy sidebar, responsive drawer, sticky topbar, user-menu, hand-rolled flash toast (to be replaced), permission-gated nav (flat, no sections).
- Inertia shared props already include `auth.can`, `auth.isAdmin`, `auth.user`, `flash`.

## New dependencies

- `@tabler/icons-vue` — icon parity with mockup.
- `sweetalert2` — toast + confirm dialogs.

## Architecture — shared layer (build once, reuse everywhere)

### 1. `resources/js/lib/swal.js` — navy-themed SweetAlert2 wrapper

- Pre-configured `Swal.mixin` with custom classes mapped to design tokens (navy confirm button, Jakarta font, `ra`/`ral` radii) so output matches the mockup rather than default SweetAlert styling.
- Exports:
  - `toast.success(msg)` / `toast.error(msg)` / `toast.info(msg)` — corner toast (`top-end` desktop), auto-dismiss ~2.5s, check/x/info icon per mockup toast.
  - `confirmDanger({ title, body, confirmText })` — red confirm for destructive actions (archive, deactivate). Returns a Promise; caller proceeds on `isConfirmed`.
  - `confirmAction({ title, body, confirmText })` — neutral/primary confirm for non-destructive confirmations.
- **Flash bridge:** a small composable/util watches Inertia `page.props.flash` and routes `flash.success` → `toast.success`, `flash.error` → `toast.error`. Replaces the hand-rolled toast block in `AdminLayout.vue`. Mounted once (in `AdminLayout` and `PortalLayout`, or app-level).

### 2. `resources/js/Components/DataTable.vue` — core table component

**Props**
- `columns: Array<{ key, label, sortable?, align?, formatter?, headerClass?, cellClass? }>`
- `rows: Array` (client mode) — full dataset.
- `pagination: Object` (server mode) — Laravel paginator payload (`data`, `links`, `meta`).
- `mode: 'client' | 'server'` (default `'client'`).
- `searchable: Boolean`, `searchKeys: Array<string>` (which row fields the client-mode search matches; server mode emits `search`).
- `perPageOptions: Array<number>` (default `[10, 25, 50]`), `perPage` default 10.
- `routeName: String` (server mode — Inertia GET target).
- `filterParams: Object` (server mode — extra query params, e.g. service_type, status, date range).

**Features**
- **Sort:** click a `sortable` header → cycles asc → desc → none; arrow indicator. Client mode sorts in-memory; server mode emits `sort`/`dir` and reloads via Inertia (`preserveState`, `replace`, `preserveScroll`).
- **Search:** debounced (~300ms) search box above the table. Client mode filters `searchKeys`; server mode emits `search`.
- **Pagination:** footer with page links + per-page selector. Client mode paginates the filtered/sorted array; server mode renders the paginator links (reuse existing pattern).
- **Filters:** `#filters` named slot rendered in the toolbar (filter tabs / selects / date inputs per page). Server mode merges `filterParams` into the reload query.
- **Cells:** `#cell-<key>` named slots for custom rendering (badges, warranty pills, action buttons); otherwise prints `formatter(value)` or raw value.
- **Responsive:** table markup on `md+`; on phones renders a `#card` named slot per row (stacked card). Empty state via `#empty` slot (default "No records found.").
- **A11y:** sortable headers are buttons with `aria-sort`; search input labelled.

**Server vs client guidance**
- Client mode: Clients, Users, Fees, Reminders (lists are small/stable, ≤ few hundred rows).
- Server mode: Transactions/Payments, Service Records, Appointments table (grow over time). Controllers add `sort`/`dir`/`per_page`/`search` handling where they don't already.

### 3. UI primitives (`resources/js/Components/…`) — match mockup

- `StatCard.vue` — colored top-border (variant: primary/ok/warn/danger), icon box, label, value, sub-line. Used on Dashboard + summary strips.
- `Badge.vue` — `variant` prop: `blue | green | amber | red | gray | indigo | purple` (maps to mockup `bb/bg/ba/br/bgr/bn/bp`). Used for service types + statuses. Include a `serviceType` helper mapping (Cleaning→blue, Gas Top-Up→amber, Repair→gray/red, Installation→indigo, Troubleshoot→purple) so all pages render service types identically.
- `WarrantyPill.vue` — `state: active | expiring | expired | none` with shield icon + label (e.g. "2 mos left", "Expires 20 Jul", "No warranty").
- `Card.vue` — `#header` (title + actions) + default body slot; matches `.card`/`.ch`/`.cb`.
- `PageHeader.vue` — title + optional subtitle + `#actions` slot. Rendered in the page body (not the topbar slot) to match `.ph`.
- `Icon.vue` — thin wrapper around `@tabler/icons-vue` (`<Icon name="..." />`) OR direct component imports per page; pick whichever keeps tree-shaking. (Decision deferred to implementation: prefer direct imports if `Icon.vue` would defeat tree-shaking.)
- Standardize `InputError.vue` styling (red, wraps, icon) + a `FormErrorSummary.vue` (list of validation errors shown at top of form on submit — improves mobile legibility).

### 4. `AdminLayout.vue` upgrade

- Sidebar: **section headers** (Main / Management / Portal) grouping nav items; Tabler icons; AC logo icon (`ti-air-conditioning` equivalent); **bottom user block** = avatar + name + role + logout (per mockup `.sb-ft`), replacing the current `ADMIN/TECHNICIAN` footer text.
- **Reminders nav badge** = count of due/overdue reminders. New Inertia shared prop `reminderCount` (computed in `HandleInertiaRequests`, only for users who can `view_clients`; cheap query or reuse `ReminderService`). Badge hidden when 0.
- Keep: responsive drawer + backdrop, sticky topbar, user-menu (Profile / Log out), permission-gated nav.
- Topbar flash toast block removed (now handled by `swal.js` flash bridge).

### Nav model → sections

```
Main:        Dashboard, Clients, Reminders (badge), Service Records
Management:  Service Fees, Appointments, Users
Portal:      Client Portal (link to /portal)
```
(Each still permission-gated as today. Section renders only if it has ≥1 visible item.)

## Page-by-page work

All pages adopt the shared layer (DataTable, primitives, Swal toast/confirm). Native `confirm()` calls (e.g. `Clients/Index` archive) replaced with `confirmDanger`.

| Page | Work |
|---|---|
| **Clients/Index** | DataTable (client mode). **Enrich columns** to mockup: Serial, Name+address, Phone, Last Service, Services (badges), Units, Next Service, Amount, Warranty pill, Actions. Requires controller to supply last-service date, service-type list, units, next-service date, last amount, warranty state per client. |
| **Clients/Show, Create, Edit, ClientForm** | Card/PageHeader primitives; service history with WarrantyPill + Badge; form error standardization. |
| **Users/Index + UserModal** | DataTable (client mode); active toggle; Swal confirm on deactivate; permission checkbox grid styling. |
| **Fees/Index + FeeModal** | Match mockup service-fee table (grouped, mode badges); Swal toast on save/delete. |
| **ServiceRecords/Index** | DataTable (server mode). |
| **ServiceRecords/Create (builder, ClientPicker, ServiceLineCard)** | Match mockup add-service modal styling (service blocks, fee badges, sticky total bar, warranty block); keep existing R1–R8 logic untouched. |
| **ServiceRecords/Show** | Card primitives + badges + warranty pill + document links. |
| **Appointments/Index (+ MonthCalendar, AppointmentModal)** | Calendar day-dots polish; summary StatCards; appointments **table → DataTable** (server mode) matching mockup columns (Date/Time, Client, Contact, Serial, Service, Address, Units, Amount, Status). |
| **Reminders/Index** | Reminder cards with left-border state colors (overdue/due/ok) per mockup; StatCards; Swal toast on mark-contacted. |
| **Payments/Show, Return** | Method chooser + result styling per mockup payment modal; toast on outcome. |
| **Dashboard** | StatCards (top-border variants), period filter tabs, mini calendar + day panel, Services-by-Type CSS bars, recent transactions table (DataTable), Export CSV. |
| **Documents (invoice/receipt Blade)** | Verify on-screen view matches mockup receipt/invoice styling (mono, dashed rules); these are Blade, lower priority — visual tidy only. |
| **Portal (Login, Show, PortalLayout)** | Gradient bg, mobile-first cards, next-service banner, history cards with warranty pills, receipt download, WhatsApp CTAs — polish to match mockup portal. |

### Controller / backend changes (with tests)

- **`ClientController@index`**: add per-client aggregates for the richer table (last visit date, distinct service types, total units, next-service date, last amount, warranty state). Update `ClientControllerTest` assertions.
- **Server-mode DataTables** (ServiceRecords, Appointments, Transactions): add `sort`/`dir`/`per_page`/`search` query handling in the respective controllers; update/extend feature tests.
- **`HandleInertiaRequests`**: share `reminderCount` (gated by `view_clients`).
- All **151 existing tests must stay green**; touched controllers get updated/added assertions.

## Responsive & error handling

- Tables: `md+` table, phone stacked cards (DataTable `#card` slot), iPad = table.
- SweetAlert2 toasts/dialogs are mobile-friendly by default; theme keeps them readable on small screens.
- Validation: standardized inline `InputError` (red, wraps, never overflows) + `FormErrorSummary` on submit. Action/server errors surface via `toast.error`.

## Execution plan (phased — subagent-driven)

- **P0 — Foundation:** add deps; `lib/swal.js` (theme + helpers) + flash bridge; remove hand-rolled toast.
- **P1 — Shared components:** `DataTable.vue` + primitives (`StatCard`, `Badge`, `WarrantyPill`, `Card`, `PageHeader`, `Icon`, `InputError`/`FormErrorSummary`).
- **P2 — Shell:** `AdminLayout.vue` (sections, user block, reminder badge) + `HandleInertiaRequests` shared prop.
- **P3 — Admin pages:** refactor page-by-page (Clients first — includes controller enrichment + test updates — then Users, Fees, ServiceRecords, Appointments, Reminders, Payments, Dashboard, Documents).
- **P4 — Portal:** Login/Show/PortalLayout polish.
- **P5 — Verify:** full PHP suite green; visual pass on phone/iPad/desktop widths via `npm run dev`.

## Testing strategy

- Backend: existing PHP feature suite (151/458) is the regression net; touched controllers get updated/added assertions. No new test framework.
- Frontend: no Vue unit tests currently; verification is visual via the Vite dev server at mobile/tablet/desktop breakpoints (per user preference to eyeball with `npm run dev`).

## Out of scope (this round)

- Dashboard logic redesign, service-assignment model, technician data scoping / what figures a technician sees (revenue visibility, jobs-under-me) — **deferred to a dedicated brainstorming session** after this UI round.
- BayarCash go-live, deployment doc (tracked in `docs/STATUS.md`).
- A separate deeper cosmetic polish pass the owner intends to do later.
