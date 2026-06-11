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
