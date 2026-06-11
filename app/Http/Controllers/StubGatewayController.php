<?php

namespace App\Http\Controllers;

use App\Actions\Payments\HandleGatewayCallback;
use App\Models\Transaction;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Support\Checksum;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Stand-in for the BayarCash hosted payment page. Active only when
 * BAYARCASH_DRIVER=fake (routes are guarded in web.php). Lets a developer
 * simulate the gateway firing a signed callback through the REAL webhook path.
 */
class StubGatewayController extends Controller
{
    public function show(string $ref, Request $request): View
    {
        $transaction = Transaction::where('txn_id', $request->query('order'))->first();

        return view('dev.bayarcash', [
            'ref' => $ref,
            'order' => $request->query('order'),
            'amount' => $transaction?->amount,
        ]);
    }

    public function simulate(
        string $ref,
        Request $request,
        PaymentGateway $gateway,
        HandleGatewayCallback $handle,
    ): RedirectResponse {
        $transaction = Transaction::where('txn_id', $request->input('order'))->firstOrFail();

        $statusCode = $request->input('outcome') === 'paid' ? 3 : 4;
        $amount = number_format((float) $transaction->amount, 2, '.', '');
        $secret = (string) config('services.bayarcash.api_secret');

        // Field order MUST match CallbackParser::parse().
        $checksum = Checksum::make([$ref, $transaction->txn_id, $amount, (string) $statusCode], $secret);

        // Build a BayarCash-shaped request and run it through the shared parser + handler.
        $callback = Request::create(route('webhooks.bayarcash'), 'POST', [
            'transaction_id' => $ref,
            'order_number' => $transaction->txn_id,
            'amount' => $amount,
            'status' => $statusCode,
            'checksum' => $checksum,
        ]);

        $handle($gateway->parseCallback($callback));

        return redirect()->route('payments.return', $transaction);
    }
}
