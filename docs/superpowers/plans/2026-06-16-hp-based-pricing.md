# HP-Based Pricing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Service types can be marked HP-based; each HP value (e.g. 1.5 HP) has a configured price; selecting an HP-based service type in the service record form shows an HP dropdown that auto-fills the rate as base_fee + hp_tier_price; HP value is stored on the service line.

**Architecture:** Three migrations add `is_hp_based` to `service_types`, create `service_hp_tiers` (FK to `service_types`, cascadeDelete), and add `hp_value` to `service_lines`. `ServiceHpTierController` handles tier CRUD. `ServiceTypeController::update()` gains `is_hp_based`. `normalizeLine()` in `ServiceVisitController` computes rate server-side including HP surcharge. `ServiceLineCard.vue` gains an HP dropdown that client-side previews the rate. Fee Schedule tab shows HP config per service type card.

**Tech Stack:** Laravel 12, Inertia.js + Vue 3, PostgreSQL, TailwindCSS.

---

## File Map

| Action | File |
|--------|------|
| Create | `database/migrations/2026_06_16_000002_add_is_hp_based_to_service_types_table.php` |
| Create | `database/migrations/2026_06_16_000003_create_service_hp_tiers_table.php` |
| Create | `database/migrations/2026_06_16_000004_add_hp_value_to_service_lines_table.php` |
| Create | `app/Models/ServiceHpTier.php` |
| Modify | `app/Models/ServiceType.php` |
| Modify | `app/Models/ServiceLine.php` |
| Create | `app/Http/Controllers/ServiceHpTierController.php` |
| Modify | `app/Http/Controllers/ServiceTypeController.php` |
| Modify | `app/Http/Controllers/ServiceVisitController.php` |
| Modify | `app/Http/Requests/StoreServiceVisitRequest.php` |
| Modify | `routes/web.php` |
| Modify | `resources/js/Pages/ServiceTypes/Index.vue` |
| Modify | `resources/js/Pages/ServiceRecords/Partials/ServiceLineCard.vue` |
| Modify | `resources/js/Pages/ServiceRecords/Create.vue` |
| Create | `tests/Feature/ServiceHpTierTest.php` |

---

### Task 1: Migrations + Models

**Files:**
- Create: 3 migration files
- Create: `app/Models/ServiceHpTier.php`
- Modify: `app/Models/ServiceType.php`
- Modify: `app/Models/ServiceLine.php`

- [ ] **Step 1: Write the three migrations**

```php
<?php
// database/migrations/2026_06_16_000002_add_is_hp_based_to_service_types_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->boolean('is_hp_based')->default(false)->after('requires_next_service');
        });
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn('is_hp_based');
        });
    }
};
```

```php
<?php
// database/migrations/2026_06_16_000003_create_service_hp_tiers_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_hp_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained('service_types')->cascadeOnDelete();
            $table->decimal('hp_value', 3, 1);
            $table->decimal('price', 8, 2);
            $table->timestamps();
            $table->unique(['service_type_id', 'hp_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_hp_tiers');
    }
};
```

```php
<?php
// database/migrations/2026_06_16_000004_add_hp_value_to_service_lines_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_lines', function (Blueprint $table) {
            $table->decimal('hp_value', 3, 1)->nullable()->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('service_lines', function (Blueprint $table) {
            $table->dropColumn('hp_value');
        });
    }
};
```

- [ ] **Step 2: Run migrations**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan migrate
```

Expected: 3 migrations run, all `Migrated`.

- [ ] **Step 3: Create ServiceHpTier model**

```php
<?php
// app/Models/ServiceHpTier.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceHpTier extends Model
{
    protected $fillable = ['service_type_id', 'hp_value', 'price'];

    protected $casts = [
        'hp_value' => 'decimal:1',
        'price' => 'decimal:2',
    ];

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
```

- [ ] **Step 4: Update ServiceType model**

Replace the file:

```php
<?php
// app/Models/ServiceType.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceType extends Model
{
    protected $fillable = ['name', 'requires_next_service', 'is_hp_based'];

    protected $casts = [
        'requires_next_service' => 'boolean',
        'is_hp_based' => 'boolean',
    ];

    public function hpTiers(): HasMany
    {
        return $this->hasMany(ServiceHpTier::class);
    }
}
```

- [ ] **Step 5: Update ServiceLine model — add hp_value to fillable and cast**

In `app/Models/ServiceLine.php`, update `$fillable` and `casts()`:

```php
protected $fillable = [
    'visit_id',
    'unit_id',
    'service_type',
    'unit_type',
    'gas_option',
    'units',
    'rate',
    'repair_desc',
    'discount',
    'hp_value',
    'next_service_date',
    'notes',
    'subtotal',
];

protected function casts(): array
{
    return [
        'units' => 'integer',
        'rate' => 'decimal:2',
        'discount' => 'decimal:2',
        'hp_value' => 'decimal:1',
        'subtotal' => 'decimal:2',
        'next_service_date' => 'date',
    ];
}
```

- [ ] **Step 6: Run full suite to confirm models don't break existing tests**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: same count as before, all green.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_16_000002_add_is_hp_based_to_service_types_table.php \
        database/migrations/2026_06_16_000003_create_service_hp_tiers_table.php \
        database/migrations/2026_06_16_000004_add_hp_value_to_service_lines_table.php \
        app/Models/ServiceHpTier.php app/Models/ServiceType.php app/Models/ServiceLine.php
git commit -m "feat: HP pricing schema — service_hp_tiers table, is_hp_based on service_types, hp_value on service_lines"
```

---

### Task 2: ServiceHpTierController + ServiceTypeController update + Routes

**Files:**
- Create: `app/Http/Controllers/ServiceHpTierController.php`
- Modify: `app/Http/Controllers/ServiceTypeController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/ServiceHpTierTest.php`:

```php
<?php
namespace Tests\Feature;

use App\Models\ServiceHpTier;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceHpTierTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function type(): ServiceType
    {
        return ServiceType::create(['name' => 'Gas Top-Up', 'requires_next_service' => false, 'is_hp_based' => false]);
    }

    public function test_admin_can_toggle_is_hp_based(): void
    {
        $admin = $this->admin();
        $type = $this->type();

        $this->actingAs($admin)->put(route('service-types.update', $type), [
            'name' => $type->name,
            'is_hp_based' => true,
        ])->assertRedirect();

        $this->assertTrue($type->fresh()->is_hp_based);
    }

    public function test_admin_can_create_hp_tier(): void
    {
        $admin = $this->admin();
        $admin->update(['permissions' => ['manage_service_types', 'edit_fees']]);
        $type = $this->type();

        $this->actingAs($admin)->post(route('service-hp-tiers.store'), [
            'service_type_id' => $type->id,
            'hp_value' => 1.5,
            'price' => 25.00,
        ])->assertRedirect();

        $this->assertSame(1, ServiceHpTier::where('service_type_id', $type->id)->count());
        $this->assertSame('1.5', ServiceHpTier::first()->hp_value);
        $this->assertSame('25.00', ServiceHpTier::first()->price);
    }

    public function test_duplicate_hp_value_updates_price(): void
    {
        $admin = $this->admin();
        $admin->update(['permissions' => ['manage_service_types', 'edit_fees']]);
        $type = $this->type();

        ServiceHpTier::create(['service_type_id' => $type->id, 'hp_value' => 1.5, 'price' => 20.00]);

        $this->actingAs($admin)->post(route('service-hp-tiers.store'), [
            'service_type_id' => $type->id,
            'hp_value' => 1.5,
            'price' => 30.00,
        ])->assertRedirect();

        $this->assertSame(1, ServiceHpTier::count());
        $this->assertSame('30.00', ServiceHpTier::first()->price);
    }

    public function test_admin_can_delete_hp_tier(): void
    {
        $admin = $this->admin();
        $admin->update(['permissions' => ['manage_service_types', 'edit_fees']]);
        $type = $this->type();
        $tier = ServiceHpTier::create(['service_type_id' => $type->id, 'hp_value' => 2.0, 'price' => 35.00]);

        $this->actingAs($admin)->delete(route('service-hp-tiers.destroy', $tier))->assertRedirect();

        $this->assertSame(0, ServiceHpTier::count());
    }

    public function test_deleting_service_type_cascades_to_tiers(): void
    {
        $type = $this->type();
        ServiceHpTier::create(['service_type_id' => $type->id, 'hp_value' => 1.0, 'price' => 15.00]);

        $type->delete();

        $this->assertSame(0, ServiceHpTier::count());
    }

    public function test_non_edit_fees_cannot_create_tier(): void
    {
        $tech = User::factory()->create(['role' => 'technician', 'permissions' => ['manage_service_types']]);
        $type = $this->type();

        $this->actingAs($tech)->post(route('service-hp-tiers.store'), [
            'service_type_id' => $type->id,
            'hp_value' => 1.5,
            'price' => 25.00,
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests — expect failures (routes/controller missing)**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceHpTierTest
```

Expected: all FAIL.

- [ ] **Step 3: Create ServiceHpTierController**

```php
<?php
// app/Http/Controllers/ServiceHpTierController.php
namespace App\Http\Controllers;

use App\Models\ServiceHpTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServiceHpTierController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('edit_fees'), 403);

        $data = $request->validate([
            'service_type_id' => ['required', 'integer', 'exists:service_types,id'],
            'hp_value' => ['required', 'numeric', 'min:0.5', 'max:20'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        ServiceHpTier::updateOrCreate(
            ['service_type_id' => $data['service_type_id'], 'hp_value' => $data['hp_value']],
            ['price' => $data['price']],
        );

        return back()->with('success', 'HP tier saved.');
    }

    public function destroy(ServiceHpTier $tier): RedirectResponse
    {
        abort_unless(request()->user()->can('edit_fees'), 403);

        $tier->delete();

        return back()->with('success', 'HP tier removed.');
    }
}
```

- [ ] **Step 4: Update ServiceTypeController::update() to accept is_hp_based**

In `app/Http/Controllers/ServiceTypeController.php`, update the `update()` method validation and save:

```php
public function update(Request $request, ServiceType $serviceType): RedirectResponse
{
    $request->validate([
        'name' => ['required', 'string', 'max:100', "unique:service_types,name,{$serviceType->id}"],
        'requires_next_service' => ['boolean'],
        'is_hp_based' => ['boolean'],
    ]);

    $oldName = $serviceType->name;
    $newName = $request->input('name');

    $serviceType->update([
        'name' => $newName,
        'requires_next_service' => $request->boolean('requires_next_service', $serviceType->requires_next_service),
        'is_hp_based' => $request->boolean('is_hp_based', $serviceType->is_hp_based),
    ]);

    if ($oldName !== $newName) {
        DB::table('service_fees')->where('service_type', $oldName)->update(['service_type' => $newName]);
        DB::table('service_lines')->where('service_type', $oldName)->update(['service_type' => $newName]);
    }

    return back()->with('success', 'Service type updated.');
}
```

- [ ] **Step 5: Add routes**

In `routes/web.php`, inside `Route::middleware('can:edit_fees')->group(...)` add after the existing fee routes:

```php
use App\Http\Controllers\ServiceHpTierController;

Route::post('service-hp-tiers', [ServiceHpTierController::class, 'store'])->name('service-hp-tiers.store');
Route::delete('service-hp-tiers/{tier}', [ServiceHpTierController::class, 'destroy'])->name('service-hp-tiers.destroy');
```

Also add `use App\Http\Controllers\ServiceHpTierController;` to the top-level imports in `routes/web.php`.

Actually, the routes should use `can:edit_fees` gate. Place them inside the existing `Route::middleware('can:edit_fees')->group(...)` block that already has `fees.store`, `fees.update`, `fees.destroy`.

- [ ] **Step 6: Run HP tier tests**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceHpTierTest
```

Expected: all 6 tests PASS.

- [ ] **Step 7: Run full suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/ServiceHpTierController.php app/Http/Controllers/ServiceTypeController.php routes/web.php tests/Feature/ServiceHpTierTest.php
git commit -m "feat: ServiceHpTierController + is_hp_based on ServiceType update + routes"
```

---

### Task 3: Props — hpTiers in ServiceTypeController::index() and ServiceVisitController::create()

**Files:**
- Modify: `app/Http/Controllers/ServiceTypeController.php` (index method)
- Modify: `app/Http/Controllers/ServiceVisitController.php` (create method)

- [ ] **Step 1: Update ServiceTypeController::index() to pass hpTiers + is_hp_based**

In `ServiceTypeController::index()`, replace the render call:

```php
public function index(): Response
{
    $fees = ServiceFee::orderBy('service_type')->orderBy('option')->get();

    return Inertia::render('ServiceTypes/Index', [
        'serviceTypes' => ServiceType::orderBy('name')->get(['id', 'name', 'requires_next_service', 'is_hp_based']),
        'feeGroups'    => $fees->groupBy('service_type'),
        'modes'        => StoreServiceFeeRequest::MODES,
        'hpTiers'      => \App\Models\ServiceHpTier::orderBy('hp_value')
                            ->get(['id', 'service_type_id', 'hp_value', 'price'])
                            ->groupBy('service_type_id'),
    ]);
}
```

- [ ] **Step 2: Update ServiceVisitController::create() to pass hpTiers + is_hp_based in serviceTypes**

In `ServiceVisitController::create()`, change the `serviceTypes` and add `hpTiers`:

```php
'serviceTypes' => ServiceType::orderBy('name')->get(['id', 'name', 'requires_next_service', 'is_hp_based'])->toArray(),
'hpTiers' => \App\Models\ServiceHpTier::orderBy('hp_value')
                ->get(['id', 'service_type_id', 'hp_value', 'price'])
                ->groupBy('service_type_id'),
```

These two lines go inside the existing `create()` return array, replacing the current `'serviceTypes'` line and adding `'hpTiers'` after it.

- [ ] **Step 3: Run full suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all green (prop changes are additive, no breakage).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/ServiceTypeController.php app/Http/Controllers/ServiceVisitController.php
git commit -m "feat: pass hpTiers + is_hp_based props to ServiceTypes/Index and ServiceRecords/Create"
```

---

### Task 4: normalizeLine() HP rate logic + StoreServiceVisitRequest

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php` (normalizeLine method)
- Modify: `app/Http/Requests/StoreServiceVisitRequest.php`

- [ ] **Step 1: Write failing test for HP pricing in service record store**

Add to `tests/Feature/ServiceHpTierTest.php`:

```php
public function test_service_record_line_rate_includes_hp_surcharge(): void
{
    $this->seed(\Database\Seeders\ServiceTypeSeeder::class);

    $type = ServiceType::where('name', 'Gas Top-Up')->first();
    $type->update(['is_hp_based' => true]);

    \App\Models\ServiceFee::insert([
        ['service_type' => 'Gas Top-Up', 'option' => 'Full Top-Up', 'rate' => 50.00, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
    ]);

    ServiceHpTier::create(['service_type_id' => $type->id, 'hp_value' => 1.5, 'price' => 20.00]);

    $admin = $this->admin();
    $admin->update(['tenant_id' => $admin->id]);
    $client = \App\Models\Client::create(['name' => 'C', 'phone' => '012-0000000', 'address' => 'KL', 'tenant_id' => $admin->tenantId()]);

    $this->actingAs($admin)->post(route('service-records.store'), [
        'client_mode' => 'existing',
        'client_id' => $client->id,
        'visit_date' => '2026-06-16',
        'warranty_months' => 0,
        'payment_method' => 'DuitNow QR',
        'lines' => [[
            'service_type' => 'Gas Top-Up',
            'unit_type' => null,
            'gas_option' => 'Full Top-Up',
            'units' => 1,
            'rate' => 0,
            'discount' => 0,
            'hp_value' => 1.5,
        ]],
    ])->assertRedirect();

    $line = \App\Models\ServiceLine::latest('id')->first();
    $this->assertSame('70.00', $line->rate);
    $this->assertSame('1.5', $line->hp_value);
}

public function test_line_without_hp_value_uses_base_fee_only(): void
{
    $this->seed(\Database\Seeders\ServiceTypeSeeder::class);

    \App\Models\ServiceFee::insert([
        ['service_type' => 'Gas Top-Up', 'option' => 'Full Top-Up', 'rate' => 50.00, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $admin = $this->admin();
    $admin->update(['tenant_id' => $admin->id]);
    $client = \App\Models\Client::create(['name' => 'C', 'phone' => '012-0000000', 'address' => 'KL', 'tenant_id' => $admin->tenantId()]);

    $this->actingAs($admin)->post(route('service-records.store'), [
        'client_mode' => 'existing',
        'client_id' => $client->id,
        'visit_date' => '2026-06-16',
        'warranty_months' => 0,
        'payment_method' => 'DuitNow QR',
        'lines' => [[
            'service_type' => 'Gas Top-Up',
            'gas_option' => 'Full Top-Up',
            'units' => 1,
            'rate' => 0,
            'discount' => 0,
        ]],
    ])->assertRedirect();

    $line = \App\Models\ServiceLine::latest('id')->first();
    $this->assertSame('50.00', $line->rate);
    $this->assertNull($line->hp_value);
}
```

- [ ] **Step 2: Run new tests — expect failure**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="ServiceHpTierTest::test_service_record_line_rate_includes_hp_surcharge"
```

Expected: FAIL (normalizeLine doesn't handle hp_value yet).

- [ ] **Step 3: Add hp_value to StoreServiceVisitRequest rules**

In `app/Http/Requests/StoreServiceVisitRequest.php`, add to the `rules()` array:

```php
'lines.*.hp_value' => ['nullable', 'numeric', 'min:0.5', 'max:20'],
```

Add this line after `'lines.*.unit_id'` in the rules array.

Also update `withValidator()` to skip the R1 fee-existence check for HP-based types that have no option-based fee. Find the section:

```php
} elseif ($type) {
    // R1 — a matching fee must exist so the rate can be snapshotted server-side.
    $option = $type === 'Gas Top-Up' ? ($line['gas_option'] ?? null) : ($line['unit_type'] ?? null);
    if ($option && ! ServiceFee::where('service_type', $type)->where('option', $option)->exists()) {
        $v->errors()->add("$key.service_type", "No fee configured for {$type} · {$option}.");
    }
}
```

Replace with:

```php
} elseif ($type) {
    $isHpBased = \App\Models\ServiceType::where('name', $type)->value('is_hp_based');
    if (! $isHpBased) {
        // R1 — a matching fee must exist so the rate can be snapshotted server-side.
        $option = $type === 'Gas Top-Up' ? ($line['gas_option'] ?? null) : ($line['unit_type'] ?? null);
        if ($option && ! ServiceFee::where('service_type', $type)->where('option', $option)->exists()) {
            $v->errors()->add("$key.service_type", "No fee configured for {$type} · {$option}.");
        }
    }
}
```

- [ ] **Step 4: Update normalizeLine() to compute HP surcharge**

In `ServiceVisitController::normalizeLine()`, update the rate calculation block:

```php
// R1 — rate is server-authoritative from the fee book, except Repair (flexible/manual).
if ($isRepair) {
    $rate = (float) $line['rate'];
} else {
    $option = $isGas ? $gasOption : $unitType;
    $rate = (float) ServiceFee::where('service_type', $type)->where('option', $option)->value('rate');

    if (!empty($line['hp_value'])) {
        $serviceTypeId = \App\Models\ServiceType::where('name', $type)->value('id');
        if ($serviceTypeId) {
            $hpRate = (float) \App\Models\ServiceHpTier::where('service_type_id', $serviceTypeId)
                ->where('hp_value', (float) $line['hp_value'])
                ->value('price');
            $rate += $hpRate;
        }
    }
}
```

Also update the return array to include `hp_value`:

```php
return [
    'unit_id'           => $hasUnit ? (int) $line['unit_id'] : null,
    'service_type'      => $type,
    'unit_type'         => $unitType,
    'gas_option'        => $gasOption,
    'units'             => $hasUnit ? 1 : (int) $line['units'],
    'rate'              => $rate,
    'repair_desc'       => $isRepair ? ($line['repair_desc'] ?? null) : null,
    'discount'          => (float) ($line['discount'] ?? 0),
    'hp_value'          => !empty($line['hp_value']) ? (float) $line['hp_value'] : null,
    'next_service_date' => ($carriesUnitType && !$hasUnit) ? ($line['next_service_date'] ?? null) : null,
    'notes'             => $isRepair ? null : ($line['notes'] ?? null),
];
```

- [ ] **Step 5: Run HP pricing tests**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceHpTierTest
```

Expected: all 8 tests PASS.

- [ ] **Step 6: Run full suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php app/Http/Requests/StoreServiceVisitRequest.php tests/Feature/ServiceHpTierTest.php
git commit -m "feat: normalizeLine computes base_fee + HP surcharge; hp_value stored on service line"
```

---

### Task 5: Fee Schedule UI — HP toggle + tier table in ServiceTypes/Index.vue

**Files:**
- Modify: `resources/js/Pages/ServiceTypes/Index.vue`

- [ ] **Step 1: Add hpTiers prop and HP toggle/tier logic**

In `ServiceTypes/Index.vue`, update the `<script setup>` block:

At the `defineProps` call, add `hpTiers`:

```js
const props = defineProps({
    serviceTypes: Array,
    feeGroups: { type: Object, default: () => ({}) },
    modes: Array,
    hpTiers: { type: Object, default: () => ({}) },
});
```

Add icon import — at the top, add `IconToggleLeft` to the `@tabler/icons-vue` import:

```js
import { IconPencil, IconCheck, IconX, IconPlus, IconToggleLeft } from '@tabler/icons-vue';
```

Add HP tier management functions after the `remove` function:

```js
// HP tier management
const hpAddForms = ref({});

function getHpForm(typeId) {
    if (!hpAddForms.value[typeId]) {
        hpAddForms.value[typeId] = { hp_value: '', price: '', processing: false, error: '' };
    }
    return hpAddForms.value[typeId];
}

function toggleHpBased(type) {
    router.put(route('service-types.update', type.id), {
        name: type.name,
        is_hp_based: !type.is_hp_based,
    }, { preserveScroll: true });
}

function addHpTier(typeId) {
    const f = getHpForm(typeId);
    f.error = '';
    if (!f.hp_value || !f.price) { f.error = 'HP and price required.'; return; }
    f.processing = true;
    router.post(route('service-hp-tiers.store'), {
        service_type_id: typeId,
        hp_value: f.hp_value,
        price: f.price,
    }, {
        preserveScroll: true,
        onSuccess: () => { f.hp_value = ''; f.price = ''; f.processing = false; },
        onError: () => { f.processing = false; },
    });
}

function removeHpTier(tier) {
    router.delete(route('service-hp-tiers.destroy', tier.id), { preserveScroll: true });
}

const STANDARD_HP = [1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 5.0];
```

- [ ] **Step 2: Update Fee Schedule tab template to show HP section per card**

**Important:** Iterate `serviceTypes` (not `feeGroups`) in the Fee Schedule tab so HP-based types with no fee rows still appear and can have tiers configured.

Remove the empty-state check `v-if="Object.keys(feeGroups).length === 0"` and the `v-else` wrapper. Replace the entire Fee Schedule tab content with:

```html
<div v-if="serviceTypes.length === 0" class="rounded-ral border border-line bg-surface p-10 text-center shadow-card">
    <p class="text-sm font-medium text-ink-soft">No service types yet.</p>
</div>

<div v-else class="space-y-4">
    <div
        v-for="type in serviceTypes"
        :key="type.id"
        class="overflow-hidden rounded-ral border border-line bg-surface shadow-card"
    >
```

Close the `v-else` div after the FeeModal at the bottom. Now replace the inner content of each card with:

```html
<!-- Service type header with HP toggle -->
<div class="flex items-center justify-between gap-3 border-b border-line bg-surface-muted px-4 py-2.5">
    <Badge :variant="serviceVariant(type.name)">{{ type.name }}</Badge>
    <button
        v-if="canEditFees"
        type="button"
        class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition"
        :class="type.is_hp_based
            ? 'bg-primary/10 text-primary'
            : 'bg-surface-muted text-ink-soft border border-line'"
        @click="toggleHpBased(type)"
    >
        <span
            class="h-3 w-3 rounded-full border-2 transition"
            :class="type.is_hp_based ? 'border-primary bg-primary' : 'border-ink-muted bg-transparent'"
        />
        HP-based pricing
    </button>
</div>

<!-- Regular fee rows (if any exist for this type) -->
<div v-if="feeGroups[type.name]?.length" class="divide-y divide-line">
    <div
        v-for="f in feeGroups[type.name]"
        :key="f.id"
        class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3"
    >
        <div class="flex items-center gap-3">
            <span class="text-sm font-medium text-ink">{{ f.option || 'Flat job' }}</span>
        </div>
        <div class="flex items-center gap-4">
            <Badge v-if="f.pricing_mode === 'flexible'" variant="amber">Flexible</Badge>
            <span v-else class="font-mono font-semibold text-navy-800">
                {{ money(f.rate) }}<span class="ml-1 text-xs font-normal text-ink-soft">/ {{ modeLabel[f.pricing_mode] ?? f.pricing_mode }}</span>
            </span>
            <div v-if="canEditFees" class="flex items-center gap-3">
                <button type="button" class="text-sm font-medium text-primary hover:text-primary-hover" @click="openEdit(f)">Edit</button>
                <button type="button" class="text-sm font-medium text-danger hover:underline" @click="remove(f)">Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- HP Tiers section (visible when is_hp_based) -->
<template v-if="type.is_hp_based">
    <div class="border-t border-line bg-surface-muted/50 px-4 py-2.5">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">HP Tiers</p>
    </div>
    <div class="divide-y divide-line">
        <div
            v-for="tier in hpTiers[type.id] ?? []"
            :key="tier.id"
            class="flex items-center justify-between px-4 py-2.5"
        >
            <span class="text-sm text-ink font-mono">{{ Number(tier.hp_value).toFixed(1) }} HP</span>
            <div class="flex items-center gap-4">
                <span class="font-mono font-semibold text-navy-800">{{ money(tier.price) }}</span>
                <button
                    v-if="canEditFees"
                    type="button"
                    class="text-sm font-medium text-danger hover:underline"
                    @click="removeHpTier(tier)"
                >Delete</button>
            </div>
        </div>

        <!-- Add HP tier row -->
        <div v-if="canEditFees" class="flex flex-wrap items-end gap-3 px-4 py-3">
            <div>
                <label class="mb-1 block text-xs font-semibold text-ink-muted">HP</label>
                <input
                    v-model="getHpForm(type.id).hp_value"
                    type="number"
                    step="0.5"
                    min="0.5"
                    max="20"
                    placeholder="e.g. 1.5"
                    list="std-hp"
                    class="w-24 rounded-ra border border-line bg-surface px-3 py-1.5 text-sm text-ink shadow-card focus:border-primary focus:outline-none"
                />
                <datalist id="std-hp">
                    <option v-for="hp in STANDARD_HP" :key="hp" :value="hp" />
                </datalist>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-ink-muted">Price (RM)</label>
                <input
                    v-model="getHpForm(type.id).price"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    class="w-28 rounded-ra border border-line bg-surface px-3 py-1.5 text-sm text-ink shadow-card focus:border-primary focus:outline-none"
                />
            </div>
            <button
                type="button"
                class="rounded-ra bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                :disabled="getHpForm(type.id).processing"
                @click="addHpTier(type.id)"
            >Add tier</button>
            <p v-if="getHpForm(type.id).error" class="w-full text-xs text-danger">
                {{ getHpForm(type.id).error }}
            </p>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Build frontend**

```bash
docker compose exec -T laravel.test npm run build
```

Expected: build completes without errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/ServiceTypes/Index.vue
git commit -m "feat: HP toggle + tier table in Fee Schedule tab"
```

---

### Task 6: ServiceLineCard.vue + Create.vue — HP dropdown

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Partials/ServiceLineCard.vue`
- Modify: `resources/js/Pages/ServiceRecords/Create.vue`

- [ ] **Step 1: Update ServiceLineCard.vue — add hpTiers prop + HP dropdown**

In `ServiceLineCard.vue`, update `defineProps` to add `hpTiers`:

```js
const props = defineProps({
    line: { type: Object, required: true },
    index: { type: Number, required: true },
    feeMap: { type: Object, required: true },
    serviceTypes: Array,
    unitTypes: Array,
    gasOptions: Array,
    unitTypeServices: Array,
    clientUnits: { type: Array, default: () => [] },
    hpTiers: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
    removable: Boolean,
    visitDate: { type: String, default: null },
});
```

Add computed properties for HP logic (after the existing `requiresNextService` computed):

```js
const isHpBased = computed(() => {
    if (!props.line.service_type) return false;
    const t = props.serviceTypes?.find(t => t.name === props.line.service_type);
    return t?.is_hp_based ?? false;
});

const currentServiceTypeId = computed(() => {
    const t = props.serviceTypes?.find(t => t.name === props.line.service_type);
    return t?.id ?? null;
});

const hpOptions = computed(() => {
    if (!currentServiceTypeId.value) return [];
    return props.hpTiers?.[currentServiceTypeId.value] ?? [];
});
```

Update the `watch(() => props.line.service_type, ...)` watcher to clear `hp_value` on service type change:

```js
watch(() => props.line.service_type, () => {
    props.line.unit_type = null;
    props.line.unit_id = null;
    props.line.gas_option = null;
    props.line.hp_value = null;
    props.line.repair_desc = '';
    props.line.next_service_date = null;
    nextServiceMonths.value = null;
    props.line.notes = '';
    if (isRepair.value) props.line.rate = '';
    autofill();
});
```

Add a watcher for `hp_value` changes:

```js
watch(() => props.line.hp_value, () => {
    if (!isHpBased.value) return;
    autofill();
});
```

Update `autofill()` to incorporate HP pricing:

```js
function autofill() {
    if (isRepair.value || !props.line.service_type) return;
    const option = isGas.value ? props.line.gas_option : props.line.unit_type;
    let baseRate;
    if (!option) {
        const flat = props.feeMap[props.line.service_type];
        baseRate = flat != null ? flat : 0;
    } else {
        baseRate = props.feeMap[`${props.line.service_type}|${option}`] ?? 0;
    }

    if (isHpBased.value && props.line.hp_value) {
        const tier = hpOptions.value.find(t => Number(t.hp_value) === Number(props.line.hp_value));
        const hpPrice = tier ? Number(tier.price) : 0;
        props.line.rate = baseRate + hpPrice;
    } else if (!isHpBased.value) {
        props.line.rate = baseRate !== 0 ? baseRate : '';
    }
}
```

Add the HP dropdown to the template, after the gas option block and before the repair description block:

```html
<!-- HP dropdown (HP-based service types) -->
<div v-if="isHpBased">
    <label class="mb-1.5 block text-sm font-semibold text-ink">Horsepower (HP)</label>
    <select
        v-model.number="line.hp_value"
        class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
    >
        <option :value="null" disabled>Choose HP…</option>
        <option v-for="tier in hpOptions" :key="tier.id" :value="Number(tier.hp_value)">
            {{ Number(tier.hp_value).toFixed(1) }} HP — {{ 'RM ' + Number(tier.price).toFixed(2) }}
        </option>
    </select>
    <InputError :message="err('hp_value')" />
</div>
```

Place this block inside the `<div class="grid gap-4 sm:grid-cols-2">` after the gas option `<div v-if="isGas">` block and before the repair description `<div v-if="isRepair">` block.

- [ ] **Step 2: Update Create.vue — add hp_value to blankLine + pass hpTiers prop**

In `Create.vue`:

Update `defineProps` to add `hpTiers`:

```js
const props = defineProps({
    fees: Array,
    serviceTypes: Array,
    unitTypes: Array,
    gasOptions: Array,
    unitTypeServices: Array,
    presetClient: { type: Object, default: null },
    presetClientUnits: { type: Array, default: () => [] },
    presetTechnicianId: { type: Number, default: null },
    technicians: { type: Array, default: null },
    hpTiers: { type: Object, default: () => ({}) },
});
```

Update `blankLine()` to include `hp_value`:

```js
const blankLine = () => ({
    unit_id: null, service_type: '', unit_type: null, gas_option: null, repair_desc: '',
    units: 1, rate: '', discount: 0, next_service_date: null, notes: '', hp_value: null,
});
```

Update `addLinesForAllUnits()` to include `hp_value: null`:

```js
form.lines.push({
    unit_id: unit.id,
    service_type: '',
    unit_type: unit.unit_type,
    gas_option: null,
    repair_desc: '',
    units: 1,
    rate: '',
    discount: 0,
    next_service_date: null,
    notes: '',
    hp_value: null,
});
```

In the template, find all `<ServiceLineCard` usages and add `:hp-tiers="hpTiers"`:

```html
<ServiceLineCard
    v-for="(line, i) in form.lines"
    :key="i"
    :line="line"
    :index="i"
    :fee-map="feeMap"
    :service-types="serviceTypes"
    :unit-types="unitTypes"
    :gas-options="gasOptions"
    :unit-type-services="unitTypeServices"
    :client-units="clientUnits"
    :hp-tiers="hpTiers"
    :errors="form.errors"
    :removable="form.lines.length > 1"
    :visit-date="form.visit_date"
    @remove="removeLine(i)"
/>
```

- [ ] **Step 3: Build frontend**

```bash
docker compose exec -T laravel.test npm run build
```

Expected: build completes without errors.

- [ ] **Step 4: Run full suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ServiceRecords/Partials/ServiceLineCard.vue resources/js/Pages/ServiceRecords/Create.vue
git commit -m "feat: HP dropdown in service line card — autofills base_fee + hp_tier_price"
```

---

### Task 7: Final verification

- [ ] **Step 1: Run full test suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all existing tests green + new ServiceHpTierTest (8 tests) = suite count +8.

- [ ] **Step 2: Manual smoke test (dev environment)**

1. Log in as admin. Go to Settings → Services → Fee Schedule tab.
2. On "Gas Top-Up" card — click "HP-based pricing" toggle. Card should show HP Tiers section.
3. Add a tier: 1.5 HP, RM 20.00. Click "Add tier". Tier appears in the list.
4. Add another: 2.0 HP, RM 30.00. Appears in list.
5. Go to Service Records → New Service Record. Select a client. Add a line.
6. Choose "Gas Top-Up" as service type. Choose "Full Top-Up" gas option.
7. HP dropdown should appear. Select 1.5 HP. Rate field should auto-fill to base fee + RM 20.00.
8. Submit the form. View the created service record — line should show correct rate.
9. Toggle "HP-based pricing" OFF on Gas Top-Up. HP tiers section hides (tiers still in DB).
10. Toggle back ON — tiers reappear.

- [ ] **Step 3: Update FEEDBACK doc — mark FEAT-018 TESTING**

In `docs/FEEDBACK-13062026.md`, change FEAT-018 status from `OPEN` to `TESTING`.

- [ ] **Step 4: Commit**

```bash
git add docs/FEEDBACK-13062026.md
git commit -m "docs: mark FEAT-018 TESTING"
```
