# Service Pricing Unification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the three hardcoded/split pricing mechanisms (`service_fees` by name, separate additive `service_hp_tiers`, hardcoded `UNIT_TYPES`/`GAS_OPTIONS`/`UNIT_TYPE_SERVICES`/`Repair`/`Gas Top-Up`) with one data-driven model: each service type has a `pricing_mode` (`flat` | `hp_tiered` | `flexible`) and a single `service_fees` table of per-unit-type price rows, where each unit type owns its own HP→price set (direct lookup, no surcharge).

**Architecture:** `service_types.pricing_mode` drives everything. `service_fees(service_type_id, unit_type, hp_value?, price)` is the single price book and absorbs `service_hp_tiers`. The Service Settings fee editor saves a whole price set per service in one transactional replace. The service-record line resolves rate by branching on `pricing_mode` instead of service-name string checks. Flexible services keep an editable price + description (BUG-002). HP tiers add/edit inline (FEAT-003).

**Tech Stack:** Laravel 12 (PHP 8.5), Inertia + Vue 3, Postgres 16, Tailwind. Tests via PHPUnit in Docker. No model factories — fixtures via `Model::create()`.

**Conventions:**
- Run tests: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
- Run one test: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=TestName`
- Migrate: `docker exec saifzz-aircond-laravel.test-1 php artisan migrate`
- Fresh + seed: `docker exec saifzz-aircond-laravel.test-1 php artisan migrate:fresh --seed`
- Build: `docker compose exec -T laravel.test npm run build`
- `service_types` + `service_fees` are GLOBAL (no `tenant_id`) — do not add tenant scoping.
- Existing fee/line data is disposable (FEEDBACK Q7) — migrations may rebuild destructively.
- Commit after every task. No `Co-Authored-By` trailer.

---

## File Structure

**Migrations (create):**
- `database/migrations/2026_06_18_000010_add_pricing_mode_to_service_types_table.php`
- `database/migrations/2026_06_18_000011_rebuild_service_fees_table.php`
- `database/migrations/2026_06_18_000012_drop_service_hp_tiers_table.php`
- `database/migrations/2026_06_18_000013_drop_gas_option_from_service_lines_table.php`

**Models:**
- Modify `app/Models/ServiceType.php` — `pricing_mode`, `fees()`, drop `is_hp_based`/`hpTiers()`.
- Modify `app/Models/ServiceFee.php` — new columns + `serviceType()`.
- Delete `app/Models/ServiceHpTier.php`.
- Modify `app/Models/ServiceLine.php` — drop `gas_option` from fillable.

**Requests:**
- Create `app/Http/Requests/SyncServiceFeesRequest.php`.
- Delete `app/Http/Requests/StoreServiceFeeRequest.php`, `app/Http/Requests/UpdateServiceFeeRequest.php`.
- Modify `app/Http/Requests/StoreServiceVisitRequest.php` — drop constants, rewrite validation off `pricing_mode`.

**Controllers:**
- Modify `app/Http/Controllers/ServiceTypeController.php` — `index()` new props; add `syncFees()`.
- Delete `app/Http/Controllers/ServiceFeeController.php`, `app/Http/Controllers/ServiceHpTierController.php`.
- Modify `app/Http/Controllers/ServiceVisitController.php` — `create()`/`edit()` props, rewrite `normalizeLine()`.
- Modify `app/Http/Controllers/CatalogController.php` — new fee shape.

**Routes:** Modify `routes/web.php`.

**Seeders:** Modify `database/seeders/ServiceTypeSeeder.php`, `database/seeders/ServiceFeeSeeder.php`.

**Docs/snapshot:**
- Modify `app/Services/Documents/SnapshotBuilder.php`, `resources/views/documents/invoice.blade.php`, `resources/views/documents/receipt.blade.php`.

**Frontend:**
- Modify `resources/js/Pages/ServiceTypes/Index.vue` (dynamic fee editor).
- Delete `resources/js/Pages/ServiceTypes/Partials/FeeModal.vue`.
- Modify `resources/js/Pages/ServiceRecords/Partials/ServiceLineCard.vue`.
- Modify `resources/js/Pages/ServiceRecords/Create.vue`, `resources/js/Pages/ServiceRecords/Edit.vue`.
- Modify `resources/js/Pages/ServiceRecords/Show.vue`, `resources/js/Pages/Portal/Show.vue`, `resources/js/Pages/Clients/Show.vue` (drop `gas_option` display).
- Modify `resources/js/Pages/Catalog/Index.vue`.

**Tests:**
- Delete `tests/Feature/ServiceHpTierTest.php`.
- Rewrite `tests/Feature/ServiceFeeTest.php`.
- Create `tests/Feature/ServicePricingTest.php`.
- Fix references in `tests/Feature/ServiceVisitTest.php`, `tests/Feature/MultiTenantIsolationTest.php`, `tests/Feature/TechnicianScopingTest.php`.

---

## Task 1: Schema migrations + models + seeders

**Files:**
- Create: the 4 migrations above
- Modify: `app/Models/ServiceType.php`, `app/Models/ServiceFee.php`, `app/Models/ServiceLine.php`
- Delete: `app/Models/ServiceHpTier.php`
- Modify: `database/seeders/ServiceTypeSeeder.php`, `database/seeders/ServiceFeeSeeder.php`

- [ ] **Step 1: Write migration — pricing_mode on service_types**

`database/migrations/2026_06_18_000010_add_pricing_mode_to_service_types_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->string('pricing_mode', 20)->default('flat')->after('name');
        });

        // Backfill from old semantics: Repair = flexible, is_hp_based = hp_tiered, else flat.
        DB::table('service_types')->where('name', 'Repair')->update(['pricing_mode' => 'flexible']);
        if (Schema::hasColumn('service_types', 'is_hp_based')) {
            DB::table('service_types')->where('is_hp_based', true)->update(['pricing_mode' => 'hp_tiered']);
            Schema::table('service_types', function (Blueprint $table) {
                $table->dropColumn('is_hp_based');
            });
        }
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->boolean('is_hp_based')->default(false)->after('name');
        });
        DB::table('service_types')->where('pricing_mode', 'hp_tiered')->update(['is_hp_based' => true]);
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
        });
    }
};
```

- [ ] **Step 2: Write migration — rebuild service_fees**

`database/migrations/2026_06_18_000011_rebuild_service_fees_table.php` (data disposable → drop & recreate):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('service_fees');

        Schema::create('service_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained('service_types')->cascadeOnDelete();
            $table->string('unit_type');
            $table->decimal('hp_value', 3, 1)->nullable();
            $table->decimal('price', 8, 2);
            $table->timestamps();

            $table->unique(['service_type_id', 'unit_type', 'hp_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_fees');

        // Restore the pre-unification shape (name-keyed, option/rate/pricing_mode).
        Schema::create('service_fees', function (Blueprint $table) {
            $table->id();
            $table->string('service_type');
            $table->string('option')->nullable();
            $table->decimal('rate', 8, 2)->nullable();
            $table->string('pricing_mode')->default('fixed_per_unit');
            $table->timestamps();
            $table->unique(['service_type', 'option']);
        });
    }
};
```

- [ ] **Step 3: Write migration — drop service_hp_tiers**

`database/migrations/2026_06_18_000012_drop_service_hp_tiers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('service_hp_tiers');
    }

    public function down(): void
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
};
```

- [ ] **Step 4: Write migration — drop gas_option from service_lines**

`database/migrations/2026_06_18_000013_drop_gas_option_from_service_lines_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_lines', function (Blueprint $table) {
            $table->dropColumn('gas_option');
        });
    }

    public function down(): void
    {
        Schema::table('service_lines', function (Blueprint $table) {
            $table->string('gas_option')->nullable()->after('unit_type');
        });
    }
};
```

- [ ] **Step 5: Update ServiceType model**

Replace `app/Models/ServiceType.php` body:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceType extends Model
{
    public const MODES = ['flat', 'hp_tiered', 'flexible'];

    protected $fillable = ['name', 'pricing_mode', 'requires_next_service'];

    protected $casts = [
        'requires_next_service' => 'boolean',
    ];

    public function fees(): HasMany
    {
        return $this->hasMany(ServiceFee::class);
    }
}
```

- [ ] **Step 6: Update ServiceFee model**

Replace `app/Models/ServiceFee.php` body:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceFee extends Model
{
    use HasFactory;

    protected $fillable = ['service_type_id', 'unit_type', 'hp_value', 'price'];

    protected function casts(): array
    {
        return [
            'hp_value' => 'decimal:1',
            'price'    => 'decimal:2',
        ];
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
```

- [ ] **Step 7: Delete ServiceHpTier model + drop gas_option from ServiceLine fillable**

Delete file `app/Models/ServiceHpTier.php`.

In `app/Models/ServiceLine.php`, remove `'gas_option',` from the `$fillable` array (line 18).

- [ ] **Step 8: Update ServiceTypeSeeder**

Replace the `$types` array in `database/seeders/ServiceTypeSeeder.php` so each row carries `pricing_mode`, and write it in `updateOrCreate`:

```php
$types = [
    ['name' => 'Cleaning',     'pricing_mode' => 'hp_tiered', 'requires_next_service' => true],
    ['name' => 'Gas Top-Up',   'pricing_mode' => 'flat',      'requires_next_service' => false],
    ['name' => 'Repair',       'pricing_mode' => 'flexible',  'requires_next_service' => false],
    ['name' => 'Installation', 'pricing_mode' => 'flat',      'requires_next_service' => true],
    ['name' => 'Troubleshoot', 'pricing_mode' => 'flat',      'requires_next_service' => true],
    ['name' => 'Dismantle',    'pricing_mode' => 'flexible',  'requires_next_service' => false],
];

foreach ($types as $type) {
    ServiceType::updateOrCreate(
        ['name' => $type['name']],
        ['pricing_mode' => $type['pricing_mode'], 'requires_next_service' => $type['requires_next_service']],
    );
}
```

- [ ] **Step 9: Update ServiceFeeSeeder to the new shape**

Replace `database/seeders/ServiceFeeSeeder.php` body:

```php
<?php

namespace Database\Seeders;

use App\Models\ServiceFee;
use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceFeeSeeder extends Seeder
{
    public function run(): void
    {
        $byName = ServiceType::pluck('id', 'name');

        // [service type, unit type, hp_value (null = flat), price]
        $fees = [
            // Cleaning — hp_tiered, per unit type
            ['Cleaning', 'Wall Mounted', 1.0, 50],
            ['Cleaning', 'Wall Mounted', 1.5, 60],
            ['Cleaning', 'Wall Mounted', 2.0, 80],
            ['Cleaning', 'Cassette', 1.0, 70],
            ['Cleaning', 'Cassette', 1.5, 85],
            ['Cleaning', 'Cassette', 2.0, 110],
            // Gas Top-Up — flat
            ['Gas Top-Up', '20 PSI', null, 80],
            ['Gas Top-Up', 'Half Top-Up', null, 150],
            ['Gas Top-Up', 'Full Top-Up', null, 280],
            // Installation — flat
            ['Installation', 'Wall Mounted', null, 120],
            ['Installation', 'Cassette', null, 180],
            // Troubleshoot — flat
            ['Troubleshoot', 'Wall Mounted', null, 80],
            ['Troubleshoot', 'Cassette', null, 110],
            // Repair / Dismantle — flexible, no rows
        ];

        foreach ($fees as [$service, $unitType, $hp, $price]) {
            if (! isset($byName[$service])) {
                continue;
            }
            ServiceFee::updateOrCreate(
                ['service_type_id' => $byName[$service], 'unit_type' => $unitType, 'hp_value' => $hp],
                ['price' => $price],
            );
        }
    }
}
```

- [ ] **Step 10: Run fresh migrate + seed to verify schema**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan migrate:fresh --seed`
Expected: completes without error; no reference to `service_hp_tiers` or `is_hp_based`.

> Note: this will fail later code paths until Tasks 2-9 land. That's expected — this step only proves the schema + seeders are valid. If `migrate:fresh` errors because seeders other than these reference removed columns, fix those references as part of this step.

- [ ] **Step 11: Commit**

```bash
git add database/migrations app/Models database/seeders
git commit -m "feat(pricing): unified service_fees schema + pricing_mode (Task 1)"
```

---

## Task 2: Fee-set sync endpoint (CHG-005 backend + FEAT-003 backend)

**Files:**
- Create: `app/Http/Requests/SyncServiceFeesRequest.php`
- Modify: `app/Http/Controllers/ServiceTypeController.php`
- Modify: `routes/web.php`
- Delete: `app/Http/Controllers/ServiceFeeController.php`, `app/Http/Controllers/ServiceHpTierController.php`, `app/Http/Requests/StoreServiceFeeRequest.php`, `app/Http/Requests/UpdateServiceFeeRequest.php`
- Test: `tests/Feature/ServiceFeeTest.php`

- [ ] **Step 1: Write the failing test (rewrite ServiceFeeTest)**

Replace `tests/Feature/ServiceFeeTest.php` entirely. Use the project's existing admin-user helper pattern (look at the top of the old `ServiceFeeTest.php` / `ServiceHpTierTest.php` for how an `edit_fees`-capable user is built — reuse it; do NOT invent factories). Tests:

```php
<?php

namespace Tests\Feature;

use App\Models\ServiceFee;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceFeeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // Mirror the existing helper used by the old fee tests (permission: edit_fees).
        return User::factory_like_helper_from_old_test();
    }

    public function test_sync_saves_flat_unit_type_rows(): void
    {
        $type = ServiceType::create(['name' => 'Gas Top-Up', 'pricing_mode' => 'flat', 'requires_next_service' => false]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'flat',
                'fees' => [
                    ['unit_type' => '20 PSI', 'hp_value' => null, 'price' => 80],
                    ['unit_type' => 'Full Top-Up', 'hp_value' => null, 'price' => 280],
                ],
            ])->assertRedirect();

        $this->assertDatabaseHas('service_fees', ['service_type_id' => $type->id, 'unit_type' => '20 PSI', 'hp_value' => null, 'price' => 80]);
        $this->assertEquals('flat', $type->fresh()->pricing_mode);
        $this->assertCount(2, ServiceFee::where('service_type_id', $type->id)->get());
    }

    public function test_sync_saves_hp_tiered_per_unit_type(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'flat', 'requires_next_service' => true]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'hp_tiered',
                'fees' => [
                    ['unit_type' => 'Wall Mounted', 'hp_value' => 1.0, 'price' => 50],
                    ['unit_type' => 'Wall Mounted', 'hp_value' => 1.5, 'price' => 60],
                    ['unit_type' => 'Cassette', 'hp_value' => 1.0, 'price' => 70],
                ],
            ])->assertRedirect();

        $this->assertEquals('hp_tiered', $type->fresh()->pricing_mode);
        $this->assertDatabaseHas('service_fees', ['service_type_id' => $type->id, 'unit_type' => 'Cassette', 'hp_value' => 1.0, 'price' => 70]);
        $this->assertCount(3, ServiceFee::where('service_type_id', $type->id)->get());
    }

    public function test_sync_replaces_existing_rows(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'flat', 'requires_next_service' => true]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => 'Old', 'hp_value' => null, 'price' => 999]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'flat',
                'fees' => [['unit_type' => 'New', 'hp_value' => null, 'price' => 10]],
            ])->assertRedirect();

        $this->assertDatabaseMissing('service_fees', ['service_type_id' => $type->id, 'unit_type' => 'Old']);
        $this->assertDatabaseHas('service_fees', ['service_type_id' => $type->id, 'unit_type' => 'New']);
    }

    public function test_flexible_clears_all_rows(): void
    {
        $type = ServiceType::create(['name' => 'Repair', 'pricing_mode' => 'flat', 'requires_next_service' => false]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => 'x', 'hp_value' => null, 'price' => 5]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), ['pricing_mode' => 'flexible', 'fees' => []])
            ->assertRedirect();

        $this->assertEquals('flexible', $type->fresh()->pricing_mode);
        $this->assertCount(0, ServiceFee::where('service_type_id', $type->id)->get());
    }

    public function test_hp_tiered_requires_hp_value(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'flat', 'requires_next_service' => true]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'hp_tiered',
                'fees' => [['unit_type' => 'Wall Mounted', 'hp_value' => null, 'price' => 50]],
            ])->assertSessionHasErrors('fees.0.hp_value');
    }

    public function test_flat_rejects_hp_value(): void
    {
        $type = ServiceType::create(['name' => 'Gas Top-Up', 'pricing_mode' => 'flat', 'requires_next_service' => false]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'flat',
                'fees' => [['unit_type' => '20 PSI', 'hp_value' => 1.0, 'price' => 50]],
            ])->assertSessionHasErrors('fees.0.hp_value');
    }

    public function test_duplicate_unit_type_hp_pair_rejected(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'flat', 'requires_next_service' => true]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'hp_tiered',
                'fees' => [
                    ['unit_type' => 'Wall Mounted', 'hp_value' => 1.0, 'price' => 50],
                    ['unit_type' => 'Wall Mounted', 'hp_value' => 1.0, 'price' => 60],
                ],
            ])->assertSessionHasErrors('fees');
    }

    public function test_non_edit_fees_user_forbidden(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'flat', 'requires_next_service' => true]);
        $tech = User::techWithoutEditFees_from_old_test_helper();

        $this->actingAs($tech)
            ->put(route('service-types.fees.sync', $type), ['pricing_mode' => 'flexible', 'fees' => []])
            ->assertForbidden();
    }
}
```

> Before running: replace `factory_like_helper_from_old_test()` / `techWithoutEditFees_from_old_test_helper()` with the real fixture-building code copied from the old `ServiceFeeTest.php`/`ServiceHpTierTest.php` (admin user with `edit_fees`; technician without). Keep it inline — no new factory classes.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceFeeTest`
Expected: FAIL — route `service-types.fees.sync` undefined.

- [ ] **Step 3: Write SyncServiceFeesRequest**

`app/Http/Requests/SyncServiceFeesRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\ServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncServiceFeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('edit_fees');
    }

    public function rules(): array
    {
        $mode = $this->input('pricing_mode');

        return [
            'pricing_mode' => ['required', Rule::in(ServiceType::MODES)],
            'fees' => ['array', Rule::requiredIf($mode !== 'flexible')],
            'fees.*.unit_type' => ['required_with:fees.*', 'string', 'max:255'],
            'fees.*.price' => ['required_with:fees.*', 'numeric', 'min:0'],
            // hp_value: required for hp_tiered, forbidden otherwise.
            'fees.*.hp_value' => [
                Rule::requiredIf($mode === 'hp_tiered'),
                $mode === 'hp_tiered' ? 'numeric' : 'nullable',
                $mode === 'hp_tiered' ? 'min:0.5' : 'prohibited',
                $mode === 'hp_tiered' ? 'max:20' : 'nullable',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $seen = [];
            foreach ((array) $this->input('fees', []) as $i => $fee) {
                $key = ($fee['unit_type'] ?? '') . '|' . ($fee['hp_value'] ?? '');
                if (isset($seen[$key])) {
                    $v->errors()->add('fees', 'Duplicate unit type / HP combination.');
                }
                $seen[$key] = true;
            }
        });
    }
}
```

> Note: `prohibited` on `hp_value` for non-hp modes rejects a non-null value; `nullable` allows it to be absent/null. The conditional array assembles the right rule set per mode.

- [ ] **Step 4: Add syncFees() to ServiceTypeController + update imports**

In `app/Http/Controllers/ServiceTypeController.php`, add `use App\Http\Requests\SyncServiceFeesRequest;` and this method:

```php
public function syncFees(SyncServiceFeesRequest $request, ServiceType $serviceType): RedirectResponse
{
    $data = $request->validated();

    DB::transaction(function () use ($serviceType, $data) {
        $serviceType->update(['pricing_mode' => $data['pricing_mode']]);
        $serviceType->fees()->delete();

        if ($data['pricing_mode'] !== 'flexible') {
            foreach ($data['fees'] as $fee) {
                $serviceType->fees()->create([
                    'unit_type' => $fee['unit_type'],
                    'hp_value'  => $data['pricing_mode'] === 'hp_tiered' ? $fee['hp_value'] : null,
                    'price'     => $fee['price'],
                ]);
            }
        }
    });

    return back()->with('success', 'Fees updated.');
}
```

- [ ] **Step 5: Wire routes + delete dead controllers/requests**

In `routes/web.php`:
- Remove the `fees` redirect + `fees.store/update/destroy` + `service-hp-tiers.store/destroy` routes (lines ~89-96).
- Remove `use App\Http\Controllers\ServiceFeeController;` and `use App\Http\Controllers\ServiceHpTierController;`.
- Inside the `can:edit_fees` group, add:

```php
Route::put('service-types/{serviceType}/fees', [ServiceTypeController::class, 'syncFees'])->name('service-types.fees.sync');
```

Delete files: `app/Http/Controllers/ServiceFeeController.php`, `app/Http/Controllers/ServiceHpTierController.php`, `app/Http/Requests/StoreServiceFeeRequest.php`, `app/Http/Requests/UpdateServiceFeeRequest.php`.

- [ ] **Step 6: Run the test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceFeeTest`
Expected: PASS (all 8).

- [ ] **Step 7: Delete the obsolete HP-tier test + commit**

```bash
git rm tests/Feature/ServiceHpTierTest.php
git add app/Http routes/web.php tests/Feature/ServiceFeeTest.php
git commit -m "feat(pricing): fee-set sync endpoint + validation (Task 2)"
```

---

## Task 3: Service-record pricing resolution (BUG-002 + generalize off pricing_mode)

**Files:**
- Modify: `app/Http/Requests/StoreServiceVisitRequest.php`
- Modify: `app/Http/Controllers/ServiceVisitController.php` (`normalizeLine()`)
- Test: `tests/Feature/ServicePricingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ServicePricingTest.php`. Build a client + service types + fees inline (copy the user/client fixture pattern from `ServiceVisitTest.php`). Cover:

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceFee;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePricingTest extends TestCase
{
    use RefreshDatabase;

    // Helpers: copy admin()/client() fixture builders from ServiceVisitTest.php.

    public function test_flat_line_snapshots_unit_type_price(): void
    {
        $type = ServiceType::create(['name' => 'Gas Top-Up', 'pricing_mode' => 'flat', 'requires_next_service' => false]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => '20 PSI', 'hp_value' => null, 'price' => 80]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Gas Top-Up', 'unit_type' => '20 PSI', 'units' => 1, 'rate' => 999],
        ]))->assertRedirect();

        // Rate is server-authoritative: snapshot = fee price, not the submitted 999.
        $this->assertDatabaseHas('service_lines', ['service_type' => 'Gas Top-Up', 'unit_type' => '20 PSI', 'rate' => 80]);
    }

    public function test_hp_tiered_line_snapshots_per_unit_type_price(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'hp_tiered', 'requires_next_service' => true]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => 'Cassette', 'hp_value' => 1.5, 'price' => 85]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Cleaning', 'unit_type' => 'Cassette', 'hp_value' => 1.5, 'units' => 1],
        ]))->assertRedirect();

        $this->assertDatabaseHas('service_lines', ['service_type' => 'Cleaning', 'unit_type' => 'Cassette', 'hp_value' => 1.5, 'rate' => 85]);
    }

    public function test_flexible_line_uses_submitted_rate_and_keeps_description(): void
    {
        ServiceType::create(['name' => 'Repair', 'pricing_mode' => 'flexible', 'requires_next_service' => false]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Repair', 'repair_desc' => 'Fixed compressor', 'rate' => 250, 'units' => 1],
        ]))->assertRedirect();

        $this->assertDatabaseHas('service_lines', ['service_type' => 'Repair', 'rate' => 250, 'repair_desc' => 'Fixed compressor']);
    }

    public function test_flexible_requires_price_and_description(): void
    {
        ServiceType::create(['name' => 'Repair', 'pricing_mode' => 'flexible', 'requires_next_service' => false]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Repair', 'units' => 1],
        ]))->assertSessionHasErrors(['lines.0.rate', 'lines.0.repair_desc']);
    }

    public function test_unknown_unit_type_rejected(): void
    {
        $type = ServiceType::create(['name' => 'Gas Top-Up', 'pricing_mode' => 'flat', 'requires_next_service' => false]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => '20 PSI', 'hp_value' => null, 'price' => 80]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Gas Top-Up', 'unit_type' => 'Nonexistent', 'units' => 1],
        ]))->assertSessionHasErrors('lines.0.unit_type');
    }

    public function test_hp_tiered_requires_valid_hp(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'hp_tiered', 'requires_next_service' => true]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => 'Wall Mounted', 'hp_value' => 1.0, 'price' => 50]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'hp_value' => 9.9, 'units' => 1],
        ]))->assertSessionHasErrors('lines.0.hp_value');
    }
}
```

> `payload(array $lines)` helper: copy a valid base store payload (client_mode, client_id, visit_date, warranty_months, payment_method, technician_id) from `ServiceVisitTest.php` and merge in `lines`. Use an admin with `collect_payment` so `payment_method` passes, or set `payment_method = 'DuitNow QR'`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServicePricingTest`
Expected: FAIL (old validation references removed columns / wrong rate).

- [ ] **Step 3: Rewrite StoreServiceVisitRequest**

In `app/Http/Requests/StoreServiceVisitRequest.php`:
- Delete the three `const UNIT_TYPES`, `GAS_OPTIONS`, `UNIT_TYPE_SERVICES`.
- Remove `lines.*.unit_type` / `lines.*.gas_option` `Rule::in(...)` rules; replace with:

```php
'lines.*.unit_type' => ['nullable', 'string', 'max:255'],
```
(drop `lines.*.gas_option` entirely.)

- Replace the `withValidator` per-line loop body with pricing-mode-driven logic:

```php
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

    // flat / hp_tiered both need a unit type that exists in the fee book.
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
```

(Keep the existing Cash-permission check above the loop unchanged.)

- [ ] **Step 4: Rewrite normalizeLine()**

Replace `normalizeLine()` in `app/Http/Controllers/ServiceVisitController.php`:

```php
private function normalizeLine(array $line): array
{
    $typeName = $line['service_type'];
    $serviceType = \App\Models\ServiceType::where('name', $typeName)->first();
    $mode = $serviceType?->pricing_mode ?? 'flexible';
    $isFlexible = $mode === 'flexible';
    $isHp = $mode === 'hp_tiered';
    $requiresNext = $serviceType?->requires_next_service ?? false;
    $hasUnit = ! empty($line['unit_id']);

    $unitType = $isFlexible ? null : ($line['unit_type'] ?? null);
    $hpValue = $isHp && ! empty($line['hp_value']) ? (float) $line['hp_value'] : null;

    if ($isFlexible) {
        $rate = (float) $line['rate'];
    } else {
        $q = \App\Models\ServiceFee::where('service_type_id', $serviceType->id)
            ->where('unit_type', $unitType);
        $isHp ? $q->where('hp_value', $hpValue) : $q->whereNull('hp_value');
        $rate = (float) $q->value('price');
    }

    return [
        'unit_id'           => $hasUnit ? (int) $line['unit_id'] : null,
        'service_type'      => $typeName,
        'unit_type'         => $unitType,
        'units'             => $hasUnit ? 1 : (int) $line['units'],
        'rate'              => $rate,
        'repair_desc'       => $isFlexible ? ($line['repair_desc'] ?? null) : null,
        'discount'          => (float) ($line['discount'] ?? 0),
        'next_service_date' => ($requiresNext && ! $hasUnit) ? ($line['next_service_date'] ?? null) : null,
        'notes'             => $isFlexible ? null : ($line['notes'] ?? null),
        'hp_value'          => $hpValue,
    ];
}
```

> `gas_option` is gone from the returned array (column dropped). `repair_desc`/`notes` now key off `flexible` not `'Repair'`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServicePricingTest`
Expected: PASS (all 6).

- [ ] **Step 6: Commit**

```bash
git add app/Http tests/Feature/ServicePricingTest.php
git commit -m "feat(pricing): record rate resolution by pricing_mode; flexible editable (Task 3)"
```

---

## Task 4: Controller props + snapshot/invoice cleanup

**Files:**
- Modify: `app/Http/Controllers/ServiceTypeController.php` (`index()`)
- Modify: `app/Http/Controllers/ServiceVisitController.php` (`create()`, `edit()`)
- Modify: `app/Http/Controllers/CatalogController.php`
- Modify: `app/Services/Documents/SnapshotBuilder.php`
- Modify: `resources/views/documents/invoice.blade.php`, `resources/views/documents/receipt.blade.php`

- [ ] **Step 1: Update ServiceTypeController::index()**

Replace the `index()` body so it passes the unified shape (drop `feeGroups`/`modes`/`hpTiers` built from old columns; group fees by `service_type_id`):

```php
public function index(): Response
{
    return Inertia::render('ServiceTypes/Index', [
        'serviceTypes' => ServiceType::orderBy('name')
            ->with('fees:id,service_type_id,unit_type,hp_value,price')
            ->get(['id', 'name', 'pricing_mode', 'requires_next_service']),
        'modes' => ServiceType::MODES,
    ]);
}
```

Remove the now-unused `use App\Http\Requests\StoreServiceFeeRequest;` and `use App\Models\ServiceFee;` if no longer referenced in the file.

- [ ] **Step 2: Update ServiceVisitController::create()**

Replace the fee/type/unit props in `create()`:

```php
'serviceTypes' => ServiceType::orderBy('name')
    ->with('fees:id,service_type_id,unit_type,hp_value,price')
    ->get(['id', 'name', 'pricing_mode', 'requires_next_service'])->toArray(),
```

Delete the `'fees'`, `'unitTypes'`, `'gasOptions'`, `'unitTypeServices'`, and `'hpTiers'` props entirely (the Vue side reads fees off `serviceTypes[].fees`).

- [ ] **Step 3: Update ServiceVisitController::edit()**

`edit()` passes `visit` + `technicians` only. Add the same `serviceTypes` prop (with fees) so the Edit page can render lines/labels consistently:

```php
'serviceTypes' => ServiceType::orderBy('name')
    ->with('fees:id,service_type_id,unit_type,hp_value,price')
    ->get(['id', 'name', 'pricing_mode', 'requires_next_service'])->toArray(),
```

(Editing line *prices* is FEAT-007, out of scope — this prop is only for display/labels.)

- [ ] **Step 4: Update CatalogController::index()**

```php
public function index(): Response
{
    return Inertia::render('Catalog/Index', [
        'serviceTypes' => ServiceType::orderBy('name')
            ->with('fees:id,service_type_id,unit_type,hp_value,price')
            ->get(['id', 'name', 'pricing_mode', 'requires_next_service']),
    ]);
}
```

Remove `use App\Http\Requests\StoreServiceFeeRequest;` and the old `$fees`/`feeGroups`/`modes` lines.

- [ ] **Step 5: Drop gas_option from SnapshotBuilder**

In `app/Services/Documents/SnapshotBuilder.php`, remove the `'gas_option' => $l->gas_option,` line (line 42) from the line map.

- [ ] **Step 6: Drop gas_option from invoice + receipt blades**

In `resources/views/documents/invoice.blade.php` and `resources/views/documents/receipt.blade.php`, find where `gas_option` is rendered (grep `gas_option`) and remove it, leaving `unit_type` as the variant label. If the markup concatenates `unit_type`/`gas_option`, keep just `unit_type`.

- [ ] **Step 7: Verify build + full suite (no Vue yet — expect Vue pages still reference old props; that's Task 5)**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: backend tests green. (Frontend not exercised by PHPUnit.) Fix any PHP test still referencing `gas_option`/old fee columns by deferring to Task 6 if they are frontend-only — but PHP failures must be addressed now.

- [ ] **Step 8: Commit**

```bash
git add app/Http app/Services resources/views/documents
git commit -m "feat(pricing): controllers pass unified fee shape; drop gas_option from docs (Task 4)"
```

---

## Task 5: Service Settings dynamic fee editor (CHG-005 + FEAT-003 frontend)

**Files:**
- Modify: `resources/js/Pages/ServiceTypes/Index.vue`
- Delete: `resources/js/Pages/ServiceTypes/Partials/FeeModal.vue`

- [ ] **Step 1: Rewrite the Fee Schedule tab as a per-service dynamic editor**

In `resources/js/Pages/ServiceTypes/Index.vue`:
- Props become `{ serviceTypes: Array, modes: Array }`. Each `serviceTypes[i]` now has `pricing_mode` and `fees: [{id, unit_type, hp_value, price}]`. Drop `feeGroups`/`hpTiers` props and the `FeeModal` import/usage.
- Keep the Service Types tab (add/edit name + requires_next_service toggle) as-is.
- Replace the Fee Schedule tab with, for each service type, an editable block:
  - a `pricing_mode` selector (Flat / HP-tiered / Flexible),
  - if not flexible: a list of **unit-type blocks**. Each block has a `unit_type` text input and either a single `price` input (flat) or a repeatable list of `{hp_value, price}` rows with add/remove (hp_tiered),
  - "Add unit type" button; per-block "Add HP tier" (hp_tiered) + remove buttons,
  - a "Save fees" button that PUTs to `service-types.fees.sync`.

Use this script setup as the editor core (keep the existing Service-Types-tab code above it):

```js
import { reactive } from 'vue';

// Build editable local state from props (one editor per service type).
function buildEditor(type) {
    const mode = type.pricing_mode;
    let unitBlocks = [];
    if (mode === 'hp_tiered') {
        const byUnit = {};
        for (const f of type.fees) {
            (byUnit[f.unit_type] ??= []).push({ hp_value: Number(f.hp_value), price: Number(f.price) });
        }
        unitBlocks = Object.entries(byUnit).map(([unit_type, tiers]) => ({ unit_type, tiers }));
    } else if (mode === 'flat') {
        unitBlocks = type.fees.map(f => ({ unit_type: f.unit_type, price: Number(f.price) }));
    }
    return reactive({ pricing_mode: mode, unitBlocks, saving: false, errors: {} });
}

const editors = reactive({});
for (const t of props.serviceTypes) editors[t.id] = buildEditor(t);

function addUnit(ed) {
    ed.unitBlocks.push(ed.pricing_mode === 'hp_tiered'
        ? { unit_type: '', tiers: [{ hp_value: '', price: '' }] }
        : { unit_type: '', price: '' });
}
function removeUnit(ed, i) { ed.unitBlocks.splice(i, 1); }
function addTier(block) { block.tiers.push({ hp_value: '', price: '' }); }
function removeTier(block, i) { block.tiers.splice(i, 1); }

function onModeChange(ed) {
    // Reset blocks so flat<->hp_tiered shapes stay valid.
    ed.unitBlocks = ed.pricing_mode === 'flexible' ? [] : [];
}

function flatten(ed) {
    if (ed.pricing_mode === 'flexible') return [];
    if (ed.pricing_mode === 'flat') {
        return ed.unitBlocks.map(b => ({ unit_type: b.unit_type, hp_value: null, price: b.price }));
    }
    const rows = [];
    for (const b of ed.unitBlocks) {
        for (const t of b.tiers) rows.push({ unit_type: b.unit_type, hp_value: t.hp_value, price: t.price });
    }
    return rows;
}

function saveFees(type) {
    const ed = editors[type.id];
    ed.saving = true;
    ed.errors = {};
    router.put(route('service-types.fees.sync', type.id), {
        pricing_mode: ed.pricing_mode,
        fees: flatten(ed),
    }, {
        preserveScroll: true,
        onError: (e) => { ed.errors = e; ed.saving = false; },
        onSuccess: () => { ed.saving = false; },
    });
}
```

Template for the Fee Schedule tab (replace the old fee-card markup; reuse existing Tailwind tokens from this file — `rounded-ra`, `border-line`, `bg-surface`, `text-ink`, `bg-primary`, etc.):

```vue
<div v-else-if="activeTab === 'fees'">
  <p class="mb-5 text-sm text-ink-soft">
    Set each service's pricing. HP-tiered services price every unit type by HP. Flexible services let the technician enter price + description per job. Changes apply to future records only.
  </p>

  <div class="space-y-5">
    <div v-for="type in serviceTypes" :key="type.id" class="overflow-hidden rounded-ra border border-line bg-surface shadow-card">
      <div class="flex items-center justify-between gap-3 border-b border-line bg-surface-muted px-4 py-2.5">
        <Badge :variant="serviceVariant(type.name)">{{ type.name }}</Badge>
        <select v-if="canEditFees" v-model="editors[type.id].pricing_mode" @change="onModeChange(editors[type.id])"
          class="rounded-ra border-line bg-surface text-sm text-ink shadow-card focus:border-primary focus:ring-primary">
          <option value="flat">Flat (per unit type)</option>
          <option value="hp_tiered">HP-tiered</option>
          <option value="flexible">Flexible (manual)</option>
        </select>
      </div>

      <div v-if="editors[type.id].pricing_mode === 'flexible'" class="px-4 py-4 text-sm text-ink-soft">
        No fixed prices — technician enters price and description at time of job.
      </div>

      <div v-else class="divide-y divide-line">
        <div v-for="(block, bi) in editors[type.id].unitBlocks" :key="bi" class="px-4 py-3">
          <div class="flex items-center gap-3">
            <input v-model="block.unit_type" placeholder="Unit type (e.g. Wall Mounted)"
              class="flex-1 rounded-ra border-line bg-surface text-sm text-ink shadow-card focus:border-primary focus:ring-primary" />
            <input v-if="editors[type.id].pricing_mode === 'flat'" v-model.number="block.price" type="number" step="0.01" min="0" placeholder="Price"
              class="w-28 rounded-ra border-line bg-surface font-mono text-sm text-ink shadow-card focus:border-primary focus:ring-primary" />
            <button v-if="canEditFees" type="button" class="text-sm font-medium text-danger hover:underline" @click="removeUnit(editors[type.id], bi)">Remove</button>
          </div>

          <div v-if="editors[type.id].pricing_mode === 'hp_tiered'" class="mt-3 space-y-2 pl-1">
            <div v-for="(tier, ti) in block.tiers" :key="ti" class="flex items-center gap-3">
              <input v-model.number="tier.hp_value" type="number" step="0.5" min="0.5" max="20" placeholder="HP"
                class="w-24 rounded-ra border-line bg-surface font-mono text-sm text-ink shadow-card focus:border-primary focus:ring-primary" />
              <input v-model.number="tier.price" type="number" step="0.01" min="0" placeholder="Price"
                class="w-28 rounded-ra border-line bg-surface font-mono text-sm text-ink shadow-card focus:border-primary focus:ring-primary" />
              <button type="button" class="text-xs text-danger hover:underline" @click="removeTier(block, ti)">×</button>
            </div>
            <button type="button" class="text-sm text-primary hover:underline" @click="addTier(block)">+ Add HP tier</button>
          </div>
        </div>

        <div v-if="canEditFees" class="flex items-center justify-between px-4 py-3">
          <button type="button" class="flex items-center gap-1.5 text-sm text-primary hover:underline" @click="addUnit(editors[type.id])">
            <IconPlus class="h-4 w-4" /> Add unit type
          </button>
          <button type="button" :disabled="editors[type.id].saving"
            class="rounded-ra bg-primary px-4 py-1.5 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
            @click="saveFees(type)">Save fees</button>
        </div>
      </div>
    </div>
  </div>
</div>
```

Remove the standalone "Set Fee" header button (the per-service Save button replaces it). Remove the `<FeeModal>` element + its import, and the `modalOpen`/`editing`/`openAdd`/`openEdit`/`remove` fee-modal handlers and the old `hpAddForms`/`toggleHpBased`/`addHpTier`/`removeHpTier`/`getHpForm` HP helpers. Delete `resources/js/Pages/ServiceTypes/Partials/FeeModal.vue`.

- [ ] **Step 2: Build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: build succeeds, no unresolved imports (FeeModal gone).

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/ServiceTypes
git rm resources/js/Pages/ServiceTypes/Partials/FeeModal.vue
git commit -m "feat(pricing): dynamic per-service fee editor; remove FeeModal (Task 5)"
```

---

## Task 6: Service-record line UI off pricing_mode (BUG-002 frontend) + page wiring

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Partials/ServiceLineCard.vue`
- Modify: `resources/js/Pages/ServiceRecords/Create.vue`
- Modify: `resources/js/Pages/ServiceRecords/Edit.vue`
- Modify: `resources/js/Pages/ServiceRecords/Show.vue`, `resources/js/Pages/Portal/Show.vue`, `resources/js/Pages/Clients/Show.vue`

- [ ] **Step 1: Rewrite ServiceLineCard.vue to drive off pricing_mode**

Replace the script logic so behaviour comes from the selected service type's `pricing_mode` + its `fees`, not name checks:

```js
const serviceType = computed(() => props.serviceTypes?.find(t => t.name === props.line.service_type) ?? null);
const mode = computed(() => serviceType.value?.pricing_mode ?? null);
const isFlexible = computed(() => mode.value === 'flexible');
const isHp = computed(() => mode.value === 'hp_tiered');
const carriesUnitType = computed(() => mode.value === 'flat' || mode.value === 'hp_tiered');
const requiresNextService = computed(() => serviceType.value?.requires_next_service ?? false);

const fees = computed(() => serviceType.value?.fees ?? []);
const unitTypeOptions = computed(() => [...new Set(fees.value.map(f => f.unit_type))]);
const hpOptions = computed(() => fees.value
    .filter(f => f.unit_type === props.line.unit_type)
    .map(f => ({ hp_value: Number(f.hp_value), price: Number(f.price) })));

function autofill() {
    if (isFlexible.value || !props.line.service_type) return;
    if (isHp.value) {
        const tier = hpOptions.value.find(t => Number(t.hp_value) === Number(props.line.hp_value));
        props.line.rate = tier ? tier.price : '';
    } else { // flat
        const fee = fees.value.find(f => f.unit_type === props.line.unit_type);
        props.line.rate = fee ? Number(fee.price) : '';
    }
}

watch(() => props.line.service_type, () => {
    props.line.unit_type = null;
    props.line.unit_id = null;
    props.line.hp_value = null;
    props.line.repair_desc = '';
    props.line.next_service_date = null;
    nextServiceMonths.value = null;
    props.line.notes = '';
    if (isFlexible.value) props.line.rate = '';
    autofill();
});
watch(() => props.line.unit_type, () => { props.line.hp_value = null; autofill(); });
watch(() => props.line.hp_value, () => { if (isHp.value) autofill(); });
```

Template changes:
- Drop the gas-option `<select>` and the `isGas` branch.
- Unit-type `<select>` shows when `carriesUnitType`, options = `unitTypeOptions`.
- HP `<select>` shows when `isHp`, options = `hpOptions` (label `{{ hp }} HP — RM {{ price }}`).
- The flexible branch: show the description textarea (was "Repair description", relabel to "Description") and make the **rate input editable** (`:readonly="!isFlexible"`). Keep rate readonly (auto) for flat/hp.
- `feeBadgeLabel`: `isFlexible ? 'Flexible' : (rate set ? money(rate) : null)`.
- Props: drop `feeMap`, `unitTypes`, `gasOptions`, `unitTypeServices`, `hpTiers`. Keep `serviceTypes` (now carrying `pricing_mode` + `fees`), `line`, `index`, `errors`, `removable`, `visitDate`, `clientUnits`.

- [ ] **Step 2: Update Create.vue + Edit.vue prop wiring**

In `resources/js/Pages/ServiceRecords/Create.vue`:
- Drop props `fees`, `unitTypes`, `gasOptions`, `unitTypeServices`, `hpTiers` and any `feeMap` computed built from `fees`.
- `blankLine()` keeps `hp_value: null`, drops `gas_option`.
- Pass only `:service-types`, `:line`, `:index`, `:errors`, `:removable`, `:visit-date`, `:client-units` to `<ServiceLineCard>`.

In `resources/js/Pages/ServiceRecords/Edit.vue`: apply the same prop cleanup; it now receives `serviceTypes` from the controller (Task 4 Step 3). Remove any `gas_option` references.

- [ ] **Step 3: Drop gas_option display in Show pages**

In `resources/js/Pages/ServiceRecords/Show.vue`, `resources/js/Pages/Portal/Show.vue`, `resources/js/Pages/Clients/Show.vue`: grep `gas_option` and remove those bindings, leaving `unit_type` as the variant label.

- [ ] **Step 4: Build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: succeeds, no references to removed props.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ServiceRecords resources/js/Pages/Portal/Show.vue resources/js/Pages/Clients/Show.vue
git commit -m "feat(pricing): record line UI driven by pricing_mode; flexible editable + desc (Task 6)"
```

---

## Task 7: Catalog page + remaining test fixes + full verification

**Files:**
- Modify: `resources/js/Pages/Catalog/Index.vue`
- Modify: `tests/Feature/ServiceVisitTest.php`, `tests/Feature/MultiTenantIsolationTest.php`, `tests/Feature/TechnicianScopingTest.php` (as needed)

- [ ] **Step 1: Update Catalog/Index.vue to the new shape**

Props become `{ serviceTypes: Array }` where each has `pricing_mode` + `fees`. Render per service: flexible → "Flexible pricing" note; flat → unit_type → price rows; hp_tiered → unit_type → (HP, price) rows. Remove `feeGroups`/`modes` props and any `option`/`pricing_mode`-string display. (Grouping polish = CHG-006, deferred — this is the minimum to not break.)

- [ ] **Step 2: Fix backend tests referencing old fee/gas columns**

Run the full suite and fix every failure that references `gas_option`, `service_hp_tiers`, `is_hp_based`, `ServiceFee::create([... 'option' ...])`, or `StoreServiceVisitRequest::UNIT_TYPES/GAS_OPTIONS/UNIT_TYPE_SERVICES`:

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`

In `ServiceVisitTest.php` / `MultiTenantIsolationTest.php` / `TechnicianScopingTest.php`, update any fee fixtures to the new `ServiceFee::create(['service_type_id' => ..., 'unit_type' => ..., 'hp_value' => ..., 'price' => ...])` shape and any line payloads to drop `gas_option` / use `unit_type` + `hp_value`. Update service-type creation to include `pricing_mode`.

- [ ] **Step 3: Run full suite to green**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: PASS (all green). Note new total in the commit message.

- [ ] **Step 4: Build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: succeeds.

- [ ] **Step 5: Manual smoke (eyeball via dev server)**

Per project memory, use `npm run dev` HMR for eyeballing. Verify:
1. Service Settings → Fee Schedule: switch a service to HP-tiered, add 2 unit types each with HP tiers, Save → reload shows persisted rows.
2. Switch a service to Flexible → Save → rows cleared.
3. New Service Record: pick an HP-tiered service → unit type → HP dropdown → rate autofills; pick Flexible service → rate editable + description shown; pick flat service → unit type → rate autofills.
4. Complete a flat record → invoice shows unit_type, no gas_option, correct total.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Catalog tests
git commit -m "feat(pricing): catalog new shape; fix suite for unified fees (Task 7)"
```

---

## Self-Review Notes (spec coverage)

- CHG-005 (rename option→unit type, dynamic unit types, HP tier+pricing at add-fee level): Tasks 1,2,5.
- BUG-002 (flexible editable price + description): Tasks 3 (backend), 6 (frontend).
- FEAT-003 (HP tier add/edit button): Task 5 (inline add/remove HP tiers in the editor).
- Kill hardcoded constants/names: Tasks 1 (schema), 3 (validation + normalizeLine), 6 (line UI).
- Reseed valid demo data: Task 1 (seeders).
- CHG-006 explicitly deferred (Task 7 Step 1 does minimum to not break Catalog).

## Deploy notes (post-merge to main)
- `php artisan migrate` (4 migrations).
- Reseed fee/demo data — existing fee data disposable. Confirm prod `service_fees`/`service_types` reseeded (e.g. `db:seed --class=ServiceTypeSeeder` + `--class=ServiceFeeSeeder`, or `migrate:fresh --seed` if prod data is still disposable).
- `npm run build`.
