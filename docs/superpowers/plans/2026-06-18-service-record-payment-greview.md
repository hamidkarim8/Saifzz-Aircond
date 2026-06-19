# Service Record Payment + Google Review (CHG-007/008) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the redundant payment-method selector from the service-record create/edit forms (CHG-008) and surface the Google Review QR on the create form before payment (CHG-007).

**Architecture:** The collect-payment screen already selects the real method and `PaymentService` overwrites `transaction.method` at collection, so the create/edit forms only need a pending placeholder. Drop the `payment_method` form field + validation, hardcode the pending placeholder on store, leave method untouched on update. Pass the tenant's Google Review QR into the create page and render it as an inline card where the payment-method card used to be.

**Tech Stack:** Laravel 11, Inertia + Vue 3, Tailwind. Tests run via `docker exec saifzz-aircond-laravel.test-1 php artisan test`.

---

## File Structure

- `app/Http/Requests/StoreServiceVisitRequest.php` — drop `payment_method` rule.
- `app/Http/Requests/UpdateServiceVisitRequest.php` — drop `payment_method` rule.
- `app/Http/Controllers/ServiceVisitController.php` — `store` hardcodes pending method; `update` stops writing method; `create` passes `googleReview`.
- `resources/js/Pages/ServiceRecords/Create.vue` — remove payment-method card, add Google Review card.
- `resources/js/Pages/ServiceRecords/Edit.vue` — remove payment-method card.
- `tests/Feature/ServiceVisitTest.php` — new test: store without `payment_method`.

---

## Task 1: Backend — store works without `payment_method`, defaults pending method

**Files:**
- Modify: `app/Http/Requests/StoreServiceVisitRequest.php:32`
- Modify: `app/Http/Controllers/ServiceVisitController.php:143`
- Test: `tests/Feature/ServiceVisitTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ServiceVisitTest.php` (after `test_rate_is_snapshotted_from_fee_book_ignoring_client_input`):

```php
public function test_store_without_payment_method_defaults_pending_method(): void
{
    $this->seedFees();
    $data = $this->payload([
        ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'rate' => 60, 'discount' => 0],
    ]);
    unset($data['payment_method']); // CHG-008 — form no longer sends it

    $this->actingAs($this->recorder())->post(route('service-records.store'), $data)->assertRedirect();

    $visit = ServiceVisit::with('transaction')->latest('id')->first();
    $this->assertSame('pending', $visit->transaction->status);
    $this->assertSame('DuitNow QR', $visit->transaction->method); // placeholder, overwritten at collection
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter test_store_without_payment_method_defaults_pending_method`
Expected: FAIL — validation error "payment method field is required" → no redirect (status 302 to back with errors, assertion mismatch) or null method.

- [ ] **Step 3: Drop the validation rule**

In `app/Http/Requests/StoreServiceVisitRequest.php`, remove the line:

```php
'payment_method' => ['required', Rule::in(['Cash', 'DuitNow QR'])],
```

If `Rule` import becomes unused, leave it — other rules in the file may use it; do not remove imports unless the file has no other `Rule::` usage.

- [ ] **Step 4: Hardcode the pending placeholder in store**

In `app/Http/Controllers/ServiceVisitController.php`, the transaction create (around line 140-145), change:

```php
$visit->transaction()->create([
    'txn_id' => $this->nextTxnId(),
    'amount' => $visit->total_amount,
    'method' => $data['payment_method'],
    'status' => 'pending',
]);
```

to:

```php
$visit->transaction()->create([
    'txn_id' => $this->nextTxnId(),
    'amount' => $visit->total_amount,
    'method' => 'DuitNow QR', // pending placeholder; PaymentService sets real method at collection
    'status' => 'pending',
]);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter test_store_without_payment_method_defaults_pending_method`
Expected: PASS

- [ ] **Step 6: Run the full ServiceVisitTest to confirm no regression**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter ServiceVisitTest`
Expected: PASS (existing tests still send `payment_method`; extra field is now ignored).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/StoreServiceVisitRequest.php app/Http/Controllers/ServiceVisitController.php tests/Feature/ServiceVisitTest.php
git commit -m "feat(service-records): store no longer requires payment_method (CHG-008)"
```

---

## Task 2: Backend — update preserves existing method

**Files:**
- Modify: `app/Http/Requests/UpdateServiceVisitRequest.php:28`
- Modify: `app/Http/Controllers/ServiceVisitController.php:251-254`
- Test: `tests/Feature/ServiceVisitUpdateTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ServiceVisitUpdateTest.php` (place beside existing update tests; reuse that file's existing helpers for building a pending visit — match the pattern already used there for `actingAs` + a pending `ServiceVisit` with a `transaction`):

```php
public function test_update_without_payment_method_preserves_existing_method(): void
{
    // Arrange: a pending visit whose transaction method is Cash
    [$user, $visit] = $this->pendingVisitFor(); // existing helper in this test file
    $visit->transaction->update(['method' => 'Cash']);

    $payload = $this->updatePayload($visit); // existing helper producing a valid update body
    unset($payload['payment_method']);

    // Act
    $this->actingAs($user)->put(route('service-records.update', $visit), $payload)->assertRedirect();

    // Assert: method untouched
    $this->assertSame('Cash', $visit->transaction->fresh()->method);
}
```

> If `ServiceVisitUpdateTest` does not expose `pendingVisitFor()` / `updatePayload()` helpers with those exact names, read the file first and adapt the arrange/act lines to its existing setup (e.g. an inline `$this->payload(...)` like `ServiceVisitTest`). Keep the three assertions: method starts `Cash`, payload omits `payment_method`, method ends `Cash`.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter test_update_without_payment_method_preserves_existing_method`
Expected: FAIL — `payment_method` required (no redirect) or method overwritten to default.

- [ ] **Step 3: Drop the validation rule**

In `app/Http/Requests/UpdateServiceVisitRequest.php`, remove:

```php
'payment_method' => ['required', Rule::in(['Cash', 'DuitNow QR'])],
```

- [ ] **Step 4: Stop writing method on update**

In `app/Http/Controllers/ServiceVisitController.php` (around line 251-254), change:

```php
$serviceRecord->transaction->update([
    'method' => $data['payment_method'],
    'amount' => $serviceRecord->total_amount,
]);
```

to:

```php
$serviceRecord->transaction->update([
    'amount' => $serviceRecord->total_amount, // method preserved; set at payment collection
]);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter test_update_without_payment_method_preserves_existing_method`
Expected: PASS

- [ ] **Step 6: Run the full update test to confirm no regression**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter ServiceVisitUpdateTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/UpdateServiceVisitRequest.php app/Http/Controllers/ServiceVisitController.php tests/Feature/ServiceVisitUpdateTest.php
git commit -m "feat(service-records): update preserves transaction method (CHG-008)"
```

---

## Task 3: Backend — pass Google Review QR to the create page

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php:59-86`
- Test: `tests/Feature/ServiceVisitTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ServiceVisitTest.php`:

```php
public function test_create_page_receives_google_review_when_configured(): void
{
    $this->seedFees();
    $user = $this->recorder();
    \App\Models\BusinessSetting::updateOrCreate(
        ['tenant_id' => $user->tenantId()],
        ['google_review_qr_path' => 'qr/review.png', 'google_review_url' => 'https://g.page/r/abc']
    );

    $this->actingAs($user)
        ->get(route('service-records.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ServiceRecords/Create')
            ->where('googleReview.url', 'https://g.page/r/abc')
            ->where('googleReview.qrUrl', fn ($v) => is_string($v) && str_contains($v, 'qr/review.png'))
        );
}
```

> Confirm the `BusinessSetting` column names by reading `app/Http/Controllers/ServiceVisitController.php:164-167` (the `show()` method already reads `google_review_qr_path` and `google_review_url`). Match exactly.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter test_create_page_receives_google_review_when_configured`
Expected: FAIL — `googleReview` prop missing.

- [ ] **Step 3: Pass googleReview from create()**

In `app/Http/Controllers/ServiceVisitController.php`, in `create()` (line 59-86), before the `return Inertia::render(...)`, add the same lookup `show()` uses:

```php
$biz = \App\Models\BusinessSetting::forTenant(request()->user()->tenantId());
$qrUrl = $biz['google_review_qr_path']
    ? \Illuminate\Support\Facades\Storage::disk('public')->url($biz['google_review_qr_path'])
    : null;
```

Then add this prop to the `Inertia::render('ServiceRecords/Create', [...])` array:

```php
'googleReview' => ['qrUrl' => $qrUrl, 'url' => $biz['google_review_url']],
```

> Verify `BusinessSetting::forTenant()` accepts the tenant id the same way `show()` calls it (line 164 uses `$serviceRecord->tenant_id`). In `create()` use `request()->user()->tenantId()`.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter test_create_page_receives_google_review_when_configured`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php tests/Feature/ServiceVisitTest.php
git commit -m "feat(service-records): pass Google Review QR to create page (CHG-007)"
```

---

## Task 4: Frontend — Create.vue: remove payment-method card, add Google Review card

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Create.vue`

- [ ] **Step 1: Add the googleReview prop and drop payment_method from the form**

In the `defineProps` object (lines 13-20), add:

```js
    googleReview: { type: Object, default: () => ({ qrUrl: null, url: null }) },
```

In the `useForm({...})` (lines 29-39), remove the line:

```js
    payment_method: canCollectCash ? 'Cash' : 'DuitNow QR',
```

The `canCollectCash` const (line 11) is now unused by the form — if nothing else in the file references it, remove its declaration too. Grep the file for `canCollectCash` before removing.

- [ ] **Step 2: Replace the Payment-method card with a Google Review card**

In the template, delete the entire `<!-- Payment method -->` block (lines 171-190, the `<Card title="Payment method">...</Card>` and its comment). Replace it with:

```vue
            <!-- Google Review — shown before payment so the tech can prompt the customer -->
            <Card v-if="googleReview.qrUrl" title="Google Review">
                <div class="flex flex-col items-center gap-3 text-center">
                    <p class="text-sm text-ink-soft">Show this to the customer to leave a review.</p>
                    <img :src="googleReview.qrUrl" alt="Google Review QR" class="h-44 w-44 object-contain" />
                    <a
                        v-if="googleReview.url"
                        :href="googleReview.url"
                        target="_blank"
                        rel="noopener"
                        class="text-sm font-semibold text-primary underline"
                    >Open review page</a>
                </div>
            </Card>
```

- [ ] **Step 3: Build assets to verify the page compiles**

Run: `docker exec saifzz-aircond-laravel.test-1 npm run build`
Expected: build succeeds, no Vue compile errors referencing Create.vue.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/ServiceRecords/Create.vue
git commit -m "feat(service-records): Google Review card replaces payment selector on create (CHG-007/008)"
```

---

## Task 5: Frontend — Edit.vue: remove payment-method card

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Edit.vue`

- [ ] **Step 1: Remove payment_method from the form**

In `useForm` (line 41), remove:

```js
    payment_method: props.visit.transaction?.method ?? (canCollectCash ? 'Cash' : 'DuitNow QR'),
```

If `canCollectCash` is now unused elsewhere in the file, remove its declaration. Grep the file for `canCollectCash` first.

- [ ] **Step 2: Remove the payment-method card from the template**

Delete the entire `<!-- Payment method -->` block (lines 144-163), including the `<Card title="Payment method">...</Card>` and the `form.errors.payment_method` paragraph at line 163.

- [ ] **Step 3: Build assets to verify the page compiles**

Run: `docker exec saifzz-aircond-laravel.test-1 npm run build`
Expected: build succeeds, no Vue compile errors referencing Edit.vue.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/ServiceRecords/Edit.vue
git commit -m "feat(service-records): remove payment selector from edit form (CHG-008)"
```

---

## Task 6: Full regression + manual eyeball

- [ ] **Step 1: Run the full suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: PASS (pay attention to `PaymentTest`, `ServiceVisitTest`, `ServiceVisitUpdateTest`, `AppointmentPaymentCompletionTest`).

- [ ] **Step 2: Manual eyeball (npm run dev)**

Start: `docker exec saifzz-aircond-laravel.test-1 npm run dev` (or existing dev server).
Check:
- New Service Record form: no payment-method selector; Google Review card shown when a QR is configured in Business Settings, hidden when not.
- Edit (pending record): no payment-method selector; save works.
- Create a record → collect payment → method (Cash/Manual QR/DuitNow QR) recorded correctly; receipt shows the chosen method.

- [ ] **Step 3: Update feedback doc**

In `docs/FEEDBACK-17062026.md`, set CHG-007 and CHG-008 Status `OPEN` → `TESTING`, add Notes describing what shipped.

- [ ] **Step 4: Commit**

```bash
git add docs/FEEDBACK-17062026.md
git commit -m "docs: CHG-007/008 → TESTING"
```
