<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\Data\CallbackResult;
use App\Services\Payments\Data\PaymentIntentData;
use App\Services\Payments\Data\PaymentIntentResult;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function createIntent(PaymentIntentData $data): PaymentIntentResult;

    public function parseCallback(Request $request): CallbackResult;
}
