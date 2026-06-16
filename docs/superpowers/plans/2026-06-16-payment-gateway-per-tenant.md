# Payment Gateway Per Tenant Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each boss (Khalid, Saifzz) stores their own BayarCash credentials encrypted in the DB; payment routing and webhook verification use the correct tenant's credentials.

**Architecture:** New `tenant_gateways` table with encrypted columns. `TenantGateway` model exposes a static `resolveGateway(?int $tenantId)` used by both `PaymentService` and `PaymentWebhookController`. New `PaymentGatewayController` + `Pages/PaymentSettings/Index.vue` let each boss configure their gateway. Existing DI binding in `PaymentServiceProvider` is removed (nothing injects `PaymentGateway` globally anymore).

**Tech Stack:** Laravel 12, Inertia.js + Vue 3, PostgreSQL, TailwindCSS, Laravel encrypted cast.

---

## File Map

| Action | File |
|--------|------|
| Create | `database/migrations/2026_06_16_000001_create_tenant_gateways_table.php` |
| Create | `app/Models/TenantGateway.php` |
| Modify | `app/Services/Payments/PaymentService.php` |
| Modify | `app/Http/Controllers/PaymentWebhookController.php` |
| Modify | `app/Providers/PaymentServiceProvider.php` |
| Create | `app/Http/Controllers/PaymentGatewayController.php` |
| Create | `app/Http/Requests/UpdatePaymentGatewayRequest.php` |
| Modify | `routes/web.php` |
| Create | `resources/js/Pages/PaymentSettings/Index.vue` |
| Modify | `resources/js/Layouts/AdminLayout.vue` |
| Create | `tests/Feature/PaymentGatewaySettingsTest.php` |
| Modify | `tests/Feature/PaymentWebhookTest.php` |

---

### Task 1: Migration + TenantGateway model

**Files:**
- Create: `database/migrations/2026_06_16_000001_create_tenant_gateways_table.php`
- Create: `app/Models/TenantGateway.php`

- [ ] **Step 1: Write the migration**

```php
<?php
// database/migrations/2026_06_16_000001_create_tenant_gateways_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('api_token');
            $table->text('portal_key');
            $table->text('api_secret');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_gateways');
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan migrate
```

Expected: `Migrating: 2026_06_16_000001_create_tenant_gateways_table` then `Migrated`.

- [ ] **Step 3: Write the TenantGateway model**

```php
<?php
// app/Models/TenantGateway.php
namespace App\Models;

use App\Services\Payments\BayarCashGateway;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\FakeBayarCashGateway;
use Illuminate\Database\Eloquent\Model;

class TenantGateway extends Model
{
    protected $fillable = ['tenant_id', 'api_token', 'portal_key', 'api_secret'];

    protected $casts = [
        'api_token' => 'encrypted',
        'portal_key' => 'encrypted',
        'api_secret' => 'encrypted',
    ];

    public static function resolveGateway(?int $tenantId): PaymentGateway
    {
        if ($tenantId !== null) {
            $row = static::where('tenant_id', $tenantId)->first();
            if ($row) {
                return new BayarCashGateway([
                    'api_token' => $row->api_token,
                    'portal_key' => $row->portal_key,
                    'api_secret' => $row->api_secret,
                    'channel' => 5,
                    'base_url' => config('services.bayarcash.base_url'),
                    'driver' => 'live',
                ]);
            }
        }
        $config = config('services.bayarcash');
        return ($config['driver'] ?? 'fake') === 'live'
            ? new BayarCashGateway($config)
            : new FakeBayarCashGateway();
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_16_000001_create_tenant_gateways_table.php app/Models/TenantGateway.php
git commit -m "feat: tenant_gateways table + TenantGateway model with resolveGateway"
```

---

### Task 2: Refactor PaymentService + PaymentWebhookController

**Files:**
- Modify: `app/Services/Payments/PaymentService.php`
- Modify: `app/Http/Controllers/PaymentWebhookController.php`
- Modify: `app/Providers/PaymentServiceProvider.php`

- [ ] **Step 1: Write failing test for tenant-aware webhook**

Add to `tests/Feature/PaymentWebhookTest.php`:

```php
public function test_tenant_gateway_secret_is_used_for_webhook_verification(): void
{
    $boss = \App\Models\User::factory()->admin()->create();
    $boss->update(['tenant_id' => $boss->id]);

    \App\Models\TenantGateway::create([
        'tenant_id' => $boss->id,
        'api_token' => 'tok',
        'portal_key' => 'pkey',
        'api_secret' => 'tenant-secret',
    ]);

    $client = \App\Models\Client::create([
        'name' => 'T', 'phone' => '012-0000000', 'address' => 'KL',
        'tenant_id' => $boss->tenantId(),
    ]);
    $visit = $client->visits()->create([
        'visit_date' => '2026-06-16', 'warranty_months' => 0, 'total_amount' => 100,
        'created_by' => $boss->id, 'technician_id' => null, 'tenant_id' => $boss->tenantId(),
    ]);
    $txn = $visit->transaction()->create([
        'txn_id' => 'TXN-20260616-001', 'amount' => 100,
        'method' => 'DuitNow QR', 'status' => 'pending', 'gateway_ref' => 'STUB-T1',
    ]);

    $fields = ['STUB-T1', $txn->txn_id, '100.00', '3'];
    $payload = [
        'transaction_id' => 'STUB-T1',
        'order_number' => $txn->txn_id,
        'amount' => '100.00',
        'status' => 3,
        'checksum' => Checksum::make($fields, 'tenant-secret'),
    ];

    $this->post(route('webhooks.bayarcash'), $payload)->assertOk();
    $this->assertSame('paid', $txn->fresh()->status);
}

public function test_wrong_tenant_secret_is_rejected(): void
{
    $boss = \App\Models\User::factory()->admin()->create();
    $boss->update(['tenant_id' => $boss->id]);

    \App\Models\TenantGateway::create([
        'tenant_id' => $boss->id,
        'api_token' => 'tok',
        'portal_key' => 'pkey',
        'api_secret' => 'tenant-secret',
    ]);

    $client = \App\Models\Client::create([
        'name' => 'T', 'phone' => '012-0000000', 'address' => 'KL',
        'tenant_id' => $boss->tenantId(),
    ]);
    $visit = $client->visits()->create([
        'visit_date' => '2026-06-16', 'warranty_months' => 0, 'total_amount' => 100,
        'created_by' => $boss->id, 'technician_id' => null, 'tenant_id' => $boss->tenantId(),
    ]);
    $txn = $visit->transaction()->create([
        'txn_id' => 'TXN-20260616-002', 'amount' => 100,
        'method' => 'DuitNow QR', 'status' => 'pending', 'gateway_ref' => 'STUB-T2',
    ]);

    $fields = ['STUB-T2', $txn->txn_id, '100.00', '3'];
    $payload = [
        'transaction_id' => 'STUB-T2',
        'order_number' => $txn->txn_id,
        'amount' => '100.00',
        'status' => 3,
        'checksum' => Checksum::make($fields, 'wrong-secret'),
    ];

    $this->post(route('webhooks.bayarcash'), $payload)->assertForbidden();
    $this->assertSame('pending', $txn->fresh()->status);
}
```

- [ ] **Step 2: Run new tests — expect failure**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PaymentWebhookTest
```

Expected: `test_tenant_gateway_secret_is_used_for_webhook_verification` and `test_wrong_tenant_secret_is_rejected` FAIL (controller still uses global gateway).

- [ ] **Step 3: Refactor PaymentWebhookController**

Replace the entire file:

```php
<?php
// app/Http/Controllers/PaymentWebhookController.php
namespace App\Http\Controllers;

use App\Actions\Payments\HandleGatewayCallback;
use App\Models\TenantGateway;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request, HandleGatewayCallback $handle): Response
    {
        $orderNumber = (string) $request->input('order_number', '');
        $transaction = Transaction::with('visit')->where('txn_id', $orderNumber)->first();
        $tenantId = $transaction?->visit?->tenant_id;
        $gateway = TenantGateway::resolveGateway($tenantId);

        $result = $gateway->parseCallback($request);

        if (! $result->verified) {
            return response('invalid signature', 403);
        }

        $handle($result);

        return response('OK', 200);
    }
}
```

- [ ] **Step 4: Refactor PaymentService — remove DI gateway**

Replace the entire file:

```php
<?php
// app/Services/Payments/PaymentService.php
namespace App\Services\Payments;

use App\Models\Receipt;
use App\Models\TenantGateway;
use App\Models\Transaction;
use App\Services\Documents\SnapshotBuilder;
use Illuminate\Support\Facades\DB;

final class PaymentService
{
    public function __construct(
        private readonly SnapshotBuilder $snapshots,
    ) {}

    public function confirmCash(Transaction $transaction): void
    {
        if ($transaction->status === 'paid') {
            return;
        }

        DB::transaction(function () use ($transaction) {
            $transaction->forceFill([
                'status' => 'paid',
                'method' => 'Cash',
                'paid_at' => now(),
            ])->save();

            $this->issueReceipt($transaction);
        });
    }

    public function startGateway(Transaction $transaction): string
    {
        $visit = $transaction->visit()->with('client')->first();
        $gateway = TenantGateway::resolveGateway($visit->tenant_id);

        $result = $gateway->createIntent(new Data\PaymentIntentData(
            orderNumber: $transaction->txn_id,
            amount: (float) $transaction->amount,
            payerName: $visit->client->name,
            payerEmail: $visit->client->email ?? null,
            payerPhone: $visit->client->phone,
            returnUrl: route('payments.return', $transaction),
            callbackUrl: route('webhooks.bayarcash'),
            channel: (int) config('services.bayarcash.channel'),
        ));

        $transaction->forceFill([
            'method' => 'DuitNow QR',
            'gateway_ref' => $result->gatewayRef,
        ])->save();

        return $result->paymentUrl;
    }

    public function issueReceipt(Transaction $transaction): Receipt
    {
        return Receipt::firstOrCreate(
            ['transaction_id' => $transaction->id],
            [
                'number' => $this->nextReceiptNumber(),
                'amount' => $transaction->amount,
                'snapshot' => $this->snapshots->forTransaction($transaction),
            ],
        );
    }

    private function nextReceiptNumber(): string
    {
        $prefix = 'RCP-'.now()->format('Ymd').'-';
        $last = Receipt::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 5: Remove the dead gateway binding from PaymentServiceProvider**

```php
<?php
// app/Providers/PaymentServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Gateway is now resolved per-tenant via TenantGateway::resolveGateway().
    }
}
```

- [ ] **Step 6: Run ALL webhook tests**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PaymentWebhookTest
```

Expected: all tests PASS including the two new ones.

- [ ] **Step 7: Run full suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: same count as before + 2 new, all green.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Payments/PaymentService.php app/Http/Controllers/PaymentWebhookController.php app/Providers/PaymentServiceProvider.php tests/Feature/PaymentWebhookTest.php
git commit -m "feat: per-tenant gateway resolution in PaymentService and webhook controller"
```

---

### Task 3: PaymentGatewayController + Request + Routes

**Files:**
- Create: `app/Http/Controllers/PaymentGatewayController.php`
- Create: `app/Http/Requests/UpdatePaymentGatewayRequest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write failing test for settings controller**

Create `tests/Feature/PaymentGatewaySettingsTest.php`:

```php
<?php
namespace Tests\Feature;

use App\Models\TenantGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentGatewaySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function boss(): User
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);
        return $boss->fresh();
    }

    public function test_admin_can_view_payment_settings_page(): void
    {
        $boss = $this->boss();
        $this->actingAs($boss)->get('/payment-settings')->assertOk();
    }

    public function test_non_admin_cannot_view_payment_settings(): void
    {
        $tech = User::factory()->create(['role' => 'technician']);
        $this->actingAs($tech)->get('/payment-settings')->assertForbidden();
    }

    public function test_admin_can_save_gateway_credentials(): void
    {
        $boss = $this->boss();
        $this->actingAs($boss)->put('/payment-settings', [
            'api_token' => 'tok123',
            'portal_key' => 'pkey456',
            'api_secret' => 'sec789',
        ])->assertRedirect();

        $row = TenantGateway::where('tenant_id', $boss->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('tok123', $row->api_token);
        $this->assertSame('pkey456', $row->portal_key);
        $this->assertSame('sec789', $row->api_secret);
    }

    public function test_blank_fields_on_update_keep_existing_values(): void
    {
        $boss = $this->boss();
        TenantGateway::create([
            'tenant_id' => $boss->id,
            'api_token' => 'original-tok',
            'portal_key' => 'original-pkey',
            'api_secret' => 'original-sec',
        ]);

        $this->actingAs($boss)->put('/payment-settings', [
            'api_token' => '',
            'portal_key' => '',
            'api_secret' => '',
        ])->assertRedirect();

        $row = TenantGateway::where('tenant_id', $boss->id)->first();
        $this->assertSame('original-tok', $row->api_token);
        $this->assertSame('original-pkey', $row->portal_key);
        $this->assertSame('original-sec', $row->api_secret);
    }

    public function test_boss_cannot_modify_other_boss_gateway(): void
    {
        $boss1 = $this->boss();
        $boss2 = $this->boss();

        TenantGateway::create([
            'tenant_id' => $boss2->id,
            'api_token' => 'b2-tok',
            'portal_key' => 'b2-pkey',
            'api_secret' => 'b2-sec',
        ]);

        // boss1 PUTs settings — must only affect boss1's row
        $this->actingAs($boss1)->put('/payment-settings', [
            'api_token' => 'b1-tok',
            'portal_key' => 'b1-pkey',
            'api_secret' => 'b1-sec',
        ])->assertRedirect();

        // boss2's row unchanged
        $this->assertSame('b2-tok', TenantGateway::where('tenant_id', $boss2->id)->first()->api_token);
    }

    public function test_page_shows_configured_status(): void
    {
        $boss = $this->boss();
        TenantGateway::create([
            'tenant_id' => $boss->id,
            'api_token' => 'tok',
            'portal_key' => 'abcd1234',
            'api_secret' => 'sec',
        ]);

        $response = $this->actingAs($boss)->get('/payment-settings');
        $response->assertInertia(fn ($page) => $page
            ->component('PaymentSettings/Index')
            ->where('isConfigured', true)
            ->where('portalKeyHint', '1234')
        );
    }
}
```

- [ ] **Step 2: Run test — expect 404 (routes don't exist yet)**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PaymentGatewaySettingsTest
```

Expected: all FAIL with 404 or route not found errors.

- [ ] **Step 3: Add routes to web.php**

In `routes/web.php`, add inside the `Route::middleware('auth')->group(...)` block (after the users group):

```php
use App\Http\Controllers\PaymentGatewayController;

// Payment Settings (admin only — boss configures their BayarCash credentials)
Route::get('payment-settings', [PaymentGatewayController::class, 'index'])->name('payment-settings.index');
Route::put('payment-settings', [PaymentGatewayController::class, 'update'])->name('payment-settings.update');
```

Also add `use App\Http\Controllers\PaymentGatewayController;` to the imports at the top of `routes/web.php`.

- [ ] **Step 4: Create UpdatePaymentGatewayRequest**

```php
<?php
// app/Http/Requests/UpdatePaymentGatewayRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $existing = \App\Models\TenantGateway::where('tenant_id', $this->user()->id)->exists();

        return [
            'api_token' => $existing ? ['nullable', 'string'] : ['required', 'string'],
            'portal_key' => $existing ? ['nullable', 'string'] : ['required', 'string'],
            'api_secret' => $existing ? ['nullable', 'string'] : ['required', 'string'],
        ];
    }
}
```

- [ ] **Step 5: Create PaymentGatewayController**

```php
<?php
// app/Http/Controllers/PaymentGatewayController.php
namespace App\Http\Controllers;

use App\Http\Requests\UpdatePaymentGatewayRequest;
use App\Models\TenantGateway;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PaymentGatewayController extends Controller
{
    public function index(): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        $row = TenantGateway::where('tenant_id', request()->user()->id)->first();

        return Inertia::render('PaymentSettings/Index', [
            'isConfigured' => $row !== null,
            'portalKeyHint' => $row ? substr($row->portal_key, -4) : null,
        ]);
    }

    public function update(UpdatePaymentGatewayRequest $request): RedirectResponse
    {
        $tenantId = $request->user()->id;
        $row = TenantGateway::where('tenant_id', $tenantId)->first();

        $updates = [];
        if (filled($request->input('api_token'))) $updates['api_token'] = $request->input('api_token');
        if (filled($request->input('portal_key'))) $updates['portal_key'] = $request->input('portal_key');
        if (filled($request->input('api_secret'))) $updates['api_secret'] = $request->input('api_secret');

        if ($row) {
            if ($updates) $row->update($updates);
        } else {
            TenantGateway::create(['tenant_id' => $tenantId] + $updates);
        }

        return back()->with('success', 'Payment gateway settings saved.');
    }
}
```

- [ ] **Step 6: Run settings tests**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PaymentGatewaySettingsTest
```

Expected: all 5 tests PASS.

- [ ] **Step 7: Run full suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/PaymentGatewayController.php app/Http/Requests/UpdatePaymentGatewayRequest.php routes/web.php tests/Feature/PaymentGatewaySettingsTest.php
git commit -m "feat: PaymentGatewayController + routes for per-tenant gateway settings"
```

---

### Task 4: PaymentSettings/Index.vue + AdminLayout sidebar

**Files:**
- Create: `resources/js/Pages/PaymentSettings/Index.vue`
- Modify: `resources/js/Layouts/AdminLayout.vue`

- [ ] **Step 1: Create PaymentSettings/Index.vue**

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
    isConfigured: Boolean,
    portalKeyHint: { type: String, default: null },
});

const form = useForm({
    api_token: '',
    portal_key: '',
    api_secret: '',
});

const submit = () => form.put(route('payment-settings.update'), {
    onSuccess: () => form.reset(),
});
</script>

<template>
    <AdminLayout>
        <template #header>
            <h1 class="text-base font-bold text-navy-800">Payment Settings</h1>
        </template>

        <!-- Status banner -->
        <div
            class="mb-6 flex items-center gap-3 rounded-ral border px-4 py-3 text-sm font-medium"
            :class="isConfigured
                ? 'border-ok/30 bg-ok/10 text-ok'
                : 'border-warn/30 bg-warn/10 text-warn-700'"
        >
            <span v-if="isConfigured">Gateway configured ✓ — DuitNow QR payments are live.</span>
            <span v-else>Gateway not configured — payments will use test mode.</span>
        </div>

        <Card title="BayarCash Credentials">
            <p class="mb-5 text-sm text-ink-soft">
                Leave a field blank to keep the existing value.
                Credentials are encrypted at rest and never displayed after saving.
            </p>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">API Token</label>
                    <input
                        v-model="form.api_token"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :placeholder="isConfigured ? '••••••••' : 'Enter API Token'"
                    />
                    <p v-if="form.errors.api_token" class="mt-1 text-xs text-danger">{{ form.errors.api_token }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">Portal Key</label>
                    <input
                        v-model="form.portal_key"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :placeholder="isConfigured && portalKeyHint ? `•••••••• (ending …${portalKeyHint})` : 'Enter Portal Key'"
                    />
                    <p v-if="form.errors.portal_key" class="mt-1 text-xs text-danger">{{ form.errors.portal_key }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-ink">API Secret</label>
                    <input
                        v-model="form.api_secret"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-ra border border-line bg-surface px-3 py-2 text-sm text-ink shadow-card focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :placeholder="isConfigured ? '••••••••' : 'Enter API Secret'"
                    />
                    <p v-if="form.errors.api_secret" class="mt-1 text-xs text-danger">{{ form.errors.api_secret }}</p>
                </div>

                <div class="pt-2">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60"
                    >
                        {{ form.processing ? 'Saving…' : 'Save credentials' }}
                    </button>
                </div>
            </form>
        </Card>
    </AdminLayout>
</template>
```

- [ ] **Step 2: Add Payment Settings to AdminLayout sidebar**

In `resources/js/Layouts/AdminLayout.vue`, the `sections` computed currently has a Settings group ending with Clients. Add the `IconCreditCard` import and a new nav item.

At line 8, add `IconCreditCard` to the tabler import:

```js
import {
    IconLayoutDashboard, IconUsers, IconBell, IconClipboardPlus,
    IconCalendarEvent, IconUserCog,
    IconAirConditioning, IconLogout, IconMenu2, IconCategory, IconReceipt2, IconBook,
    IconCreditCard,
} from '@tabler/icons-vue';
```

In the `sections` computed, add a Payment Settings item to the Settings array — after Services, before Users:

```js
{ title: 'Settings', items: [
    { label: 'Services', route: 'service-types.index', match: 'service-types', icon: IconCategory, permission: 'manage_service_types', adminOnly: true },
    { label: 'Payment Settings', route: 'payment-settings.index', match: 'payment-settings', icon: IconCreditCard, permission: null, adminOnly: true },
    { label: 'Users', route: 'users.index', match: 'users', icon: IconUserCog, permission: 'manage_users', adminOnly: true },
    { label: 'Clients', route: 'clients.index', match: 'clients', icon: IconUsers, permission: 'view_clients', adminOnly: true },
]},
```

- [ ] **Step 3: Build frontend**

```bash
docker compose exec -T laravel.test npm run build
```

Expected: build completes without errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/PaymentSettings/Index.vue resources/js/Layouts/AdminLayout.vue
git commit -m "feat: PaymentSettings UI + sidebar nav entry"
```

---

### Task 5: Final verification

- [ ] **Step 1: Run full test suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all existing tests green + new PaymentGatewaySettingsTest (5) + new PaymentWebhookTest (2) = suite count +7.

- [ ] **Step 2: Manual smoke test (dev environment)**

1. Log in as Khalid (`khalid@admin.com` / `khalid123`)
2. Navigate to Settings → Payment Settings
3. Observe yellow "not configured" banner
4. Enter any dummy values for the three fields, click Save
5. Observe green "configured ✓" banner on reload
6. Leave fields blank and Save again — credentials unchanged (green banner still)
7. Log in as Saifzz — should see their OWN settings page (independent of Khalid's)

- [ ] **Step 3: Update FEEDBACK doc — mark FEAT-016 TESTING**

In `docs/FEEDBACK-13062026.md`, change FEAT-016 status from `OPEN` to `TESTING`.

- [ ] **Step 4: Commit feedback doc**

```bash
git add docs/FEEDBACK-13062026.md
git commit -m "docs: mark FEAT-016 TESTING"
```
