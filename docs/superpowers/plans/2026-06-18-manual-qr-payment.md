# Manual QR Payment (FEAT-004) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Manual QR" payment method — a per-tenant static QR image (uploaded in Business Settings) that an admin shows at collection, then confirms by hand to mark the transaction paid + issue a receipt (no gateway).

**Architecture:** A new `payment_qr_path` column on `business_settings` (uploaded like the existing Google Review QR). Collection mirrors Cash: `PaymentService::confirmManualQr()` marks paid with `method = 'Manual QR'`. A new admin-only route + a Manual QR button on the payment page (shown only when the tenant has uploaded a QR).

**Tech Stack:** Laravel 12 (PHP 8.5), Inertia + Vue 3, Pest/PHPUnit feature tests, Docker Sail.

**Test runner:** `docker exec saifzz-aircond-laravel.test-1 php artisan test`
**Build:** `docker compose exec -T laravel.test npm run build`
**Git:** `cd "//wsl.localhost/Ubuntu/home/hamid/Saifzz-Aircond"`, branch `dev`, no Co-Authored-By trailers.

---

## File Structure

- **Create** `database/migrations/2026_06_18_000030_add_payment_qr_to_business_settings_table.php` — `payment_qr_path` column.
- **Modify** `app/Models/BusinessSetting.php` — fillable + `forTenant()`.
- **Modify** `app/Http/Requests/UpdateBusinessSettingRequest.php` — `payment_qr` validation.
- **Modify** `app/Http/Controllers/BusinessSettingController.php` — store upload + expose `paymentQrUrl`.
- **Modify** `app/Services/Payments/PaymentService.php` — `confirmManualQr()`.
- **Modify** `app/Http/Controllers/PaymentController.php` — `manualQr()` action + `show()` props.
- **Modify** `routes/web.php` — `payments.manualQr` route.
- **Modify** `resources/js/Pages/BusinessSettings/Index.vue` — Manual QR upload card.
- **Modify** `resources/js/Pages/Payments/Show.vue` — Manual QR method button.
- **Modify** `tests/Feature/BusinessSettingTest.php` — upload test.
- **Modify** `tests/Feature/PaymentTest.php` — collection tests.

---

## Task 1: Data layer + Business Settings upload

**Files:**
- Create: `database/migrations/2026_06_18_000030_add_payment_qr_to_business_settings_table.php`
- Modify: `app/Models/BusinessSetting.php`
- Modify: `app/Http/Requests/UpdateBusinessSettingRequest.php`
- Modify: `app/Http/Controllers/BusinessSettingController.php`
- Test: `tests/Feature/BusinessSettingTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/BusinessSettingTest.php` (inside the class). Ensure these imports exist at the top of the file — add any that are missing: `use Illuminate\Http\UploadedFile;` and `use Illuminate\Support\Facades\Storage;`.

```php
    public function test_admin_uploads_payment_qr_and_show_exposes_url(): void
    {
        Storage::fake('public');
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->put(route('business-settings.update'), [
                'payment_qr' => UploadedFile::fake()->image('myqr.png', 300, 300),
            ])
            ->assertRedirect();

        $row = \App\Models\BusinessSetting::where('tenant_id', $admin->id)->first();
        $this->assertSame("payment-qr/tenant-{$admin->id}.png", $row->payment_qr_path);
        Storage::disk('public')->assertExists($row->payment_qr_path);

        $this->actingAs($admin)
            ->get(route('business-settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('paymentQrUrl', fn ($url) => $url !== null));
    }
```

Note: if `BusinessSettingTest` does not already seed/avoid `ServiceTypeSeeder`, copy the existing `setUp`/helper conventions already in the file — do not change them. The admin user needs no tenant seeding (`tenant_id` defaults to the user's own id only in production seeding; here `tenant_id = $admin->id` is set by the controller from `request()->user()->id`).

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_admin_uploads_payment_qr_and_show_exposes_url`
Expected: FAIL — column/prop don't exist yet (`payment_qr_path` unknown, `paymentQrUrl` missing).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_18_000030_add_payment_qr_to_business_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('payment_qr_path')->nullable()->after('google_review_qr_path');
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn('payment_qr_path');
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/BusinessSetting.php`:

Add `'payment_qr_path'` to `$fillable` (after `'google_review_qr_path'`):

```php
    protected $fillable = [
        'tenant_id', 'business_name', 'address', 'phone',
        'ssm_no', 'google_review_url', 'google_review_qr_path', 'payment_qr_path',
    ];
```

In `forTenant()`, add to the returned array (after the `google_review_qr_path` line):

```php
            'payment_qr_path' => $row?->payment_qr_path,
```

And add `payment_qr_path:?string` to the `@return` docblock shape.

- [ ] **Step 5: Update the request**

In `app/Http/Requests/UpdateBusinessSettingRequest.php`, add to `rules()` (after `google_review_qr`):

```php
            'payment_qr' => ['nullable', 'image', 'max:2048'],
```

- [ ] **Step 6: Update the controller**

In `app/Http/Controllers/BusinessSettingController.php`:

In `update()`, after the existing `if ($request->hasFile('google_review_qr')) { ... }` block, add:

```php
        if ($request->hasFile('payment_qr')) {
            $path = "payment-qr/tenant-{$tenantId}.png";
            Storage::disk('public')->put($path, file_get_contents($request->file('payment_qr')->getRealPath()));
            $data['payment_qr_path'] = $path;
        }
```

In `show()`, add a prop to the `Inertia::render('BusinessSettings/Index', [...])` array (after the `qrUrl` entry):

```php
            'paymentQrUrl' => $row?->payment_qr_path
                ? Storage::disk('public')->url($row->payment_qr_path)
                : null,
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_admin_uploads_payment_qr_and_show_exposes_url`
Expected: PASS.

- [ ] **Step 8: Regression — business settings suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="BusinessSettingTest|PaymentGatewaySettingsTest"`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_06_18_000030_add_payment_qr_to_business_settings_table.php app/Models/BusinessSetting.php app/Http/Requests/UpdateBusinessSettingRequest.php app/Http/Controllers/BusinessSettingController.php tests/Feature/BusinessSettingTest.php
git commit -m "feat(business-settings): per-tenant manual-QR image upload (FEAT-004)"
```

---

## Task 2: Manual QR collection (service + route + controller)

**Files:**
- Modify: `app/Services/Payments/PaymentService.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/PaymentController.php`
- Test: `tests/Feature/PaymentTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/PaymentTest.php` (inside the class). Add an admin helper plus the tests:

```php
    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_admin_confirms_manual_qr_marks_paid_and_issues_receipt(): void
    {
        $admin = $this->admin();
        $txn = $this->pendingTransaction(110.0, $admin->id);

        $this->actingAs($admin)
            ->post(route('payments.manualQr', $txn))
            ->assertRedirect(route('payments.return', $txn));

        $txn->refresh();
        $this->assertSame('paid', $txn->status);
        $this->assertSame('Manual QR', $txn->method);
        $this->assertNotNull($txn->paid_at);

        $receipt = Receipt::where('transaction_id', $txn->id)->first();
        $this->assertNotNull($receipt);
        $this->assertMatchesRegularExpression('/^RCP-\d{8}-001$/', $receipt->number);
    }

    public function test_manual_qr_confirm_is_idempotent(): void
    {
        $admin = $this->admin();
        $txn = $this->pendingTransaction(110.0, $admin->id);

        $this->actingAs($admin)->post(route('payments.manualQr', $txn));
        $this->actingAs($admin)->post(route('payments.manualQr', $txn));

        $this->assertSame(1, Receipt::where('transaction_id', $txn->id)->count());
    }

    public function test_non_admin_collector_cannot_use_manual_qr(): void
    {
        // technician WITH collect_payment (passes route middleware) but not admin
        $collector = $this->collector();
        $txn = $this->pendingTransaction(110.0, $collector->id);

        $this->actingAs($collector)
            ->post(route('payments.manualQr', $txn))
            ->assertForbidden();
    }

    public function test_user_without_collect_payment_cannot_use_manual_qr(): void
    {
        $txn = $this->pendingTransaction();
        $tech = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['record_service'],
        ]);

        $this->actingAs($tech)
            ->post(route('payments.manualQr', $txn))
            ->assertForbidden();
    }

    public function test_admin_cannot_manual_qr_a_cross_tenant_transaction(): void
    {
        // Admin scoped to tenant A; transaction belongs to tenant B.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'tenant_id' => 9001]);
        $admin->update(['tenant_id' => $admin->id]); // self-root tenant A

        $client = Client::create(['name' => 'OtherTenant', 'phone' => '012-0000000', 'address' => 'KL', 'tenant_id' => 9002]);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-11', 'warranty_months' => 0,
            'total_amount' => 50.0, 'tenant_id' => 9002,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260611-777', 'amount' => 50.0, 'method' => 'Cash', 'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('payments.manualQr', $txn))
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="manual_qr|manualQr|manual QR"`
Expected: FAIL — `payments.manualQr` route does not exist (RouteNotFoundException) for all five.

- [ ] **Step 3: Add the service method**

In `app/Services/Payments/PaymentService.php`, add after `confirmCash()`:

```php
    public function confirmManualQr(Transaction $transaction): void
    {
        if ($transaction->status === 'paid') {
            return;
        }

        DB::transaction(function () use ($transaction) {
            $transaction->forceFill([
                'status' => 'paid',
                'method' => 'Manual QR',
                'paid_at' => now(),
            ])->save();

            $this->issueReceipt($transaction);
            $this->completeLinkedAppointment($transaction);
        });
    }
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, in the `collect_payment`-gated payments block (right after the `payments.cash` line), add:

```php
    Route::post('payments/{transaction}/manual-qr', [PaymentController::class, 'manualQr'])->middleware('can:collect_payment')->name('payments.manualQr');
```

- [ ] **Step 5: Add the controller action + show() props**

In `app/Http/Controllers/PaymentController.php`:

Add the `manualQr` action (after `cash()`):

```php
    public function manualQr(Transaction $transaction, PaymentService $payments): RedirectResponse
    {
        $this->authorizeVisitScope($transaction);
        abort_unless(request()->user()->isAdmin(), 403);

        $payments->confirmManualQr($transaction);

        return redirect()->route('payments.return', $transaction)
            ->with('success', 'Manual QR payment recorded.');
    }
```

In `show()`, after `$transaction->load('visit.client');`, resolve the QR once and add two props to the rendered `transaction`-payload array. Replace the `return Inertia::render('Payments/Show', [...]);` block so the render includes the new top-level props:

```php
        $biz = \App\Models\BusinessSetting::forTenant($transaction->visit->tenant_id);
        $manualQrUrl = $biz['payment_qr_path']
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($biz['payment_qr_path'])
            : null;

        return Inertia::render('Payments/Show', [
            'transaction' => [
                'id' => $transaction->id,
                'txn_id' => $transaction->txn_id,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'method' => $transaction->method,
                'visit_id' => $transaction->visit_id,
                'client' => [
                    'name' => $transaction->visit->client->name,
                    'serial_no' => $transaction->visit->client->serial_no,
                ],
            ],
            'manualQrUrl' => $manualQrUrl,
            'isAdmin' => request()->user()->isAdmin(),
        ]);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PaymentTest`
Expected: PASS (existing + 5 new).

- [ ] **Step 7: Regression — payment + tenant suites**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="PaymentTest|PaymentWebhookTest|MultiTenantIsolationTest|StubGatewayTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Payments/PaymentService.php routes/web.php app/Http/Controllers/PaymentController.php tests/Feature/PaymentTest.php
git commit -m "feat(payments): admin-only Manual QR collection method (FEAT-004)"
```

---

## Task 3: Frontend — upload card + payment button

**Files:**
- Modify: `resources/js/Pages/BusinessSettings/Index.vue`
- Modify: `resources/js/Pages/Payments/Show.vue`

- [ ] **Step 1: Business Settings — Manual QR upload card**

In `resources/js/Pages/BusinessSettings/Index.vue`:

(a) Add `paymentQrUrl` to `defineProps`:

```js
const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    qrUrl: { type: String, default: null },
    paymentQrUrl: { type: String, default: null },
    payment: { type: Object, default: () => ({}) },
});
```

(b) Add a form for the manual QR (after the `payForm` definition):

```js
const manualQrForm = useForm({ payment_qr: null });
const saveManualQr = () => manualQrForm.put(route('business-settings.update'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => { manualQrForm.payment_qr = null; },
});
```

(c) In the Payment tab (the `<div v-else>` block, currently containing the status banner + BayarCash card), insert a Manual QR card **above** the `BayarCash Credentials` card — directly after the status-banner `<div>` and before `<Card title="BayarCash Credentials">`:

```vue
            <Card title="Manual QR (DuitNow)" class="mb-6">
                <p class="mb-5 text-sm text-ink-soft">Upload your DuitNow / bank QR. Admins can show this at payment collection; once the customer transfers, confirm receipt manually.</p>
                <div class="grid gap-6 lg:grid-cols-2">
                    <form class="space-y-4" @submit.prevent="saveManualQr">
                        <div>
                            <label class="mb-1.5 block text-sm font-semibold text-ink">QR image (PNG/JPG, max 2MB)</label>
                            <input type="file" accept="image/*" :class="inputClass"
                                @change="manualQrForm.payment_qr = $event.target.files[0]" />
                            <p v-if="manualQrForm.errors.payment_qr" class="mt-1 text-xs text-danger">{{ manualQrForm.errors.payment_qr }}</p>
                        </div>
                        <button type="submit" :disabled="manualQrForm.processing"
                            class="inline-flex items-center rounded-ra bg-primary px-4 py-2 text-sm font-semibold text-white shadow-card hover:bg-primary-hover disabled:opacity-60">
                            {{ manualQrForm.processing ? 'Saving…' : 'Save Manual QR' }}
                        </button>
                    </form>
                    <div>
                        <div class="mb-2 text-sm font-semibold text-ink-soft">Current QR</div>
                        <div class="grid place-items-center rounded-ral border border-line bg-white p-6">
                            <img v-if="paymentQrUrl" :src="paymentQrUrl" alt="Manual payment QR" class="h-48 w-48 object-contain" />
                            <span v-else class="text-sm text-ink-soft">No QR uploaded yet.</span>
                        </div>
                    </div>
                </div>
            </Card>
```

- [ ] **Step 2: Payments/Show — Manual QR method button**

In `resources/js/Pages/Payments/Show.vue`:

(a) Update imports/props/state. Change `import { ref } from 'vue';` to `import { ref, computed } from 'vue';`. Replace the `defineProps` line and add the computed:

```js
const props = defineProps({
    transaction: Object,
    manualQrUrl: { type: String, default: null },
    isAdmin: { type: Boolean, default: false },
});

const canManualQr = computed(() => props.isAdmin && !!props.manualQrUrl);
```

(b) Add the confirm handler (after `payByCash`):

```js
const payByManualQr = async () => {
    const ok = await confirmAction({
        title: 'Confirm payment received?',
        body: 'This marks the transaction paid via Manual QR and issues a receipt.',
        confirmText: 'Confirm payment',
    });
    if (!ok) return;
    processing.value = true;
    router.post(route('payments.manualQr', props.transaction.id), {}, {
        onFinish: () => (processing.value = false),
    });
};
```

(c) Extend `handleConfirm`:

```js
const handleConfirm = () => {
    if (method.value === 'duitnow') payByGateway();
    else if (method.value === 'manualqr') payByManualQr();
    else if (method.value === 'cash') payByCash();
};
```

(d) Add the Manual QR button between the DuitNow QR button and the Cash button (insert right after the closing `</button>` of the DuitNow block, before the `<!-- Cash -->` comment):

```vue
                <!-- Manual QR (admin only, requires uploaded QR) -->
                <button
                    v-if="canManualQr"
                    type="button"
                    :disabled="processing"
                    class="w-full rounded-ral border-2 bg-surface p-4 text-left transition focus:outline-none disabled:opacity-50"
                    :class="method === 'manualqr'
                        ? 'border-primary bg-primary-50'
                        : 'border-line hover:border-primary/40 hover:bg-primary-50/30'"
                    @click="method = 'manualqr'"
                >
                    <div class="flex items-center gap-4">
                        <div
                            class="grid h-11 w-11 shrink-0 place-items-center rounded-ral text-lg font-bold"
                            :class="method === 'manualqr' ? 'bg-primary text-white' : 'bg-primary-50 text-primary'"
                        >
                            QR
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold text-ink">Manual QR</div>
                            <div class="mt-0.5 text-sm text-ink-soft">Customer scans your saved QR, then confirm</div>
                        </div>
                        <div
                            v-if="method === 'manualqr'"
                            class="h-5 w-5 shrink-0 rounded-full bg-primary text-white text-xs grid place-items-center"
                        >✓</div>
                    </div>

                    <!-- Uploaded QR shown when selected -->
                    <div
                        v-if="method === 'manualqr'"
                        class="mt-4 flex flex-col items-center gap-2 rounded-ral border border-primary/20 bg-white px-4 py-6"
                    >
                        <img :src="manualQrUrl" alt="Payment QR" class="h-44 w-44 object-contain" />
                        <p class="text-xs text-ink-soft">Customer scans, then tap confirm once paid</p>
                    </div>
                </button>
```

(e) Add the confirm-button label. In the confirm `<button>`'s label spans, add a `manualqr` case — insert before the `cash` label span:

```vue
                <span v-else-if="method === 'manualqr'">Confirm Payment — Manual QR</span>
```

- [ ] **Step 3: Build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: completes, manifest written, no errors. Fix only real errors if any.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/BusinessSettings/Index.vue resources/js/Pages/Payments/Show.vue
git commit -m "feat(payments): Manual QR upload card + collection button UI (FEAT-004)"
```

---

## Task 4: Full suite + manual smoke

- [ ] **Step 1: Full test suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: PASS (prior 316 + 1 BusinessSetting + 5 Payment = 322), no regressions.

- [ ] **Step 2: Manual smoke (npm run dev for eyeball)**

Start dev if not running: `docker compose exec -T laravel.test npm run dev`.
- As an admin: Business Settings → Payment tab → upload a QR image → preview shows it.
- Open a pending record → Collect payment. Confirm the **Manual QR** button appears (admin + QR uploaded). Select it → uploaded QR renders. Confirm → redirected to return page, method shows "Manual QR", receipt issued.
- Log in as a technician with `collect_payment` but not admin → Manual QR button absent; DuitNow QR + Cash present.
- As an admin in a tenant with NO uploaded QR → Manual QR button absent.

- [ ] **Step 3: Final commit if smoke fixups**

```bash
git add -A
git commit -m "chore(payments): FEAT-004 smoke fixups"
```

---

## Self-review notes

- **Spec coverage:** migration + model (Task 1) ✓; request + controller upload + `paymentQrUrl` (Task 1) ✓; `confirmManualQr` (Task 2) ✓; admin-only route + controller guard (Task 2) ✓; `show()` props (Task 2) ✓; Business Settings card + Payments/Show button (Task 3) ✓; tests for upload + paid/method/receipt/idempotent/non-admin-403/no-collect_payment-403/cross-tenant-403 (Tasks 1-2) ✓; `'Manual QR'` flows through string renderers unchanged (no task needed) ✓.
- **Method name consistency:** `confirmManualQr` (service), `manualQr` (controller), `payments.manualQr` (route), `payByManualQr`/`canManualQr`/`'manualqr'` (Vue) — used identically across tasks.
- **No migration to `transactions`** (method is a free string). Deploy: `php artisan migrate` (business_settings only) + `npm run build`.
