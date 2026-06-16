<?php
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
        return DB::transaction(function () use ($transaction) {
            $existing = Receipt::where('transaction_id', $transaction->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return Receipt::create([
                'transaction_id' => $transaction->id,
                'number'         => $this->nextReceiptNumber(),
                'amount'         => $transaction->amount,
                'snapshot'       => $this->snapshots->forTransaction($transaction),
            ]);
        });
    }

    private function nextReceiptNumber(): string
    {
        $prefix = 'RCP-'.now()->format('Ymd').'-';
        $last = Receipt::where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
