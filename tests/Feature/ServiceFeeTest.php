<?php

namespace Tests\Feature;

use App\Models\ServiceFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceFeeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function techWithoutFees(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients', 'record_service'],
        ]);
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('fees.index'))->assertRedirect(route('login'));
    }

    public function test_technician_without_edit_fees_is_forbidden(): void
    {
        $this->actingAs($this->techWithoutFees())
            ->get(route('fees.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_price_book(): void
    {
        $this->actingAs($this->admin())->get(route('fees.index'))->assertOk();
    }

    public function test_admin_can_add_a_fixed_fee(): void
    {
        $this->actingAs($this->admin())
            ->post(route('fees.store'), [
                'service_type' => 'Cleaning',
                'option' => 'Ceiling',
                'pricing_mode' => 'fixed_per_unit',
                'rate' => 75,
            ])->assertRedirect();

        $this->assertDatabaseHas('service_fees', ['service_type' => 'Cleaning', 'option' => 'Ceiling', 'rate' => 75]);
    }

    public function test_rate_required_unless_flexible(): void
    {
        $this->actingAs($this->admin())
            ->post(route('fees.store'), [
                'service_type' => 'Cleaning',
                'option' => 'NoRate',
                'pricing_mode' => 'fixed_per_unit',
            ])->assertSessionHasErrors('rate');
    }

    public function test_flexible_fee_allows_null_rate(): void
    {
        $this->actingAs($this->admin())
            ->post(route('fees.store'), [
                'service_type' => 'Repair',
                'option' => null,
                'pricing_mode' => 'flexible',
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('service_fees', ['service_type' => 'Repair', 'pricing_mode' => 'flexible', 'rate' => null]);
    }

    public function test_duplicate_type_option_is_rejected(): void
    {
        ServiceFee::create(['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit']);

        $this->actingAs($this->admin())
            ->post(route('fees.store'), [
                'service_type' => 'Cleaning',
                'option' => 'Wall Mounted',
                'pricing_mode' => 'fixed_per_unit',
                'rate' => 99,
            ])->assertSessionHasErrors('option');
    }

    public function test_admin_can_update_rate(): void
    {
        $fee = ServiceFee::create(['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit']);

        $this->actingAs($this->admin())
            ->put(route('fees.update', $fee), ['pricing_mode' => 'fixed_per_unit', 'rate' => 70])
            ->assertRedirect();

        $this->assertSame('70.00', $fee->fresh()->rate);
    }

    public function test_switching_to_flexible_nulls_the_rate(): void
    {
        $fee = ServiceFee::create(['service_type' => 'Repair', 'option' => null, 'rate' => 50, 'pricing_mode' => 'fixed_per_unit']);

        $this->actingAs($this->admin())
            ->put(route('fees.update', $fee), ['pricing_mode' => 'flexible'])
            ->assertRedirect();

        $this->assertNull($fee->fresh()->rate);
    }

    public function test_admin_can_delete_a_fee(): void
    {
        $fee = ServiceFee::create(['service_type' => 'Cleaning', 'option' => 'Temp', 'rate' => 10, 'pricing_mode' => 'fixed_per_unit']);

        $this->actingAs($this->admin())
            ->delete(route('fees.destroy', $fee))
            ->assertRedirect();

        $this->assertDatabaseMissing('service_fees', ['id' => $fee->id]);
    }
}
