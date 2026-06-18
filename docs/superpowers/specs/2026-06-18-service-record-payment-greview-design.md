# Design — CHG-007 + CHG-008 (Service Record front: Google Review + payment-method)

**Date:** 2026-06-18
**Items:** CHG-007 (P2), CHG-008 (P2) — `docs/FEEDBACK-17062026.md`
**Area:** Service Record create/edit flow

## Problem

The New Service Record form carries a redundant **Payment method** selector (Cash / DuitNow
QR). The actual method is chosen later on the collect-payment screen
(`Payments/Show.vue`), and `PaymentService` overwrites `transaction.method` with the real
method at collection. The selector adds noise and a misleading choice.

Separately, the Google Review prompt only appears **after** payment (Show page, paid state).
Khalid wants it surfaced **before** payment, in place of the removed payment-method block on
the create form, so the technician can show the review QR to the customer while writing up the
job.

## CHG-008 — Remove payment-method selector

**Frontend**
- `resources/js/Pages/ServiceRecords/Create.vue`: delete the "Payment method" `Card`
  (currently lines 171-190); remove `payment_method` from `useForm`.
- `resources/js/Pages/ServiceRecords/Edit.vue`: delete the "Payment method" `Card`
  (currently lines 144-163); remove `payment_method` from `useForm`.

**Backend**
- `app/Http/Requests/StoreServiceVisitRequest.php`: drop the `payment_method` rule.
- `app/Http/Requests/UpdateServiceVisitRequest.php`: drop the `payment_method` rule.
- `ServiceVisitController@store`: transaction create no longer reads `$data['payment_method']`.
  Set pending placeholder `'method' => 'DuitNow QR'`. This is only the pending value;
  `PaymentService` (confirmCash / confirmManualQr / startGateway) overwrites it with the real
  method at collection.
- `ServiceVisitController@update`: stop writing `method` on the transaction. Update `amount`
  only — preserves the existing method on the pending transaction.

**Why method stays correct:** transaction starts pending with placeholder `DuitNow QR`;
on collection `PaymentService` sets `Cash` / `Manual QR` / `DuitNow QR`. Receipt, Show badge,
Transactions list all read the post-collection value. Unchanged.

## CHG-007 — Google Review on the create form

**Backend**
- `ServiceVisitController@create`: pass `googleReview => ['qrUrl' => $qrUrl, 'url' =>
  $biz['google_review_url']]`, reusing the same `BusinessSetting::forTenant` + storage URL
  lookup already in `show()` (lines 164-167). Tenant = `request()->user()->tenantId()`.

**Frontend**
- `Create.vue`: add a **"Google Review" `Card`** where the payment-method Card was (bottom of
  the form, before the sticky total bar). Contents: the QR image (`googleReview.qrUrl`) plus an
  "Open review page" link (`googleReview.url`), with a short instruction line ("Show this to the
  customer to leave a review"). Static inline display — no modal in form context.
- Render the card only when `googleReview.qrUrl` is set. If no QR is configured for the tenant,
  the card is hidden entirely (no empty placeholder).

## Scope

- Google Review card: **Create.vue only** (the before-payment creation flow). Edit.vue gets the
  payment-block removal only — no review card.
- No DB migration. No new routes. `BusinessSetting` already stores `google_review_qr_path` /
  `google_review_url`.

## Testing

- Feature test (docker sail): create a service record without posting `payment_method` →
  succeeds, transaction created `pending` with method `DuitNow QR`.
- Update a pending record → method unchanged, amount recalculated.
- Existing payment tests still pass (cash / manual QR / gateway set their own method).
- Manual eyeball (npm run dev): create form shows Google Review card when QR configured, hidden
  when not; no payment-method selector on create or edit.
