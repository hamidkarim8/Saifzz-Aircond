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

    public function test_visit_scope_isolates_tenants(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $this->visitFor($this->clientFor($khalid), $khalid);
        $this->visitFor($this->clientFor($saifzz), $saifzz);

        $this->assertSame(1, \App\Models\ServiceVisit::visibleTo($khalid)->count());
        $this->assertSame(1, \App\Models\ServiceVisit::visibleTo($saifzz)->count());
    }

    public function test_appointment_scope_isolates_tenants(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $this->appointmentFor($this->clientFor($khalid), $khalid);
        $this->appointmentFor($this->clientFor($saifzz), $saifzz);

        $this->assertSame(1, \App\Models\Appointment::visibleTo($khalid)->count());
        $this->assertSame(1, \App\Models\Appointment::visibleTo($saifzz)->count());
    }

    public function test_client_scope_isolates_tenants(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $this->clientFor($khalid);
        $this->clientFor($saifzz);

        $this->assertSame(1, \App\Models\Client::visibleTo($khalid)->count());
        $this->assertSame(1, \App\Models\Client::visibleTo($saifzz)->count());
    }

    /** Create a boss admin that is its own tenant root. */
    private function boss(): \App\Models\User
    {
        $boss = \App\Models\User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);

        return $boss->fresh();
    }

    private function clientFor(\App\Models\User $boss): \App\Models\Client
    {
        return \App\Models\Client::create([
            'name' => 'C', 'phone' => '011-0000000', 'address' => 'KL',
            'tenant_id' => $boss->tenantId(),
        ]);
    }

    private function visitFor(\App\Models\Client $client, \App\Models\User $boss): \App\Models\ServiceVisit
    {
        return $client->visits()->create([
            'visit_date' => '2026-06-01', 'warranty_months' => 0, 'total_amount' => 100,
            'created_by' => $boss->id, 'technician_id' => null,
            'tenant_id' => $boss->tenantId(),
        ]);
    }

    private function appointmentFor(\App\Models\Client $client, \App\Models\User $boss): \App\Models\Appointment
    {
        return $client->appointments()->create([
            'datetime' => '2026-06-20 10:00:00', 'status' => 'pending',
            'technician_id' => null, 'tenant_id' => $boss->tenantId(),
        ]);
    }
}
