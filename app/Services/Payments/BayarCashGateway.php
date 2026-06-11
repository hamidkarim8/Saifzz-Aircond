<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CallbackResult;
use App\Services\Payments\Data\PaymentIntentData;
use App\Services\Payments\Data\PaymentIntentResult;
use App\Services\Payments\Support\CallbackParser;
use App\Services\Payments\Support\Checksum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Live BayarCash v3 driver. Scaffolded against the documented API shape but inert
 * until credentials exist. TODO(go-live) markers note constants to confirm vs docs.
 */
final class BayarCashGateway implements PaymentGateway
{
    public function __construct(private readonly array $config) {}

    public function createIntent(PaymentIntentData $data): PaymentIntentResult
    {
        if (empty($this->config['api_token']) || empty($this->config['portal_key']) || empty($this->config['api_secret'])) {
            throw new RuntimeException(
                'BayarCash credentials not configured. Set BAYARCASH_API_TOKEN, '
                .'BAYARCASH_PORTAL_KEY and BAYARCASH_API_SECRET, or use BAYARCASH_DRIVER=fake.'
            );
        }

        $amount = number_format($data->amount, 2, '.', '');

        // TODO(go-live): confirm payload keys + required fields (BayarCash may require payer_email).
        $payload = [
            'portal_key' => $this->config['portal_key'],
            'order_number' => $data->orderNumber,
            'amount' => $amount,
            'payer_name' => $data->payerName,
            'payer_email' => $data->payerEmail,
            'payer_telephone_number' => $data->payerPhone,
            'payment_channel' => $data->channel,
            'return_url' => $data->returnUrl,
            'callback_url' => $data->callbackUrl,
        ];

        // TODO(go-live): confirm checksum field set + ordering for the create request.
        $payload['checksum'] = Checksum::make(
            [$payload['order_number'], $amount, $payload['payment_channel']],
            (string) $this->config['api_secret'],
        );

        $response = Http::withToken($this->config['api_token'])
            ->acceptJson()
            ->post(rtrim($this->config['base_url'], '/').'/payment-intents', $payload)
            ->throw()
            ->json();

        // TODO(go-live): confirm response keys (intent id + redirect url).
        return new PaymentIntentResult(
            (string) ($response['id'] ?? ''),
            (string) ($response['url'] ?? ''),
        );
    }

    public function parseCallback(Request $request): CallbackResult
    {
        return CallbackParser::parse($request, (string) $this->config['api_secret']);
    }
}
