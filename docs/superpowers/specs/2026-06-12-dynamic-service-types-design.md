# Dynamic Service Types — Design Spec

**Date:** 2026-06-12
**Status:** Approved

## Problem

Service types are hardcoded as PHP constants in three places:
- `Appointment::SERVICE_TYPES`
- `StoreServiceVisitRequest::SERVICE_TYPES`
- `StoreServiceFeeRequest::SERVICE_TYPES`

All three are identical: `['Cleaning', 'Gas Top-Up', 'Repair', 'Installation', 'Troubleshoot']`.
Adding a new type (e.g. "Dismantle") requires a code change and redeploy.

## Goal

Allow admins and technicians to add and rename service types via a UI, without code changes.

## Data Model

New table: `service_types`

| column     | type            | notes                  |
|------------|-----------------|------------------------|
| id         | bigint PK       |                        |
| name       | string(100)     | unique, not nullable   |
| created_at | timestamp       |                        |
| updated_at | timestamp       |                        |

No soft-delete. Add and edit only — no deletion allowed.

Seeder (`ServiceTypeSeeder`) inserts the existing 5 types plus "Dismantle" on first run (uses `firstOrCreate`).

## Backend

### Model
`App\Models\ServiceType` — `$fillable = ['name']`.

### Controller
`App\Http\Controllers\ServiceTypeController`:
- `index` — renders `ServiceTypes/Index` via Inertia, passes all types
- `store` — validates, creates new type
- `update` — validates (unique except self), updates name

### Routes
```
GET  /service-types              service-types.index
POST /service-types              service-types.store
PUT  /service-types/{serviceType} service-types.update
```
All three gated by `can:manage_service_types` middleware.

### Permission
New permission `manage_service_types` added to:
- `User::PERMISSIONS` (full catalogue)
- `User::DEFAULT_TECHNICIAN_PERMISSIONS` (all new technicians get it by default)
- Not in `ADMIN_ONLY_PERMISSIONS` — admins implicitly hold all permissions already

### Validation
`name`: required, string, max:100, unique in `service_types` (except self on update).

### Remove hardcoded constants
- Delete `Appointment::SERVICE_TYPES`
- Delete `StoreServiceVisitRequest::SERVICE_TYPES`
- Delete `StoreServiceFeeRequest::SERVICE_TYPES`

Replace all `Rule::in(self::SERVICE_TYPES)` with `Rule::exists('service_types', 'name')`.

Replace all controller references (`Appointment::SERVICE_TYPES`, `StoreServiceVisitRequest::SERVICE_TYPES`) with `\App\Models\ServiceType::pluck('name')->all()`.

## UI

### New page: `resources/js/Pages/ServiceTypes/Index.vue`
- Table listing all service types
- Each row: type name + "Edit" button → inline text input + "Save" / "Cancel"
- "Add type" button at top → appends new editable row
- Duplicate name error shown inline from server validation
- Follows existing design system (card/table pattern matching Fees page)

### Nav
New entry in `AdminLayout.vue` under the `Management` section:

```js
{ label: 'Service Types', route: 'service-types.index', match: 'service-types', icon: IconCategory, permission: 'manage_service_types' }
```

Visible to any user with `manage_service_types` (admins + technicians by default).

### Existing dropdowns
`AppointmentController` and `ServiceVisitController` already pass `serviceTypes` as a prop. Those calls switch from the deleted constants to `ServiceType::pluck('name')->all()`. No changes needed to the appointment modal or service record create form.

## Migration order
1. Create `service_types` table
2. Run `ServiceTypeSeeder`
3. Remove constants, wire up new model

## Tests
- `ServiceTypeTest`: CRUD via controller (store, update), duplicate name rejected, no destroy route
- Existing `AppointmentTest` and `ServiceVisitTest`: update fixture seeder to use DB types instead of const
- Permission gate: technician can access, unauthenticated cannot
