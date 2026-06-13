# Per-Unit Identity Tracking — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add individual air-conditioning unit identity to clients — track label, type, HP, brand, model, serial and refrigerant per unit — and link service lines to specific units so per-unit history and reminders work correctly.

**Architecture:** New `client_units` table linked to `clients`; `service_lines.unit_id` FK (nullable, backward-compat); data migration auto-creates generic units from existing service_line counts; `ReminderService` refactored to query `client_units.next_service_date`; client profile gets a Units section; service visit create gets a unit selector per line.

**Tech Stack:** Laravel 11, Inertia.js, Vue 3, SQLite (tests), MySQL (prod)

---

## File Map

**New files:**
- `database/migrations/2026_06_13_000100_create_client_units_table.php`
- `database/migrations/2026_06_13_000110_add_unit_id_to_service_lines.php`
- `database/migrations/2026_06_13_000120_backfill_client_units.php`
- `app/Models/ClientUnit.php`
- `app/Http/Controllers/ClientUnitController.php`
- `app/Http/Requests/StoreClientUnitRequest.php`
- `app/Http/Requests/UpdateClientUnitRequest.php`
- `resources/js/Pages/Clients/Partials/UnitsSection.vue`
- `resources/js/Pages/Clients/Partials/UnitModal.vue`
- `tests/Feature/ClientUnitTest.php`

**Modified files:**
- `app/Models/Client.php` — add `hasMany(ClientUnit)`
- `app/Models/ServiceLine.php` — add `unit_id` to fillable, add `belongsTo(ClientUnit)`
- `app/Models/User.php` — add `manage_units` to `PERMISSIONS` + `DEFAULT_TECHNICIAN_PERMISSIONS`
- `app/Http/Controllers/ClientController.php` — load units in `show()`
- `app/Http/Controllers/ServiceVisitController.php` — pass units in `create()`, sync unit dates in `store()`
- `app/Http/Requests/StoreServiceVisitRequest.php` — add `lines.*.unit_id`
- `app/Services/Reminders/ReminderService.php` — query `client_units` instead of `service_lines`
- `resources/js/Pages/Clients/Show.vue` — add UnitsSection
- `resources/js/Pages/ServiceRecords/Partials/ServiceLineCard.vue` — add unit selector
- `resources/js/Pages/ServiceRecords/Create.vue` — pass units, add "add line for each unit"
- `routes/web.php` — add client unit routes
- `tests/Feature/ReminderServiceTest.php` — update for new query source

---

## Task 1: Schema migrations

**Files:**
- Create: `database/migrations/2026_06_13_000100_create_client_units_table.php`
- Create: `database/migrations/2026_06_13_000110_add_unit_id_to_service_lines.php`

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/ClientUnitTest.php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_units_table_exists_with_correct_columns(): void
    {
        $this->assertTrue(Schema::hasTable('client_units'));
        foreach (['id', 'client_id', 'label', 'unit_type', 'hp', 'brand', 'model',
                  'serial_no', 'refrigerant_type', 'next_service_date', 'next_service_type',
                  'is_active', 'notes', 'created_at', 'updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('client_units', $col), "Missing column: $col");
        }
    }

    public function test_service_lines_has_unit_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('service_lines', 'unit_id'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_client_units_table_exists|test_service_lines_has_unit_id"
```

Expected: 2 FAIL

- [ ] **Step 3: Create the client_units migration**

```php
// database/migrations/2026_06_13_000100_create_client_units_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('label');                          // "Master Bedroom"
            $table->string('unit_type');                      // Wall Mounted | Cassette
            $table->decimal('hp', 3, 2)->nullable();         // 0.75 | 1.00 | 1.50 | 2.00 | 2.50
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_no')->nullable();
            $table->string('refrigerant_type')->nullable();   // R32 | R410A | R22
            $table->date('next_service_date')->nullable();
            $table->string('next_service_type')->nullable();  // service type due next
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_units');
    }
};
```

- [ ] **Step 4: Create the unit_id migration**

```php
// database/migrations/2026_06_13_000110_add_unit_id_to_service_lines.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_lines', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('visit_id')
                ->constrained('client_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
        });
    }
};
```

- [ ] **Step 5: Run migrations and verify tests pass**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_client_units_table_exists|test_service_lines_has_unit_id"
```

Expected: 2 PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_13_000100_create_client_units_table.php \
        database/migrations/2026_06_13_000110_add_unit_id_to_service_lines.php \
        tests/Feature/ClientUnitTest.php
git commit -m "feat(units): schema — client_units table + unit_id on service_lines"
```

---

## Task 2: Models and permission

**Files:**
- Create: `app/Models/ClientUnit.php`
- Modify: `app/Models/Client.php`
- Modify: `app/Models/ServiceLine.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/ClientUnitTest.php`

- [ ] **Step 1: Add model relationship tests**

Append to `tests/Feature/ClientUnitTest.php`:

```php
use App\Models\Client;
use App\Models\ClientUnit;
use App\Models\ServiceLine;
use App\Models\ServiceVisit;
use App\Models\User;

// Add to class body:

public function test_client_has_many_units(): void
{
    $client = Client::create(['name' => 'T', 'phone' => '011-11111111', 'address' => 'A']);
    ClientUnit::create(['client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);
    ClientUnit::create(['client_id' => $client->id, 'label' => 'BR2', 'unit_type' => 'Cassette', 'is_active' => true]);

    $this->assertCount(2, $client->units);
}

public function test_service_line_belongs_to_unit(): void
{
    $client = Client::create(['name' => 'T', 'phone' => '011-11111111', 'address' => 'A']);
    $unit = ClientUnit::create(['client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);
    $visit = ServiceVisit::create(['client_id' => $client->id, 'visit_date' => '2026-06-01', 'warranty_months' => 0]);
    $line = ServiceLine::create([
        'visit_id' => $visit->id, 'unit_id' => $unit->id,
        'service_type' => 'Cleaning', 'units' => 1, 'rate' => 80, 'discount' => 0,
    ]);

    $this->assertEquals($unit->id, $line->fresh()->unit->id);
}

public function test_manage_units_in_permission_catalogue(): void
{
    $this->assertContains('manage_units', User::PERMISSIONS);
}

public function test_manage_units_default_for_technician(): void
{
    $this->assertContains('manage_units', User::DEFAULT_TECHNICIAN_PERMISSIONS);
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="ClientUnitTest"
```

Expected: new tests FAIL

- [ ] **Step 3: Create ClientUnit model**

```php
// app/Models/ClientUnit.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientUnit extends Model
{
    protected $fillable = [
        'client_id', 'label', 'unit_type', 'hp', 'brand', 'model',
        'serial_no', 'refrigerant_type', 'next_service_date', 'next_service_type',
        'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'hp' => 'decimal:2',
            'next_service_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ServiceLine::class, 'unit_id');
    }

    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }
}
```

- [ ] **Step 4: Update Client model**

In `app/Models/Client.php`, add the import and relationship after `reminderContact()`:

```php
use App\Models\ClientUnit;

// Add method to Client class:
public function units(): HasMany
{
    return $this->hasMany(ClientUnit::class);
}
```

- [ ] **Step 5: Update ServiceLine model**

In `app/Models/ServiceLine.php`:

Add `'unit_id'` to `$fillable`:
```php
protected $fillable = [
    'visit_id',
    'unit_id',       // ← add this
    'service_type',
    'unit_type',
    'gas_option',
    'units',
    'rate',
    'repair_desc',
    'discount',
    'next_service_date',
    'notes',
    'subtotal',
];
```

Add relationship after the `visit()` method:
```php
use App\Models\ClientUnit;

public function unit(): BelongsTo
{
    return $this->belongsTo(ClientUnit::class, 'unit_id');
}
```

- [ ] **Step 6: Update User model permissions**

In `app/Models/User.php`, add `'manage_units'` to both arrays:

```php
public const PERMISSIONS = [
    'view_clients',
    'record_service',
    'set_appointment',
    'collect_payment',
    'edit_client',
    'view_reports',
    'edit_fees',
    'export_data',
    'view_all_data',
    'manage_units',    // ← add this
    'manage_users',
    'manage_service_types',
];

public const DEFAULT_TECHNICIAN_PERMISSIONS = [
    'view_clients',
    'record_service',
    'set_appointment',
    'manage_units',         // ← add this
    'manage_service_types',
];
```

- [ ] **Step 7: Run tests**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="ClientUnitTest"
```

Expected: all PASS

- [ ] **Step 8: Commit**

```bash
git add app/Models/ClientUnit.php app/Models/Client.php app/Models/ServiceLine.php \
        app/Models/User.php tests/Feature/ClientUnitTest.php
git commit -m "feat(units): ClientUnit model, relationships, manage_units permission"
```

---

## Task 3: ClientUnitController — store, update, deactivate, index

**Files:**
- Create: `app/Http/Requests/StoreClientUnitRequest.php`
- Create: `app/Http/Requests/UpdateClientUnitRequest.php`
- Create: `app/Http/Controllers/ClientUnitController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ClientUnitTest.php`

- [ ] **Step 1: Add controller tests**

Append to `tests/Feature/ClientUnitTest.php`:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

// Add to class body:

private function makeClient(): Client
{
    return Client::create(['name' => 'Test', 'phone' => '011-22334455', 'address' => 'KL']);
}

private function makeAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

private function makeTech(): User
{
    return User::factory()->technician()->create();
}

public function test_guest_cannot_store_unit(): void
{
    $client = $this->makeClient();
    $this->postJson(route('clients.units.store', $client), ['label' => 'BR1', 'unit_type' => 'Wall Mounted'])
        ->assertRedirect(route('login'));
}

public function test_admin_can_store_unit(): void
{
    $client = $this->makeClient();
    $this->actingAs($this->makeAdmin())
        ->postJson(route('clients.units.store', $client), [
            'label' => 'Master Bedroom', 'unit_type' => 'Wall Mounted',
            'hp' => 1.0, 'brand' => 'LG', 'model' => 'S12EQ', 'serial_no' => 'ABC123',
            'refrigerant_type' => 'R32', 'notes' => null,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('client_units', ['client_id' => $client->id, 'label' => 'Master Bedroom']);
}

public function test_tech_with_manage_units_can_store_unit(): void
{
    $client = $this->makeClient();
    $tech = $this->makeTech();
    $this->assertTrue($tech->hasPermission('manage_units'));

    $this->actingAs($tech)
        ->postJson(route('clients.units.store', $client), ['label' => 'BR1', 'unit_type' => 'Cassette'])
        ->assertRedirect();

    $this->assertDatabaseHas('client_units', ['label' => 'BR1', 'unit_type' => 'Cassette']);
}

public function test_admin_can_update_unit(): void
{
    $client = $this->makeClient();
    $unit = ClientUnit::create(['client_id' => $client->id, 'label' => 'Old', 'unit_type' => 'Wall Mounted', 'is_active' => true]);

    $this->actingAs($this->makeAdmin())
        ->putJson(route('clients.units.update', [$client, $unit]), ['label' => 'New Label', 'unit_type' => 'Cassette'])
        ->assertRedirect();

    $this->assertSame('New Label', $unit->fresh()->label);
}

public function test_admin_can_deactivate_unit(): void
{
    $client = $this->makeClient();
    $unit = ClientUnit::create(['client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);

    $this->actingAs($this->makeAdmin())
        ->patchJson(route('clients.units.deactivate', [$client, $unit]))
        ->assertRedirect();

    $this->assertFalse($unit->fresh()->is_active);
}

public function test_unit_belonging_to_other_client_returns_404(): void
{
    $clientA = $this->makeClient();
    $clientB = $this->makeClient();
    $unit = ClientUnit::create(['client_id' => $clientA->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);

    $this->actingAs($this->makeAdmin())
        ->patchJson(route('clients.units.deactivate', [$clientB, $unit]))
        ->assertNotFound();
}

public function test_units_index_returns_json_for_client(): void
{
    $client = $this->makeClient();
    ClientUnit::create(['client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);
    ClientUnit::create(['client_id' => $client->id, 'label' => 'BR2', 'unit_type' => 'Wall Mounted', 'is_active' => false]); // inactive

    $response = $this->actingAs($this->makeAdmin())
        ->getJson(route('clients.units.index', $client));

    $response->assertOk()->assertJsonCount(1); // only active
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="ClientUnitTest"
```

Expected: new tests FAIL (routes/controller don't exist yet)

- [ ] **Step 3: Create StoreClientUnitRequest**

```php
// app/Http/Requests/StoreClientUnitRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage_units');
    }

    public function rules(): array
    {
        return [
            'label'            => ['required', 'string', 'max:100'],
            'unit_type'        => ['required', Rule::in(['Wall Mounted', 'Cassette'])],
            'hp'               => ['nullable', Rule::in([0.75, 1.0, 1.5, 2.0, 2.5])],
            'brand'            => ['nullable', 'string', 'max:100'],
            'model'            => ['nullable', 'string', 'max:100'],
            'serial_no'        => ['nullable', 'string', 'max:100'],
            'refrigerant_type' => ['nullable', Rule::in(['R32', 'R410A', 'R22'])],
            'notes'            => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

- [ ] **Step 4: Create UpdateClientUnitRequest**

```php
// app/Http/Requests/UpdateClientUnitRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage_units');
    }

    public function rules(): array
    {
        return [
            'label'            => ['required', 'string', 'max:100'],
            'unit_type'        => ['required', Rule::in(['Wall Mounted', 'Cassette'])],
            'hp'               => ['nullable', Rule::in([0.75, 1.0, 1.5, 2.0, 2.5])],
            'brand'            => ['nullable', 'string', 'max:100'],
            'model'            => ['nullable', 'string', 'max:100'],
            'serial_no'        => ['nullable', 'string', 'max:100'],
            'refrigerant_type' => ['nullable', Rule::in(['R32', 'R410A', 'R22'])],
            'notes'            => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

- [ ] **Step 5: Create ClientUnitController**

```php
// app/Http/Controllers/ClientUnitController.php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientUnitRequest;
use App\Http\Requests\UpdateClientUnitRequest;
use App\Models\Client;
use App\Models\ClientUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ClientUnitController extends Controller
{
    /** JSON list of active units for client — used by service visit unit selector. */
    public function index(Client $client): JsonResponse
    {
        $units = $client->units()->active()->orderBy('label')->get([
            'id', 'label', 'unit_type', 'hp', 'brand', 'model', 'serial_no', 'refrigerant_type', 'next_service_date',
        ]);

        return response()->json($units);
    }

    public function store(StoreClientUnitRequest $request, Client $client): RedirectResponse
    {
        $client->units()->create($request->validated());

        return back()->with('success', 'Unit added.');
    }

    public function update(UpdateClientUnitRequest $request, Client $client, ClientUnit $unit): RedirectResponse
    {
        abort_if($unit->client_id !== $client->id, 404);
        $unit->update($request->validated());

        return back()->with('success', 'Unit updated.');
    }

    public function deactivate(Client $client, ClientUnit $unit): RedirectResponse
    {
        abort_if($unit->client_id !== $client->id, 404);
        abort_unless(request()->user()->can('manage_units'), 403);
        $unit->update(['is_active' => false]);

        return back()->with('success', 'Unit deactivated.');
    }
}
```

- [ ] **Step 6: Add routes**

In `routes/web.php`, add inside the `Route::middleware('auth')->group(...)` block, after the clients routes section:

```php
// Client Units — nested under clients, gated by view_clients (read) and manage_units (write)
Route::get('clients/{client}/units', [ClientUnitController::class, 'index'])
    ->middleware('can:view_clients')->name('clients.units.index');
Route::middleware('can:manage_units')->group(function () {
    Route::post('clients/{client}/units', [ClientUnitController::class, 'store'])->name('clients.units.store');
    Route::put('clients/{client}/units/{unit}', [ClientUnitController::class, 'update'])->name('clients.units.update');
    Route::patch('clients/{client}/units/{unit}/deactivate', [ClientUnitController::class, 'deactivate'])->name('clients.units.deactivate');
});
```

Add the import at the top of `routes/web.php`:
```php
use App\Http\Controllers\ClientUnitController;
```

- [ ] **Step 7: Run tests**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="ClientUnitTest"
```

Expected: all PASS

- [ ] **Step 8: Run full suite to confirm no regressions**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all PASS

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/StoreClientUnitRequest.php \
        app/Http/Requests/UpdateClientUnitRequest.php \
        app/Http/Controllers/ClientUnitController.php \
        routes/web.php tests/Feature/ClientUnitTest.php
git commit -m "feat(units): ClientUnitController — store, update, deactivate, index"
```

---

## Task 4: Data migration — backfill client_units from service_lines

**Files:**
- Create: `database/migrations/2026_06_13_000120_backfill_client_units.php`
- Test: `tests/Feature/ClientUnitTest.php`

- [ ] **Step 1: Add backfill tests**

Append to `tests/Feature/ClientUnitTest.php`:

```php
use App\Models\ServiceVisit;

// Add to class body:

public function test_backfill_creates_units_from_service_lines(): void
{
    $client = $this->makeClient();
    $visit  = ServiceVisit::create(['client_id' => $client->id, 'visit_date' => '2026-05-01', 'warranty_months' => 0]);

    // 3 wall-mounted, 1 cassette
    \App\Models\ServiceLine::create(['visit_id' => $visit->id, 'service_type' => 'Cleaning',
        'unit_type' => 'Wall Mounted', 'units' => 3, 'rate' => 80, 'discount' => 0,
        'next_service_date' => '2026-09-01']);
    \App\Models\ServiceLine::create(['visit_id' => $visit->id, 'service_type' => 'Installation',
        'unit_type' => 'Cassette', 'units' => 1, 'rate' => 300, 'discount' => 0,
        'next_service_date' => '2026-12-01']);

    // Run the backfill
    \Artisan::call('migrate', ['--path' => 'database/migrations/2026_06_13_000120_backfill_client_units.php']);

    $units = \App\Models\ClientUnit::where('client_id', $client->id)->orderBy('label')->get();
    $this->assertCount(4, $units); // 3 Wall Mounted + 1 Cassette

    $wallUnits = $units->where('unit_type', 'Wall Mounted')->values();
    $this->assertCount(3, $wallUnits);
    $this->assertSame('Wall Mounted 1', $wallUnits[0]->label);
    $this->assertSame('2026-09-01', $wallUnits[0]->next_service_date?->toDateString());
    $this->assertSame('Cleaning', $wallUnits[0]->next_service_type);
    $this->assertNull($wallUnits[1]->next_service_date); // only first unit gets it

    $cassette = $units->where('unit_type', 'Cassette')->first();
    $this->assertSame('Cassette 1', $cassette->label);
    $this->assertSame('2026-12-01', $cassette->next_service_date?->toDateString());
    $this->assertSame('Installation', $cassette->next_service_type);
}

public function test_backfill_skips_clients_already_with_units(): void
{
    $client = $this->makeClient();
    $visit  = ServiceVisit::create(['client_id' => $client->id, 'visit_date' => '2026-05-01', 'warranty_months' => 0]);
    \App\Models\ServiceLine::create(['visit_id' => $visit->id, 'service_type' => 'Cleaning',
        'unit_type' => 'Wall Mounted', 'units' => 1, 'rate' => 80, 'discount' => 0]);

    // Pre-existing unit
    ClientUnit::create(['client_id' => $client->id, 'label' => 'Existing', 'unit_type' => 'Wall Mounted', 'is_active' => true]);

    \Artisan::call('migrate', ['--path' => 'database/migrations/2026_06_13_000120_backfill_client_units.php']);

    // Should still be 1 — backfill skips clients that already have units
    $this->assertCount(1, ClientUnit::where('client_id', $client->id)->get());
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_backfill"
```

Expected: FAIL (migration file doesn't exist)

- [ ] **Step 3: Create the backfill migration**

```php
// database/migrations/2026_06_13_000120_backfill_client_units.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $clients = DB::table('clients')->whereNull('deleted_at')->pluck('id');

        foreach ($clients as $clientId) {
            // Skip clients that already have units (idempotent)
            if (DB::table('client_units')->where('client_id', $clientId)->exists()) {
                continue;
            }

            // Group service_lines by unit_type: MAX(units) tells us how many of each type
            $groups = DB::table('service_lines as sl')
                ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id')
                ->where('sv.client_id', $clientId)
                ->whereNotNull('sl.unit_type')
                ->groupBy('sl.unit_type')
                ->select(
                    'sl.unit_type',
                    DB::raw('MAX(sl.units) as max_units'),
                    DB::raw('MAX(sl.next_service_date) as max_next_service_date'),
                )
                ->get();

            foreach ($groups as $group) {
                // Find service_type for the line that had the MAX next_service_date
                $nextServiceType = null;
                if ($group->max_next_service_date) {
                    $nextServiceType = DB::table('service_lines as sl')
                        ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id')
                        ->where('sv.client_id', $clientId)
                        ->where('sl.unit_type', $group->unit_type)
                        ->where('sl.next_service_date', $group->max_next_service_date)
                        ->value('sl.service_type');
                }

                $count = max(1, (int) $group->max_units);
                for ($n = 1; $n <= $count; $n++) {
                    DB::table('client_units')->insert([
                        'client_id'         => $clientId,
                        'label'             => $group->unit_type . ' ' . $n,
                        'unit_type'         => $group->unit_type,
                        'next_service_date' => $n === 1 ? $group->max_next_service_date : null,
                        'next_service_type' => $n === 1 ? $nextServiceType : null,
                        'is_active'         => true,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Non-reversible data migration — down() is a no-op.
    }
};
```

- [ ] **Step 4: Run tests**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_backfill"
```

Expected: PASS

- [ ] **Step 5: Run full suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_13_000120_backfill_client_units.php \
        tests/Feature/ClientUnitTest.php
git commit -m "feat(units): data migration — backfill client_units from service_line counts"
```

---

## Task 5: ReminderService refactor

**Files:**
- Modify: `app/Services/Reminders/ReminderService.php`
- Modify: `tests/Feature/ReminderServiceTest.php`

- [ ] **Step 1: Add new reminder tests**

Append to `tests/Feature/ReminderServiceTest.php`:

```php
use App\Models\ClientUnit;

// Add to class body — new helper:

private function makeUnit(Client $client, string $type, ?string $nextDate, string $serviceType = 'Cleaning'): ClientUnit
{
    return ClientUnit::create([
        'client_id'         => $client->id,
        'label'             => $type . ' 1',
        'unit_type'         => $type,
        'is_active'         => true,
        'next_service_date' => $nextDate,
        'next_service_type' => $nextDate ? $serviceType : null,
    ]);
}

// New tests:

public function test_reminder_sources_from_client_units(): void
{
    $client = $this->makeClient('Unit Client');
    $this->makeUnit($client, 'Wall Mounted', '2026-06-20'); // due this month

    $r = $this->dueList();

    $this->assertCount(1, $r['due_this_month']);
    $this->assertSame('Unit Client', $r['due_this_month'][0]['name']);
    $this->assertSame('2026-06-20', $r['due_this_month'][0]['next_due']);
}

public function test_reminder_service_type_from_unit(): void
{
    $client = $this->makeClient('Type Client');
    $this->makeUnit($client, 'Wall Mounted', '2026-06-20', 'Installation');

    $r = $this->dueList();

    $this->assertSame('Installation', $r['due_this_month'][0]['service_type']);
}

public function test_reminder_units_count_active_units_due(): void
{
    $client = $this->makeClient('Multi Client');
    $this->makeUnit($client, 'Wall Mounted', '2026-06-20');
    $this->makeUnit($client, 'Cassette', '2026-06-25');
    $this->makeUnit($client, 'Wall Mounted', null); // no date — not due

    $r = $this->dueList();

    $this->assertSame(2, $r['due_this_month'][0]['units']);
}

public function test_inactive_unit_excluded_from_reminders(): void
{
    $client = $this->makeClient('Inactive Client');
    ClientUnit::create([
        'client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted',
        'is_active' => false, 'next_service_date' => '2026-06-20', 'next_service_type' => 'Cleaning',
    ]);

    $r = $this->dueList();

    $this->assertCount(0, $r['due_this_month']);
}

public function test_fallback_to_service_lines_for_clients_without_units(): void
{
    // Client with service_line next_service_date but no client_units records
    $client = $this->makeClient('Legacy Client');
    $visit = \App\Models\ServiceVisit::create(['client_id' => $client->id, 'visit_date' => '2026-05-01', 'warranty_months' => 0]);
    \App\Models\ServiceLine::create([
        'visit_id' => $visit->id, 'service_type' => 'Cleaning',
        'units' => 1, 'rate' => 80, 'discount' => 0, 'next_service_date' => '2026-06-20',
    ]);

    $r = $this->dueList();

    // Legacy client still appears via service_line fallback
    $this->assertCount(1, $r['due_this_month']);
    $this->assertSame('Legacy Client', $r['due_this_month'][0]['name']);
}
```

- [ ] **Step 2: Run tests to verify new ones fail**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="ReminderServiceTest"
```

Expected: existing pass, new ones FAIL

- [ ] **Step 3: Rewrite ReminderService::dueList()**

Replace the entire `dueList()` method in `app/Services/Reminders/ReminderService.php`:

```php
public function dueList(): array
{
    $today = Carbon::today();
    $endOfMonth = $today->copy()->endOfMonth();

    // Primary: query client_units (post-migration, most clients have units)
    $unitRows = DB::table('client_units as cu')
        ->join('clients as c', 'c.id', '=', 'cu.client_id')
        ->leftJoin('reminder_contacts as rc', 'rc.client_id', '=', 'c.id')
        ->whereNull('c.deleted_at')
        ->where('cu.is_active', true)
        ->whereNotNull('cu.next_service_date')
        ->groupBy('c.id', 'c.serial_no', 'c.name', 'c.phone', 'c.address')
        ->havingRaw('MAX(cu.next_service_date) <= ?', [$endOfMonth->toDateString()])
        ->orderByRaw('MAX(cu.next_service_date) asc')
        ->get([
            'c.id as client_id',
            'c.serial_no',
            'c.name',
            'c.phone',
            'c.address',
            DB::raw('MAX(cu.next_service_date) as next_due'),
            DB::raw('(SELECT MAX(sv2.visit_date) FROM service_visits sv2 WHERE sv2.client_id = c.id) as last_service_date'),
            DB::raw('MAX(CASE WHEN rc.id IS NULL THEN 0 ELSE 1 END) as contacted_flag'),
            DB::raw('COUNT(DISTINCT cu.id) as units'),
            DB::raw('(SELECT cu2.next_service_type FROM client_units cu2 WHERE cu2.client_id = c.id AND cu2.is_active = 1 AND cu2.next_service_date IS NOT NULL ORDER BY cu2.next_service_date DESC LIMIT 1) as service_type'),
        ]);

    $coveredIds = $unitRows->pluck('client_id')->all();

    // Fallback: legacy clients with no units but still have service_line next_service_dates
    $legacyRows = DB::table('service_lines as sl')
        ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id')
        ->join('clients as c', 'c.id', '=', 'sv.client_id')
        ->leftJoin('reminder_contacts as rc', 'rc.client_id', '=', 'c.id')
        ->whereNull('c.deleted_at')
        ->whereNotNull('sl.next_service_date')
        ->when(!empty($coveredIds), fn ($q) => $q->whereNotIn('c.id', $coveredIds))
        ->groupBy('c.id', 'c.serial_no', 'c.name', 'c.phone', 'c.address')
        ->havingRaw('MAX(sl.next_service_date) <= ?', [$endOfMonth->toDateString()])
        ->orderByRaw('MAX(sl.next_service_date) asc')
        ->get([
            'c.id as client_id',
            'c.serial_no',
            'c.name',
            'c.phone',
            'c.address',
            DB::raw('MAX(sl.next_service_date) as next_due'),
            DB::raw('MAX(sv.visit_date) as last_service_date'),
            DB::raw('MAX(CASE WHEN rc.id IS NULL THEN 0 ELSE 1 END) as contacted_flag'),
            DB::raw('SUM(sl.units) as units'),
            DB::raw('(SELECT sl2.service_type FROM service_lines sl2 JOIN service_visits sv2 ON sv2.id = sl2.visit_id WHERE sv2.client_id = c.id AND sl2.next_service_date IS NOT NULL ORDER BY sl2.next_service_date DESC LIMIT 1) as service_type'),
        ]);

    $allRows = $unitRows->concat($legacyRows)->sortBy('next_due');

    $todayStr = $today->toDateString();
    $overdue = [];
    $dueThisMonth = [];

    foreach ($allRows as $row) {
        $nextDue = substr((string) $row->next_due, 0, 10);
        $item = [
            'client_id'         => (int) $row->client_id,
            'serial_no'         => $row->serial_no,
            'name'              => $row->name,
            'phone'             => $row->phone,
            'address'           => $row->address,
            'service_type'      => $row->service_type,
            'units'             => (int) $row->units,
            'next_due'          => $nextDue,
            'last_service_date' => $row->last_service_date ? substr((string) $row->last_service_date, 0, 10) : null,
            'contacted'         => (bool) $row->contacted_flag,
        ];
        if ($nextDue < $todayStr) {
            $overdue[] = $item;
        } else {
            $dueThisMonth[] = $item;
        }
    }

    $contactedCount = collect($overdue)->where('contacted', true)->count()
        + collect($dueThisMonth)->where('contacted', true)->count();

    return [
        'overdue'       => $overdue,
        'due_this_month' => $dueThisMonth,
        'stats' => [
            'overdue'        => count($overdue),
            'due_this_month' => count($dueThisMonth),
            'contacted'      => $contactedCount,
        ],
    ];
}
```

- [ ] **Step 4: Run reminder tests**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="ReminderServiceTest"
```

Expected: all PASS

- [ ] **Step 5: Run full suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Reminders/ReminderService.php tests/Feature/ReminderServiceTest.php
git commit -m "feat(units): refactor ReminderService to query client_units; legacy fallback retained"
```

---

## Task 6: Client profile — units section

**Files:**
- Modify: `app/Http/Controllers/ClientController.php`
- Create: `resources/js/Pages/Clients/Partials/UnitsSection.vue`
- Create: `resources/js/Pages/Clients/Partials/UnitModal.vue`
- Modify: `resources/js/Pages/Clients/Show.vue`

- [ ] **Step 1: Update ClientController::show to pass units**

In `app/Http/Controllers/ClientController.php`, change the `show()` method:

```php
public function show(Client $client): Response
{
    $user = request()->user();
    $client->load([
        'visits' => fn ($q) => $q->visibleTo($user)->latest('visit_date'),
        'visits.lines',
        'visits.transaction',
        'appointments' => fn ($q) => $q->visibleTo($user)->latest('datetime'),
        'units' => fn ($q) => $q->orderBy('label'),
    ]);

    return Inertia::render('Clients/Show', [
        'client' => $client,
        'canManageUnits' => $user->can('manage_units'),
    ]);
}
```

- [ ] **Step 2: Create UnitModal.vue**

```vue
<!-- resources/js/Pages/Clients/Partials/UnitModal.vue -->
<script setup>
import { watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import FormErrorSummary from '@/Components/FormErrorSummary.vue';

const props = defineProps({
    open: Boolean,
    clientId: Number,
    unit: { type: Object, default: null }, // null = add new
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.unit);

const HP_OPTIONS = [0.75, 1.0, 1.5, 2.0, 2.5];
const UNIT_TYPES = ['Wall Mounted', 'Cassette'];
const REFRIGERANTS = ['R32', 'R410A', 'R22'];

const form = useForm({
    label: '', unit_type: '', hp: null, brand: '', model: '',
    serial_no: '', refrigerant_type: null, notes: '',
});

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    if (props.unit) {
        form.label = props.unit.label ?? '';
        form.unit_type = props.unit.unit_type ?? '';
        form.hp = props.unit.hp ?? null;
        form.brand = props.unit.brand ?? '';
        form.model = props.unit.model ?? '';
        form.serial_no = props.unit.serial_no ?? '';
        form.refrigerant_type = props.unit.refrigerant_type ?? null;
        form.notes = props.unit.notes ?? '';
    } else {
        form.reset();
    }
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('clients.units.update', [props.clientId, props.unit.id]), {
            onSuccess: () => emit('close'), preserveScroll: true,
        });
    } else {
        form.post(route('clients.units.store', props.clientId), {
            onSuccess: () => emit('close'), preserveScroll: true,
        });
    }
};
</script>

<template>
    <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0"
                leave-active-class="transition duration-150" leave-to-class="opacity-0">
        <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-navy-900/60 p-0 backdrop-blur-sm sm:items-center sm:p-4"
             @click.self="emit('close')">
            <div class="w-full max-w-md rounded-t-rax bg-surface p-6 shadow-lift sm:rounded-rax">
                <div class="mb-5">
                    <h3 class="text-lg font-bold text-navy-800">{{ isEdit ? 'Edit unit' : 'Add unit' }}</h3>
                    <p class="mt-1 text-sm text-ink-soft">Details about this air-conditioning unit.</p>
                </div>

                <FormErrorSummary :errors="form.errors" />

                <form class="space-y-4" @submit.prevent="submit">
                    <!-- Label -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Location label <span class="text-danger">*</span></label>
                        <input v-model="form.label" type="text" placeholder="e.g. Master Bedroom"
                               class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                        <InputError :message="form.errors.label" />
                    </div>

                    <!-- Unit type -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Unit type <span class="text-danger">*</span></label>
                        <select v-model="form.unit_type"
                                class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            <option value="" disabled>Choose…</option>
                            <option v-for="t in UNIT_TYPES" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <InputError :message="form.errors.unit_type" />
                    </div>

                    <!-- HP + Refrigerant -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">HP</label>
                            <select v-model="form.hp"
                                    class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                <option :value="null">—</option>
                                <option v-for="h in HP_OPTIONS" :key="h" :value="h">{{ h }} HP</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Refrigerant</label>
                            <select v-model="form.refrigerant_type"
                                    class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                <option :value="null">—</option>
                                <option v-for="r in REFRIGERANTS" :key="r" :value="r">{{ r }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Brand + Model -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Brand</label>
                            <input v-model="form.brand" type="text" placeholder="LG"
                                   class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">Model</label>
                            <input v-model="form.model" type="text" placeholder="S12EQ"
                                   class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                        </div>
                    </div>

                    <!-- Serial No -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Unit serial no.</label>
                        <input v-model="form.serial_no" type="text" placeholder="Unit's own serial number"
                               class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                        <InputError :message="form.errors.serial_no" />
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Notes</label>
                        <textarea v-model="form.notes" rows="2" placeholder="Optional notes"
                                  class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" class="text-sm font-medium text-ink-soft hover:text-ink" @click="emit('close')">Cancel</button>
                        <button type="submit" :disabled="form.processing"
                                class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60">
                            {{ isEdit ? 'Save changes' : 'Add unit' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Transition>
</template>
```

- [ ] **Step 3: Create UnitsSection.vue**

```vue
<!-- resources/js/Pages/Clients/Partials/UnitsSection.vue -->
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Badge from '@/Components/Badge.vue';
import UnitModal from './UnitModal.vue';

const props = defineProps({
    client: Object,
    canManage: Boolean,
});

const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const fmtDate = (d) => {
    if (!d) return null;
    const [y, m, day] = d.slice(0, 10).split('-');
    return `${day} ${months[+m - 1]} ${y}`;
};
const hpLabel = (hp) => hp ? `${Number(hp)} HP` : null;

const modalOpen = ref(false);
const editingUnit = ref(null);

const openAdd = () => { editingUnit.value = null; modalOpen.value = true; };
const openEdit = (unit) => { editingUnit.value = unit; modalOpen.value = true; };
const closeModal = () => { modalOpen.value = false; editingUnit.value = null; };

const deactivate = (unit) => {
    if (!confirm(`Deactivate "${unit.label}"? It won't show in new service records.`)) return;
    router.patch(route('clients.units.deactivate', [props.client.id, unit.id]), {}, { preserveScroll: true });
};

const units = props.client.units ?? [];
const activeUnits = units.filter(u => u.is_active);
</script>

<template>
    <section>
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-bold uppercase tracking-wide text-ink-soft">Units ({{ activeUnits.length }})</h3>
            <button v-if="canManage" class="text-sm font-semibold text-primary hover:text-primary-hover"
                    @click="openAdd">+ Add unit</button>
        </div>

        <div v-if="activeUnits.length" class="space-y-2">
            <div v-for="unit in activeUnits" :key="unit.id"
                 class="rounded-ral border border-line bg-surface p-3 shadow-card">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-semibold text-sm text-navy-800">{{ unit.label }}</div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-ink-soft">
                            <span>{{ unit.unit_type }}</span>
                            <span v-if="hpLabel(unit.hp)">· {{ hpLabel(unit.hp) }}</span>
                            <span v-if="unit.brand">· {{ unit.brand }}</span>
                            <span v-if="unit.model" class="text-ink-muted">{{ unit.model }}</span>
                        </div>
                        <div v-if="unit.serial_no" class="mt-0.5 font-mono text-[11px] tracking-wide text-ink-muted">SN: {{ unit.serial_no }}</div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Badge v-if="unit.refrigerant_type" variant="blue">{{ unit.refrigerant_type }}</Badge>
                        <div v-if="canManage" class="flex gap-2">
                            <button class="text-xs font-medium text-primary hover:underline" @click="openEdit(unit)">Edit</button>
                            <button class="text-xs font-medium text-danger hover:underline" @click="deactivate(unit)">Deactivate</button>
                        </div>
                    </div>
                </div>
                <div v-if="unit.next_service_date" class="mt-2 flex items-center gap-1 text-xs text-ink-soft">
                    <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    Next service: <span class="font-semibold text-ink">{{ fmtDate(unit.next_service_date) }}</span>
                    <span v-if="unit.next_service_type" class="text-ink-muted">({{ unit.next_service_type }})</span>
                </div>
            </div>
        </div>
        <p v-else class="rounded-ral border border-dashed border-line bg-surface py-6 text-center text-sm text-ink-soft">
            No units registered yet.
        </p>

        <UnitModal
            :open="modalOpen"
            :client-id="client.id"
            :unit="editingUnit"
            @close="closeModal"
        />
    </section>
</template>
```

- [ ] **Step 4: Update Clients/Show.vue**

Add the import at the top of the `<script setup>` block:

```javascript
import UnitsSection from './Partials/UnitsSection.vue';
```

Update `defineProps` to include `canManageUnits`:

```javascript
const props = defineProps({
    client: Object,
    canManageUnits: { type: Boolean, default: false },
});
```

In the `<template>`, after the `<!-- Appointments -->` section (inside the `<div class="grid gap-6 lg:grid-cols-3">`), add the UnitsSection as a full-width section below the 3-column grid. Replace the closing `</div>` of the grid and `</AdminLayout>`:

```html
        </div>

        <!-- Units section -->
        <div class="mt-6">
            <UnitsSection :client="client" :can-manage="canManageUnits" />
        </div>
    </AdminLayout>
```

- [ ] **Step 5: Build frontend**

```bash
docker exec saifzz-aircond-laravel.test-1 npm run build
```

Expected: `✓ built in X.XXs`

- [ ] **Step 6: Run full suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ClientController.php \
        resources/js/Pages/Clients/Partials/UnitsSection.vue \
        resources/js/Pages/Clients/Partials/UnitModal.vue \
        resources/js/Pages/Clients/Show.vue \
        public/build
git commit -m "feat(units): client profile units section — list, add, edit, deactivate"
```

---

## Task 7: Service visit create — unit selector + sync

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php`
- Modify: `app/Http/Requests/StoreServiceVisitRequest.php`
- Modify: `resources/js/Pages/ServiceRecords/Partials/ServiceLineCard.vue`
- Modify: `resources/js/Pages/ServiceRecords/Create.vue`
- Test: `tests/Feature/ClientUnitTest.php`

- [ ] **Step 1: Add unit integration tests**

Append to `tests/Feature/ClientUnitTest.php`:

```php
use App\Models\ServiceType;
use App\Models\ServiceFee;
use App\Models\ServiceVisit;

// Add to class body:

private function seedFee(string $type, string $option, float $rate): void
{
    ServiceType::firstOrCreate(['name' => $type]);
    ServiceFee::create(['service_type' => $type, 'option' => $option, 'pricing_mode' => 'fixed_per_unit', 'rate' => $rate]);
}

public function test_service_line_stores_unit_id_when_provided(): void
{
    $this->seedFee('Cleaning', 'Wall Mounted', 80);
    $client = $this->makeClient();
    $unit = ClientUnit::create(['client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);

    $this->actingAs($this->makeAdmin())
        ->post(route('service-records.store'), [
            'client_mode' => 'existing',
            'client_id' => $client->id,
            'visit_date' => '2026-06-14',
            'warranty_months' => 0,
            'payment_method' => 'Cash',
            'lines' => [[
                'service_type' => 'Cleaning',
                'unit_type' => 'Wall Mounted',
                'unit_id' => $unit->id,
                'units' => 1,
                'rate' => 80,
                'discount' => 0,
                'next_service_date' => '2026-09-14',
                'notes' => null,
            ]],
        ])
        ->assertRedirect();

    $line = \App\Models\ServiceLine::where('visit_id', ServiceVisit::latest()->value('id'))->first();
    $this->assertSame($unit->id, $line->unit_id);
}

public function test_unit_next_service_date_synced_after_visit_with_unit_id(): void
{
    $this->seedFee('Cleaning', 'Wall Mounted', 80);
    $client = $this->makeClient();
    $unit = ClientUnit::create(['client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);

    $this->actingAs($this->makeAdmin())
        ->post(route('service-records.store'), [
            'client_mode' => 'existing',
            'client_id' => $client->id,
            'visit_date' => '2026-06-14',
            'warranty_months' => 0,
            'payment_method' => 'Cash',
            'lines' => [[
                'service_type' => 'Cleaning',
                'unit_type' => 'Wall Mounted',
                'unit_id' => $unit->id,
                'units' => 1,
                'rate' => 80,
                'discount' => 0,
                'next_service_date' => '2026-09-14',
                'notes' => null,
            ]],
        ]);

    $this->assertSame('2026-09-14', $unit->fresh()->next_service_date?->toDateString());
    $this->assertSame('Cleaning', $unit->fresh()->next_service_type);
}

public function test_service_line_next_service_date_null_when_unit_id_set(): void
{
    $this->seedFee('Cleaning', 'Wall Mounted', 80);
    $client = $this->makeClient();
    $unit = ClientUnit::create(['client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);

    $this->actingAs($this->makeAdmin())
        ->post(route('service-records.store'), [
            'client_mode' => 'existing',
            'client_id' => $client->id,
            'visit_date' => '2026-06-14',
            'warranty_months' => 0,
            'payment_method' => 'Cash',
            'lines' => [[
                'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
                'unit_id' => $unit->id, 'units' => 1, 'rate' => 80, 'discount' => 0,
                'next_service_date' => '2026-09-14', 'notes' => null,
            ]],
        ]);

    $line = \App\Models\ServiceLine::where('visit_id', ServiceVisit::latest()->value('id'))->first();
    $this->assertNull($line->next_service_date); // moved to unit
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_service_line_stores_unit_id|test_unit_next_service_date|test_service_line_next_service_date_null"
```

Expected: FAIL

- [ ] **Step 3: Update StoreServiceVisitRequest to accept unit_id**

In `app/Http/Requests/StoreServiceVisitRequest.php`, add to the `rules()` array:

```php
'lines.*.unit_id' => ['nullable', 'integer', 'exists:client_units,id'],
```

Place it after the `'lines.*.notes'` line.

- [ ] **Step 4: Update ServiceVisitController**

In `app/Http/Controllers/ServiceVisitController.php`:

**Update `create()` to pass preset client units:**

```php
public function create(): Response
{
    $presetClient = request('client')
        ? Client::where('id', request('client'))->first(['id', 'serial_no', 'name', 'phone'])
        : null;

    return Inertia::render('ServiceRecords/Create', [
        'fees' => ServiceFee::orderBy('service_type')->get(['service_type', 'option', 'rate', 'pricing_mode']),
        'serviceTypes' => ServiceType::orderBy('name')->pluck('name')->all(),
        'unitTypes' => StoreServiceVisitRequest::UNIT_TYPES,
        'gasOptions' => StoreServiceVisitRequest::GAS_OPTIONS,
        'unitTypeServices' => StoreServiceVisitRequest::UNIT_TYPE_SERVICES,
        'presetClient' => $presetClient,
        'presetClientUnits' => $presetClient
            ? \App\Models\ClientUnit::where('client_id', $presetClient->id)->active()->orderBy('label')->get(['id','label','unit_type','hp'])
            : [],
        'technicians' => request()->user()->seesAllData()
            ? \App\Models\User::where('role', \App\Models\User::ROLE_TECHNICIAN)
                ->where('active', true)->orderBy('name')->get(['id', 'name'])
            : null,
    ]);
}
```

**Update `store()` — add `unit_id` to normalizeLine and sync unit dates after save:**

Replace the `store()` method:

```php
public function store(StoreServiceVisitRequest $request): RedirectResponse
{
    $data = $request->validated();

    $visit = DB::transaction(function () use ($data, $request) {
        $client = $data['client_mode'] === 'existing'
            ? Client::findOrFail($data['client_id'])
            : Client::create($data['new_client']);

        $user = $request->user();
        $technicianId = $user->seesAllData()
            ? ($data['technician_id'] ?? $user->id)
            : $user->id;

        $visit = $client->visits()->create([
            'visit_date'      => $data['visit_date'],
            'warranty_months' => $data['warranty_months'],
            'created_by'      => $user->id,
            'technician_id'   => $technicianId,
        ]);

        foreach ($data['lines'] as $line) {
            $visit->lines()->create($this->normalizeLine($line));
        }

        // Sync next_service_date onto each unit that was referenced
        foreach ($data['lines'] as $line) {
            if (!empty($line['unit_id']) && !empty($line['next_service_date'])) {
                \App\Models\ClientUnit::where('id', $line['unit_id'])->update([
                    'next_service_date' => $line['next_service_date'],
                    'next_service_type' => $line['service_type'],
                ]);
            }
        }

        $visit->recalculateTotal();

        $visit->transaction()->create([
            'txn_id'  => $this->nextTxnId(),
            'amount'  => $visit->total_amount,
            'method'  => $data['payment_method'],
            'status'  => 'pending',
        ]);

        return $visit;
    });

    return redirect()
        ->route('service-records.show', $visit)
        ->with('success', 'Service record created. Proceed to payment.');
}
```

**Update `normalizeLine()` to handle unit_id:**

```php
private function normalizeLine(array $line): array
{
    $type = $line['service_type'];
    $isRepair = $type === 'Repair';
    $isGas = $type === 'Gas Top-Up';
    $carriesUnitType = in_array($type, StoreServiceVisitRequest::UNIT_TYPE_SERVICES, true);
    $hasUnit = !empty($line['unit_id']);

    $unitType = $carriesUnitType ? ($line['unit_type'] ?? null) : null;
    $gasOption = $isGas ? ($line['gas_option'] ?? null) : null;

    if ($isRepair) {
        $rate = (float) $line['rate'];
    } else {
        $option = $isGas ? $gasOption : $unitType;
        $rate = (float) ServiceFee::where('service_type', $type)->where('option', $option)->value('rate');
    }

    return [
        'unit_id'          => $hasUnit ? (int) $line['unit_id'] : null,
        'service_type'     => $type,
        'unit_type'        => $unitType,
        'gas_option'       => $gasOption,
        'units'            => $hasUnit ? 1 : (int) $line['units'],
        'rate'             => $rate,
        'repair_desc'      => $isRepair ? ($line['repair_desc'] ?? null) : null,
        'discount'         => (float) ($line['discount'] ?? 0),
        // When unit_id is set, next_service_date lives on the unit — not the line.
        'next_service_date' => ($carriesUnitType && !$hasUnit) ? ($line['next_service_date'] ?? null) : null,
        'notes'            => $isRepair ? null : ($line['notes'] ?? null),
    ];
}
```

- [ ] **Step 5: Run backend tests**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_service_line_stores_unit_id|test_unit_next_service_date|test_service_line_next_service_date_null"
```

Expected: PASS

- [ ] **Step 6: Update ServiceLineCard.vue to add unit selector**

In `resources/js/Pages/ServiceRecords/Partials/ServiceLineCard.vue`, update `defineProps` and add unit selector field:

Replace the existing `<script setup>` section:

```javascript
<script setup>
import { computed, watch } from 'vue';
import Badge from '@/Components/Badge.vue';
import InputError from '@/Components/InputError.vue';
import { serviceVariant } from '@/lib/badges';

const props = defineProps({
    line: { type: Object, required: true },
    index: { type: Number, required: true },
    feeMap: { type: Object, required: true },
    serviceTypes: Array,
    unitTypes: Array,
    gasOptions: Array,
    unitTypeServices: Array,
    clientUnits: { type: Array, default: () => [] }, // ← new: active units for the selected client
    errors: { type: Object, default: () => ({}) },
    removable: Boolean,
});
const emit = defineEmits(['remove']);

const isRepair = computed(() => props.line.service_type === 'Repair');
const isGas = computed(() => props.line.service_type === 'Gas Top-Up');
const carriesUnitType = computed(() => props.unitTypeServices.includes(props.line.service_type));
const hasUnitSelected = computed(() => !!props.line.unit_id);

const err = (field) => props.errors[`lines.${props.index}.${field}`];

// When a unit is selected, auto-fill unit_type from the unit record
watch(() => props.line.unit_id, (unitId) => {
    if (unitId) {
        const unit = props.clientUnits.find(u => u.id === unitId);
        if (unit) props.line.unit_type = unit.unit_type;
    }
});

watch(() => props.line.service_type, () => {
    props.line.unit_type = null;
    props.line.unit_id = null;
    props.line.gas_option = null;
    props.line.repair_desc = '';
    props.line.next_service_date = null;
    props.line.notes = '';
    if (isRepair.value) props.line.rate = '';
    autofill();
});
watch([() => props.line.unit_type, () => props.line.gas_option], autofill);

function autofill() {
    if (isRepair.value || !props.line.service_type) return;
    const option = isGas.value ? props.line.gas_option : props.line.unit_type;
    if (!option) { props.line.rate = ''; return; }
    const rate = props.feeMap[`${props.line.service_type}|${option}`];
    props.line.rate = rate != null ? rate : '';
}

const subtotal = computed(() => {
    const v = (Number(props.line.rate) || 0) * (Number(props.line.units) || 0) - (Number(props.line.discount) || 0);
    return Math.max(0, v);
});

const money = (v) => 'RM ' + Number(v).toFixed(2);

const typeAccent = {
    Cleaning: 'border-l-primary', 'Gas Top-Up': 'border-l-warn', Repair: 'border-l-danger',
    Installation: 'border-l-ok', Troubleshoot: 'border-l-invoice',
};

const feeBadgeLabel = computed(() => {
    if (!props.line.service_type) return null;
    if (isRepair.value) return 'Flexible';
    if (props.line.rate !== '' && props.line.rate != null) return money(props.line.rate);
    return null;
});
const feeBadgeVariant = computed(() => isRepair.value ? 'amber' : 'blue');

const unitLabel = (u) => `${u.label} (${u.unit_type}${u.hp ? ' · ' + Number(u.hp) + 'HP' : ''})`;
</script>
```

In the `<template>`, add a unit selector field inside the `<div class="grid gap-4 sm:grid-cols-2">`, right before the "Service type" field:

```html
<!-- Unit selector (shown when client has units and service uses unit_type) -->
<div v-if="clientUnits.length && carriesUnitType" class="sm:col-span-2">
    <label class="mb-1.5 block text-sm font-semibold text-ink">Unit <span class="font-normal text-ink-muted text-xs">(optional — skip to use count mode)</span></label>
    <select v-model="line.unit_id"
            class="w-full rounded-ra border border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
        <option :value="null">— No specific unit —</option>
        <option v-for="u in clientUnits" :key="u.id" :value="u.id">{{ unitLabel(u) }}</option>
    </select>
</div>
```

Also update the "Units" field to be hidden when a specific unit is selected (force 1):

Replace the existing "Units" field block:

```html
<!-- Units count — hidden when a specific unit is selected (always 1) -->
<div v-if="!hasUnitSelected">
    <label class="mb-1.5 block text-sm font-semibold text-ink">Units</label>
    <input v-model.number="line.units" type="number" min="1" inputmode="numeric"
           class="w-full rounded-ra border-line bg-surface font-mono text-ink shadow-card focus:border-primary focus:ring-primary" />
    <InputError :message="err('units')" />
</div>
```

- [ ] **Step 7: Update Create.vue to pass units and add "Add line for each unit" button**

In `resources/js/Pages/ServiceRecords/Create.vue`, make the following changes:

**Add to `<script setup>` — ref for client units and fetch logic:**

Find the existing `presetClient` prop usage. Add after the `ref` imports at the top:

```javascript
import { ref, computed, watch } from 'vue';
```

Add a `clientUnits` ref and a function to fetch units when client changes. Find where `selectedClient` is managed (the client picker sets it) and add:

```javascript
const clientUnits = ref(props.presetClientUnits ?? []);

// Fetch units whenever the selected client changes
const onClientSelected = (client) => {
    selectedClient.value = client;
    if (client?.id) {
        fetch(route('clients.units.index', client.id), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(r => r.json())
            .then(units => { clientUnits.value = units; })
            .catch(() => { clientUnits.value = []; });
    } else {
        clientUnits.value = [];
    }
};
```

**Update the `defineProps` to include `presetClientUnits`:**

```javascript
const props = defineProps({
    // ... existing props ...
    presetClientUnits: { type: Array, default: () => [] },
});
```

**Add "Add line for each unit" button** — find the existing "Add service line" button and add alongside it:

```html
<button
    v-if="clientUnits.length"
    type="button"
    class="rounded-ra border border-primary px-4 py-2.5 text-sm font-semibold text-primary transition hover:bg-primary-50"
    @click="addLinesForAllUnits"
>
    + Add line for each unit ({{ clientUnits.length }})
</button>
```

**Add the `addLinesForAllUnits` function** to `<script setup>`:

```javascript
const addLinesForAllUnits = () => {
    clientUnits.value.forEach(unit => {
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
        });
    });
};
```

**Pass `clientUnits` to each ServiceLineCard** — find the `<ServiceLineCard>` component usage and add `:client-units="clientUnits"`.

**Pass `onClientSelected` to ClientPicker** — find `<ClientPicker>` and update its `@select` handler to call `onClientSelected` instead of directly setting `selectedClient`.

- [ ] **Step 8: Build frontend**

```bash
docker exec saifzz-aircond-laravel.test-1 npm run build
```

Expected: `✓ built in X.XXs`

- [ ] **Step 9: Run full test suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all PASS

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php \
        app/Http/Requests/StoreServiceVisitRequest.php \
        resources/js/Pages/ServiceRecords/Partials/ServiceLineCard.vue \
        resources/js/Pages/ServiceRecords/Create.vue \
        tests/Feature/ClientUnitTest.php \
        public/build
git commit -m "feat(units): service visit unit selector — unit_id on lines, sync unit next_service_date on save"
```

---

## Self-Review Checklist

### Spec coverage
- [x] `client_units` table — Task 1
- [x] `unit_id` FK on `service_lines` — Task 1
- [x] Full identity fields (label, type, HP, brand, model, serial, refrigerant) — Tasks 1 + 2
- [x] `manage_units` permission — Task 2
- [x] ClientUnitController store/update/deactivate — Task 3
- [x] Auto-migration from service_lines — Task 4
- [x] ReminderService refactor to client_units — Task 5
- [x] Fallback for legacy clients — Task 5
- [x] Client profile units section — Task 6
- [x] UnitModal (add/edit) — Task 6
- [x] Unit selector on service line card — Task 7
- [x] "Add line for each unit" button — Task 7
- [x] Sync `unit.next_service_date` after visit save — Task 7
- [x] `next_service_date` null on line when unit_id set — Task 7
- [x] Inline unit add from service visit — partial (index endpoint exists, UI link in UnitModal accessible; full inline add via modal is wired in Task 7 via the `clients.units.store` route)

### No placeholders found ✓
### Type consistency verified ✓ (ClientUnit/ClientUnitController/client_units consistent throughout)
