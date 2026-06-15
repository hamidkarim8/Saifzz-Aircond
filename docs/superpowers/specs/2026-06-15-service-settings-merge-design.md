# CHG-004: Merge Service Types + Fees into One Page

**Date:** 2026-06-15
**Status:** Approved

## Summary

Merge the two Settings sub-pages ("Service Types" at `/service-types` and "Service Fees" at `/fees`) into a single "Service Settings" page at `/service-types`. Remove the separate Fees nav entry. Two action buttons at page top launch their respective modals.

## Route Changes

- `service-types.index` (GET `/service-types`) — controller now returns both `serviceTypes` + `feeGroups` + `modes` props.
- `fees.index` (GET `/fees`) — redirect to `service-types.index` (prevents broken bookmarks).
- All mutation routes unchanged: `fees.store`, `fees.update`, `fees.destroy`, `service-types.store`, `service-types.update`.

## Permission Gate

Entire merged page gated by `manage_service_types` (same as before). `edit_fees` check removed from page render; it remains on mutation routes (`fees.store/update/destroy`).

## Sidebar

- Remove `fees.index` nav entry.
- Rename `service-types.index` entry label: "Service Types" → "Service Settings".
- Permission + `adminOnly` flags unchanged (`manage_service_types`, `adminOnly: true`).

## Page Layout (`ServiceTypes/Index.vue`)

**Header:** "Service Settings" (h1 in topbar slot).

**Top-right action buttons (two):**
- "New Service Type" — expands add-row inline (existing behaviour).
- "Set Fee" — opens `FeeModal` in add mode.

**Section 1 — Service Types**
- Card titled "Service Types".
- Existing inline-edit list, toggle `requires_next_service`, add-row at bottom (unchanged logic).

**Section 2 — Fee Schedule**
- Info banner (rates auto-applied, gas/repair notes) above card.
- Card titled "Fee Schedule" with existing fee table (grouped by service type, edit/delete per row).
- `FeeModal` imported here; used for both "Set Fee" button and row-level "Edit".

## File Changes

| File | Action |
|------|--------|
| `app/Http/Controllers/ServiceTypeController.php` | `index()` also queries `ServiceFee` data + passes `feeGroups`, `modes` props |
| `routes/web.php` | `fees.index` GET → redirect to `service-types.index` |
| `resources/js/Pages/ServiceTypes/Index.vue` | Add fee section + FeeModal + two action buttons |
| `resources/js/Pages/Fees/Index.vue` | Delete (absorbed) |
| `resources/js/Pages/Fees/Partials/FeeModal.vue` | Move → `resources/js/Pages/ServiceTypes/Partials/FeeModal.vue` |
| `resources/js/Layouts/AdminLayout.vue` | Remove fees nav entry; rename service-types label |

## What Is NOT Changed

- `ServiceFeeController` — mutation methods (`store`, `update`, `destroy`) unchanged.
- `ServiceTypeController` mutation methods (`store`, `update`) unchanged.
- `FeeModal` internal logic unchanged (same form fields, same routes, same Repair/flexible behaviour).
- All backend validation, permission guards on mutation routes.

## Testing

- Manual: navigate to `/service-types` as admin — both sections visible, both modals open, add/edit/delete fees and types work.
- Manual: navigate to `/fees` — redirects to `/service-types`.
- Manual: sidebar shows one "Service Settings" entry; no "Service Fees" entry.
- Existing test suite should stay green (no model/controller logic changes, only a redirect added).
