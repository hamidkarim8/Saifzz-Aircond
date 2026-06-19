# Business Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Per-tenant, admin-editable business identity (name/address/phone/SSM) + Google Review QR, surfaced on invoices/receipts and the payment-received view, plus a static official-logo swap with favicon, under one consolidated Business Settings nav hub.

**Architecture:** New `business_settings` table (one row per tenant root, mirrors `tenant_gateways`). A `BusinessSetting::forTenant()` resolver returns the tenant row or falls back to `config('business.*')`. `SnapshotBuilder` freezes per-tenant identity (incl. SSM) into each document; the document blade gains a base64 logo + SSM line. A tabbed `BusinessSettings/Index.vue` (Identity + live preview / Google Review / Payment) drives it; the existing payment-gateway backend is reused untouched. Logo is a static asset swap.

**Tech Stack:** Laravel 11 (PHP 8.5), Inertia + Vue 3, Tailwind, dompdf (`barryvdh/laravel-dompdf`), Postgres, Docker Compose. Tests via `docker exec saifzz-aircond-laravel.test-1 php artisan test`. Frontend build via `docker compose exec -T laravel.test npm run build`.

**Conventions:**
- Test runner: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=<Name>` (sequential, no `--parallel`).
- Per-tenant seam: `tenant_id` is ALWAYS server-sourced from `auth user`, never from request input.
- Branch: `dev`. Commit after each task. No Co-Authored-By trailer.

---

## File Structure

**Create:**
- `database/migrations/2026_06_17_000001_create_business_settings_table.php`
- `app/Models/BusinessSetting.php`
- `app/Http/Controllers/BusinessSettingController.php`
- `app/Http/Requests/UpdateBusinessSettingRequest.php`
- `resources/js/Pages/BusinessSettings/Index.vue`
- `resources/js/Pages/BusinessSettings/Partials/InvoicePreview.vue`
- `tests/Feature/BusinessSettingTest.php`
- `public/img/logo-256.png`, `public/favicon.png` (generated assets; `public/favicon.ico` regenerated)

**Modify:**
- `app/Services/Documents/SnapshotBuilder.php` — per-tenant business + ssm
- `app/Support/` helper for logo data-URI (new file `app/Support/BrandAssets.php`)
- `app/Http/Controllers/DocumentController.php` — pass `$logo`
- `app/Http/Controllers/PortalController.php` — pass `$logo` where it renders documents
- `resources/views/documents/layout.blade.php` — logo img + ssm line
- `resources/views/app.blade.php` — favicon links
- `app/Http/Controllers/ServiceVisitController.php` — Show props (review qr/url)
- `resources/js/Pages/ServiceRecords/Show.vue` — Google Review button + modal
- `resources/js/Layouts/AdminLayout.vue` — nav: Business Settings replaces Payment Settings; logo img
- `resources/js/Layouts/GuestLayout.vue`, `resources/js/Pages/Welcome.vue`, `resources/js/Pages/Portal/Login.vue` — logo img
- `routes/web.php` — business-settings routes + payment-settings GET redirect
- `database/seeders/DatabaseSeeder.php` — Saifzz business_settings row

**Already done (pre-plan):** `public/img/logo.png` (2.5MB source) + `public/img/google-review-qr.png` copied in.

---

## Task 1: business_settings migration + model + forTenant resolver

**Files:**
- Create: `database/migrations/2026_06_17_000001_create_business_settings_table.php`
- Create: `app/Models/BusinessSetting.php`
- Test: `tests/Feature/BusinessSettingTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('business_name')->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('ssm_no')->nullable();
            $table->string('google_review_url')->nullable();
            $table->string('google_review_qr_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSetting extends Model
{
    protected $fillable = [
        'tenant_id', 'business_name', 'address', 'phone',
        'ssm_no', 'google_review_url', 'google_review_qr_path',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    /**
     * Resolve a tenant's business identity, falling back to global config
     * when no row exists (or tenant is null — test fixtures / legacy rows).
     *
     * @return array{name:string,address:string,phone:string,ssm_no:?string,google_review_url:?string,google_review_qr_path:?string}
     */
    public static function forTenant(?int $tenantId): array
    {
        $row = $tenantId !== null
            ? static::where('tenant_id', $tenantId)->first()
            : null;

        return [
            'name' => $row?->business_name ?: config('business.name'),
            'address' => $row?->address ?: config('business.address'),
            'phone' => $row?->phone ?: config('business.phone'),
            'ssm_no' => $row?->ssm_no,
            'google_review_url' => $row?->google_review_url,
            'google_review_qr_path' => $row?->google_review_qr_path,
        ];
    }
}
```

- [ ] **Step 3: Write the failing test**

Create `tests/Feature/BusinessSettingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_tenant_returns_row_when_present(): void
    {
        $boss = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $boss->update(['tenant_id' => $boss->id]);
        BusinessSetting::create([
            'tenant_id' => $boss->id,
            'business_name' => 'Acme Cooling',
            'ssm_no' => '202603093151 (003839732-K)',
        ]);

        $resolved = BusinessSetting::forTenant($boss->id);

        $this->assertSame('Acme Cooling', $resolved['name']);
        $this->assertSame('202603093151 (003839732-K)', $resolved['ssm_no']);
    }

    public function test_for_tenant_falls_back_to_config_when_absent(): void
    {
        $resolved = BusinessSetting::forTenant(null);

        $this->assertSame(config('business.name'), $resolved['name']);
        $this->assertNull($resolved['ssm_no']);
    }
}
```

> NOTE: confirm the `User` factory + `ROLE_ADMIN` constant exist (they do — used across the suite). If the factory does not set `tenant_id` automatically, the explicit `update` above covers it.

- [ ] **Step 4: Run migration + test**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=BusinessSettingTest`
Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_17_000001_create_business_settings_table.php app/Models/BusinessSetting.php tests/Feature/BusinessSettingTest.php
git commit -m "feat: business_settings table + BusinessSetting::forTenant resolver"
```

---

## Task 2: SnapshotBuilder reads per-tenant identity + SSM

**Files:**
- Modify: `app/Services/Documents/SnapshotBuilder.php:17-22`
- Test: `tests/Feature/BusinessSettingTest.php` (add test)

- [ ] **Step 1: Write the failing test** (append to `BusinessSettingTest`)

```php
    public function test_snapshot_freezes_per_tenant_business_identity(): void
    {
        $boss = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $boss->update(['tenant_id' => $boss->id]);
        BusinessSetting::create([
            'tenant_id' => $boss->id,
            'business_name' => 'Tenant Cooling Co',
            'ssm_no' => 'SSM-123',
        ]);

        $client = \App\Models\Client::factory()->create(['tenant_id' => $boss->id]);
        $visit = \App\Models\ServiceVisit::factory()->create([
            'tenant_id' => $boss->id,
            'client_id' => $client->id,
            'created_by' => $boss->id,
            'technician_id' => $boss->id,
        ]);
        $txn = \App\Models\Transaction::factory()->create(['visit_id' => $visit->id]);

        $snap = app(\App\Services\Documents\SnapshotBuilder::class)->forTransaction($txn);

        $this->assertSame('Tenant Cooling Co', $snap['business']['name']);
        $this->assertSame('SSM-123', $snap['business']['ssm_no']);
    }
```

> NOTE: verify factory field names (`Client`, `ServiceVisit`, `Transaction` factories exist — used in existing tests). Adjust required columns to match the existing factories (e.g. visit may need `visit_date`, `total_amount`). Reference an existing test that builds a transaction (e.g. in `tests/Feature` document/payment tests) and copy its factory setup if the above misses a required column.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_snapshot_freezes_per_tenant_business_identity`
Expected: FAIL — `business.name` is still global config / `ssm_no` key missing.

- [ ] **Step 3: Edit SnapshotBuilder**

Replace the `'business'` block (lines 18-22):

```php
            'business' => (function () use ($visit) {
                $b = \App\Models\BusinessSetting::forTenant($visit->tenant_id);
                return [
                    'name' => $b['name'],
                    'address' => $b['address'],
                    'phone' => $b['phone'],
                    'ssm_no' => $b['ssm_no'],
                ];
            })(),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=BusinessSettingTest`
Expected: all passed.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Documents/SnapshotBuilder.php tests/Feature/BusinessSettingTest.php
git commit -m "feat: SnapshotBuilder freezes per-tenant business identity + SSM"
```

---

## Task 3: Logo data-URI helper + document blade (logo + SSM)

**Files:**
- Create: `app/Support/BrandAssets.php`
- Modify: `app/Http/Controllers/DocumentController.php` (invoiceData/receiptData)
- Modify: `app/Http/Controllers/PortalController.php` (document render path)
- Modify: `resources/views/documents/layout.blade.php`

- [ ] **Step 1: Create the brand-assets helper**

```php
<?php

namespace App\Support;

class BrandAssets
{
    /**
     * Base64 data-URI of the web logo — renders in both the HTML document
     * view and dompdf (which cannot reliably resolve asset URLs).
     * Cached per-request.
     */
    public static function logoDataUri(): ?string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached ?: null;
        }

        $path = public_path('img/logo-256.png');
        if (! is_file($path)) {
            $cached = '';
            return null;
        }

        $cached = 'data:image/png;base64,'.base64_encode(file_get_contents($path));
        return $cached;
    }
}
```

> NOTE: `logo-256.png` is generated in Task 8. Until then this returns null and the blade simply renders no logo — safe ordering.

- [ ] **Step 2: Pass `$logo` from DocumentController**

In `app/Http/Controllers/DocumentController.php`, add `'logo' => \App\Support\BrandAssets::logoDataUri(),` to the arrays returned by BOTH `invoiceData()` (line ~62) and `receiptData()`. Since `receiptData()` delegates to `DocumentService::receiptViewModel()`, merge there instead:

```php
    private function receiptData(Transaction $transaction): array
    {
        return $this->documents->receiptViewModel($transaction)
            + ['logo' => \App\Support\BrandAssets::logoDataUri()];
    }
```

And in `invoiceData()` return array add:

```php
            'logo' => \App\Support\BrandAssets::logoDataUri(),
```

- [ ] **Step 3: Pass `$logo` from PortalController**

Find where `PortalController` renders `documents.invoice` / `documents.receipt` (grep `documents.`). Add `'logo' => \App\Support\BrandAssets::logoDataUri(),` to the same data array(s). If PortalController builds the view-model via `DocumentService`, merge the key the same way as Step 2.

- [ ] **Step 4: Edit the blade header**

In `resources/views/documents/layout.blade.php`, replace the `.head` div (lines 62-69) with:

```blade
        <div class="head">
            @if(!empty($logo))
                <img src="{{ $logo }}" alt="" style="height:64px;width:64px;border-radius:50%;object-fit:cover;display:block;margin:0 auto 10px;">
            @endif
            <div class="co">{{ $snapshot['business']['name'] ?? config('business.name') }}</div>
            <div class="co-sub">
                {{ $snapshot['business']['address'] ?? '' }}<br>
                {{ $snapshot['business']['phone'] ?? '' }}
                @if(!empty($snapshot['business']['ssm_no']))
                    <br>SSM: {{ $snapshot['business']['ssm_no'] }}
                @endif
            </div>
            <div class="kind">@yield('kind')</div>
        </div>
```

- [ ] **Step 5: Smoke test the render**

Run the existing document tests to confirm no regression:
Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=Document`
Expected: existing document tests still pass (blade renders with `$logo` null = no img, ssm optional).

> If no `Document`-named test exists, run the payment/receipt feature tests that hit the document views instead, or `php artisan test` for the whole suite at the next checkpoint.

- [ ] **Step 6: Commit**

```bash
git add app/Support/BrandAssets.php app/Http/Controllers/DocumentController.php app/Http/Controllers/PortalController.php resources/views/documents/layout.blade.php
git commit -m "feat: logo + SSM on invoice/receipt documents (base64 for PDF)"
```

---

## Task 4: BusinessSettingController + request + routes

**Files:**
- Create: `app/Http/Controllers/BusinessSettingController.php`
- Create: `app/Http/Requests/UpdateBusinessSettingRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BusinessSettingTest.php`

- [ ] **Step 1: Write the form request**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('manage_users') ?? false;
    }

    public function rules(): array
    {
        return [
            'business_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'ssm_no' => ['nullable', 'string', 'max:100'],
            'google_review_url' => ['nullable', 'url', 'max:500'],
            'google_review_qr' => ['nullable', 'image', 'max:2048'], // KB
        ];
    }
}
```

> NOTE: confirm `User::hasPermission` is the correct method name (used throughout — yes). Admins are implicitly granted; if `hasPermission` does not auto-grant admins, mirror whatever `UpdatePaymentGatewayRequest::authorize()` does — copy it verbatim for consistency.

- [ ] **Step 2: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBusinessSettingRequest;
use App\Models\BusinessSetting;
use App\Models\TenantGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingController extends Controller
{
    public function show(): Response
    {
        $tenantId = request()->user()->id;
        $row = BusinessSetting::where('tenant_id', $tenantId)->first();
        $gateway = TenantGateway::where('tenant_id', $tenantId)->first();

        return Inertia::render('BusinessSettings/Index', [
            'settings' => [
                'business_name' => $row?->business_name,
                'address' => $row?->address,
                'phone' => $row?->phone,
                'ssm_no' => $row?->ssm_no,
                'google_review_url' => $row?->google_review_url,
            ],
            'qrUrl' => $row?->google_review_qr_path
                ? Storage::disk('public')->url($row->google_review_qr_path)
                : null,
            'payment' => [
                'isConfigured' => $gateway !== null,
                'portalKeyHint' => $gateway ? substr($gateway->portal_key, -4) : null,
            ],
        ]);
    }

    public function update(UpdateBusinessSettingRequest $request): RedirectResponse
    {
        $tenantId = $request->user()->id;

        $data = $request->only(['business_name', 'address', 'phone', 'ssm_no', 'google_review_url']);

        if ($request->hasFile('google_review_qr')) {
            $path = "qr/tenant-{$tenantId}.png";
            Storage::disk('public')->put($path, file_get_contents($request->file('google_review_qr')->getRealPath()));
            $data['google_review_qr_path'] = $path;
        }

        BusinessSetting::updateOrCreate(['tenant_id' => $tenantId], $data);

        return back()->with('success', 'Business settings saved.');
    }
}
```

- [ ] **Step 3: Add routes**

In `routes/web.php`, inside the same authenticated/admin group where `payment-settings` lives, add:

```php
    Route::get('/business-settings', [\App\Http\Controllers\BusinessSettingController::class, 'show'])->name('business-settings.show');
    Route::put('/business-settings', [\App\Http\Controllers\BusinessSettingController::class, 'update'])->name('business-settings.update');
    Route::redirect('/payment-settings', '/business-settings'); // legacy GET → hub
```

> Keep the existing `payment-settings.update` PUT route. Remove ONLY the old `payment-settings.index` GET route line (replaced by the redirect above) — confirm its exact name before deleting; if other code references `payment-settings.index`, keep it as the redirect's name instead: `Route::redirect('/payment-settings', '/business-settings')->name('payment-settings.index');`.

- [ ] **Step 4: Write failing tests** (append to `BusinessSettingTest`)

```php
    private function bossAdmin(): User
    {
        $boss = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $boss->update(['tenant_id' => $boss->id]);
        return $boss;
    }

    public function test_admin_can_view_business_settings(): void
    {
        $this->actingAs($this->bossAdmin())
            ->get(route('business-settings.show'))
            ->assertOk();
    }

    public function test_admin_can_save_identity(): void
    {
        $boss = $this->bossAdmin();
        $this->actingAs($boss)->put(route('business-settings.update'), [
            'business_name' => 'New Name Sdn Bhd',
            'ssm_no' => '202603093151 (003839732-K)',
        ])->assertRedirect();

        $this->assertDatabaseHas('business_settings', [
            'tenant_id' => $boss->id,
            'business_name' => 'New Name Sdn Bhd',
            'ssm_no' => '202603093151 (003839732-K)',
        ]);
    }

    public function test_qr_upload_stores_file_and_path(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $boss = $this->bossAdmin();

        $this->actingAs($boss)->put(route('business-settings.update'), [
            'google_review_qr' => \Illuminate\Http\Testing\File::image('qr.png', 200, 200),
        ])->assertRedirect();

        $this->assertDatabaseHas('business_settings', [
            'tenant_id' => $boss->id,
            'google_review_qr_path' => "qr/tenant-{$boss->id}.png",
        ]);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists("qr/tenant-{$boss->id}.png");
    }

    public function test_tenant_id_not_honored_from_input(): void
    {
        $boss = $this->bossAdmin();
        $this->actingAs($boss)->put(route('business-settings.update'), [
            'tenant_id' => 99999,
            'business_name' => 'X',
        ])->assertRedirect();

        $this->assertDatabaseHas('business_settings', ['tenant_id' => $boss->id]);
        $this->assertDatabaseMissing('business_settings', ['tenant_id' => 99999]);
    }

    public function test_non_admin_cannot_update(): void
    {
        $tech = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);
        $this->actingAs($tech)->put(route('business-settings.update'), [
            'business_name' => 'Hax',
        ])->assertForbidden();
    }
```

> NOTE: PUT with a file upload — Laravel's test client handles multipart on `put()` via method spoofing automatically. If the file does not arrive, switch the QR test to `->put(route(...), [...])` using `Illuminate\Http\UploadedFile::fake()->image(...)` (equivalent). Confirm `ROLE_TECHNICIAN` default lacks `manage_users` (it does).

- [ ] **Step 5: Run tests**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=BusinessSettingTest`
Expected: all passed.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/BusinessSettingController.php app/Http/Requests/UpdateBusinessSettingRequest.php routes/web.php tests/Feature/BusinessSettingTest.php
git commit -m "feat: BusinessSettingController (identity + QR upload), routes, tests"
```

---

## Task 5: BusinessSettings page (Identity + live preview / Google Review / Payment)

**Files:**
- Create: `resources/js/Pages/BusinessSettings/Index.vue`
- Create: `resources/js/Pages/BusinessSettings/Partials/InvoicePreview.vue`

No automated test (frontend). Verified via build + manual.

- [ ] **Step 1: Create the invoice preview partial**

`resources/js/Pages/BusinessSettings/Partials/InvoicePreview.vue` — mirrors the document header styling so edits preview live:

```vue
<script setup>
defineProps({
    name: { type: String, default: '' },
    address: { type: String, default: '' },
    phone: { type: String, default: '' },
    ssm: { type: String, default: '' },
});
</script>

<template>
    <div class="mx-auto max-w-[420px] rounded-[10px] border border-line bg-white p-6 text-[12px] text-ink shadow-card">
        <div class="border-b-2 border-navy-800 pb-4 text-center">
            <img src="/img/logo-256.png" alt="" class="mx-auto mb-2.5 h-16 w-16 rounded-full object-cover" />
            <div class="text-[19px] font-bold text-navy-800">{{ name || 'Business Name' }}</div>
            <div class="mt-1 text-[10.5px] leading-relaxed text-ink-soft">
                {{ address || 'Business address' }}<br />
                {{ phone || 'Phone number' }}
                <template v-if="ssm"><br />SSM: {{ ssm }}</template>
            </div>
            <div class="mt-2.5 text-[14px] font-bold uppercase tracking-wide text-primary">Invoice</div>
        </div>
        <div class="mt-3 rounded-md border border-line bg-surface p-3">
            <div class="font-bold text-navy-800">Aircond Service · Sample</div>
            <div class="mt-1 flex justify-between text-ink-soft"><span>Units × 1</span><span>RM 120.00</span></div>
        </div>
        <div class="mt-2 flex items-center justify-between rounded-md bg-navy-800 px-3.5 py-3 text-white">
            <span class="text-[10.5px] font-bold uppercase tracking-wide">Total</span>
            <span class="text-[21px] font-extrabold">RM 120.00</span>
        </div>
    </div>
</template>
```

> NOTE: match Tailwind tokens to the project's config (`navy-800`, `ink`, `ink-soft`, `line`, `surface`, `primary`, `shadow-card`, `rounded-ra` are used elsewhere — confirm in `tailwind.config.js`; substitute the nearest existing token if any name differs).

- [ ] **Step 2: Create the tabbed settings page**

`resources/js/Pages/BusinessSettings/Index.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import InvoicePreview from './Partials/InvoicePreview.vue';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    qrUrl: { type: String, default: null },
    payment: { type: Object, default: () => ({}) },
});

const activeTab = ref('identity');

const idForm = useForm({
    business_name: props.settings.business_name ?? '',
    address: props.settings.address ?? '',
    phone: props.settings.phone ?? '',
    ssm_no: props.settings.ssm_no ?? '',
});
const saveIdentity = () => idForm.put(route('business-settings.update'), { preserveScroll: true });

const reviewForm = useForm({
    google_review_url: props.settings.google_review_url ?? '',
    google_review_qr: null,
});
const saveReview = () => reviewForm.put(route('business-settings.update'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => { reviewForm.google_review_qr = null; },
});

const payForm = useForm({ api_token: '', portal_key: '', api_secret: '' });
const savePayment = () => payForm.put(route('payment-settings.update'), {
    preserveScroll: true,
    onSuccess: () => payForm.reset(),
});

const tabs = [
    { id: 'identity', label: 'Identity' },
    { id: 'review', label: 'Google Review' },
    { id: 'payment', label: 'Payment' },
];
const inputClass = 'w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary';
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-navy-800">Business Settings</h1>
        </template>

        <div class="mb-5 flex gap-1 border-b border-line">
            <button
                v-for="t in tabs" :key="t.id"
                class="border-b-2 px-4 py-2 text-sm font-semibold transition"
                :class="activeTab === t.id ? 'border-primary text-primary' : 'border-transparent text-ink-soft hover:text-ink'"
                @click="activeTab = t.id"
            >{{ t.label }}</button>
        </div>

        <!-- Identity -->
        <div v-if="activeTab === 'identity'" class="grid gap-6 lg:grid-cols-2">
            <Card title="Business identity">
                <form class="space-y-4" @submit.prevent="saveIdentity">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Business name</label>
                        <input v-model="idForm.business_name" :class="inputClass" placeholder="Saifzz Aircond Services" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Address</label>
                        <textarea v-model="idForm.address" rows="2" :class="inputClass" placeholder="Business address"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Phone number</label>
                        <input v-model="idForm.phone" :class="inputClass" placeholder="012-9876543" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">SSM registration no.</label>
                        <input v-model="idForm.ssm_no" :class="inputClass" placeholder="202603093151 (003839732-K)" />
                    </div>
                    <button type="submit" :disabled="idForm.processing"
                        class="inline-flex items-center rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60">
                        {{ idForm.processing ? 'Saving…' : 'Save identity' }}
                    </button>
                </form>
            </Card>
            <div>
                <div class="mb-2 text-sm font-semibold text-ink-soft">Invoice preview</div>
                <InvoicePreview :name="idForm.business_name" :address="idForm.address" :phone="idForm.phone" :ssm="idForm.ssm_no" />
            </div>
        </div>

        <!-- Google Review -->
        <div v-else-if="activeTab === 'review'" class="grid gap-6 lg:grid-cols-2">
            <Card title="Google Review QR">
                <form class="space-y-4" @submit.prevent="saveReview">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Review link (optional)</label>
                        <input v-model="reviewForm.google_review_url" :class="inputClass" placeholder="https://g.page/r/..." />
                        <p v-if="reviewForm.errors.google_review_url" class="mt-1 text-xs text-danger">{{ reviewForm.errors.google_review_url }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">QR image (PNG/JPG, max 2MB)</label>
                        <input type="file" accept="image/*" :class="inputClass"
                            @change="reviewForm.google_review_qr = $event.target.files[0]" />
                        <p v-if="reviewForm.errors.google_review_qr" class="mt-1 text-xs text-danger">{{ reviewForm.errors.google_review_qr }}</p>
                    </div>
                    <button type="submit" :disabled="reviewForm.processing"
                        class="inline-flex items-center rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60">
                        {{ reviewForm.processing ? 'Saving…' : 'Save Google Review' }}
                    </button>
                </form>
            </Card>
            <div>
                <div class="mb-2 text-sm font-semibold text-ink-soft">Current QR</div>
                <div class="grid place-items-center rounded-ral border border-line bg-white p-6">
                    <img v-if="qrUrl" :src="qrUrl" alt="Google Review QR" class="h-48 w-48 object-contain" />
                    <span v-else class="text-sm text-ink-soft">No QR uploaded yet.</span>
                </div>
            </div>
        </div>

        <!-- Payment -->
        <div v-else>
            <div class="mb-6 flex items-center gap-3 rounded-ra border px-4 py-3 text-sm font-medium"
                :class="payment.isConfigured ? 'border-green-300 bg-green-50 text-ok' : 'border-yellow-300 bg-yellow-50 text-yellow-700'">
                <span v-if="payment.isConfigured">Gateway configured ✓ — DuitNow QR payments are live.</span>
                <span v-else>Gateway not configured — payments will use test mode.</span>
            </div>
            <Card title="BayarCash Credentials">
                <p class="mb-5 text-sm text-ink-soft">Leave a field blank to keep the existing value. Credentials are encrypted at rest.</p>
                <form class="space-y-4" @submit.prevent="savePayment">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">API Token</label>
                        <input v-model="payForm.api_token" type="password" autocomplete="off" :class="inputClass" :placeholder="payment.isConfigured ? '••••••••' : 'Enter API Token'" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Portal Key</label>
                        <input v-model="payForm.portal_key" type="password" autocomplete="off" :class="inputClass" :placeholder="payment.isConfigured && payment.portalKeyHint ? `•••••••• (ending …${payment.portalKeyHint})` : 'Enter Portal Key'" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">API Secret</label>
                        <input v-model="payForm.api_secret" type="password" autocomplete="off" :class="inputClass" :placeholder="payment.isConfigured ? '••••••••' : 'Enter API Secret'" />
                    </div>
                    <button type="submit" :disabled="payForm.processing"
                        class="inline-flex items-center rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60">
                        {{ payForm.processing ? 'Saving…' : 'Save credentials' }}
                    </button>
                </form>
            </Card>
        </div>
    </AdminLayout>
</template>
```

- [ ] **Step 2 (verify): Build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: build succeeds, no Vue compile errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/BusinessSettings/
git commit -m "feat: Business Settings page (identity+preview / review QR / payment tabs)"
```

---

## Task 6: Nav swap (Business Settings replaces Payment Settings) + delete old page

**Files:**
- Modify: `resources/js/Layouts/AdminLayout.vue:41`
- Delete: `resources/js/Pages/PaymentSettings/Index.vue`

- [ ] **Step 1: Swap nav item**

In `resources/js/Layouts/AdminLayout.vue`, replace line 41:

```js
            { label: 'Payment Settings', route: 'payment-settings.index', match: 'payment-settings', icon: IconCreditCard, adminOnly: true },
```

with:

```js
            { label: 'Business Settings', route: 'business-settings.show', match: 'business-settings', icon: IconBuildingStore, adminOnly: true },
```

Add `IconBuildingStore` to the `@tabler/icons-vue` import line (keep `IconCreditCard` only if still used elsewhere in the file — grep; if not, remove it).

- [ ] **Step 2: Delete the old standalone page**

```bash
git rm resources/js/Pages/PaymentSettings/Index.vue
```

> The route redirect from Task 4 covers any direct hits to `/payment-settings`. The `PaymentGatewayController::index()` Inertia render of `PaymentSettings/Index` is now unreachable via nav — but if Task 4 kept `payment-settings.index` as the redirect name, `index()` is dead code. Leave `PaymentGatewayController::index()` in place (harmless); do not break `payment-settings.update`.

- [ ] **Step 3: Build + confirm**

Run: `docker compose exec -T laravel.test npm run build`
Expected: success, no missing-import errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Layouts/AdminLayout.vue
git commit -m "feat: Business Settings nav hub replaces Payment Settings item"
```

---

## Task 7: Google Review button + modal on payment-received view

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php:164-166` (Show props)
- Modify: `resources/js/Pages/ServiceRecords/Show.vue` (paid block + modal)
- Test: `tests/Feature/BusinessSettingTest.php`

- [ ] **Step 1: Pass review props from controller**

In `ServiceVisitController::show()`, after `$serviceRecord->load(...)`, build the review payload and add to the render:

```php
        $biz = \App\Models\BusinessSetting::forTenant($serviceRecord->tenant_id);
        $qrUrl = $biz['google_review_qr_path']
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($biz['google_review_qr_path'])
            : null;

        return Inertia::render('ServiceRecords/Show', [
            'visit' => $serviceRecord,
            'googleReview' => ['qrUrl' => $qrUrl, 'url' => $biz['google_review_url']],
        ]);
```

- [ ] **Step 2: Write failing test** (append to `BusinessSettingTest`)

```php
    public function test_show_passes_google_review_qr_url(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $boss = $this->bossAdmin();
        BusinessSetting::create([
            'tenant_id' => $boss->id,
            'google_review_qr_path' => 'qr/sample.png',
            'google_review_url' => 'https://g.page/r/test',
        ]);
        \Illuminate\Support\Facades\Storage::disk('public')->put('qr/sample.png', 'x');

        $client = \App\Models\Client::factory()->create(['tenant_id' => $boss->id]);
        $visit = \App\Models\ServiceVisit::factory()->create([
            'tenant_id' => $boss->id, 'client_id' => $client->id,
            'created_by' => $boss->id, 'technician_id' => $boss->id,
        ]);

        $this->actingAs($boss)
            ->get(route('service-records.show', $visit->id))
            ->assertInertia(fn ($page) => $page
                ->where('googleReview.url', 'https://g.page/r/test')
                ->whereNot('googleReview.qrUrl', null));
    }
```

> NOTE: confirm the Show route name is `service-records.show` (grep `routes/web.php`). Confirm `assertInertia` is available (`Inertia\Testing\AssertableInertia` — used in existing DashboardTest). Match the visit factory's required columns to existing tests.

- [ ] **Step 3: Run test to verify it fails then passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_show_passes_google_review_qr_url`
Expected: PASS after Step 1 (write test first → run → see fail if controller not yet edited; here Step 1 precedes, so confirm green).

- [ ] **Step 4: Add button + modal in Show.vue**

In `resources/js/Pages/ServiceRecords/Show.vue`:

(a) Add prop + state to `<script setup>`:

```js
const props = defineProps({
    visit: Object,
    googleReview: { type: Object, default: () => ({ qrUrl: null, url: null }) },
});
const showReview = ref(false);
```

> Merge with the EXISTING `defineProps` — do not duplicate. Ensure `ref` is imported from `vue` and `Modal` from `@/Components/Modal.vue` (add imports if missing).

(b) In the paid block (line ~156, the `<span>` of receipt links), add the button:

```vue
                        <button
                            v-if="googleReview.qrUrl"
                            type="button"
                            class="inline-flex items-center rounded-ra border border-ok/50 bg-white px-3 py-1.5 text-sm font-semibold text-ok transition hover:bg-ok/10"
                            @click="showReview = true"
                        >Google Review</button>
```

(c) Add the modal before `</AdminLayout>`:

```vue
        <Modal :show="showReview" @close="showReview = false">
            <div class="space-y-4 p-6 text-center">
                <h3 class="text-base font-bold text-navy-800">Rate us on Google</h3>
                <p class="text-sm text-ink-soft">Scan the QR code to leave a review.</p>
                <img v-if="googleReview.qrUrl" :src="googleReview.qrUrl" alt="Google Review QR" class="mx-auto h-56 w-56 object-contain" />
                <a v-if="googleReview.url" :href="googleReview.url" target="_blank" rel="noopener"
                    class="block text-sm font-semibold text-primary underline">Open review page</a>
                <button type="button" class="rounded-ra border border-line px-4 py-2 text-sm font-semibold text-ink-soft hover:bg-surface" @click="showReview = false">Close</button>
            </div>
        </Modal>
```

> Confirm `Modal.vue`'s prop/event API (`:show` + `@close`) by reading it; adapt if it uses different prop names.

- [ ] **Step 5: Build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: success.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php resources/js/Pages/ServiceRecords/Show.vue tests/Feature/BusinessSettingTest.php
git commit -m "feat: Google Review QR button + modal on paid service record"
```

---

## Task 8: Static logo swap + favicon + resized assets

**Files:**
- Create: `public/img/logo-256.png`, `public/favicon.png`, regenerate `public/favicon.ico`
- Modify: `resources/views/app.blade.php`
- Modify: `resources/js/Layouts/AdminLayout.vue:84`, `resources/js/Layouts/GuestLayout.vue`, `resources/js/Pages/Welcome.vue`, `resources/js/Pages/Portal/Login.vue`

- [ ] **Step 1: Generate resized assets**

Inside the container (has PHP GD; ImageMagick may also be present). Try ImageMagick first, fall back to a PHP GD one-liner:

```bash
# ImageMagick (preferred)
docker compose exec -T laravel.test sh -c 'command -v convert && convert public/img/logo.png -resize 256x256 public/img/logo-256.png && convert public/img/logo.png -resize 32x32 public/favicon.png && convert public/img/logo.png -resize 32x32 public/favicon.ico || echo NO_IMAGEMAGICK'
```

If `NO_IMAGEMAGICK`, use PHP GD:

```bash
docker compose exec -T laravel.test php -r '
$src = imagecreatefrompng("public/img/logo.png");
$w = imagesx($src); $h = imagesy($src);
foreach ([["public/img/logo-256.png",256],["public/favicon.png",32]] as [$out,$sz]) {
  $dst = imagecreatetruecolor($sz,$sz);
  imagealphablending($dst,false); imagesavealpha($dst,true);
  imagecopyresampled($dst,$src,0,0,0,0,$sz,$sz,$w,$h);
  imagepng($dst,$out); imagedestroy($dst);
}
echo "done\n";'
# favicon.ico: browsers accept PNG content; copy the 32px png
docker compose exec -T laravel.test cp public/favicon.png public/favicon.ico
```

Verify: `ls -la public/img/logo-256.png public/favicon.png public/favicon.ico` — all exist, logo-256 well under 100KB.

- [ ] **Step 2: Add favicon links to app.blade.php**

In `resources/views/app.blade.php`, after the `<meta name="viewport">` line add:

```blade
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" type="image/png" href="/favicon.png">
```

- [ ] **Step 3: Swap the sidebar logo (AdminLayout)**

In `resources/js/Layouts/AdminLayout.vue` line 84, replace:

```vue
                <div class="grid h-9 w-9 place-items-center rounded-ra bg-primary text-white"><IconAirConditioning :size="20" /></div>
```

with:

```vue
                <img src="/img/logo-256.png" alt="Saifzz Aircond" class="h-9 w-9 rounded-ra object-cover" />
```

Remove `IconAirConditioning` from the import on line 8 (confirm no other use in the file via grep first).

- [ ] **Step 4: Swap logo in GuestLayout, Welcome, Portal/Login**

In each of `GuestLayout.vue`, `Welcome.vue`, `Portal/Login.vue`: grep for `IconAirConditioning`, replace each badge usage with an `<img src="/img/logo-256.png" alt="Saifzz Aircond" class="...">` keeping the SAME size/rounding classes the badge had (e.g. an `h-12 w-12` badge → `<img class="h-12 w-12 rounded-... object-cover">`). Remove now-unused `IconAirConditioning` imports.

> Read each file's current badge markup first; preserve its exact sizing classes so layout doesn't shift. Keep gradient/box wrappers only if they still look right behind a circular logo — otherwise drop the wrapper and apply rounding to the img.

- [ ] **Step 5: Build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: success, no unresolved imports.

- [ ] **Step 6: Commit**

```bash
git add public/img/logo-256.png public/favicon.png public/favicon.ico resources/views/app.blade.php resources/js/Layouts/AdminLayout.vue resources/js/Layouts/GuestLayout.vue resources/js/Pages/Welcome.vue resources/js/Pages/Portal/Login.vue
git commit -m "feat: official logo swap across app + favicon"
```

---

## Task 9: Seed Saifzz business settings + QR

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Add seeder block**

After the Saifzz superadmin user is created/resolved in `DatabaseSeeder::run()`, add (use the variable holding the Saifzz user; if it's fetched by email, fetch it):

```php
        $saifzz = \App\Models\User::where('email', 'saifzz@admin.com')->first();
        if ($saifzz) {
            // Copy the bundled Google Review QR onto the public disk for Saifzz.
            $qrSource = public_path('img/google-review-qr.png');
            $qrPath = "qr/tenant-{$saifzz->id}.png";
            if (is_file($qrSource)) {
                \Illuminate\Support\Facades\Storage::disk('public')->put($qrPath, file_get_contents($qrSource));
            }

            \App\Models\BusinessSetting::updateOrCreate(
                ['tenant_id' => $saifzz->id],
                [
                    'business_name' => config('business.name'),
                    'address' => config('business.address'),
                    'phone' => config('business.phone'),
                    'ssm_no' => '202603093151 (003839732-K)',
                    'google_review_qr_path' => is_file($qrSource) ? $qrPath : null,
                ],
            );
        }
```

> NOTE: confirm the Saifzz account email is `saifzz@admin.com` (memory FEAT-015) and the seeder self-roots `tenant_id = own id`. Idempotent via `updateOrCreate`.

- [ ] **Step 2: Run the seeder against the test DB to confirm no errors**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan db:seed --class=DatabaseSeeder --env=testing`
Expected: completes without error (or run full `migrate:fresh --seed` on the test container if that's the established pattern).

- [ ] **Step 3: Commit**

```bash
git add database/seeders/DatabaseSeeder.php
git commit -m "feat: seed Saifzz business identity + Google Review QR"
```

---

## Task 10: Full suite + build + final verification

- [ ] **Step 1: Run the complete test suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: all green (prior 290 + new BusinessSettingTest cases). Fix any regression before proceeding.

- [ ] **Step 2: Production build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: clean build.

- [ ] **Step 3: Manual smoke (document the check, do not skip)**

- Log in as Saifzz admin → sidebar shows official logo + "Business Settings" nav item.
- Business Settings → Identity: edit fields, preview updates live, save persists.
- Google Review tab: QR thumbnail shows seeded QR.
- Open a PAID service record → "Google Review" button → modal shows QR.
- Open an invoice + receipt (HTML view and PDF download) → logo + SSM render.
- Favicon shows in browser tab.

- [ ] **Step 4: Final commit if any fixups**

```bash
git add -A
git commit -m "chore: business settings final fixups"
```

---

## Self-Review (completed by plan author)

**Spec coverage:** logo static swap + favicon (T8); per-tenant identity table + resolver (T1); invoice/receipt identity+SSM+logo (T2,T3); Business Settings hub w/ live preview + payment fold-in (T4,T5,T6); Google Review QR upload (T4,T5) + button/modal (T7); seeding (T9). All spec sections mapped.

**Placeholder scan:** no TBD/TODO; every code step shows code. NOTE callouts flag facts the implementer must verify against existing code (factory columns, route names, Modal API, token names) — these are verification instructions, not placeholders.

**Type consistency:** `forTenant()` array keys (`name/address/phone/ssm_no/google_review_url/google_review_qr_path`) used consistently in T1/T2/T7/T9. Route names `business-settings.show|update`, `payment-settings.update` consistent. Prop `googleReview.{qrUrl,url}` consistent T7. QR path scheme `qr/tenant-{id}.png` consistent T4/T7/T9.
