<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\Data\PaymentIntentData;
use App\Services\Payments\FakeBayarCashGateway;
use App\Services\Payments\PaymentStatus;
use App\Services\Payments\Support\Checksum;
use Illuminate\Http\Request;
use Tests\TestCase;

class FakeBayarCashGatewayTest extends TestCase
{
    public function test_create_intent_returns_ref_and_stub_url(): void
    {
        $gateway = new FakeBayarCashGateway();

        $result = $gateway->createIntent(new PaymentIntentData(
            orderNumber: 'TXN-20260611-001',
            amount: 110.0,
            payerName: 'Zainab',
            payerEmail: null,
            payerPhone: '012-3456789',
            returnUrl: 'http://localhost/return',
            callbackUrl: 'http://localhost/webhooks/bayarcash',
            channel: 5,
        ));

        $this->assertNotEmpty($result->gatewayRef);
        $this->assertStringContainsString('dev/bayarcash', $result->paymentUrl);
        $this->assertStringContainsString('TXN-20260611-001', urldecode($result->paymentUrl));
    }

    public function test_parse_callback_verifies_signature_and_maps_status(): void
    {
        config(['services.bayarcash.api_secret' => 'secret']);
        $gateway = new FakeBayarCashGateway();

        $fields = ['STUB-1', 'TXN-20260611-001', '110.00', '3'];
        $request = Request::create('/webhooks/bayarcash', 'POST', [
            'transaction_id' => 'STUB-1',
            'order_number' => 'TXN-20260611-001',
            'amount' => '110.00',
            'status' => '3',
            'checksum' => Checksum::make($fields, 'secret'),
        ]);

        $result = $gateway->parseCallback($request);

        $this->assertTrue($result->verified);
        $this->assertSame(PaymentStatus::PAID, $result->status);
        $this->assertSame('TXN-20260611-001', $result->orderNumber);
        $this->assertSame(110.0, $result->amount);
    }

    public function test_parse_callback_flags_bad_signature(): void
    {
        config(['services.bayarcash.api_secret' => 'secret']);
        $gateway = new FakeBayarCashGateway();

        $request = Request::create('/webhooks/bayarcash', 'POST', [
            'transaction_id' => 'STUB-1',
            'order_number' => 'TXN-20260611-001',
            'amount' => '110.00',
            'status' => '3',
            'checksum' => 'tampered',
        ]);

        $this->assertFalse($gateway->parseCallback($request)->verified);
    }
}
