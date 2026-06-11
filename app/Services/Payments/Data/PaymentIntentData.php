<?php

namespace App\Services\Payments\Data;

final class PaymentIntentData
{
    public function __construct(
        public readonly string $orderNumber,   // our txn_id
        public readonly float $amount,
        public readonly string $payerName,
        public readonly ?string $payerEmail,
        public readonly ?string $payerPhone,
        public readonly string $returnUrl,
        public readonly string $callbackUrl,
        public readonly int $channel,
    ) {}
}
