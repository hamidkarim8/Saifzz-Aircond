<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MultiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_id_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('clients', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('service_visits', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('appointments', 'tenant_id'));
    }
}
