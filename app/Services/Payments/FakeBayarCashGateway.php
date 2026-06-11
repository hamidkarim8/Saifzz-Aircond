<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CallbackResult;
use App\Services\Payments\Data\PaymentIntentData;
use App\Services\Payments\Data\PaymentIntentResult;
use App\Services\Payments\Support\CallbackParser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class FakeBayarCashGateway implements PaymentGateway
{
    public function createIntent(PaymentIntentData $data): PaymentIntentResult
    {
        $ref = 'STUB-'.Str::upper(Str::random(12));

        $url = route('dev.bayarcash.show', [
            'ref' => $ref,
            'order' => $data->orderNumber,
        ]);

        return new PaymentIntentResult($ref, $url);
    }

    public function parseCallback(Request $request): CallbackResult
    {
        return CallbackParser::parse($request, (string) config('services.bayarcash.api_secret'));
    }
}
