<?php
namespace App\Http\Controllers;

use App\Actions\Payments\HandleGatewayCallback;
use App\Models\TenantGateway;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request, HandleGatewayCallback $handle): Response
    {
        $orderNumber = (string) $request->input('order_number', '');
        $transaction = Transaction::with('visit')->where('txn_id', $orderNumber)->first();
        $tenantId = $transaction?->visit?->tenant_id;
        $gateway = TenantGateway::resolveGateway($tenantId);

        $result = $gateway->parseCallback($request);

        if (! $result->verified) {
            return response('invalid signature', 403);
        }

        $handle($result);

        return response('OK', 200);
    }
}
