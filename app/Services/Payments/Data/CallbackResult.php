<?php

namespace App\Services\Payments\Data;

use App\Services\Payments\PaymentStatus;

final class CallbackResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly string $orderNumber,
        public readonly ?string $gatewayRef,
        public readonly PaymentStatus $status,
        public readonly ?float $amount,
        public readonly array $raw,
    ) {}
}
