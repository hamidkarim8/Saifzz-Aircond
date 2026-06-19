# Appointment Client Selection + Universal Add Record Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the appointment modal use an explicit Existing-client / Walk-in toggle, and show "Add Record" on every appointment row — pre-filling the service-record form from the appointment (existing client OR walk-in name/phone/address), back-linking walk-ins to the client created at record time.

**Architecture:** Backend is the source of truth: `ServiceVisitController::create()` resolves prefill (existing client vs walk-in) from the tenant-scoped appointment; `store()` back-links the appointment when a new client is created. Frontend: the appointment modal gains a two-card mode toggle (display-only, server contract unchanged); the service-record Create page accepts a `presetNewClient` prop; the appointments table shows Add Record for all rows routed via `appointment` id alone.

**Tech Stack:** Laravel 12 (PHP 8.5), Inertia + Vue 3, Pest/PHPUnit feature tests, Vite. Tests run via `docker exec saifzz-aircond-laravel.test-1 php artisan test`.

**Spec:** `docs/superpowers/specs/2026-06-19-appointment-client-selection-design.md`

---

## File Structure

- `app/Http/Controllers/ServiceVisitController.php` — `create()` derives `presetClient` / `presetNewClient` from the appointment; `store()` back-links the appointment to a newly created client.
- `tests/Feature/ServiceVisitTest.php` — feature tests for the two backend behaviours.
- `resources/js/Pages/ServiceRecords/Create.vue` — accepts `presetNewClient`, seeds the form in `new` mode.
- `resources/js/Pages/Appointments/Partials/AppointmentModal.vue` — two-card Existing-client / Walk-in toggle.
- `resources/js/Pages/Appointments/Index.vue` — Add Record on every row, routed via `appointment` id.

`ClientPicker.vue` needs **no change**: it already renders `form.new_client` fields whenever `form.client_mode === 'new'`, and Create.vue sets that mode before the picker mounts.

---

## Task 1: Backend — `create()` derives prefill from the appointment

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php:59-92` (the `create()` method)
- Test: `tests/Feature/ServiceVisitTest.php`

- [ ] **Step 1: Write the failing tests**

Add these two methods to `tests/Feature/ServiceVisitTest.php` (before the closing brace):

```php
public function test_create_prefills_new_client_from_walkin_appointment(): void
{
    $user = $this->allDataRecorder();
    $appt = \App\Models\Appointment::create([
        'datetime' => '2026-06-20 09:00',
        'customer_name' => 'Walk In Wan',
        'phone' => '012-7654321',
        'address' => 'Shah Alam',
        'status' => 'pending',
        'technician_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('service-records.create', ['appointment' => $appt->id]))
        ->assertInertia(fn ($page) => $page
            ->component('ServiceRecords/Create')
            ->where('presetClient', null)
            ->where('presetNewClient.name', 'Walk In Wan')
            ->where('presetNewClient.phone', '012-7654321')
            ->where('presetNewClient.address', 'Shah Alam')
            ->where('presetAppointmentId', $appt->id)
        );
}

public function test_create_prefills_existing_client_from_appointment(): void
{
    $user = $this->allDataRecorder();
    $client = Client::create(['name' => 'Acme', 'phone' => '012-3456789', 'address' => 'KL']);
    $appt = \App\Models\Appointment::create([
        'datetime' => '2026-06-20 09:00',
        'client_id' => $client->id,
        'phone' => '012-3456789',
        'address' => 'KL',
        'status' => 'pending',
        'technician_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('service-records.create', ['appointment' => $appt->id]))
        ->assertInertia(fn ($page) => $page
            ->where('presetClient.id', $client->id)
            ->where('presetNewClient', null)
        );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter='prefills'`
Expected: FAIL — `presetNewClient` prop does not exist yet (and `presetClient` is null when only `appointment` is passed).

- [ ] **Step 3: Rewrite `create()`**

Replace the entire `create()` method (`ServiceVisitController.php:59-92`) with:

```php
public function create(): Response
{
    $user = request()->user();

    $appointment = request('appointment')
        ? Appointment::visibleTo($user)->whereKey(request('appointment'))
            ->first(['id', 'client_id', 'customer_name', 'phone', 'address'])
        : null;

    // Existing client: explicit ?client= param (client-profile path) OR the appointment's client.
    $clientId = request('client') ?: $appointment?->client_id;
    $presetClient = $clientId
        ? Client::visibleTo($user)->where('id', $clientId)->first(['id', 'serial_no', 'name', 'phone'])
        : null;

    // Walk-in appointment (no client) → prefill the new-client form.
    $presetNewClient = (!$presetClient && $appointment && !$appointment->client_id)
        ? ['name' => $appointment->customer_name, 'phone' => $appointment->phone, 'address' => $appointment->address]
        : null;

    $biz = \App\Models\BusinessSetting::forTenant($user->tenantId());
    $qrUrl = $biz['google_review_qr_path']
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($biz['google_review_qr_path'])
        : null;

    return Inertia::render('ServiceRecords/Create', [
        'googleReview' => ['qrUrl' => $qrUrl, 'url' => $biz['google_review_url']],
        'serviceTypes' => ServiceType::orderBy('name')
            ->with('fees:id,service_type_id,unit_type,hp_value,price')
            ->get(['id', 'name', 'pricing_mode', 'requires_next_service'])->toArray(),
        'presetClient' => $presetClient,
        'presetNewClient' => $presetNewClient,
        'presetClientUnits' => $presetClient
            ? \App\Models\ClientUnit::where('client_id', $presetClient->id)
                ->where('is_active', true)->orderBy('label')
                ->get(['id', 'label', 'unit_type', 'hp'])
            : [],
        'presetTechnicianId' => request('technician_id') ? (int) request('technician_id') : null,
        'presetAppointmentId' => $appointment?->id,
        'technicians' => $user->seesAllData()
            ? \App\Models\User::where('role', \App\Models\User::ROLE_TECHNICIAN)
                ->where('active', true)
                ->when($user->tenantId() !== null, fn ($q) => $q->where('tenant_id', $user->tenantId()))
                ->orderBy('name')->get(['id', 'name'])
            : null,
    ]);
}
```

`Appointment` is already imported (`ServiceVisitController.php:7`). No new import.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter='prefills'`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php tests/Feature/ServiceVisitTest.php
git commit -m "feat(service-records): derive create() prefill from appointment (existing or walk-in)"
```

---

## Task 2: Backend — `store()` back-links walk-in appointment to the new client

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php` (inside `store()`'s `DB::transaction`)
- Test: `tests/Feature/ServiceVisitTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ServiceVisitTest.php`:

```php
public function test_store_backlinks_walkin_appointment_to_new_client(): void
{
    $this->seedFees();
    $user = $this->recorder();
    $appt = \App\Models\Appointment::create([
        'datetime' => '2026-06-20 09:00',
        'customer_name' => 'Walk In Wan',
        'phone' => '012-7654321',
        'address' => 'Shah Alam',
        'status' => 'pending',
    ]);

    $this->actingAs($user)->post(route('service-records.store'), [
        'client_mode' => 'new',
        'new_client' => ['name' => 'Walk In Wan', 'phone' => '012-7654321', 'address' => 'Shah Alam'],
        'visit_date' => '2026-06-20',
        'warranty_months' => 0,
        'appointment_id' => $appt->id,
        'lines' => [[
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'discount' => 0,
        ]],
    ])->assertRedirect();

    $client = Client::where('name', 'Walk In Wan')->first();
    $this->assertNotNull($client);
    $this->assertEquals($client->id, $appt->fresh()->client_id);
}

public function test_store_existing_client_does_not_change_appointment_client(): void
{
    $this->seedFees();
    $user = $this->recorder();
    $client = Client::create(['name' => 'Acme', 'phone' => '012-3456789', 'address' => 'KL']);
    $appt = \App\Models\Appointment::create([
        'datetime' => '2026-06-20 09:00', 'client_id' => $client->id,
        'phone' => '012-3456789', 'address' => 'KL', 'status' => 'pending',
    ]);

    $this->actingAs($user)->post(route('service-records.store'), [
        'client_mode' => 'existing', 'client_id' => $client->id,
        'visit_date' => '2026-06-20', 'warranty_months' => 0,
        'appointment_id' => $appt->id,
        'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'discount' => 0]],
    ])->assertRedirect();

    $this->assertEquals($client->id, $appt->fresh()->client_id);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter='appointment_client OR backlinks'`
Expected: `test_store_backlinks_...` FAILS (appointment's `client_id` stays null). `test_store_existing_...` should already pass — that's fine, it's a regression guard.

- [ ] **Step 3: Add the back-link inside the transaction**

In `store()`, immediately after the `$visit = $client->visits()->create([...]);` block (around `ServiceVisitController.php:124`) and before the `foreach ($data['lines'] ...)` loop, add:

```php
// Walk-in appointment promoted to a real client → back-link it (spec decision: back-link on submit).
if ($data['client_mode'] === 'new' && !empty($data['appointment_id'])) {
    Appointment::whereKey($data['appointment_id'])
        ->when($user->tenantId() !== null, fn ($q) => $q->where('tenant_id', $user->tenantId()))
        ->update(['client_id' => $client->id]);
}
```

(`$user` and `$data` are already in scope inside the transaction closure. `appointment_id` was already tenant-validated by `StoreServiceVisitRequest`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter='appointment_client OR backlinks'`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php tests/Feature/ServiceVisitTest.php
git commit -m "feat(service-records): back-link walk-in appointment to new client on store"
```

---

## Task 3: Frontend — `Create.vue` accepts `presetNewClient`

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Create.vue:13-39` (props + `useForm`)

- [ ] **Step 1: Add the prop**

In the `defineProps` object (`Create.vue:13-21`), add after `presetClient`:

```js
    presetNewClient: { type: Object, default: null },
```

- [ ] **Step 2: Seed the form from the preset**

Replace the `useForm({...})` head (`Create.vue:30-39`) so `client_mode` and `new_client` honour the preset:

```js
const form = useForm({
    client_mode: props.presetNewClient ? 'new' : 'existing',
    client_id: null,
    new_client: {
        name: props.presetNewClient?.name ?? '',
        phone: props.presetNewClient?.phone ?? '',
        address: props.presetNewClient?.address ?? '',
    },
    visit_date: new Date().toISOString().slice(0, 10),
    warranty_months: 0,
    technician_id: props.presetTechnicianId ?? null,
    appointment_id: props.presetAppointmentId ?? null,
    lines: [blankLine()],
});
```

`ClientPicker.vue` needs no change — with `form.client_mode === 'new'` it renders the new-client fields bound to `form.new_client`, which are now pre-filled. (Its `if (props.presetClient)` block does not run because `presetClient` is null in the walk-in case.)

- [ ] **Step 3: Build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: builds clean, no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/ServiceRecords/Create.vue
git commit -m "feat(service-records): prefill new-client form from presetNewClient"
```

---

## Task 4: Frontend — `AppointmentModal.vue` two-card toggle

**Files:**
- Modify: `resources/js/Pages/Appointments/Partials/AppointmentModal.vue`

- [ ] **Step 1: Add `clientMode` state + mode switching (script)**

After the `chosen`/`query`/`results`/`searching` refs (`AppointmentModal.vue:32-35`), add:

```js
const clientMode = ref('existing'); // 'existing' | 'walk_in' — display only; server infers from client_id

const setMode = (mode) => {
    clientMode.value = mode;
    if (mode === 'existing') {
        form.customer_name = '';
    } else {
        clearClient();
    }
    form.clearErrors('client_id', 'customer_name');
};
```

- [ ] **Step 2: Set `clientMode` on open (script)**

In the open watcher (`AppointmentModal.vue:63-87`), set the mode in both branches. In the edit branch (after `chosen.value = a.client ?? null;`) add:

```js
        clientMode.value = a.client_id ? 'existing' : 'walk_in';
```

In the `else` (new) branch, after the existing `if (props.presetClient) applyClient(props.presetClient);` line, add:

```js
        clientMode.value = props.presetClient ? 'existing' : 'existing';
```

(Default fresh appointments to the `existing` card; users switch to Walk-in explicitly.)

- [ ] **Step 3: Guard the submit payload (script)**

Replace `submit()` (`AppointmentModal.vue:89-96`) with:

```js
const submit = () => {
    if (clientMode.value === 'walk_in') {
        form.client_id = null;
    } else {
        form.customer_name = '';
    }
    const opts = { onSuccess: () => emit('saved'), preserveScroll: true };
    if (isEdit.value) {
        form.put(route('appointments.update', props.appointment.id), opts);
    } else {
        form.post(route('appointments.store'), opts);
    }
};
```

- [ ] **Step 4: Replace the client + customer-name markup (template)**

Replace the two blocks — the `<!-- Client (optional) -->` block (`AppointmentModal.vue:112-133`) and the `<!-- Customer name ... -->` block (`AppointmentModal.vue:135-140`) — with this single toggle section:

```html
                    <!-- Client: existing vs walk-in -->
                    <div>
                        <div class="mb-4 grid grid-cols-2 gap-3">
                            <button
                                type="button"
                                class="flex flex-col items-start gap-1 rounded-ral border-2 px-4 py-3 text-left transition"
                                :class="clientMode === 'existing' ? 'border-primary bg-primary-50 shadow-card' : 'border-line bg-surface hover:border-primary/40'"
                                @click="setMode('existing')"
                            >
                                <span class="flex items-center gap-2">
                                    <span class="flex h-4 w-4 items-center justify-center rounded-full border-2 transition" :class="clientMode === 'existing' ? 'border-primary' : 'border-line'">
                                        <span v-if="clientMode === 'existing'" class="h-2 w-2 rounded-full bg-primary" />
                                    </span>
                                    <span class="text-sm font-semibold" :class="clientMode === 'existing' ? 'text-primary' : 'text-ink'">Existing client</span>
                                </span>
                                <span class="pl-6 text-xs text-ink-soft">Search by name, serial or phone</span>
                            </button>
                            <button
                                type="button"
                                class="flex flex-col items-start gap-1 rounded-ral border-2 px-4 py-3 text-left transition"
                                :class="clientMode === 'walk_in' ? 'border-primary bg-primary-50 shadow-card' : 'border-line bg-surface hover:border-primary/40'"
                                @click="setMode('walk_in')"
                            >
                                <span class="flex items-center gap-2">
                                    <span class="flex h-4 w-4 items-center justify-center rounded-full border-2 transition" :class="clientMode === 'walk_in' ? 'border-primary' : 'border-line'">
                                        <span v-if="clientMode === 'walk_in'" class="h-2 w-2 rounded-full bg-primary" />
                                    </span>
                                    <span class="text-sm font-semibold" :class="clientMode === 'walk_in' ? 'text-primary' : 'text-ink'">Walk-in</span>
                                </span>
                                <span class="pl-6 text-xs text-ink-soft">Enter customer details manually</span>
                            </button>
                        </div>

                        <!-- Existing client search/selection -->
                        <div v-if="clientMode === 'existing'">
                            <div v-if="chosen" class="flex items-center justify-between rounded-ra border border-primary/30 bg-primary-50 px-4 py-2.5">
                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-ink">{{ chosen.name }} <span class="ml-1 font-mono text-sm text-primary">#{{ chosen.serial_no }}</span></div>
                                </div>
                                <button type="button" class="text-sm font-medium text-ink-soft hover:text-danger" @click="clearClient">Change</button>
                            </div>
                            <div v-else class="relative">
                                <input v-model="query" type="search" placeholder="Search name, serial or phone…" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                                <ul v-if="results.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-ra border border-line bg-surface shadow-lift">
                                    <li v-for="c in results" :key="c.id">
                                        <button type="button" class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-surface-muted" @click="choose(c)">
                                            <span class="font-medium text-ink">{{ c.name }}</span>
                                            <span class="font-mono text-xs text-primary">#{{ c.serial_no }}</span>
                                        </button>
                                    </li>
                                </ul>
                                <p v-if="searching" class="mt-1 text-xs text-ink-muted">Searching…</p>
                            </div>
                            <p v-if="form.errors.client_id" class="mt-1 text-sm text-danger">{{ form.errors.client_id }}</p>
                        </div>

                        <!-- Walk-in: manual customer name -->
                        <div v-else>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Customer name</label>
                            <input v-model="form.customer_name" type="text" placeholder="e.g. Encik Ali" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                            <p v-if="form.errors.customer_name" class="mt-1 text-sm text-danger">{{ form.errors.customer_name }}</p>
                        </div>
                    </div>
```

The Phone / Address / Notes / Date / Time / Technician fields below stay as they are (phone + address apply to both modes; they are auto-filled from a chosen client and editable for a walk-in).

- [ ] **Step 5: Build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: builds clean.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Appointments/Partials/AppointmentModal.vue
git commit -m "feat(appointments): explicit existing-client vs walk-in toggle in modal"
```

---

## Task 5: Frontend — Add Record on every appointment row

**Files:**
- Modify: `resources/js/Pages/Appointments/Index.vue:278-282` (desktop) and `:315` (mobile card)

- [ ] **Step 1: Desktop Add Record (template)**

Replace the desktop Add Record `<Link>` (`Index.vue:278-282`) with:

```html
                        <Link
                            :href="route('service-records.create', { appointment: row.id, technician_id: row.technician_id })"
                            class="text-ok hover:text-ok/80"
                        >Add Record</Link>
```

(Removes the `v-if="row.client"` gate and the `client` param.)

- [ ] **Step 2: Mobile Add Record (template)**

Replace the mobile-card Add Record `<Link>` (`Index.vue:315`) with:

```html
                            <Link :href="route('service-records.create', { appointment: row.id, technician_id: row.technician_id })" class="text-ok hover:text-ok/80">Add Record</Link>
```

- [ ] **Step 3: Build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: builds clean.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Appointments/Index.vue
git commit -m "feat(appointments): show Add Record on every row, routed via appointment id"
```

---

## Task 6: Full verification

- [ ] **Step 1: Run the full suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: all green (324 prior + 4 new = 328), no failures.

- [ ] **Step 2: Final build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: clean.

- [ ] **Step 3: Manual smoke (npm run dev, eyeball)**

- New appointment → toggle Existing/Walk-in works; Walk-in submits with customer name.
- Appointments table → every row shows Add Record.
- Client-backed row → Add Record opens record with existing client pre-selected.
- Walk-in row → Add Record opens record in New-client mode pre-filled (name/phone/address).
- Submit the walk-in record → appointment row now shows the client serial (back-linked).

---

## Notes for the executor

- **No migration.** `appointments.customer_name` already exists (session 48).
- **Test runner:** `docker exec saifzz-aircond-laravel.test-1 php artisan test` (the agent shell has no PHP). Frontend build: `docker compose exec -T laravel.test npm run build`.
- **Branch:** work on `dev`; never commit to `main`.
- **Status flow:** code-complete = TESTING (only Khalid closes to DONE).
- **Deploy on merge:** `npm run build`. No migration, no reseed.
