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
