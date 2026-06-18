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
                $this->payments->completeLinkedAppointment($transaction);
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
