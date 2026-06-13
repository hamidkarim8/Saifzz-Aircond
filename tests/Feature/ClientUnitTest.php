<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientUnit;
use App\Models\ServiceLine;
use App\Models\ServiceVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_units_table_exists_with_correct_columns(): void
    {
        $this->assertTrue(Schema::hasTable('client_units'));
        foreach (['id', 'client_id', 'label', 'unit_type', 'hp', 'brand', 'model',
                  'serial_no', 'refrigerant_type', 'next_service_date', 'next_service_type',
                  'is_active', 'notes', 'created_at', 'updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('client_units', $col), "Missing column: $col");
        }
    }

    public function test_service_lines_has_unit_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('service_lines', 'unit_id'));
    }

    public function test_client_has_many_units(): void
    {
        $client = Client::create(['name' => 'T', 'phone' => '011-11111111', 'address' => 'A']);
        ClientUnit::create(['client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);
        ClientUnit::create(['client_id' => $client->id, 'label' => 'BR2', 'unit_type' => 'Cassette', 'is_active' => true]);

        $this->assertCount(2, $client->units);
    }

    public function test_service_line_belongs_to_unit(): void
    {
        $client = Client::create(['name' => 'T', 'phone' => '011-11111111', 'address' => 'A']);
        $unit = ClientUnit::create(['client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);
        $visit = ServiceVisit::create(['client_id' => $client->id, 'visit_date' => '2026-06-01', 'warranty_months' => 0]);
        $line = ServiceLine::create([
            'visit_id' => $visit->id, 'unit_id' => $unit->id,
            'service_type' => 'Cleaning', 'units' => 1, 'rate' => 80, 'discount' => 0,
        ]);

        $this->assertEquals($unit->id, $line->fresh()->unit->id);
    }

    public function test_manage_units_in_permission_catalogue(): void
    {
        $this->assertContains('manage_units', User::PERMISSIONS);
    }

    public function test_manage_units_default_for_technician(): void
    {
        $this->assertContains('manage_units', User::DEFAULT_TECHNICIAN_PERMISSIONS);
    }

    private function makeClient(): Client
    {
        return Client::create(['name' => 'Test', 'phone' => '011-22334455', 'address' => 'KL']);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeTech(): User
    {
        return User::factory()->technician()->create();
    }

    public function test_guest_cannot_store_unit(): void
    {
        $client = $this->makeClient();
        $this->postJson(route('clients.units.store', $client), ['label' => 'BR1', 'unit_type' => 'Wall Mounted'])
            ->assertRedirect(route('login'));
    }

    public function test_admin_can_store_unit(): void
    {
        $client = $this->makeClient();
        $this->actingAs($this->makeAdmin())
            ->postJson(route('clients.units.store', $client), [
                'label' => 'Master Bedroom', 'unit_type' => 'Wall Mounted',
                'hp' => 1.0, 'brand' => 'LG', 'model' => 'S12EQ', 'serial_no' => 'ABC123',
                'refrigerant_type' => 'R32', 'notes' => null,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_units', ['client_id' => $client->id, 'label' => 'Master Bedroom']);
    }

    public function test_tech_with_manage_units_can_store_unit(): void
    {
        $client = $this->makeClient();
        $tech = $this->makeTech();
        $this->assertTrue($tech->hasPermission('manage_units'));

        $this->actingAs($tech)
            ->postJson(route('clients.units.store', $client), ['label' => 'BR1', 'unit_type' => 'Cassette'])
            ->assertRedirect();

        $this->assertDatabaseHas('client_units', ['label' => 'BR1', 'unit_type' => 'Cassette']);
    }

    public function test_admin_can_update_unit(): void
    {
        $client = $this->makeClient();
        $unit = ClientUnit::create(['client_id' => $client->id, 'label' => 'Old', 'unit_type' => 'Wall Mounted', 'is_active' => true]);

        $this->actingAs($this->makeAdmin())
            ->putJson(route('clients.units.update', [$client, $unit]), ['label' => 'New Label', 'unit_type' => 'Cassette'])
            ->assertRedirect();

        $this->assertSame('New Label', $unit->fresh()->label);
    }

    public function test_admin_can_deactivate_unit(): void
    {
        $client = $this->makeClient();
        $unit = ClientUnit::create(['client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);

        $this->actingAs($this->makeAdmin())
            ->patchJson(route('clients.units.deactivate', [$client, $unit]))
            ->assertRedirect();

        $this->assertFalse($unit->fresh()->is_active);
    }

    public function test_unit_belonging_to_other_client_returns_404(): void
    {
        $clientA = $this->makeClient();
        $clientB = $this->makeClient();
        $unit = ClientUnit::create(['client_id' => $clientA->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);

        $this->actingAs($this->makeAdmin())
            ->patchJson(route('clients.units.deactivate', [$clientB, $unit]))
            ->assertNotFound();
    }

    public function test_units_index_returns_json_for_client(): void
    {
        $client = $this->makeClient();
        ClientUnit::create(['client_id' => $client->id, 'label' => 'BR1', 'unit_type' => 'Wall Mounted', 'is_active' => true]);
        ClientUnit::create(['client_id' => $client->id, 'label' => 'BR2', 'unit_type' => 'Wall Mounted', 'is_active' => false]); // inactive

        $response = $this->actingAs($this->makeAdmin())
            ->getJson(route('clients.units.index', $client));

        $response->assertOk()->assertJsonCount(1); // only active
    }

    public function test_guest_cannot_access_units_index(): void
    {
        $client = $this->makeClient();
        $this->getJson(route('clients.units.index', $client))->assertRedirect(route('login'));
    }

    public function test_user_without_manage_units_cannot_store_unit(): void
    {
        $client = $this->makeClient();
        $user = User::factory()->technician()->create(['permissions' => ['view_clients', 'record_service']]);

        $this->actingAs($user)
            ->postJson(route('clients.units.store', $client), ['label' => 'BR1', 'unit_type' => 'Wall Mounted'])
            ->assertForbidden();
    }
}
