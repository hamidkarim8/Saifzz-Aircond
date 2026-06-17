<?php
namespace Tests\Feature;

use App\Models\TenantGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentGatewaySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function boss(): User
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);
        return $boss->fresh();
    }

    public function test_admin_can_view_payment_settings_page(): void
    {
        $boss = $this->boss();
        // /payment-settings now redirects to /business-settings (the hub page)
        $this->actingAs($boss)->get('/payment-settings')->assertRedirect('/business-settings');
    }

    public function test_non_admin_cannot_view_payment_settings(): void
    {
        $tech = User::factory()->create(['role' => 'technician']);
        $this->actingAs($tech)->get('/payment-settings')->assertForbidden();
    }

    public function test_admin_can_save_gateway_credentials(): void
    {
        $boss = $this->boss();
        $this->actingAs($boss)->put('/payment-settings', [
            'api_token' => 'tok123',
            'portal_key' => 'pkey456',
            'api_secret' => 'sec789',
        ])->assertRedirect()->assertSessionHas('success');

        $row = TenantGateway::where('tenant_id', $boss->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('tok123', $row->api_token);
        $this->assertSame('pkey456', $row->portal_key);
        $this->assertSame('sec789', $row->api_secret);
    }

    public function test_blank_fields_on_update_keep_existing_values(): void
    {
        $boss = $this->boss();
        TenantGateway::create([
            'tenant_id' => $boss->id,
            'api_token' => 'original-tok',
            'portal_key' => 'original-pkey',
            'api_secret' => 'original-sec',
        ]);

        $this->actingAs($boss)->put('/payment-settings', [
            'api_token' => '',
            'portal_key' => '',
            'api_secret' => '',
        ])->assertRedirect()->assertSessionHas('success');

        $row = TenantGateway::where('tenant_id', $boss->id)->first();
        $this->assertSame('original-tok', $row->api_token);
        $this->assertSame('original-pkey', $row->portal_key);
        $this->assertSame('original-sec', $row->api_secret);
    }

    public function test_boss_cannot_modify_other_boss_gateway(): void
    {
        $boss1 = $this->boss();
        $boss2 = $this->boss();

        TenantGateway::create([
            'tenant_id' => $boss2->id,
            'api_token' => 'b2-tok',
            'portal_key' => 'b2-pkey',
            'api_secret' => 'b2-sec',
        ]);

        $this->actingAs($boss1)->put('/payment-settings', [
            'api_token' => 'b1-tok',
            'portal_key' => 'b1-pkey',
            'api_secret' => 'b1-sec',
        ])->assertRedirect();

        $this->assertSame('b2-tok', TenantGateway::where('tenant_id', $boss2->id)->first()->api_token);
        $this->assertNotNull(TenantGateway::where('tenant_id', $boss1->id)->first());
    }

    public function test_non_admin_cannot_update_payment_settings(): void
    {
        $tech = User::factory()->create(['role' => 'technician']);
        $this->actingAs($tech)->put('/payment-settings', [
            'api_token'  => 'tok',
            'portal_key' => 'pkey',
            'api_secret' => 'sec',
        ])->assertForbidden();
    }

    public function test_partial_update_only_changes_filled_field(): void
    {
        $boss = $this->boss();
        TenantGateway::create([
            'tenant_id'  => $boss->id,
            'api_token'  => 'original-tok',
            'portal_key' => 'original-pkey',
            'api_secret' => 'original-sec',
        ]);

        $this->actingAs($boss)->put('/payment-settings', [
            'api_token'  => 'new-tok',
            'portal_key' => '',
            'api_secret' => '',
        ])->assertRedirect()->assertSessionHas('success');

        $row = TenantGateway::where('tenant_id', $boss->id)->first();
        $this->assertSame('new-tok', $row->api_token);
        $this->assertSame('original-pkey', $row->portal_key);
        $this->assertSame('original-sec', $row->api_secret);
    }

    public function test_page_shows_configured_status(): void
    {
        $boss = $this->boss();
        TenantGateway::create([
            'tenant_id' => $boss->id,
            'api_token' => 'tok',
            'portal_key' => 'abcd1234',
            'api_secret' => 'sec',
        ]);

        // Payment settings data now lives on the business-settings hub page.
        $response = $this->actingAs($boss)->get('/business-settings');
        $response->assertInertia(fn ($page) => $page
            ->component('BusinessSettings/Index')
            ->where('payment.isConfigured', true)
            ->where('payment.portalKeyHint', '1234')
        );
    }
}
