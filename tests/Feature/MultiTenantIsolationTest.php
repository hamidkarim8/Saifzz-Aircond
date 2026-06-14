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

    public function test_seeded_bosses_are_their_own_tenant_root(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $khalid = \App\Models\User::where('email', 'khalid@admin.com')->first();
        $saifzz = \App\Models\User::where('email', 'saifzz@admin.com')->first();

        $this->assertSame($khalid->id, $khalid->tenantId());
        $this->assertSame($saifzz->id, $saifzz->tenantId());
        $this->assertNotSame($khalid->tenantId(), $saifzz->tenantId());
    }
}
