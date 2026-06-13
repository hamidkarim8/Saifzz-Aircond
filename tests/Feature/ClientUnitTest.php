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
}
