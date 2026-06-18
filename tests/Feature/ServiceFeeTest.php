<?php

namespace Tests\Feature;

use App\Models\ServiceFee;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceFeeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function techWithoutEditFees(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients', 'record_service'],
        ]);
    }

    public function test_sync_saves_flat_unit_type_rows(): void
    {
        $type = ServiceType::create(['name' => 'Gas Top-Up', 'pricing_mode' => 'flat', 'requires_next_service' => false]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'flat',
                'fees' => [
                    ['unit_type' => '20 PSI', 'hp_value' => null, 'price' => 80],
                    ['unit_type' => 'Full Top-Up', 'hp_value' => null, 'price' => 280],
                ],
            ])->assertRedirect();

        $this->assertDatabaseHas('service_fees', ['service_type_id' => $type->id, 'unit_type' => '20 PSI', 'hp_value' => null, 'price' => 80]);
        $this->assertEquals('flat', $type->fresh()->pricing_mode);
        $this->assertCount(2, ServiceFee::where('service_type_id', $type->id)->get());
    }

    public function test_sync_saves_hp_tiered_per_unit_type(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'flat', 'requires_next_service' => true]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'hp_tiered',
                'fees' => [
                    ['unit_type' => 'Wall Mounted', 'hp_value' => 1.0, 'price' => 50],
                    ['unit_type' => 'Wall Mounted', 'hp_value' => 1.5, 'price' => 60],
                    ['unit_type' => 'Cassette', 'hp_value' => 1.0, 'price' => 70],
                ],
            ])->assertRedirect();

        $this->assertEquals('hp_tiered', $type->fresh()->pricing_mode);
        $this->assertDatabaseHas('service_fees', ['service_type_id' => $type->id, 'unit_type' => 'Cassette', 'hp_value' => 1.0, 'price' => 70]);
        $this->assertCount(3, ServiceFee::where('service_type_id', $type->id)->get());
    }

    public function test_sync_replaces_existing_rows(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'flat', 'requires_next_service' => true]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => 'Old', 'hp_value' => null, 'price' => 999]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'flat',
                'fees' => [['unit_type' => 'New', 'hp_value' => null, 'price' => 10]],
            ])->assertRedirect();

        $this->assertDatabaseMissing('service_fees', ['service_type_id' => $type->id, 'unit_type' => 'Old']);
        $this->assertDatabaseHas('service_fees', ['service_type_id' => $type->id, 'unit_type' => 'New']);
    }

    public function test_flexible_clears_all_rows(): void
    {
        $type = ServiceType::create(['name' => 'Repair', 'pricing_mode' => 'flat', 'requires_next_service' => false]);
        ServiceFee::create(['service_type_id' => $type->id, 'unit_type' => 'x', 'hp_value' => null, 'price' => 5]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), ['pricing_mode' => 'flexible', 'fees' => []])
            ->assertRedirect();

        $this->assertEquals('flexible', $type->fresh()->pricing_mode);
        $this->assertCount(0, ServiceFee::where('service_type_id', $type->id)->get());
    }

    public function test_hp_tiered_requires_hp_value(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'flat', 'requires_next_service' => true]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'hp_tiered',
                'fees' => [['unit_type' => 'Wall Mounted', 'hp_value' => null, 'price' => 50]],
            ])->assertSessionHasErrors('fees.0.hp_value');
    }

    public function test_flat_rejects_hp_value(): void
    {
        $type = ServiceType::create(['name' => 'Gas Top-Up', 'pricing_mode' => 'flat', 'requires_next_service' => false]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'flat',
                'fees' => [['unit_type' => '20 PSI', 'hp_value' => 1.0, 'price' => 50]],
            ])->assertSessionHasErrors('fees.0.hp_value');
    }

    public function test_duplicate_unit_type_hp_pair_rejected(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'flat', 'requires_next_service' => true]);

        $this->actingAs($this->admin())
            ->put(route('service-types.fees.sync', $type), [
                'pricing_mode' => 'hp_tiered',
                'fees' => [
                    ['unit_type' => 'Wall Mounted', 'hp_value' => 1.0, 'price' => 50],
                    ['unit_type' => 'Wall Mounted', 'hp_value' => 1.0, 'price' => 60],
                ],
            ])->assertSessionHasErrors('fees');
    }

    public function test_non_edit_fees_user_forbidden(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning', 'pricing_mode' => 'flat', 'requires_next_service' => true]);

        $this->actingAs($this->techWithoutEditFees())
            ->put(route('service-types.fees.sync', $type), ['pricing_mode' => 'flexible', 'fees' => []])
            ->assertForbidden();
    }
}
