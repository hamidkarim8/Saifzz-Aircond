<?php

namespace App\Http\Controllers;

use App\Actions\Payments\HandleGatewayCallback;
use App\Services\Payments\Contracts\PaymentGateway;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request, PaymentGateway $gateway, HandleGatewayCallback $handle): Response
    {
        $result = $gateway->parseCallback($request);

        if (! $result->verified) {
            return response('invalid signature', 403);
        }

        $handle($result); // idempotent; ignores unknown/mismatched txns

        return response('OK', 200);
    }
}
