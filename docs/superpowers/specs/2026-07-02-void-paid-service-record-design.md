# Void paid service records (+ status chip filters)

**Date:** 2026-07-02
**Trigger:** Khalid mistakenly created a service record 1-Jul, took it through to `paid`. No way to undo a paid record today — `edit`/`destroy` are both hard-gated to `status === 'pending'`. He needs a way to un-bill a mistaken paid record without editing it in place.

## Current behavior (verified in code)

- `ServiceVisitController::edit/update/destroy` all `abort_unless($serviceRecord->transaction?->status === 'pending', ...)` (`app/Http/Controllers/ServiceVisitController.php:207,234,294`).
- `destroy()` is already a soft action for pending records: sets `transaction.status = 'cancelled'`, no row is ever hard-deleted (`ServiceVisitController.php:286-300`). UI labels this "Cancel", not "Delete".
- Once `paid`, no action buttons render at all (`ServiceRecords/Show.vue` paid branch ~156-164; `Index.vue` row actions gated to `pending` only, lines 135/142).
- `Transactions/Index.vue` already has a working status chip filter pattern (`all/paid/pending/failed/cancelled`) to mirror for `ServiceRecords/Index.vue`.
- `PortalService::accountFor()` (`app/Services/Portal/PortalService.php:33-38`) lists **all** visits for a client with no status filter — cancelled records are currently still visible to the customer.

## Data model touched by a service record

1. `ServiceVisit` — the record itself.
2. `ServiceLine[]` — line items.
3. `Transaction` (1:1, `visit_id` unique) — amount/method/status/paid_at.
4. `Invoice` (1:1 on Transaction) — lazily created on first view/download (`DocumentService::invoiceFor`).
5. `Receipt` (1:1 on Transaction) — created immediately at payment confirm (`PaymentService::issueReceipt`).
6. `Appointment` (optional, pre-existing) — flipped to `completed` when the transaction is paid (`PaymentService::completeLinkedAppointment`, `PaymentService.php:52-74`).

No inventory, commission, or separate accounting-ledger tables reference a visit/transaction — confirmed via model relation grep.

## Design

### Two-tier destroy: Cancel (pending) vs Void (paid)

- `pending` → existing **Cancel** flow, unchanged: `transaction.status = 'cancelled'`, no reason required.
- `paid` → new **Void** flow: `transaction.status = 'void'`, requires a typed reason, reverts the linked appointment.
- Any other status (`failed`, already `cancelled`/`void`) → `422`, destroy is a no-op past its first transition.

**No rows are hard-deleted.** `Invoice`/`Receipt` stay in the DB (readable for audit) — they're excluded from customer-facing access instead of erased. This is the "soft delete, but must delete the ledger [from the customer's view]" requirement.

### Schema change

New migration on `transactions` (plain `string` status column today, no DB enum/check constraint — safe to extend):

```php
$table->text('void_reason')->nullable();
$table->timestamp('voided_at')->nullable();
$table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
```

`status` gains a 4th value: `void` (existing values: `pending|paid|failed|cancelled`).

### Backend

- New `PaymentService::voidPaid(Transaction $txn, string $reason, User $actor): void` — `DB::transaction`-wrapped:
  - `$txn->update(['status' => 'void', 'void_reason' => $reason, 'voided_at' => now(), 'voided_by' => $actor->id])`.
  - If the visit's linked `Appointment` was auto-completed by this payment, revert it to `pending` (inverse of `completeLinkedAppointment`, same tenant-match guard, only fires if appointment is currently `completed`).
- `ServiceVisitController::destroy()`:
  - Accepts `Request $request` now (currently takes none).
  - Branches on `$txn->status`: `pending` → existing cancel code unchanged; `paid` → validate `reason` (`required|string|max:500`), call `voidPaid()`; else → `abort(422)`.
  - Permission: unchanged — still gated only by `ServiceVisit::visibleTo($user)`, no new permission tier (matches how Cancel works today).
- `DocumentService::receiptViewModel()` (and wherever invoice is resolved for the **portal**) — `abort_if($transaction->status === 'void', 404)`. Staff-side `DocumentController` is untouched; internal users can still open a voided record's invoice/receipt for audit.
- `PortalService::accountFor()` — constrain the `visits` eager-load to exclude `transaction.status IN ('void', 'cancelled')`, so both are excluded from the customer's service-history list (cancelled was already reachable pre-existing; folded into this change since both mean "this didn't happen" from the customer's view).
- `ServiceVisitController::index()` — accept a `status` query param; when set and not `all`, `whereHas('transaction', fn($q) => $q->where('status', $status))`.

### Frontend

- `ServiceRecords/Show.vue`: paid branch gets a **Void** button → confirm modal with a required reason textarea → `router.delete(route('service-records.destroy', ...), { data: { reason } })`.
- `ServiceRecords/Index.vue`: paid rows get the same Void button, same modal (mirrors the existing pending-row Cancel button at lines 141-147).
- New status chip filter bar on `ServiceRecords/Index.vue` — `All / Paid / Pending / Cancelled / Void`, default `All` — via `DataTable`'s existing `filterParams` prop + `#filters` slot (server mode, same mechanism `Transactions/Index.vue` already demonstrates client-side; here it round-trips through the new `status` query param).

## Testing

Feature tests:
- Void blocked on `pending`/`failed`/already-`cancelled`/already-`void` (422).
- Void succeeds on `paid`: status/reason/`voided_at`/`voided_by` persisted correctly.
- Linked appointment reverts to `pending` only if it was `completed`; untouched otherwise.
- Portal: voided/cancelled visit excluded from `accountFor()` list; direct receipt/invoice URL 404s post-void.
- Staff `DocumentController` can still open a voided record's invoice/receipt.
- `index()` status filter returns the correct row set for each chip value.

## Out of scope

- Editing a paid/voided record in place (Khalid's workflow is: void the mistake, create a fresh correct record).
- Any change to `edit`/`update` gating — stays `pending`-only.
- Hard-deleting any row.
