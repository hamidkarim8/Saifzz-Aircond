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
}
