# Payments (BayarCash stub + Cash) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build Module 5 (Payments) — Cash manual confirm + a BayarCash redirect-flow gateway behind a swappable interface, shipped with a working stub driver and a scaffolded live driver, so going live = fill credentials + flip one env var.

**Architecture:** One `PaymentGateway` interface with two drivers (`FakeBayarCashGateway` active, `BayarCashGateway` scaffolded), selected by `config('services.bayarcash.driver')` via `PaymentServiceProvider`. Everything downstream of `createIntent()` (webhook verification, idempotent Transaction/Receipt update) is shared. Stub mimics the real flow: create intent → hosted blade page → checksum-signed callback → webhook → mark paid + create Receipt record.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Tailwind, PostgreSQL, Sail (Docker). Tests via PHPUnit on Postgres (`./vendor/bin/sail`).

> **Run prefix:** all artisan/test/npm commands run inside Sail. Prefix with `./vendor/bin/sail` (e.g. `./vendor/bin/sail test`). On Windows PowerShell without an alias use `docker compose exec laravel.test php artisan ...` / `... ./vendor/bin/phpunit ...`. The plan writes `sail` for brevity.

> **RBAC note (deviation from spec):** `ServiceVisitController@store`'s redirect is left unchanged (→ `service-records.show`). A `record_service`-only technician lacks `collect_payment`, so auto-redirecting to the gated payment page would 403. Instead a gated "Collect payment" CTA is added to the record page (Task 9).

> **BayarCash constants are provisional.** Exact callback field names, status codes, and checksum field ordering are confirmed against BayarCash v3 docs at go-live. They are centralized in `CallbackParser` + `Checksum` callers and marked `TODO(go-live)`. The stub and the parser use the SAME constants, so verification round-trips today.

---

## File Structure

**Create — gateway seam (`app/Services/Payments/`):**
- `PaymentStatus.php` — enum PENDING|PAID|FAILED
- `Data/PaymentIntentData.php` — request DTO to gateway
- `Data/PaymentIntentResult.php` — gatewayRef + paymentUrl
- `Data/CallbackResult.php` — normalized callback (verified, status, amount, …)
- `Contracts/PaymentGateway.php` — interface
- `Support/Checksum.php` — HMAC-SHA256 make/verify
- `Support/CallbackParser.php` — shared callback → CallbackResult
- `FakeBayarCashGateway.php` — active stub driver
- `BayarCashGateway.php` — scaffolded live driver

**Create — application logic:**
- `app/Services/Payments/PaymentService.php` — confirmCash, startGateway, issueReceipt, numbering, snapshot
- `app/Actions/Payments/HandleGatewayCallback.php` — idempotent callback processor
- `app/Providers/PaymentServiceProvider.php` — binds interface to driver

**Create — HTTP:**
- `app/Http/Controllers/PaymentController.php` — show, cash, pay, return
- `app/Http/Controllers/PaymentWebhookController.php` — handle
- `app/Http/Controllers/StubGatewayController.php` — show, simulate (stub only)
- `resources/views/dev/bayarcash.blade.php` — stub hosted page
- `resources/js/Pages/Payments/Show.vue` — method chooser
- `resources/js/Pages/Payments/Return.vue` — result page

**Modify:**
- `config/services.php` — add `bayarcash` block
- `bootstrap/providers.php` — register `PaymentServiceProvider`
- `bootstrap/app.php` — CSRF-exempt `webhooks/*`, `dev/bayarcash/*`
- `routes/web.php` — payment + webhook + stub routes
- `resources/js/Pages/ServiceRecords/Show.vue` — gated "Collect payment" CTA
- `.env`, `.env.example` — `BAYARCASH_*`

**Tests:**
- `tests/Unit/Payments/ChecksumTest.php`
- `tests/Unit/Payments/FakeBayarCashGatewayTest.php`
- `tests/Feature/PaymentTest.php`
- `tests/Feature/PaymentWebhookTest.php`
- `tests/Feature/StubGatewayTest.php`

---

## Task 1: Config + enum + DTOs + Checksum

**Files:**
- Create: `app/Services/Payments/PaymentStatus.php`
- Create: `app/Services/Payments/Data/PaymentIntentData.php`
- Create: `app/Services/Payments/Data/PaymentIntentResult.php`
- Create: `app/Services/Payments/Data/CallbackResult.php`
- Create: `app/Services/Payments/Support/Checksum.php`
- Modify: `config/services.php`
- Test: `tests/Unit/Payments/ChecksumTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Payments/ChecksumTest.php`:

```php
<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\Support\Checksum;
use PHPUnit\Framework\TestCase;

class ChecksumTest extends TestCase
{
    public function test_make_is_deterministic_and_verify_round_trips(): void
    {
        $fields = ['STUB-ABC', 'TXN-20260611-001', '110.00', '3'];
        $sig = Checksum::make($fields, 'secret');

        $this->assertSame($sig, Checksum::make($fields, 'secret')); // deterministic
        $this->assertTrue(Checksum::verify($fields, $sig, 'secret'));
    }

    public function test_verify_rejects_tampered_fields_and_wrong_secret(): void
    {
        $fields = ['STUB-ABC', 'TXN-20260611-001', '110.00', '3'];
        $sig = Checksum::make($fields, 'secret');

        $this->assertFalse(Checksum::verify(['STUB-ABC', 'TXN-20260611-001', '999.00', '3'], $sig, 'secret'));
        $this->assertFalse(Checksum::verify($fields, $sig, 'other-secret'));
        $this->assertFalse(Checksum::verify($fields, 'garbage', 'secret'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `sail test --filter=ChecksumTest`
Expected: FAIL — `Class "App\Services\Payments\Support\Checksum" not found`.

- [ ] **Step 3: Write the enum, DTOs, and Checksum**

`app/Services/Payments/PaymentStatus.php`:

```php
<?php

namespace App\Services\Payments;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
}
```

`app/Services/Payments/Data/PaymentIntentData.php`:

```php
<?php

namespace App\Services\Payments\Data;

final class PaymentIntentData
{
    public function __construct(
        public readonly string $orderNumber,   // our txn_id
        public readonly float $amount,
        public readonly string $payerName,
        public readonly ?string $payerEmail,
        public readonly ?string $payerPhone,
        public readonly string $returnUrl,
        public readonly string $callbackUrl,
        public readonly int $channel,
    ) {}
}
```

`app/Services/Payments/Data/PaymentIntentResult.php`:

```php
<?php

namespace App\Services\Payments\Data;

final class PaymentIntentResult
{
    public function __construct(
        public readonly string $gatewayRef,   // gateway intent/transaction id
        public readonly string $paymentUrl,   // redirect target
    ) {}
}
```

`app/Services/Payments/Data/CallbackResult.php`:

```php
<?php

namespace App\Services\Payments\Data;

use App\Services\Payments\PaymentStatus;

final class CallbackResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly string $orderNumber,
        public readonly ?string $gatewayRef,
        public readonly PaymentStatus $status,
        public readonly ?float $amount,
        public readonly array $raw,
    ) {}
}
```

`app/Services/Payments/Support/Checksum.php`:

```php
<?php

namespace App\Services\Payments\Support;

final class Checksum
{
    /**
     * HMAC-SHA256 over ordered field values joined by '|'.
     * TODO(go-live): confirm join char + field ordering against BayarCash v3 docs.
     */
    public static function make(array $orderedValues, string $secret): string
    {
        $payload = implode('|', array_map(static fn ($v) => (string) $v, $orderedValues));

        return hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(array $orderedValues, string $given, string $secret): bool
    {
        return hash_equals(self::make($orderedValues, $secret), (string) $given);
    }
}
```

- [ ] **Step 4: Add the config block**

In `config/services.php`, add inside the returned array (alongside the existing entries):

```php
    'bayarcash' => [
        'driver' => env('BAYARCASH_DRIVER', 'fake'),       // fake | live
        'api_token' => env('BAYARCASH_API_TOKEN'),          // Personal Access Token (live only)
        'api_secret' => env('BAYARCASH_API_SECRET', 'local-stub-secret'),
        'portal_key' => env('BAYARCASH_PORTAL_KEY'),
        'channel' => (int) env('BAYARCASH_CHANNEL', 5),     // 5 = DuitNow QR
        'base_url' => env('BAYARCASH_BASE_URL', 'https://console.bayar.cash/api/v3'),
    ],
```

- [ ] **Step 5: Run test to verify it passes**

Run: `sail test --filter=ChecksumTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Payments config/services.php tests/Unit/Payments/ChecksumTest.php
git commit -m "feat(payments): add gateway DTOs, status enum, checksum + config"
```

---

## Task 2: Callback parser + both gateway drivers + provider binding

**Files:**
- Create: `app/Services/Payments/Support/CallbackParser.php`
- Create: `app/Services/Payments/Contracts/PaymentGateway.php`
- Create: `app/Services/Payments/FakeBayarCashGateway.php`
- Create: `app/Services/Payments/BayarCashGateway.php`
- Create: `app/Providers/PaymentServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Unit/Payments/FakeBayarCashGatewayTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Payments/FakeBayarCashGatewayTest.php`:

```php
<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\Data\PaymentIntentData;
use App\Services\Payments\FakeBayarCashGateway;
use App\Services\Payments\PaymentStatus;
use App\Services\Payments\Support\Checksum;
use Illuminate\Http\Request;
use Tests\TestCase;

class FakeBayarCashGatewayTest extends TestCase
{
    public function test_create_intent_returns_ref_and_stub_url(): void
    {
        $gateway = new FakeBayarCashGateway();

        $result = $gateway->createIntent(new PaymentIntentData(
            orderNumber: 'TXN-20260611-001',
            amount: 110.0,
            payerName: 'Zainab',
            payerEmail: null,
            payerPhone: '012-3456789',
            returnUrl: 'http://localhost/return',
            callbackUrl: 'http://localhost/webhooks/bayarcash',
            channel: 5,
        ));

        $this->assertNotEmpty($result->gatewayRef);
        $this->assertStringContainsString('dev/bayarcash', $result->paymentUrl);
        $this->assertStringContainsString('TXN-20260611-001', urldecode($result->paymentUrl));
    }

    public function test_parse_callback_verifies_signature_and_maps_status(): void
    {
        config(['services.bayarcash.api_secret' => 'secret']);
        $gateway = new FakeBayarCashGateway();

        $fields = ['STUB-1', 'TXN-20260611-001', '110.00', '3'];
        $request = Request::create('/webhooks/bayarcash', 'POST', [
            'transaction_id' => 'STUB-1',
            'order_number' => 'TXN-20260611-001',
            'amount' => '110.00',
            'status' => '3',
            'checksum' => Checksum::make($fields, 'secret'),
        ]);

        $result = $gateway->parseCallback($request);

        $this->assertTrue($result->verified);
        $this->assertSame(PaymentStatus::PAID, $result->status);
        $this->assertSame('TXN-20260611-001', $result->orderNumber);
        $this->assertSame(110.0, $result->amount);
    }

    public function test_parse_callback_flags_bad_signature(): void
    {
        config(['services.bayarcash.api_secret' => 'secret']);
        $gateway = new FakeBayarCashGateway();

        $request = Request::create('/webhooks/bayarcash', 'POST', [
            'transaction_id' => 'STUB-1',
            'order_number' => 'TXN-20260611-001',
            'amount' => '110.00',
            'status' => '3',
            'checksum' => 'tampered',
        ]);

        $this->assertFalse($gateway->parseCallback($request)->verified);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `sail test --filter=FakeBayarCashGatewayTest`
Expected: FAIL — `Class "App\Services\Payments\FakeBayarCashGateway" not found`.

- [ ] **Step 3: Write the parser, interface, and drivers**

`app/Services/Payments/Support/CallbackParser.php`:

```php
<?php

namespace App\Services\Payments\Support;

use App\Services\Payments\Data\CallbackResult;
use App\Services\Payments\PaymentStatus;
use Illuminate\Http\Request;

final class CallbackParser
{
    /**
     * Shared by both drivers — verification is identical regardless of how the
     * intent was created. TODO(go-live): confirm field names + status codes vs docs.
     */
    public static function parse(Request $request, string $secret): CallbackResult
    {
        $fields = [
            (string) $request->input('transaction_id', ''),
            (string) $request->input('order_number', ''),
            (string) $request->input('amount', ''),
            (string) $request->input('status', ''),
        ];

        $verified = Checksum::verify($fields, (string) $request->input('checksum', ''), $secret);

        $status = match ((int) $request->input('status')) {
            3 => PaymentStatus::PAID,
            4 => PaymentStatus::FAILED,
            default => PaymentStatus::PENDING,
        };

        return new CallbackResult(
            verified: $verified,
            orderNumber: (string) $request->input('order_number', ''),
            gatewayRef: $request->input('transaction_id'),
            status: $status,
            amount: $request->filled('amount') ? (float) $request->input('amount') : null,
            raw: $request->all(),
        );
    }
}
```

`app/Services/Payments/Contracts/PaymentGateway.php`:

```php
<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\Data\CallbackResult;
use App\Services\Payments\Data\PaymentIntentData;
use App\Services\Payments\Data\PaymentIntentResult;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function createIntent(PaymentIntentData $data): PaymentIntentResult;

    public function parseCallback(Request $request): CallbackResult;
}
```

`app/Services/Payments/FakeBayarCashGateway.php`:

```php
<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CallbackResult;
use App\Services\Payments\Data\PaymentIntentData;
use App\Services\Payments\Data\PaymentIntentResult;
use App\Services\Payments\Support\CallbackParser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class FakeBayarCashGateway implements PaymentGateway
{
    public function createIntent(PaymentIntentData $data): PaymentIntentResult
    {
        $ref = 'STUB-'.Str::upper(Str::random(12));

        $url = route('dev.bayarcash.show', [
            'ref' => $ref,
            'order' => $data->orderNumber,
        ]);

        return new PaymentIntentResult($ref, $url);
    }

    public function parseCallback(Request $request): CallbackResult
    {
        return CallbackParser::parse($request, (string) config('services.bayarcash.api_secret'));
    }
}
```

`app/Services/Payments/BayarCashGateway.php`:

```php
<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CallbackResult;
use App\Services\Payments\Data\PaymentIntentData;
use App\Services\Payments\Data\PaymentIntentResult;
use App\Services\Payments\Support\CallbackParser;
use App\Services\Payments\Support\Checksum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Live BayarCash v3 driver. Scaffolded against the documented API shape but inert
 * until credentials exist. TODO(go-live) markers note constants to confirm vs docs.
 */
final class BayarCashGateway implements PaymentGateway
{
    public function __construct(private readonly array $config) {}

    public function createIntent(PaymentIntentData $data): PaymentIntentResult
    {
        if (empty($this->config['api_token']) || empty($this->config['portal_key'])) {
            throw new RuntimeException(
                'BayarCash credentials not configured. Set BAYARCASH_API_TOKEN and '
                .'BAYARCASH_PORTAL_KEY, or use BAYARCASH_DRIVER=fake.'
            );
        }

        $amount = number_format($data->amount, 2, '.', '');

        // TODO(go-live): confirm payload keys + required fields (BayarCash may require payer_email).
        $payload = [
            'portal_key' => $this->config['portal_key'],
            'order_number' => $data->orderNumber,
            'amount' => $amount,
            'payer_name' => $data->payerName,
            'payer_email' => $data->payerEmail,
            'payer_telephone_number' => $data->payerPhone,
            'payment_channel' => $data->channel,
            'return_url' => $data->returnUrl,
            'callback_url' => $data->callbackUrl,
        ];

        // TODO(go-live): confirm checksum field set + ordering for the create request.
        $payload['checksum'] = Checksum::make(
            [$payload['order_number'], $amount, $payload['payment_channel']],
            (string) $this->config['api_secret'],
        );

        $response = Http::withToken($this->config['api_token'])
            ->acceptJson()
            ->post(rtrim($this->config['base_url'], '/').'/payment-intents', $payload)
            ->throw()
            ->json();

        // TODO(go-live): confirm response keys (intent id + redirect url).
        return new PaymentIntentResult(
            (string) ($response['id'] ?? ''),
            (string) ($response['url'] ?? ''),
        );
    }

    public function parseCallback(Request $request): CallbackResult
    {
        return CallbackParser::parse($request, (string) $this->config['api_secret']);
    }
}
```

`app/Providers/PaymentServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Services\Payments\BayarCashGateway;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\FakeBayarCashGateway;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function () {
            $config = config('services.bayarcash');

            return ($config['driver'] ?? 'fake') === 'live'
                ? new BayarCashGateway($config)
                : new FakeBayarCashGateway();
        });
    }
}
```

- [ ] **Step 4: Register the provider**

In `bootstrap/providers.php`, add `App\Providers\PaymentServiceProvider::class` to the returned array (after `AppServiceProvider::class`).

- [ ] **Step 5: Run test to verify it passes**

Run: `sail test --filter=FakeBayarCashGatewayTest`
Expected: PASS (3 tests). Note: `createIntent` resolves `route('dev.bayarcash.show', …)` — this route is added in Task 7; until then this test errors on the missing route. To unblock, add the route name now OR run this test after Task 7. **Reorder note:** if running tasks strictly in order, temporarily skip the `createIntent` assertion and re-enable after Task 7. Cleaner: do Task 7 route registration before this step. (The plan registers all routes in Task 6/7.)

> **Decision:** Move the `dev/bayarcash` GET route registration (name only, see Task 7) ahead so this passes in-order. Add this one line to `routes/web.php` now, inside the file (outside any auth group):
> ```php
> Route::get('dev/bayarcash/{ref}', [\App\Http\Controllers\StubGatewayController::class, 'show'])->name('dev.bayarcash.show');
> ```
> The controller is created in Task 6; the route only needs to exist by name for `route()` to resolve. If the test runner complains the controller is missing at route-cache time (it does not for non-cached routes), proceed — route resolution for URL generation does not instantiate the controller.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Payments app/Providers/PaymentServiceProvider.php bootstrap/providers.php routes/web.php tests/Unit/Payments/FakeBayarCashGatewayTest.php
git commit -m "feat(payments): add gateway interface, fake + live drivers, provider binding"
```

---

## Task 3: PaymentService — cash confirm + receipt issuance

**Files:**
- Create: `app/Services/Payments/PaymentService.php`
- Test: `tests/Feature/PaymentTest.php` (cash + receipt portion)

- [ ] **Step 1: Write the failing test**

`tests/Feature/PaymentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Receipt;
use App\Models\ServiceVisit;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function collector(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients', 'record_service', 'collect_payment'],
        ]);
    }

    private function pendingTransaction(float $amount = 110.0): Transaction
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'total_amount' => $amount,
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 2, 'rate' => 55, 'discount' => 0,
        ]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260611-001',
            'amount' => $amount,
            'method' => 'Cash',
            'status' => 'pending',
        ]);
    }

    public function test_cash_confirm_marks_paid_and_creates_receipt(): void
    {
        $txn = $this->pendingTransaction();

        $this->actingAs($this->collector())
            ->post(route('payments.cash', $txn))
            ->assertRedirect(route('payments.return', $txn));

        $txn->refresh();
        $this->assertSame('paid', $txn->status);
        $this->assertSame('Cash', $txn->method);
        $this->assertNotNull($txn->paid_at);

        $receipt = Receipt::where('transaction_id', $txn->id)->first();
        $this->assertNotNull($receipt);
        $this->assertMatchesRegularExpression('/^RCP-\d{8}-001$/', $receipt->number);
        $this->assertSame('110.00', $receipt->amount);
        $this->assertSame('Zainab', $receipt->snapshot['client']['name']);
    }

    public function test_cash_confirm_is_idempotent(): void
    {
        $txn = $this->pendingTransaction();
        $collector = $this->collector();

        $this->actingAs($collector)->post(route('payments.cash', $txn));
        $this->actingAs($collector)->post(route('payments.cash', $txn));

        $this->assertSame(1, Receipt::where('transaction_id', $txn->id)->count());
    }

    public function test_collect_payment_permission_required_for_cash(): void
    {
        $txn = $this->pendingTransaction();
        $tech = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['record_service'],
        ]);

        $this->actingAs($tech)->post(route('payments.cash', $txn))->assertForbidden();
    }
}
```

> Routes (`payments.cash`, `payments.return`) land in Task 5; these tests stay red until then. That is expected TDD — they pin the contract `PaymentService` must satisfy. Implement `PaymentService` now (Step 3); the cash tests go green after Task 5 wires the controller. To verify `PaymentService` in isolation before Task 5, run the unit-level check in Step 4.

- [ ] **Step 2: Run test to verify it fails**

Run: `sail test --filter=PaymentTest`
Expected: FAIL — route `payments.cash` not defined.

- [ ] **Step 3: Write `PaymentService`**

`app/Services/Payments/PaymentService.php`:

```php
<?php

namespace App\Services\Payments;

use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\PaymentIntentData;
use Illuminate\Support\Facades\DB;

final class PaymentService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** Cash path: staff confirms manually (gated collect_payment). */
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

    /** Create a gateway intent; persist gateway_ref; return the redirect URL. */
    public function startGateway(Transaction $transaction): string
    {
        $visit = $transaction->visit()->with('client')->first();

        $result = $this->gateway->createIntent(new PaymentIntentData(
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

    /** One Receipt per transaction (idempotent). PDF rendering = Module 6. */
    public function issueReceipt(Transaction $transaction): Receipt
    {
        return Receipt::firstOrCreate(
            ['transaction_id' => $transaction->id],
            [
                'number' => $this->nextReceiptNumber(),
                'amount' => $transaction->amount,
                'snapshot' => $this->snapshot($transaction),
            ],
        );
    }

    /** RCP-YYYYMMDD-NNN — daily sequence, mirrors TXN numbering. */
    private function nextReceiptNumber(): string
    {
        $prefix = 'RCP-'.now()->format('Ymd').'-';
        $last = Receipt::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /** Freeze client + line + payment details for stable reprints. */
    private function snapshot(Transaction $transaction): array
    {
        $visit = $transaction->visit()->with(['client', 'lines'])->first();

        return [
            'txn_id' => $transaction->txn_id,
            'method' => $transaction->method,
            'paid_at' => optional($transaction->paid_at)->toIso8601String(),
            'client' => [
                'name' => $visit->client->name,
                'serial_no' => $visit->client->serial_no,
                'phone' => $visit->client->phone,
                'address' => $visit->client->address,
            ],
            'visit_date' => optional($visit->visit_date)->toDateString(),
            'warranty_end' => optional($visit->warranty_end)->toDateString(),
            'lines' => $visit->lines->map(fn ($l) => [
                'service_type' => $l->service_type,
                'unit_type' => $l->unit_type,
                'gas_option' => $l->gas_option,
                'units' => $l->units,
                'rate' => $l->rate,
                'discount' => $l->discount,
                'subtotal' => $l->subtotal,
                'repair_desc' => $l->repair_desc,
            ])->all(),
            'total_amount' => $visit->total_amount,
        ];
    }
}
```

- [ ] **Step 4: Verify `PaymentService` in isolation (interim check)**

Run: `sail artisan tinker --execute="
  \$c = App\Models\Client::create(['name'=>'T','phone'=>'012-0000000','address'=>'X']);
  \$v = \$c->visits()->create(['visit_date'=>'2026-06-11','warranty_months'=>0,'total_amount'=>50]);
  \$t = \$v->transaction()->create(['txn_id'=>'TXN-20260611-099','amount'=>50,'method'=>'Cash','status'=>'pending']);
  app(App\Services\Payments\PaymentService::class)->confirmCash(\$t);
  echo \$t->fresh()->status, ' ', App\Models\Receipt::where('transaction_id',\$t->id)->value('number');
"`
Expected: prints `paid RCP-YYYYMMDD-001`. (Run against a scratch DB or roll back after.)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Payments/PaymentService.php tests/Feature/PaymentTest.php
git commit -m "feat(payments): PaymentService cash confirm + receipt issuance"
```

---

## Task 4: HandleGatewayCallback action

**Files:**
- Create: `app/Actions/Payments/HandleGatewayCallback.php`
- Test: `tests/Feature/PaymentWebhookTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/PaymentWebhookTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Payments\Support\Checksum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bayarcash.api_secret' => 'secret']);
    }

    private function pendingTxn(float $amount = 110.0): Transaction
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'KL']);
        $visit = $client->visits()->create(['visit_date' => '2026-06-11', 'warranty_months' => 0, 'total_amount' => $amount]);
        $visit->lines()->create(['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 2, 'rate' => 55, 'discount' => 0]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260611-001', 'amount' => $amount,
            'method' => 'DuitNow QR', 'status' => 'pending', 'gateway_ref' => 'STUB-1',
        ]);
    }

    private function payload(string $order, string $amount, int $status, string $ref = 'STUB-1', ?string $secret = 'secret'): array
    {
        $fields = [$ref, $order, $amount, (string) $status];

        return [
            'transaction_id' => $ref,
            'order_number' => $order,
            'amount' => $amount,
            'status' => $status,
            'checksum' => Checksum::make($fields, $secret ?? 'secret'),
        ];
    }

    public function test_valid_success_callback_marks_paid_and_creates_receipt(): void
    {
        $txn = $this->pendingTxn();

        $this->post(route('webhooks.bayarcash'), $this->payload($txn->txn_id, '110.00', 3))
            ->assertOk();

        $txn->refresh();
        $this->assertSame('paid', $txn->status);
        $this->assertNotNull($txn->paid_at);
        $this->assertSame(1, Receipt::where('transaction_id', $txn->id)->count());
    }

    public function test_invalid_checksum_is_rejected_with_no_state_change(): void
    {
        $txn = $this->pendingTxn();
        $bad = $this->payload($txn->txn_id, '110.00', 3);
        $bad['checksum'] = 'tampered';

        $this->post(route('webhooks.bayarcash'), $bad)->assertForbidden();

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame(0, Receipt::count());
    }

    public function test_duplicate_delivery_is_idempotent(): void
    {
        $txn = $this->pendingTxn();
        $payload = $this->payload($txn->txn_id, '110.00', 3);

        $this->post(route('webhooks.bayarcash'), $payload)->assertOk();
        $this->post(route('webhooks.bayarcash'), $payload)->assertOk();

        $this->assertSame(1, Receipt::where('transaction_id', $txn->id)->count());
    }

    public function test_failed_callback_marks_failed_without_receipt(): void
    {
        $txn = $this->pendingTxn();

        $this->post(route('webhooks.bayarcash'), $this->payload($txn->txn_id, '110.00', 4))
            ->assertOk();

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame(0, Receipt::count());
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        $txn = $this->pendingTxn(110.0);

        $this->post(route('webhooks.bayarcash'), $this->payload($txn->txn_id, '5.00', 3))
            ->assertOk(); // accepted (200) but ignored

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame(0, Receipt::count());
    }

    public function test_unknown_order_is_ignored(): void
    {
        $this->post(route('webhooks.bayarcash'), $this->payload('TXN-NOPE', '110.00', 3))
            ->assertOk();

        $this->assertSame(0, Receipt::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `sail test --filter=PaymentWebhookTest`
Expected: FAIL — route `webhooks.bayarcash` not defined (added Task 5). Action itself is exercised once the controller exists.

- [ ] **Step 3: Write the action**

`app/Actions/Payments/HandleGatewayCallback.php`:

```php
<?php

namespace App\Actions\Payments;

use App\Models\Transaction;
use App\Services\Payments\Data\CallbackResult;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentStatus;
use Illuminate\Support\Facades\DB;

final class HandleGatewayCallback
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Apply a verified callback idempotently. Returns true when the callback was
     * accepted (even if a no-op); false when it referenced an unknown txn or a
     * mismatched amount. Caller still returns 200 to the gateway in both cases.
     */
    public function __invoke(CallbackResult $result): bool
    {
        return DB::transaction(function () use ($result) {
            $transaction = Transaction::where('txn_id', $result->orderNumber)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                return false;
            }

            if ($transaction->status === 'paid') {
                return true; // idempotent — already applied
            }

            if ($result->amount !== null
                && round((float) $transaction->amount, 2) !== round($result->amount, 2)) {
                return false; // amount mismatch — ignore
            }

            if ($result->status === PaymentStatus::PAID) {
                $transaction->forceFill([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'gateway_ref' => $result->gatewayRef ?? $transaction->gateway_ref,
                ])->save();

                $this->payments->issueReceipt($transaction);
            } elseif ($result->status === PaymentStatus::FAILED) {
                $transaction->forceFill([
                    'status' => 'failed',
                    'gateway_ref' => $result->gatewayRef ?? $transaction->gateway_ref,
                ])->save();
            }

            return true;
        });
    }
}
```

- [ ] **Step 4: Run test to verify it fails on route only**

Run: `sail test --filter=PaymentWebhookTest`
Expected: still FAIL on missing route (`webhooks.bayarcash`). Routes + controller come next.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Payments/HandleGatewayCallback.php tests/Feature/PaymentWebhookTest.php
git commit -m "feat(payments): idempotent gateway callback handler"
```

---

## Task 5: Controllers + routes + CSRF exemption

**Files:**
- Create: `app/Http/Controllers/PaymentController.php`
- Create: `app/Http/Controllers/PaymentWebhookController.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php`

- [ ] **Step 1: Write `PaymentController`**

`app/Http/Controllers/PaymentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\Payments\PaymentService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PaymentController extends Controller
{
    public function show(Transaction $transaction): Response|RedirectResponse
    {
        if ($transaction->status === 'paid') {
            return redirect()->route('payments.return', $transaction);
        }

        $transaction->load('visit.client');

        return Inertia::render('Payments/Show', [
            'transaction' => [
                'id' => $transaction->id,
                'txn_id' => $transaction->txn_id,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'method' => $transaction->method,
                'client' => [
                    'name' => $transaction->visit->client->name,
                    'serial_no' => $transaction->visit->client->serial_no,
                ],
            ],
        ]);
    }

    public function cash(Transaction $transaction, PaymentService $payments): RedirectResponse
    {
        $payments->confirmCash($transaction);

        return redirect()->route('payments.return', $transaction)
            ->with('success', 'Cash payment recorded.');
    }

    public function pay(Transaction $transaction, PaymentService $payments): HttpResponse
    {
        if ($transaction->status === 'paid') {
            return redirect()->route('payments.return', $transaction);
        }

        return Inertia::location($payments->startGateway($transaction));
    }

    public function return(Transaction $transaction): Response
    {
        $transaction->load('visit.client', 'receipt');

        return Inertia::render('Payments/Return', [
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
                'receipt' => $transaction->receipt
                    ? ['number' => $transaction->receipt->number]
                    : null,
            ],
        ]);
    }
}
```

- [ ] **Step 2: Write `PaymentWebhookController`**

`app/Http/Controllers/PaymentWebhookController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\Payments\HandleGatewayCallback;
use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request, PaymentGateway $gateway, HandleGatewayCallback $handle): Response
    {
        $result = $gateway->parseCallback($request);

        if (! $result->verified) {
            return response('invalid signature', 403);
        }

        $handle($result); // idempotent; ignores unknown/mismatched txns

        return response('OK', 200);
    }
}
```

- [ ] **Step 3: Add routes**

In `routes/web.php`: add imports at top —

```php
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentWebhookController;
```

Inside the existing `Route::middleware('auth')->group(...)` block, add:

```php
    // Payments (module 5) — collection gated by collect_payment (P3)
    Route::get('payments/{transaction}', [PaymentController::class, 'show'])->middleware('can:collect_payment')->name('payments.show');
    Route::post('payments/{transaction}/cash', [PaymentController::class, 'cash'])->middleware('can:collect_payment')->name('payments.cash');
    Route::post('payments/{transaction}/pay', [PaymentController::class, 'pay'])->middleware('can:collect_payment')->name('payments.pay');
    Route::get('payments/{transaction}/return', [PaymentController::class, 'return'])->name('payments.return');
```

Outside any auth group (public webhook), add near the bottom before `require __DIR__.'/auth.php';`:

```php
// Payment gateway callback — public, CSRF-exempt, signature-verified.
Route::post('webhooks/bayarcash', [PaymentWebhookController::class, 'handle'])->name('webhooks.bayarcash');
```

> The `dev/bayarcash/{ref}` GET route was added in Task 2 Step 5. Leave it; Task 6 adds the POST sibling.

- [ ] **Step 4: CSRF-exempt webhook + stub routes**

In `bootstrap/app.php`, inside the `withMiddleware` closure (after the `$middleware->web(...)` call):

```php
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'dev/bayarcash/*',
        ]);
```

- [ ] **Step 5: Run the cash + webhook feature tests**

Run: `sail test --filter=PaymentTest`
Expected: PASS (cash marks paid + receipt, idempotent, permission required).

Run: `sail test --filter=PaymentWebhookTest`
Expected: PASS (valid success, invalid checksum 403, duplicate idempotent, failed, amount mismatch, unknown order).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PaymentController.php app/Http/Controllers/PaymentWebhookController.php routes/web.php bootstrap/app.php
git commit -m "feat(payments): payment + webhook controllers, routes, csrf exemption"
```

---

## Task 6: Stub hosted page + simulate

**Files:**
- Create: `app/Http/Controllers/StubGatewayController.php`
- Create: `resources/views/dev/bayarcash.blade.php`
- Modify: `routes/web.php` (add POST simulate; guard stub routes to fake driver)
- Test: `tests/Feature/StubGatewayTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/StubGatewayTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StubGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bayarcash.api_secret' => 'secret', 'services.bayarcash.driver' => 'fake']);
    }

    private function pendingTxn(): Transaction
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'KL']);
        $visit = $client->visits()->create(['visit_date' => '2026-06-11', 'warranty_months' => 0, 'total_amount' => 110]);
        $visit->lines()->create(['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 2, 'rate' => 55, 'discount' => 0]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260611-001', 'amount' => 110,
            'method' => 'DuitNow QR', 'status' => 'pending', 'gateway_ref' => 'STUB-1',
        ]);
    }

    public function test_stub_hosted_page_renders(): void
    {
        $txn = $this->pendingTxn();

        $this->get(route('dev.bayarcash.show', ['ref' => 'STUB-1', 'order' => $txn->txn_id]))
            ->assertOk()
            ->assertSee('Simulate')
            ->assertSee($txn->txn_id);
    }

    public function test_simulate_paid_drives_full_callback_path(): void
    {
        $txn = $this->pendingTxn();

        $this->post(route('dev.bayarcash.simulate', ['ref' => 'STUB-1']), [
            'order' => $txn->txn_id,
            'outcome' => 'paid',
        ])->assertRedirect(route('payments.return', $txn));

        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertSame(1, Receipt::where('transaction_id', $txn->id)->count());
    }

    public function test_simulate_failed_marks_failed(): void
    {
        $txn = $this->pendingTxn();

        $this->post(route('dev.bayarcash.simulate', ['ref' => 'STUB-1']), [
            'order' => $txn->txn_id,
            'outcome' => 'failed',
        ])->assertRedirect(route('payments.return', $txn));

        $this->assertSame('failed', $txn->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `sail test --filter=StubGatewayTest`
Expected: FAIL — `dev.bayarcash.simulate` route + controller method missing; blade view missing.

- [ ] **Step 3: Write `StubGatewayController`**

`app/Http/Controllers/StubGatewayController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\Payments\HandleGatewayCallback;
use App\Models\Transaction;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Support\Checksum;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Stand-in for the BayarCash hosted payment page. Active only when
 * BAYARCASH_DRIVER=fake (routes are guarded in web.php). Lets a developer
 * simulate the gateway firing a signed callback through the REAL webhook path.
 */
class StubGatewayController extends Controller
{
    public function show(string $ref, Request $request): View
    {
        $transaction = Transaction::where('txn_id', $request->query('order'))->first();

        return view('dev.bayarcash', [
            'ref' => $ref,
            'order' => $request->query('order'),
            'amount' => $transaction?->amount,
        ]);
    }

    public function simulate(
        string $ref,
        Request $request,
        PaymentGateway $gateway,
        HandleGatewayCallback $handle,
    ): RedirectResponse {
        $transaction = Transaction::where('txn_id', $request->input('order'))->firstOrFail();

        $statusCode = $request->input('outcome') === 'paid' ? 3 : 4;
        $amount = number_format((float) $transaction->amount, 2, '.', '');
        $secret = (string) config('services.bayarcash.api_secret');

        // Field order MUST match CallbackParser::parse().
        $checksum = Checksum::make([$ref, $transaction->txn_id, $amount, (string) $statusCode], $secret);

        // Build a BayarCash-shaped request and run it through the shared parser + handler.
        $callback = Request::create(route('webhooks.bayarcash'), 'POST', [
            'transaction_id' => $ref,
            'order_number' => $transaction->txn_id,
            'amount' => $amount,
            'status' => $statusCode,
            'checksum' => $checksum,
        ]);

        $handle($gateway->parseCallback($callback));

        return redirect()->route('payments.return', $transaction);
    }
}
```

- [ ] **Step 4: Write the blade hosted page**

`resources/views/dev/bayarcash.blade.php`:

```blade
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BayarCash (Stub) — Payment</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; display: grid; place-items: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 32px; width: min(420px, 92vw); }
        .badge { display: inline-block; font-size: 12px; letter-spacing: .1em; text-transform: uppercase; color: #fbbf24; border: 1px solid #fbbf2455; border-radius: 999px; padding: 2px 10px; }
        h1 { font-size: 20px; margin: 16px 0 4px; }
        .ref { font-family: ui-monospace, monospace; color: #93c5fd; font-size: 14px; }
        .amount { font-size: 34px; font-weight: 800; margin: 18px 0; }
        form { display: inline; }
        button { font-size: 15px; font-weight: 600; border: 0; border-radius: 10px; padding: 12px 18px; cursor: pointer; width: 100%; margin-top: 10px; }
        .paid { background: #22c55e; color: #06210f; }
        .failed { background: #ef4444; color: #2a0707; }
        .note { color: #94a3b8; font-size: 12px; margin-top: 18px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">BayarCash · Stub</span>
        <h1>Confirm payment</h1>
        <div class="ref">Order: {{ $order }} · Ref: {{ $ref }}</div>
        <div class="amount">RM {{ number_format((float) $amount, 2) }}</div>

        <form method="POST" action="{{ route('dev.bayarcash.simulate', ['ref' => $ref]) }}">
            <input type="hidden" name="order" value="{{ $order }}">
            <input type="hidden" name="outcome" value="paid">
            <button class="paid" type="submit">Simulate Paid</button>
        </form>

        <form method="POST" action="{{ route('dev.bayarcash.simulate', ['ref' => $ref]) }}">
            <input type="hidden" name="order" value="{{ $order }}">
            <input type="hidden" name="outcome" value="failed">
            <button class="failed" type="submit">Simulate Failed</button>
        </form>

        <p class="note">Local stand-in for the real BayarCash hosted page. Buttons fire a
        checksum-signed callback through the production webhook handler. Replace with the live
        gateway by setting <code>BAYARCASH_DRIVER=live</code>.</p>
    </div>
</body>
</html>
```

- [ ] **Step 5: Register stub routes (guarded to fake driver)**

Replace the single `dev/bayarcash` GET line added in Task 2 with this guarded block in `routes/web.php` (outside any auth group):

```php
// Stub gateway hosted page — only when the fake driver is active.
if (config('services.bayarcash.driver') === 'fake') {
    Route::get('dev/bayarcash/{ref}', [\App\Http\Controllers\StubGatewayController::class, 'show'])->name('dev.bayarcash.show');
    Route::post('dev/bayarcash/{ref}/simulate', [\App\Http\Controllers\StubGatewayController::class, 'simulate'])->name('dev.bayarcash.simulate');
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `sail test --filter=StubGatewayTest`
Expected: PASS (3 tests). Also re-run `sail test --filter=FakeBayarCashGatewayTest` — still PASS (route now fully defined).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/StubGatewayController.php resources/views/dev/bayarcash.blade.php routes/web.php
git commit -m "feat(payments): stub bayarcash hosted page + signed-callback simulation"
```

---

## Task 7: Payment Vue pages

**Files:**
- Create: `resources/js/Pages/Payments/Show.vue`
- Create: `resources/js/Pages/Payments/Return.vue`
- Test: add render assertions to `tests/Feature/PaymentTest.php`

- [ ] **Step 1: Add render assertions to the feature test**

Append to `tests/Feature/PaymentTest.php` (inside the class):

```php
    public function test_payment_page_renders_for_collector(): void
    {
        $txn = $this->pendingTransaction();

        $this->actingAs($this->collector())
            ->get(route('payments.show', $txn))
            ->assertOk();
    }

    public function test_paid_transaction_redirects_show_to_return(): void
    {
        $txn = $this->pendingTransaction();
        $this->actingAs($this->collector())->post(route('payments.cash', $txn));

        $this->actingAs($this->collector())
            ->get(route('payments.show', $txn))
            ->assertRedirect(route('payments.return', $txn));
    }

    public function test_return_page_renders(): void
    {
        $txn = $this->pendingTransaction();

        $this->actingAs($this->collector())
            ->get(route('payments.return', $txn))
            ->assertOk();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `sail test --filter=PaymentTest`
Expected: the new render tests FAIL — Inertia page components `Payments/Show` / `Payments/Return` do not exist (Inertia testing resolves the page name; missing `.vue` makes the SSR/asset resolution fail or the page assertion error). Pre-existing cash tests still PASS.

- [ ] **Step 3: Write `Payments/Show.vue`**

`resources/js/Pages/Payments/Show.vue`:

```vue
<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ transaction: Object });

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const processing = ref(false);

const payByGateway = () => {
    processing.value = true;
    router.post(route('payments.pay', props.transaction.id), {}, {
        onFinish: () => (processing.value = false),
    });
};

const payByCash = () => {
    if (!confirm('Confirm cash received for ' + money(props.transaction.amount) + '?')) return;
    processing.value = true;
    router.post(route('payments.cash', props.transaction.id), {}, {
        onFinish: () => (processing.value = false),
    });
};
</script>

<template>
    <Head title="Collect payment" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <span class="text-ink-soft">Payments</span>
                <span class="text-ink-muted">/</span>
                <span class="font-mono font-semibold text-navy-800">{{ transaction.txn_id }}</span>
            </div>
        </template>

        <div class="mx-auto max-w-md space-y-6">
            <div class="overflow-hidden rounded-ral border border-line bg-surface shadow-card">
                <div class="bg-navy-900 p-6 text-center text-white">
                    <div class="font-mono text-xs tracking-widest text-primary-300">{{ transaction.txn_id }}</div>
                    <div class="mt-2 text-sm text-primary-200">{{ transaction.client.name }} · #{{ transaction.client.serial_no }}</div>
                    <div class="mt-3 text-4xl font-extrabold">{{ money(transaction.amount) }}</div>
                </div>

                <div class="space-y-3 p-6">
                    <button
                        type="button"
                        :disabled="processing"
                        class="w-full rounded-ral bg-primary px-4 py-3 font-semibold text-white transition hover:bg-primary-600 disabled:opacity-50"
                        @click="payByGateway"
                    >
                        Pay with DuitNow QR
                    </button>
                    <button
                        type="button"
                        :disabled="processing"
                        class="w-full rounded-ral border border-line bg-surface px-4 py-3 font-semibold text-ink transition hover:bg-surface-muted disabled:opacity-50"
                        @click="payByCash"
                    >
                        Record cash payment
                    </button>
                </div>
            </div>

            <div class="text-center text-sm">
                <Link :href="route('service-records.show', transaction.visit_id ?? transaction.id)" class="text-ink-soft hover:text-ink">
                    Back to service record
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
```

> `transaction.visit_id` is not in the `show` payload; the back link there falls back harmlessly. The `Return` payload includes `visit_id`. To make the Show back-link exact, add `'visit_id' => $transaction->visit_id,` to the `show()` payload in `PaymentController` (optional polish).

- [ ] **Step 4: Write `Payments/Return.vue`**

`resources/js/Pages/Payments/Return.vue`:

```vue
<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({ transaction: Object });

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);

const view = computed(() => ({
    paid: { icon: '✓', cls: 'bg-ok-bg text-ok', title: 'Payment received', ring: 'border-ok/40' },
    failed: { icon: '✗', cls: 'bg-danger-bg text-danger', title: 'Payment failed', ring: 'border-danger/40' },
    pending: { icon: '…', cls: 'bg-warn-bg text-warn', title: 'Awaiting payment', ring: 'border-warn/40' },
}[props.transaction.status] ?? { icon: '…', cls: 'bg-warn-bg text-warn', title: 'Awaiting payment', ring: 'border-warn/40' }));
</script>

<template>
    <Head title="Payment result" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm">
                <span class="text-ink-soft">Payments</span>
                <span class="text-ink-muted">/</span>
                <span class="font-mono font-semibold text-navy-800">{{ transaction.txn_id }}</span>
            </div>
        </template>

        <div class="mx-auto max-w-md space-y-6">
            <div class="rounded-ral border bg-surface p-8 text-center shadow-card" :class="view.ring">
                <div class="mx-auto grid h-16 w-16 place-items-center rounded-full text-3xl font-bold" :class="view.cls">
                    {{ view.icon }}
                </div>
                <h2 class="mt-4 text-xl font-bold text-ink">{{ view.title }}</h2>
                <div class="mt-1 font-mono text-sm text-ink-soft">{{ transaction.txn_id }}</div>
                <div class="mt-4 text-3xl font-extrabold text-navy-800">{{ money(transaction.amount) }}</div>
                <div class="mt-1 text-sm text-ink-soft">{{ transaction.client.name }} · #{{ transaction.client.serial_no }}</div>

                <div v-if="transaction.receipt" class="mt-4 rounded-ral bg-surface-muted px-4 py-2 text-sm text-ink-soft">
                    Receipt <span class="font-mono font-semibold text-ink">{{ transaction.receipt.number }}</span>
                    <span class="text-ink-muted">· PDF coming in Documents module</span>
                </div>

                <div v-if="transaction.status === 'failed'" class="mt-5">
                    <Link
                        :href="route('payments.show', transaction.id)"
                        class="inline-block rounded-ral bg-primary px-4 py-2 font-semibold text-white hover:bg-primary-600"
                    >
                        Retry payment
                    </Link>
                </div>
            </div>

            <div class="text-center text-sm">
                <Link :href="route('service-records.show', transaction.visit_id)" class="text-ink-soft hover:text-ink">
                    Back to service record
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
```

- [ ] **Step 5: Build assets + run tests**

Run: `sail npm run build`
Expected: builds clean (new pages compiled).

Run: `sail test --filter=PaymentTest`
Expected: PASS including the 3 new render tests.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Payments
git commit -m "feat(payments): payment method chooser + result Vue pages"
```

---

## Task 8: Wire "Collect payment" CTA into the service record page

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Show.vue`

- [ ] **Step 1: Replace the pending-payment notice block with a gated CTA**

In `resources/js/Pages/ServiceRecords/Show.vue`, add `Link` + `usePage` to the imports:

```js
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
```

(`Link` is already imported; ensure `usePage` is imported. Add a computed for the permission.)

After the existing `lineLabel` definition in `<script setup>`, add:

```js
const canCollect = computed(() => usePage().props.auth?.can?.collect_payment ?? false);
```

Replace the existing pending notice block:

```vue
            <!-- Payment status (collection handled by the Payments module) -->
            <div v-if="txn && txn.status === 'pending'" class="rounded-ral border border-warn/30 bg-warn-bg px-5 py-4 text-sm text-warn">
                Payment pending via {{ txn.method }}. Collection &amp; receipt come from the Payments module.
            </div>
```

with:

```vue
            <!-- Payment collection (module 5) -->
            <div v-if="txn && txn.status === 'pending'" class="flex flex-col gap-3 rounded-ral border border-warn/30 bg-warn-bg px-5 py-4 text-sm text-warn sm:flex-row sm:items-center sm:justify-between">
                <span>Payment pending via {{ txn.method }}.</span>
                <Link
                    v-if="canCollect"
                    :href="route('payments.show', txn.id)"
                    class="inline-block rounded-ral bg-primary px-4 py-2 font-semibold text-white transition hover:bg-primary-600"
                >
                    Collect payment
                </Link>
            </div>
            <div v-else-if="txn && txn.status === 'paid'" class="rounded-ral border border-ok/30 bg-ok-bg px-5 py-4 text-sm text-ok">
                Paid via {{ txn.method }}.
                <Link :href="route('payments.return', txn.id)" class="font-semibold underline">View receipt</Link>
            </div>
```

- [ ] **Step 2: Build assets**

Run: `sail npm run build`
Expected: builds clean.

- [ ] **Step 3: Manually verify the flow (dev server)**

Run: `sail up -d` (if not running). Then in a browser, logged in as the seeded admin:
1. Create a service record → land on its Show page.
2. Click **Collect payment** → Payment page shows amount + two buttons.
3. Click **Pay with DuitNow QR** → redirected to the stub BayarCash page.
4. Click **Simulate Paid** → land on Return page showing "Payment received" + receipt number.
5. Re-open the record → shows "Paid via DuitNow QR".

Expected: all five steps work; transaction is `paid`, one Receipt row exists.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/ServiceRecords/Show.vue
git commit -m "feat(payments): gated collect-payment CTA on service record page"
```

---

## Task 9: Env, full suite, docs + memory

**Files:**
- Modify: `.env`, `.env.example`
- Modify: `docs/STATUS.md`, `docs/SESSION-LOG.md`
- Modify: memory `saifzz-backlog.md` (+ `MEMORY.md` unchanged)

- [ ] **Step 1: Add env vars**

Append to `.env.example` (and mirror real values into `.env`):

```
BAYARCASH_DRIVER=fake
BAYARCASH_API_SECRET=local-stub-secret
# Fill these when going live, then set BAYARCASH_DRIVER=live:
# BAYARCASH_API_TOKEN=
# BAYARCASH_PORTAL_KEY=
# BAYARCASH_CHANNEL=5
# BAYARCASH_BASE_URL=https://console.bayar.cash/api/v3
```

- [ ] **Step 2: Run the full test suite**

Run: `sail test`
Expected: ALL tests pass — existing suites (Clients, Fees, Service Records, RBAC) plus new ChecksumTest, FakeBayarCashGatewayTest, PaymentTest, PaymentWebhookTest, StubGatewayTest. Note total green count.

- [ ] **Step 3: Update STATUS + SESSION-LOG**

In `docs/STATUS.md`: bump "Last updated" to session 8; move Payments from Pending to Completed with a one-line summary (Cash + BayarCash stub behind swappable interface, receipt record on paid, PDF deferred to module 6). Update "At a glance" PDF/Pay row.

In `docs/SESSION-LOG.md`: append a session 8 entry describing the Payments module build (interface, fake + live drivers, webhook idempotency, stub hosted page, receipt issuance, CTA).

- [ ] **Step 4: Update the backlog memory**

In `C:\Users\HamidKarim\.claude\projects\C--Saifzz-Aircond\memory\saifzz-backlog.md`: change the "Next: Payments (module 5)" bullet to a "DONE 2026-06-11 (session 8): Payments (module 5)" entry summarizing the swappable BayarCash stub + Cash + receipt record; set the new Next to Documents (module 6, invoice/receipt PDF — Receipt record already created by Payments). Update the "_Last updated_" footer.

- [ ] **Step 5: Commit**

```bash
git add .env.example docs/STATUS.md docs/SESSION-LOG.md
git commit -m "docs(payments): env example + status/session-log for module 5"
```

(Memory files live outside the repo; they are saved, not committed.)

---

## Self-Review

**Spec coverage:**
- Stable seam (interface + 2 drivers + config + provider) → Tasks 1, 2 ✓
- Checksum shared by both drivers → Task 1 (helper) + Task 2 (CallbackParser) ✓
- Stub redirect flow (createIntent → hosted page → signed callback → webhook → return) → Tasks 2, 5, 6 ✓
- Cash path (gated collect_payment) → Tasks 3, 5 ✓
- Receipt record on paid, RCP-YYYYMMDD-NNN, one-per-txn, snapshot, PDF deferred → Task 3 ✓
- Routes/RBAC table → Task 5 (auth + can:collect_payment), Task 6 (stub guarded) ✓
- CSRF exemption webhooks/* + dev/bayarcash/* → Task 5 ✓
- Idempotency + amount guard + invalid checksum + failed + unknown order → Task 4 + tests Task 4 ✓
- Vue Show/Return + service-record CTA → Tasks 7, 8 ✓
- Edge handling (already paid short-circuit, retry on failed) → Tasks 5 (show/pay guard), 7 (retry link) ✓
- Tests (feature + unit per spec list) → Tasks 1–8 ✓
- Env additions + go-live notes → Task 9 + BayarCashGateway TODOs ✓

**Placeholder scan:** No "TBD"/"implement later". The only TODOs are `TODO(go-live)` constants in `Checksum` callers / `CallbackParser` / `BayarCashGateway`, which are intentional, documented, and isolated — the stub + parser share them so behavior is fully defined today.

**Type consistency:** `PaymentGateway::createIntent/parseCallback`, `PaymentIntentData` (orderNumber/amount/payerName/payerEmail/payerPhone/returnUrl/callbackUrl/channel), `PaymentIntentResult` (gatewayRef/paymentUrl), `CallbackResult` (verified/orderNumber/gatewayRef/status/amount/raw), `PaymentStatus` (PENDING/PAID/FAILED), `PaymentService` (confirmCash/startGateway/issueReceipt), `HandleGatewayCallback::__invoke(CallbackResult)` — names consistent across all tasks. Checksum field order `[transaction_id/ref, order_number/txn_id, amount, status]` is identical in `CallbackParser`, `StubGatewayController::simulate`, and both feature tests.

**Ordering caveat:** Several feature tests are written in earlier tasks but only pass once routes/controllers land in Task 5/6 — standard for outside-in TDD. Each task notes when its tests go green. The `dev.bayarcash.show` route name is registered early (Task 2 Step 5) so `FakeBayarCashGateway::createIntent` URL generation resolves.
