# Edit Service Lines (FEAT-007) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the service-record Edit page edit service lines with the same power as Create — add/remove/change lines — recomputing total, transaction amount, and (live) the invoice.

**Architecture:** Reuse the `ServiceLineCard` editor in `Edit.vue`. Controller `update()` deletes-then-recreates lines via the existing `normalizeLine()`, recalculates the total, and re-syncs `transaction.amount`. Validation is shared with the create path via a new `ValidatesServiceLines` trait; a new `UpdateServiceVisitRequest` drives the update.

**Tech Stack:** Laravel 12 (PHP 8.5), Inertia + Vue 3, Pest/PHPUnit feature tests, Docker Sail.

**Test runner:** `docker exec saifzz-aircond-laravel.test-1 php artisan test`
**Build:** `docker compose exec -T laravel.test npm run build`

---

## File Structure

- **Create** `app/Http/Requests/Concerns/ValidatesServiceLines.php` — shared per-line + cash-permission validation (extracted from Store).
- **Modify** `app/Http/Requests/StoreServiceVisitRequest.php` — use the trait (behaviour identical).
- **Create** `app/Http/Requests/UpdateServiceVisitRequest.php` — update-path validation (no client fields; unit_id scoped to route record).
- **Modify** `app/Http/Controllers/ServiceVisitController.php` — `edit()` adds `clientUnits` prop; `update()` rewritten to persist lines.
- **Modify** `resources/js/Pages/ServiceRecords/Edit.vue` — read-only Services card → full `ServiceLineCard` editor + sticky total bar.
- **Create** `tests/Feature/ServiceVisitUpdateTest.php` — update behaviour coverage.

---

## Task 1: Extract shared line-validation into a trait

**Files:**
- Create: `app/Http/Requests/Concerns/ValidatesServiceLines.php`
- Modify: `app/Http/Requests/StoreServiceVisitRequest.php`
- Test (existing, must stay green): `tests/Feature/ServiceVisitTest.php`

- [ ] **Step 1: Create the trait**

Create `app/Http/Requests/Concerns/ValidatesServiceLines.php`:

```php
<?php

namespace App\Http\Requests\Concerns;

trait ValidatesServiceLines
{
    /**
     * Per-line conditional rules (R2/R3) + fee existence (R1) + cash-permission gate.
     * Shared by StoreServiceVisitRequest and UpdateServiceVisitRequest.
     */
    protected function validateServiceLines($v): void
    {
        if ($this->input('payment_method') === 'Cash' && ! $this->user()->hasPermission('collect_payment')) {
            $v->errors()->add('payment_method', 'Cash payment is not permitted for your account.');
        }

        foreach ((array) $this->input('lines', []) as $i => $line) {
            $type = $line['service_type'] ?? null;
            $key = "lines.$i";
            if (! $type) {
                continue;
            }
            $serviceType = \App\Models\ServiceType::where('name', $type)->first();
            if (! $serviceType) {
                continue;
            }

            if ($serviceType->pricing_mode === 'flexible') {
                if (empty($line['repair_desc'])) {
                    $v->errors()->add("$key.repair_desc", 'Describe the work done.');
                }
                if (! isset($line['rate']) || $line['rate'] === '' || $line['rate'] === null) {
                    $v->errors()->add("$key.rate", 'Enter a price.');
                }
                continue;
            }

            if (empty($line['unit_type'])) {
                $v->errors()->add("$key.unit_type", 'Unit type is required for this service.');
                continue;
            }

            $feeQuery = \App\Models\ServiceFee::where('service_type_id', $serviceType->id)
                ->where('unit_type', $line['unit_type']);

            if ($serviceType->pricing_mode === 'hp_tiered') {
                if (empty($line['hp_value'])) {
                    $v->errors()->add("$key.hp_value", 'HP is required for this service.');
                    continue;
                }
                $feeQuery->where('hp_value', (float) $line['hp_value']);
            } else {
                $feeQuery->whereNull('hp_value');
            }

            if (! $feeQuery->exists()) {
                $label = $line['unit_type'] . ($serviceType->pricing_mode === 'hp_tiered' ? " · {$line['hp_value']} HP" : '');
                $field = $serviceType->pricing_mode === 'hp_tiered' ? 'hp_value' : 'unit_type';
                $v->errors()->add("$key.$field", "No fee configured for {$type} · {$label}.");
            }
        }
    }
}
```

- [ ] **Step 2: Refactor `StoreServiceVisitRequest` to use the trait**

In `app/Http/Requests/StoreServiceVisitRequest.php`, add the `use` import below the namespace `use` lines:

```php
use App\Http\Requests\Concerns\ValidatesServiceLines;
```

Add the trait inside the class (first line of the class body):

```php
class StoreServiceVisitRequest extends FormRequest
{
    use ValidatesServiceLines;
```

Replace the entire `withValidator` method body (lines 60-112 in the current file) with:

```php
    public function withValidator($validator): void
    {
        $validator->after(fn ($v) => $this->validateServiceLines($v));
    }
```

- [ ] **Step 3: Run the existing store suite to verify no behaviour change**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceVisitTest`
Expected: PASS (all existing ServiceVisitTest tests green — the validation behaviour is identical).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Requests/Concerns/ValidatesServiceLines.php app/Http/Requests/StoreServiceVisitRequest.php
git commit -m "refactor(service-records): extract shared line validation into ValidatesServiceLines trait"
```

---

## Task 2: UpdateServiceVisitRequest

**Files:**
- Create: `app/Http/Requests/UpdateServiceVisitRequest.php`

- [ ] **Step 1: Create the request**

Create `app/Http/Requests/UpdateServiceVisitRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesServiceLines;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceVisitRequest extends FormRequest
{
    use ValidatesServiceLines;

    public function authorize(): bool
    {
        return $this->user()->can('record_service');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clientId = $this->route('serviceRecord')->client_id;

        return [
            'visit_date' => ['required', 'date'],
            'warranty_months' => ['required', 'integer', 'between:0,6'],
            'payment_method' => ['required', Rule::in(['Cash', 'DuitNow QR'])],
            'technician_id' => ['nullable', 'integer', 'exists:users,id'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.service_type' => ['required', 'string', Rule::exists('service_types', 'name')],
            'lines.*.unit_type' => ['nullable', 'string', 'max:255'],
            'lines.*.repair_desc' => ['nullable', 'string', 'max:1000'],
            'lines.*.units' => ['required', 'integer', 'min:1'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.next_service_date' => ['nullable', 'date'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.unit_id' => ['nullable', 'integer', Rule::exists('client_units', 'id')->where('client_id', $clientId)],
            'lines.*.hp_value' => ['nullable', 'numeric', 'min:0.5', 'max:20'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(fn ($v) => $this->validateServiceLines($v));
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Requests/UpdateServiceVisitRequest.php
git commit -m "feat(service-records): add UpdateServiceVisitRequest for line editing"
```

---

## Task 3: Rewrite controller update() + edit() clientUnits

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php`
- Test: `tests/Feature/ServiceVisitUpdateTest.php` (created here)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ServiceVisitUpdateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceFee;
use App\Models\ServiceVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceVisitUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ServiceTypeSeeder::class);
        $this->seedFees();
    }

    private function recorder(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients', 'record_service'],
        ]);
    }

    private function cashRecorder(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients', 'record_service', 'collect_payment'],
        ]);
    }

    private function seedFees(): void
    {
        // ServiceTypeSeeder: Cleaning (hp_tiered), Gas Top-Up (flat), Repair (flexible).
        // Make Cleaning flat for these tests.
        $cleaning = \App\Models\ServiceType::where('name', 'Cleaning')->first();
        $cleaning->update(['pricing_mode' => 'flat']);
        ServiceFee::firstOrCreate(
            ['service_type_id' => $cleaning->id, 'unit_type' => 'Wall Mounted', 'hp_value' => null],
            ['price' => 60]
        );

        $gas = \App\Models\ServiceType::where('name', 'Gas Top-Up')->first();
        ServiceFee::firstOrCreate(
            ['service_type_id' => $gas->id, 'unit_type' => 'Half Top-Up', 'hp_value' => null],
            ['price' => 150]
        );
    }

    /** Build a pending visit owned by $owner with one Cleaning line (rate 60). */
    private function makePendingVisit(User $owner): ServiceVisit
    {
        $client = Client::create(['name' => 'Existing', 'phone' => '012-3456789', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'created_by' => $owner->id,
            'technician_id' => $owner->id,
            'tenant_id' => $owner->tenantId(),
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $visit->recalculateTotal();
        $visit->transaction()->create([
            'txn_id' => 'TXN-20260611-001', 'amount' => $visit->total_amount,
            'method' => 'DuitNow QR', 'status' => 'pending',
        ]);

        return $visit->fresh();
    }

    private function payload(array $lines, array $overrides = []): array
    {
        return array_merge([
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'payment_method' => 'DuitNow QR',
            'lines' => $lines,
        ], $overrides);
    }

    public function test_update_recomputes_total_and_transaction_amount(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 3, 'discount' => 0],
            ]))
            ->assertRedirect(route('service-records.show', $visit));

        $visit->refresh()->load('lines', 'transaction');
        $this->assertSame('180.00', $visit->total_amount);          // 60 * 3
        $this->assertSame('180.00', $visit->transaction->amount);   // amount re-synced
        $this->assertCount(1, $visit->lines);
    }

    public function test_update_can_add_a_line(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'discount' => 0],
                ['service_type' => 'Gas Top-Up', 'unit_type' => 'Half Top-Up', 'units' => 1, 'discount' => 0],
            ]))
            ->assertRedirect();

        $visit->refresh()->load('lines');
        $this->assertCount(2, $visit->lines);
        $this->assertSame('210.00', $visit->total_amount); // 60 + 150
    }

    public function test_update_can_remove_a_line(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);
        // seed a second line so removal is observable
        $visit->lines()->create(['service_type' => 'Gas Top-Up', 'unit_type' => 'Half Top-Up', 'units' => 1, 'rate' => 150, 'discount' => 0]);
        $visit->recalculateTotal();

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'discount' => 0],
            ]))
            ->assertRedirect();

        $visit->refresh()->load('lines');
        $this->assertCount(1, $visit->lines);
        $this->assertSame('60.00', $visit->total_amount);
    }

    public function test_update_flexible_line_uses_manual_price_and_description(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Repair', 'repair_desc' => 'New capacitor', 'units' => 1, 'rate' => 220, 'discount' => 0],
            ]))
            ->assertRedirect();

        $line = $visit->refresh()->lines->first();
        $this->assertSame('220.00', $line->rate);
        $this->assertSame('New capacitor', $line->repair_desc);
        $this->assertNull($line->unit_type);
        $this->assertSame('220.00', $visit->total_amount);
    }

    public function test_update_changing_service_type_resnapshots_rate_from_fee_book(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        // Switch Cleaning(60) → Gas Top-Up(150); send a tampered rate that must be ignored.
        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Gas Top-Up', 'unit_type' => 'Half Top-Up', 'units' => 1, 'rate' => 5, 'discount' => 0],
            ]))
            ->assertRedirect();

        $line = $visit->refresh()->lines->first();
        $this->assertSame('Gas Top-Up', $line->service_type);
        $this->assertSame('150.00', $line->rate); // server fee, not the 5 sent
    }

    public function test_cannot_update_a_paid_record(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);
        $visit->transaction->update(['status' => 'paid']);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 9, 'discount' => 0],
            ]))
            ->assertStatus(422);

        $this->assertSame('60.00', $visit->refresh()->total_amount); // unchanged
    }

    public function test_cash_method_blocked_without_collect_payment(): void
    {
        $owner = $this->recorder(); // no collect_payment
        $visit = $this->makePendingVisit($owner);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'discount' => 0],
            ], ['payment_method' => 'Cash']))
            ->assertSessionHasErrors('payment_method');
    }

    public function test_update_forbidden_for_non_owner_scoped_tech(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);
        $other = $this->recorder(); // different scoped tech, not the owner

        $this->actingAs($other)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'discount' => 0],
            ]))
            ->assertForbidden();
    }

    public function test_update_validation_requires_unit_type_for_fee_service(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'units' => 1], // missing unit_type
            ]))
            ->assertSessionHasErrors('lines.0.unit_type');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceVisitUpdateTest`
Expected: FAIL — current `update()` ignores lines, so total/amount assertions fail (and the request type isn't wired).

- [ ] **Step 3: Wire the new request + rewrite `update()` + add `clientUnits` to `edit()`**

In `app/Http/Controllers/ServiceVisitController.php`, add the import below the existing request import:

```php
use App\Http\Requests\StoreServiceVisitRequest;
use App\Http\Requests\UpdateServiceVisitRequest;
```

In `edit()`, add the `clientUnits` prop to the `Inertia::render` array (alongside `technicians` / `serviceTypes`):

```php
            'clientUnits' => \App\Models\ClientUnit::where('client_id', $serviceRecord->client_id)
                ->where('is_active', true)->orderBy('label')
                ->get(['id', 'label', 'unit_type', 'hp']),
```

Replace the **entire** `update()` method (current lines 198-245) with:

```php
    public function update(UpdateServiceVisitRequest $request, ServiceVisit $serviceRecord): RedirectResponse
    {
        abort_unless(
            ServiceVisit::whereKey($serviceRecord->getKey())->visibleTo($request->user())->exists(),
            403,
        );
        abort_unless($serviceRecord->transaction?->status === 'pending', 422);

        $data = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($data, $user, $serviceRecord) {
            $technicianId = $user->seesAllData()
                ? ($data['technician_id'] ?? $serviceRecord->technician_id)
                : $serviceRecord->technician_id;

            if ($user->tenantId() !== null && $technicianId !== null) {
                abort_unless(
                    \App\Models\User::whereKey($technicianId)->where('tenant_id', $user->tenantId())->exists(),
                    404,
                );
            }

            $serviceRecord->update([
                'visit_date' => $data['visit_date'],
                'warranty_months' => $data['warranty_months'],
                'technician_id' => $technicianId,
            ]);

            // Server-authoritative line replacement: delete then recreate via normalizeLine().
            $serviceRecord->lines()->delete();
            foreach ($data['lines'] as $line) {
                $serviceRecord->lines()->create($this->normalizeLine($line));
            }

            // Re-sync next_service_date/type onto referenced units (mirrors store()).
            foreach ($data['lines'] as $line) {
                if (!empty($line['unit_id']) && !empty($line['next_service_date'])) {
                    \App\Models\ClientUnit::where('id', $line['unit_id'])
                        ->where('client_id', $serviceRecord->client_id)
                        ->update([
                            'next_service_date' => $line['next_service_date'],
                            'next_service_type' => $line['service_type'],
                        ]);
                }
            }

            $serviceRecord->recalculateTotal();

            $serviceRecord->transaction->update([
                'method' => $data['payment_method'],
                'amount' => $serviceRecord->total_amount,
            ]);
        });

        return redirect()->route('service-records.show', $serviceRecord)
            ->with('success', 'Record updated.');
    }
```

Note: the old `use \Illuminate\Http\Request` signature and the inline `$request->validate([...])` block are fully replaced; the cash-permission check now lives in the request via the trait.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceVisitUpdateTest`
Expected: PASS (all 9 tests).

- [ ] **Step 5: Run the broader service-record + tenant suites for regressions**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="ServiceVisitTest|MultiTenantIsolationTest|TechnicianScoping"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php tests/Feature/ServiceVisitUpdateTest.php
git commit -m "feat(service-records): edit service lines on update; recompute total + txn amount (FEAT-007)"
```

---

## Task 4: Edit.vue full line editor

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Edit.vue`

- [ ] **Step 1: Replace the page with the full editor**

Overwrite `resources/js/Pages/ServiceRecords/Edit.vue` with:

```vue
<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import FormErrorSummary from '@/Components/FormErrorSummary.vue';
import ServiceLineCard from './Partials/ServiceLineCard.vue';

const page = usePage();
const canCollectCash = page.props.auth?.can?.collect_payment ?? false;

const props = defineProps({
    visit: Object,
    technicians: { type: Array, default: null },
    serviceTypes: Array,
    clientUnits: { type: Array, default: () => [] },
});

const blankLine = () => ({
    unit_id: null, service_type: '', unit_type: null, hp_value: null, repair_desc: '',
    units: 1, rate: '', discount: 0, next_service_date: null, notes: '',
});

// Map persisted lines back to the editor shape (coerce decimals to numbers).
const seedLine = (l) => ({
    unit_id: l.unit_id ?? null,
    service_type: l.service_type ?? '',
    unit_type: l.unit_type ?? null,
    hp_value: l.hp_value != null ? Number(l.hp_value) : null,
    repair_desc: l.repair_desc ?? '',
    units: Number(l.units) || 1,
    rate: l.rate != null ? Number(l.rate) : '',
    discount: Number(l.discount) || 0,
    next_service_date: l.next_service_date ? String(l.next_service_date).slice(0, 10) : null,
    notes: l.notes ?? '',
});

const form = useForm({
    visit_date: props.visit.visit_date?.slice(0, 10) ?? '',
    warranty_months: props.visit.warranty_months ?? 0,
    payment_method: props.visit.transaction?.method ?? (canCollectCash ? 'Cash' : 'DuitNow QR'),
    technician_id: props.visit.technician_id ?? null,
    lines: (props.visit.lines?.length ? props.visit.lines.map(seedLine) : [blankLine()]),
});

const addLine = () => form.lines.push(blankLine());
const removeLine = (i) => form.lines.splice(i, 1);

const lineSubtotal = (l) => {
    const units = l.unit_id ? 1 : (Number(l.units) || 0);
    return Math.max(0, (Number(l.rate) || 0) * units - (Number(l.discount) || 0));
};
const grandTotal = computed(() => form.lines.reduce((s, l) => s + lineSubtotal(l), 0));
const totalServices = computed(() => form.lines.filter(l => l.service_type).length);
const totalUnits = computed(() => form.lines.reduce((s, l) => s + (l.unit_id ? 1 : (Number(l.units) || 0)), 0));
const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);

const warrantyEnd = computed(() => {
    if (!form.warranty_months || !form.visit_date) return null;
    const d = new Date(form.visit_date);
    d.setMonth(d.getMonth() + Number(form.warranty_months));
    return d.toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' });
});

const submit = () => form.patch(route('service-records.update', props.visit.id));
</script>

<template>
    <Head title="Edit service record" />

    <AdminLayout>
        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <h1 class="truncate text-base font-bold text-navy-800">Edit service record</h1>
                <Link :href="route('service-records.show', visit.id)" class="shrink-0 text-sm font-medium text-ink-soft hover:text-ink transition">← Back</Link>
            </div>
        </template>

        <form class="mx-auto max-w-3xl space-y-5 pb-32" @submit.prevent="submit">

            <FormErrorSummary :errors="form.errors" />

            <!-- Client (read-only) -->
            <Card title="Client">
                <div class="flex items-center gap-3">
                    <span class="font-semibold text-ink">{{ visit.client?.name ?? '—' }}</span>
                    <span class="font-mono text-xs text-primary">#{{ visit.client?.serial_no }}</span>
                </div>
            </Card>

            <!-- Visit meta -->
            <Card title="Visit details">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Visit date</label>
                        <input v-model="form.visit_date" type="date" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary" />
                        <p v-if="form.errors.visit_date" class="mt-1 text-sm text-danger">{{ form.errors.visit_date }}</p>
                    </div>
                    <div v-if="technicians">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Technician</label>
                        <select v-model="form.technician_id" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                            <option :value="null">{{ page.props.auth?.user?.name ?? '— Me —' }}</option>
                            <option v-for="t in technicians" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Warranty (months)</label>
                        <select v-model.number="form.warranty_months" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                            <option v-for="m in [0,1,2,3,4,5,6]" :key="m" :value="m">{{ m === 0 ? 'No warranty' : m + ' month' + (m > 1 ? 's' : '') }}</option>
                        </select>
                        <p v-if="warrantyEnd" class="mt-1 text-xs text-ok">Covered until {{ warrantyEnd }}</p>
                    </div>
                </div>
            </Card>

            <!-- Service lines -->
            <div class="space-y-3">
                <div class="flex items-center justify-between px-0.5">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-ink-soft">Services</h2>
                    <span v-if="form.errors.lines" class="text-sm text-danger">{{ form.errors.lines }}</span>
                </div>
                <ServiceLineCard
                    v-for="(line, i) in form.lines"
                    :key="i"
                    :line="line"
                    :index="i"
                    :service-types="serviceTypes"
                    :client-units="clientUnits"
                    :errors="form.errors"
                    :removable="form.lines.length > 1"
                    :visit-date="form.visit_date"
                    @remove="removeLine(i)"
                />
                <button
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-ral border-2 border-dashed border-line py-3.5 text-sm font-semibold text-ink-soft transition hover:border-primary hover:bg-primary-50 hover:text-primary"
                    @click="addLine"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
                    Add another service
                </button>
            </div>

            <!-- Payment method -->
            <Card title="Payment method">
                <div class="grid gap-3" :class="canCollectCash ? 'grid-cols-2' : 'grid-cols-1'">
                    <label
                        v-if="canCollectCash"
                        class="flex cursor-pointer items-center gap-3 rounded-ra border px-4 py-3 transition"
                        :class="form.payment_method === 'Cash' ? 'border-primary bg-primary-50 shadow-card' : 'border-line hover:border-primary/40'"
                    >
                        <input v-model="form.payment_method" type="radio" value="Cash" class="text-primary focus:ring-primary" />
                        <span class="font-semibold text-ink">Cash</span>
                    </label>
                    <label
                        class="flex cursor-pointer items-center gap-3 rounded-ra border px-4 py-3 transition"
                        :class="form.payment_method === 'DuitNow QR' ? 'border-primary bg-primary-50 shadow-card' : 'border-line hover:border-primary/40'"
                    >
                        <input v-model="form.payment_method" type="radio" value="DuitNow QR" class="text-primary focus:ring-primary" />
                        <span class="font-semibold text-ink">DuitNow QR</span>
                    </label>
                </div>
                <p v-if="form.errors.payment_method" class="mt-2 text-sm text-danger">{{ form.errors.payment_method }}</p>
            </Card>
        </form>

        <!-- Sticky total bar (navy) -->
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-navy-900/60 bg-navy-800 lg:pl-64">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <div class="flex items-center gap-5">
                    <div>
                        <div class="text-xs uppercase tracking-widest text-navy-300">Grand total</div>
                        <div class="font-mono text-2xl font-bold text-white">{{ money(grandTotal) }}</div>
                    </div>
                    <div v-if="totalServices > 0" class="hidden sm:block border-l border-navy-600 pl-5">
                        <div class="text-xs text-navy-300">{{ totalServices }} service{{ totalServices !== 1 ? 's' : '' }}</div>
                        <div class="text-xs text-navy-300">{{ totalUnits }} unit{{ totalUnits !== 1 ? 's' : '' }}</div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Link :href="route('service-records.show', visit.id)" class="text-sm font-medium text-navy-300 hover:text-white transition">Cancel</Link>
                    <button
                        type="button"
                        :disabled="form.processing"
                        class="rounded-ra bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
                        @click="submit"
                    >
                        Save changes
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
```

- [ ] **Step 2: Build the frontend**

Run: `docker compose exec -T laravel.test npm run build`
Expected: build completes, Vite manifest written, no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/ServiceRecords/Edit.vue
git commit -m "feat(service-records): full service-line editor on Edit page (FEAT-007)"
```

---

## Task 5: Full suite + manual smoke

- [ ] **Step 1: Run the full test suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: PASS (prior 307 + 9 new ServiceVisitUpdateTest = 316), no regressions.

- [ ] **Step 2: Manual smoke (npm run dev for eyeball)**

Start dev server if not running: `docker compose exec -T laravel.test npm run dev` (HMR).
- Open a pending service record → Edit.
- Change a quantity → grand total bar updates live.
- Add a service line, remove a line.
- Change a line's service type → rate auto-fills from fee book.
- Save → redirected to Show with the recomputed total; the invoice reflects the edited lines.
- Confirm a paid record has no Edit access (existing guard).

- [ ] **Step 3: Final commit if any smoke fixups**

```bash
git add -A
git commit -m "chore(service-records): FEAT-007 smoke fixups"
```

---

## Self-review notes

- **Spec coverage:** full editor (Task 4) ✓; delete-then-recreate + recalc + txn.amount (Task 3) ✓; shared trait + UpdateServiceVisitRequest (Tasks 1-2) ✓; client fixed (Edit.vue Client card read-only) ✓; live invoice (SnapshotBuilder reads lines, no code needed) ✓; tests (Task 3) cover edit/add/remove/flexible/type-change/paid-guard/cash-guard/visibility/validation ✓.
- **No migrations / no reseed** — frontend `npm run build` only on deploy.
