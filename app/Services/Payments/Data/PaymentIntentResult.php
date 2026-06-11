<?php

namespace App\Services\Payments\Data;

final class PaymentIntentResult
{
    public function __construct(
        public readonly string $gatewayRef,   // gateway intent/transaction id
        public readonly string $paymentUrl,   // redirect target
    ) {}
}
