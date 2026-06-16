<?php
namespace Tests\Feature;

use App\Models\ServiceHpTier;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceHpTierTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function type(): ServiceType
    {
        return ServiceType::create(['name' => 'Gas Top-Up', 'requires_next_service' => false, 'is_hp_based' => false]);
    }

    public function test_admin_can_toggle_is_hp_based(): void
    {
        $admin = $this->admin();
        $type = $this->type();

        $this->actingAs($admin)->put(route('service-types.update', $type), [
            'name' => $type->name,
            'is_hp_based' => true,
        ])->assertRedirect();

        $this->assertTrue($type->fresh()->is_hp_based);
    }

    public function test_admin_can_create_hp_tier(): void
    {
        $admin = $this->admin();
        $admin->update(['permissions' => ['manage_service_types', 'edit_fees']]);
        $type = $this->type();

        $this->actingAs($admin)->post(route('service-hp-tiers.store'), [
            'service_type_id' => $type->id,
            'hp_value' => 1.5,
            'price' => 25.00,
        ])->assertRedirect();

        $this->assertSame(1, ServiceHpTier::where('service_type_id', $type->id)->count());
        $this->assertSame('1.5', ServiceHpTier::first()->hp_value);
        $this->assertSame('25.00', ServiceHpTier::first()->price);
    }

    public function test_duplicate_hp_value_updates_price(): void
    {
        $admin = $this->admin();
        $admin->update(['permissions' => ['manage_service_types', 'edit_fees']]);
        $type = $this->type();

        ServiceHpTier::create(['service_type_id' => $type->id, 'hp_value' => 1.5, 'price' => 20.00]);

        $this->actingAs($admin)->post(route('service-hp-tiers.store'), [
            'service_type_id' => $type->id,
            'hp_value' => 1.5,
            'price' => 30.00,
        ])->assertRedirect();

        $this->assertSame(1, ServiceHpTier::count());
        $this->assertSame('30.00', ServiceHpTier::first()->price);
    }

    public function test_admin_can_delete_hp_tier(): void
    {
        $admin = $this->admin();
        $admin->update(['permissions' => ['manage_service_types', 'edit_fees']]);
        $type = $this->type();
        $tier = ServiceHpTier::create(['service_type_id' => $type->id, 'hp_value' => 2.0, 'price' => 35.00]);

        $this->actingAs($admin)->delete(route('service-hp-tiers.destroy', $tier))->assertRedirect();

        $this->assertSame(0, ServiceHpTier::count());
    }

    public function test_deleting_service_type_cascades_to_tiers(): void
    {
        $type = $this->type();
        ServiceHpTier::create(['service_type_id' => $type->id, 'hp_value' => 1.0, 'price' => 15.00]);

        $type->delete();

        $this->assertSame(0, ServiceHpTier::count());
    }

    public function test_non_edit_fees_cannot_create_tier(): void
    {
        $tech = User::factory()->create(['role' => 'technician', 'permissions' => ['manage_service_types']]);
        $type = $this->type();

        $this->actingAs($tech)->post(route('service-hp-tiers.store'), [
            'service_type_id' => $type->id,
            'hp_value' => 1.5,
            'price' => 25.00,
        ])->assertForbidden();
    }

    public function test_service_record_line_rate_includes_hp_surcharge(): void
    {
        $this->seed(\Database\Seeders\ServiceTypeSeeder::class);

        $type = ServiceType::where('name', 'Gas Top-Up')->first();
        $type->update(['is_hp_based' => true]);

        \App\Models\ServiceFee::insert([
            ['service_type' => 'Gas Top-Up', 'option' => 'Full Top-Up', 'rate' => 50.00, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
        ]);

        ServiceHpTier::create(['service_type_id' => $type->id, 'hp_value' => 1.5, 'price' => 20.00]);

        $admin = $this->admin();
        $admin->update(['tenant_id' => $admin->id]);
        $client = \App\Models\Client::create(['name' => 'C', 'phone' => '012-0000000', 'address' => 'KL', 'tenant_id' => $admin->tenantId()]);

        $this->actingAs($admin)->post(route('service-records.store'), [
            'client_mode' => 'existing',
            'client_id' => $client->id,
            'visit_date' => '2026-06-16',
            'warranty_months' => 0,
            'payment_method' => 'DuitNow QR',
            'lines' => [[
                'service_type' => 'Gas Top-Up',
                'unit_type' => null,
                'gas_option' => 'Full Top-Up',
                'units' => 1,
                'rate' => 0,
                'discount' => 0,
                'hp_value' => 1.5,
            ]],
        ])->assertRedirect();

        $line = \App\Models\ServiceLine::latest('id')->first();
        $this->assertSame('70.00', $line->rate);
        $this->assertSame('1.5', $line->hp_value);
    }

    public function test_line_without_hp_value_uses_base_fee_only(): void
    {
        $this->seed(\Database\Seeders\ServiceTypeSeeder::class);

        \App\Models\ServiceFee::insert([
            ['service_type' => 'Gas Top-Up', 'option' => 'Full Top-Up', 'rate' => 50.00, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $admin = $this->admin();
        $admin->update(['tenant_id' => $admin->id]);
        $client = \App\Models\Client::create(['name' => 'C', 'phone' => '012-0000000', 'address' => 'KL', 'tenant_id' => $admin->tenantId()]);

        $this->actingAs($admin)->post(route('service-records.store'), [
            'client_mode' => 'existing',
            'client_id' => $client->id,
            'visit_date' => '2026-06-16',
            'warranty_months' => 0,
            'payment_method' => 'DuitNow QR',
            'lines' => [[
                'service_type' => 'Gas Top-Up',
                'gas_option' => 'Full Top-Up',
                'units' => 1,
                'rate' => 0,
                'discount' => 0,
            ]],
        ])->assertRedirect();

        $line = \App\Models\ServiceLine::latest('id')->first();
        $this->assertSame('50.00', $line->rate);
        $this->assertNull($line->hp_value);
    }
}
