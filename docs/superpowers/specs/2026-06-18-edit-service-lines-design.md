# FEAT-007 — Edit service lines on a service record

**Date:** 2026-06-18
**Feedback item:** FEAT-007 (P1) — "Editing a service record can edit its services too — dynamically recompute invoice, total, everything."

## Problem

The service-record Edit page (`ServiceRecords/Edit.vue`) renders the service lines **read-only**. Khalid can edit visit meta (date, technician, warranty) and payment method, but cannot fix a wrong service type, adjust a quantity/price, or add/remove a line. The only recourse is cancelling the record and re-creating it.

## Goal

Make the Edit page edit service lines with the **same power as the Create page** (add / remove lines, change service type, unit type, HP, quantity, price, discount, description). Total, transaction amount, and the invoice recompute from the edited lines.

## Constraints / non-goals

- **Pending-only.** Editing stays gated to records whose transaction is `pending` (unchanged guard). Paid records have already issued documents and must not change.
- **Client stays fixed.** Changing the client would break serial number, units, and history scoping. The client remains read-only on the Edit page.
- No appointment-link change, no new migrations.

## Key facts established during exploration

- The invoice/receipt **snapshot is built live from `lines`** at document-generation time (`SnapshotBuilder::forTransaction`). There is no frozen line copy while the record is pending, so editing lines automatically flows through to the invoice. "Recompute the invoice" requires no extra work beyond persisting the new lines.
- `ServiceLine` computes `subtotal` in a `saving` model hook; `ServiceVisit::recalculateTotal()` sums line subtotals into `total_amount`. Both already exist and are reused.
- `ServiceVisitController::normalizeLine()` already snapshots the fee rate per pricing mode (flat / hp_tiered / flexible). Reused unchanged for the edit path.
- **Bug found:** the current `update()` does not touch `transaction.amount`. Even today, if lines were editable the transaction amount would go stale. The rewrite fixes this by re-syncing `amount` to the recomputed total.

## Design

### Frontend — `resources/js/Pages/ServiceRecords/Edit.vue`

- Replace the read-only "Services (read-only)" card with the Create-page editor pattern:
  - `ServiceLineCard` `v-for` over `form.lines`, with `@remove`, `:removable="form.lines.length > 1"`, passing `serviceTypes`, `clientUnits`, `visitDate`, `errors`.
  - "Add another service" dashed button (`addLine`).
  - Sticky navy grand-total bar mirroring Create (grand total + service/unit counts + Cancel + Save).
- Seed `form.lines` from `props.visit.lines`, mapping each persisted line back to the editor shape:
  `{ unit_id, service_type, unit_type, hp_value, repair_desc, units, rate, discount, next_service_date, notes }`.
  Decimal fields (`rate`, `discount`, `hp_value`) coerced to the types the card expects (numbers / strings), `next_service_date` sliced to `YYYY-MM-DD`.
- Copy `blankLine`, `addLine`, `removeLine`, `lineSubtotal`, `grandTotal`, `totalServices`, `totalUnits` from Create.
- Keep existing visit-meta + payment-method cards. Submit stays `form.patch(route('service-records.update', visit.id))`.
- The parked "Add line for each unit" button stays hidden (`v-if="false"`), consistent with Create and `docs/UNITS-TODO.md`.

### Controller — `ServiceVisitController`

**`edit()`** — add a `clientUnits` prop (active units for the visit's client), mirroring `create()`'s `presetClientUnits`, so `ServiceLineCard` can render unit info for lines that reference a unit:

```php
'clientUnits' => \App\Models\ClientUnit::where('client_id', $serviceRecord->client_id)
    ->where('is_active', true)->orderBy('label')
    ->get(['id', 'label', 'unit_type', 'hp']),
```

`serviceTypes` (with fees) is already passed — unchanged.

**`update()`** — rewrite to accept `UpdateServiceVisitRequest` and persist lines inside a transaction:

```php
public function update(UpdateServiceVisitRequest $request, ServiceVisit $serviceRecord): RedirectResponse
{
    // visibleTo + pending guards (unchanged)
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
            'visit_date'      => $data['visit_date'],
            'warranty_months' => $data['warranty_months'],
            'technician_id'   => $technicianId,
        ]);

        // Delete-then-recreate lines (server-authoritative; reuses normalizeLine).
        $serviceRecord->lines()->delete();
        foreach ($data['lines'] as $line) {
            $serviceRecord->lines()->create($this->normalizeLine($line));
        }

        // Re-sync next_service_date/type onto referenced units (mirror store()).
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

The cash-permission check moves into the request's `withValidator` (consistent with Store), so the inline `back()->withErrors` block is removed.

### Validation — shared trait + new request

The per-line fee-existence + cash-permission logic in `StoreServiceVisitRequest::withValidator` is duplicated by the edit path. Extract it:

- **`app/Http/Requests/Concerns/ValidatesServiceLines.php`** (trait) — a method `validateServiceLines($validator, ?int $clientId)` holding the cash-permission check + the per-line loop (flexible repair_desc/rate, unit_type required, fee existence by pricing mode). `StoreServiceVisitRequest` refactored to call it from its `withValidator` (passing `$this->input('client_id')`); behaviour identical, suite stays green.
- **`app/Http/Requests/UpdateServiceVisitRequest.php`** (new):
  - `authorize()`: `$this->user()->can('record_service')`.
  - `rules()`: visit_date, warranty_months, payment_method, technician_id, and the `lines.*` rules (same as Store), minus all `client_*` rules. The `unit_id` existence rule scopes to the **route record's** client: `Rule::exists('client_units', 'id')->where('client_id', $this->route('serviceRecord')->client_id)`.
  - `withValidator()`: calls `validateServiceLines($v, $this->route('serviceRecord')->client_id)`.

### Routes

`service-records.update` already exists (resource PATCH). No change.

## Tests (`tests/Feature/ServiceVisitTest.php` or a focused file)

1. Edit lines (change qty) → `total_amount` and `transaction.amount` recompute.
2. Add a line → total grows, line count +1.
3. Remove a line → total shrinks, line count −1.
4. Change a flexible line's price + description → persisted, total reflects it.
5. Change service type on a line → fee re-snapshotted from new type.
6. Cannot edit a paid record (transaction not pending → 422) — keep existing guard test.
7. Cash payment method blocked for a user without `collect_payment` (validation error).
8. Cross-tenant / non-visible record → 403 (keep existing).
9. Fee-existence failure (hp_tiered line with no matching fee) → validation error.

## Deployment

Frontend change → `npm run build`. No migrations, no reseed.
