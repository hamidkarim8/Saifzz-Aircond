# FEAT-004 — Manual QR payment method

**Date:** 2026-06-18
**Feedback item:** FEAT-004 (P1) — "New 'Manual QR' payment method — admin uploads/updates QR in Business Settings. Defaults: technician = QR + Cash; admin = QR + Manual QR + Cash."

## Problem

Payment collection (`Payments/Show`) offers two methods: **DuitNow QR** (BayarCash gateway, webhook-confirmed) and **Cash** (`collect_payment`-gated, manually confirmed). There is no way to collect via a plain **static QR** — the admin's own DuitNow/bank QR that the customer scans and transfers to directly, then the admin confirms receipt by hand.

## Goal

Add a third collection method, **Manual QR**: an admin uploads a static QR image (per-tenant, in Business Settings). At collection the admin shows the QR; once the customer transfers, the admin confirms manually → transaction marked paid + receipt issued, exactly like Cash. No gateway, no webhook.

## Decisions (locked with the user)

- **Mechanics:** manual confirm, identical to Cash (no gateway callback — a static image can't trigger one).
- **Gating:** **admin-only** at collection (`User::isAdmin()`). The whole `payments.*` route group is already `collect_payment`-gated; Manual QR adds an admin check on top.
- **QR storage:** per-tenant, in **Business Settings → Payment tab** (new `payment_qr_path` column), mirroring the existing Google Review QR upload.
- **Cash unchanged:** stays `collect_payment`-gated (CHG-016 not reversed). The "technician = … + Cash" feedback line describes the palette, not a permission change.

## Key facts established during exploration

- `transactions.method` is a plain `string` column (no DB enum). `'Manual QR'` is written **server-side only** (by `confirmManualQr`), so no migration and no change to the `Rule::in(['Cash','DuitNow QR'])` form validators on the service-record Create/Edit forms.
- `confirmCash` (in `PaymentService`) is the template: marks paid + `paid_at`, force-sets method, `issueReceipt`, `completeLinkedAppointment`, idempotent on already-paid.
- All `payments.*` routes carry `->middleware('can:collect_payment')`. Admins implicitly hold every permission (`User::hasPermission` short-circuits on `isAdmin()`), so an admin passes the route guard.
- Business Settings already has a working per-tenant image-upload pattern: `google_review_qr_path` column + `BusinessSettingController::update` storing to `qr/tenant-{id}.png` on the public disk.
- The Payment tab currently hosts the BayarCash credentials form (PUT `payment-settings.update`, a *separate* `PaymentGatewayController`). The Manual QR image belongs on the `business_settings` table, so its upload goes through `business-settings.update` (`BusinessSettingController`), not the gateway controller.

## Design

### Data — migration `2026_06_18_000030_add_payment_qr_to_business_settings_table.php`

Add nullable `payment_qr_path` string to `business_settings` (reversible `dropColumn`).

- `BusinessSetting`: add `payment_qr_path` to `$fillable`; add `'payment_qr_path' => $row?->payment_qr_path` to the `forTenant()` return array (and its docblock shape).

### Upload — Business Settings → Payment tab

- `UpdateBusinessSettingRequest::rules()`: add `'payment_qr' => ['nullable', 'image', 'max:2048']`.
- `BusinessSettingController::update()`: after the existing `google_review_qr` block, add a parallel block:

```php
if ($request->hasFile('payment_qr')) {
    $path = "payment-qr/tenant-{$tenantId}.png";
    Storage::disk('public')->put($path, file_get_contents($request->file('payment_qr')->getRealPath()));
    $data['payment_qr_path'] = $path;
}
```

- `BusinessSettingController::show()`: add a `paymentQrUrl` prop:

```php
'paymentQrUrl' => $row?->payment_qr_path
    ? Storage::disk('public')->url($row->payment_qr_path)
    : null,
```

- `BusinessSettings/Index.vue` Payment tab: add a **"Manual QR (DuitNow)"** `Card` *above* the BayarCash card. New `manualQrForm = useForm({ payment_qr: null })` submitting `put('business-settings.update', { forceFormData: true, preserveScroll: true })`; a file input (`accept="image/*"`, max 2MB hint) and a current-image preview (`<img v-if="paymentQrUrl">` / "No QR uploaded yet" empty state), styled like the Google Review tab. Add `paymentQrUrl` to the page's `defineProps`.

### Collection — service + controller + route

- `PaymentService::confirmManualQr(Transaction $transaction): void` — copy of `confirmCash` with `'method' => 'Manual QR'`:

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

- Route (in the `collect_payment`-gated block, beside `payments.cash`):

```php
Route::post('payments/{transaction}/manual-qr', [PaymentController::class, 'manualQr'])
    ->middleware('can:collect_payment')->name('payments.manualQr');
```

- `PaymentController::manualQr(Transaction $transaction, PaymentService $payments): RedirectResponse`:

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

- `PaymentController::show()`: expose the QR + admin flag so the page can render the button. Add to the rendered payload:

```php
'manualQrUrl' => \App\Models\BusinessSetting::forTenant($transaction->visit->tenant_id)['payment_qr_path']
    ? \Illuminate\Support\Facades\Storage::disk('public')->url(
        \App\Models\BusinessSetting::forTenant($transaction->visit->tenant_id)['payment_qr_path']
    )
    : null,
'isAdmin' => request()->user()->isAdmin(),
```

(The `visit` relation is already loaded for `client`; ensure `tenant_id` is available — `$transaction->visit->tenant_id`.)

### Frontend — `Payments/Show.vue`

- `defineProps`: add `manualQrUrl` (String, default null) and `isAdmin` (Boolean, default false). The `transaction` prop is unchanged.
- Add a third selectable method, `method === 'manualqr'`, with `canManualQr = computed(() => props.isAdmin && !!props.manualQrUrl)`.
- Render a **"Manual QR"** button (between DuitNow QR and Cash) only `v-if="canManualQr"`. Visual: a QR-style tile like DuitNow, subtitle "Customer scans your saved QR". When selected, show the **actual uploaded image** (`<img :src="manualQrUrl">`) instead of the dashed placeholder.
- Confirm handler: `payByManualQr()` uses the same `confirmAction` dialog as cash ("Confirm payment received?", body "This marks the transaction paid via Manual QR and issues a receipt.") then `router.post(route('payments.manualQr', transaction.id))`. Wire it into `handleConfirm` for `method === 'manualqr'`, and add the confirm-button label "Confirm Payment — Manual QR".

### Display of the method elsewhere

`'Manual QR'` flows through existing string renderers unchanged: `Payments/Return.vue`, `Transactions/Index`, the receipt snapshot/blade (`SnapshotBuilder` stores `$transaction->method` verbatim). No changes needed.

## Tests

**`tests/Feature/BusinessSettingTest.php`** (extend):
1. Admin uploads `payment_qr` (`UploadedFile::fake()->image(...)`) → `payment_qr_path` persisted, file on public disk; `show()` Inertia prop `paymentQrUrl` non-null.

**`tests/Feature/PaymentTest.php`** (extend) — add an `admin()` helper (`User::factory()->create(['role' => User::ROLE_ADMIN])`):
2. Admin posts `payments.manualQr` → redirect to return; txn `paid`, `method === 'Manual QR'`, `paid_at` set, receipt created.
3. Manual QR confirm is idempotent (second post → still one receipt).
4. Non-admin collector (tech *with* `collect_payment`) posting `payments.manualQr` → 403.
5. User without `collect_payment` → 403 (route middleware).
6. Cross-tenant / non-visible transaction → 403 (set tenant_ids so the admin can't see it) — mirror existing scope tests.

## Deployment

`php artisan migrate` (adds `payment_qr_path`). `npm run build` (Show.vue + Business Settings). No reseed. `storage:link` already present.

## Non-goals

- No change to the service-record Create/Edit `payment_method` selector (stays Cash / DuitNow QR; the collection method is chosen at the payment page, and admins pick Manual QR there).
- No gateway/webhook integration for Manual QR.
- No reversal of CHG-016 (cash remains `collect_payment`-gated).
