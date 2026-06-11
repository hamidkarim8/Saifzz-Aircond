<?php

namespace App\Services\Payments;

use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Documents\SnapshotBuilder;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\PaymentIntentData;
use Illuminate\Support\Facades\DB;

final class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SnapshotBuilder $snapshots,
    ) {}

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
                'snapshot' => $this->snapshots->forTransaction($transaction),
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
}
