<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TechnicianScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_visits_have_technician_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('service_visits', 'technician_id'));
    }

    public function test_appointments_have_technician_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('appointments', 'technician_id'));
    }

    public function test_admin_sees_all_data(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->seesAllData());
    }

    public function test_default_technician_is_scoped(): void
    {
        $tech = User::factory()->technician()->create(); // no permissions override → DEFAULT set
        $this->assertFalse($tech->seesAllData());
    }

    public function test_technician_with_view_all_data_sees_all(): void
    {
        $tech = User::factory()->technician()->create([
            'permissions' => ['view_clients', 'view_all_data'],
        ]);
        $this->assertTrue($tech->seesAllData());
    }

    public function test_view_all_data_is_in_catalogue_but_not_default(): void
    {
        $this->assertContains('view_all_data', User::PERMISSIONS);
        $this->assertNotContains('view_all_data', User::DEFAULT_TECHNICIAN_PERMISSIONS);
        $this->assertNotContains('view_all_data', User::ADMIN_ONLY_PERMISSIONS);
    }

    private function visitFor(?int $technicianId): ServiceVisit
    {
        $client = Client::create(['name' => 'C', 'phone' => '011-0000000', 'address' => 'KL']);
        return $client->visits()->create([
            'visit_date' => '2026-06-01',
            'warranty_months' => 0,
            'total_amount' => 100,
            'created_by' => null,
            'technician_id' => $technicianId,
        ]);
    }

    public function test_visible_to_scopes_technician_to_own_visits(): void
    {
        $alice = User::factory()->technician()->create();
        $bob = User::factory()->technician()->create();
        $this->visitFor($alice->id);
        $this->visitFor($bob->id);

        $this->assertSame(1, ServiceVisit::visibleTo($alice)->count());
    }

    public function test_visible_to_returns_all_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $alice = User::factory()->technician()->create();
        $this->visitFor($alice->id);
        $this->visitFor(null);

        $this->assertSame(2, ServiceVisit::visibleTo($admin)->count());
    }
}
