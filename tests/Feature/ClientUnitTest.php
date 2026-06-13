<?php

namespace Tests\Feature;

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
}
