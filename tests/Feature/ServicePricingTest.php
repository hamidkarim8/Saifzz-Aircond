<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceFee;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePricingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients', 'record_service', 'collect_payment'],
        ]);
    }

    private function payload(array $lines): array
    {
        $client = Client::create(['name' => 'Test Client', 'phone' => '012-3456789', 'address' => 'KL']);

        return [
            'client_mode'     => 'existing',
            'client_id'       => $client->id,
            'visit_date'      => '2026-06-18',
            'warranty_months' => 0,
            'payment_method'  => 'DuitNow QR',
            'lines'           => $lines,
        ];
    }

    public function test_flat_line_snapshots_unit_type_price(): void
    {
        $type = ServiceType::create(['name' => 'Gas Top-Up', 'pricing_mode' => 'flat', 'requires_next_service' => false]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => '20 PSI', 'hp_value' => null, 'price' => 80]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Gas Top-Up', 'unit_type' => '20 PSI', 'units' => 1, 'rate' => 999],
        ]))->assertRedirect();

        $this->assertDatabaseHas('service_lines', ['service_type' => 'Gas Top-Up', 'unit_type' => '20 PSI', 'rate' => 80]);
    }

    public function test_hp_tiered_line_snapshots_per_unit_type_price(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'hp_tiered', 'requires_next_service' => true]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => 'Cassette', 'hp_value' => 1.5, 'price' => 85]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Cleaning', 'unit_type' => 'Cassette', 'hp_value' => 1.5, 'units' => 1],
        ]))->assertRedirect();

        $this->assertDatabaseHas('service_lines', ['service_type' => 'Cleaning', 'unit_type' => 'Cassette', 'hp_value' => 1.5, 'rate' => 85]);
    }

    public function test_flexible_line_uses_submitted_rate_and_keeps_description(): void
    {
        ServiceType::create(['name' => 'Repair', 'pricing_mode' => 'flexible', 'requires_next_service' => false]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Repair', 'repair_desc' => 'Fixed compressor', 'rate' => 250, 'units' => 1],
        ]))->assertRedirect();

        $this->assertDatabaseHas('service_lines', ['service_type' => 'Repair', 'rate' => 250, 'repair_desc' => 'Fixed compressor']);
    }

    public function test_flexible_requires_price_and_description(): void
    {
        ServiceType::create(['name' => 'Repair', 'pricing_mode' => 'flexible', 'requires_next_service' => false]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Repair', 'units' => 1],
        ]))->assertSessionHasErrors(['lines.0.rate', 'lines.0.repair_desc']);
    }

    public function test_unknown_unit_type_rejected(): void
    {
        $type = ServiceType::create(['name' => 'Gas Top-Up', 'pricing_mode' => 'flat', 'requires_next_service' => false]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => '20 PSI', 'hp_value' => null, 'price' => 80]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Gas Top-Up', 'unit_type' => 'Nonexistent', 'units' => 1],
        ]))->assertSessionHasErrors('lines.0.unit_type');
    }

    public function test_hp_tiered_requires_valid_hp(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'hp_tiered', 'requires_next_service' => true]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => 'Wall Mounted', 'hp_value' => 1.0, 'price' => 50]);

        $this->actingAs($this->admin())->post(route('service-records.store'), $this->payload([
            ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'hp_value' => 9.9, 'units' => 1],
        ]))->assertSessionHasErrors('lines.0.hp_value');
    }
}
