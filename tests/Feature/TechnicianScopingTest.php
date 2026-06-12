<?php

namespace Tests\Feature;

use App\Models\Appointment;
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

    private function appointmentFor(?int $technicianId): Appointment
    {
        $client = Client::create(['name' => 'C', 'phone' => '011-0000000', 'address' => 'KL']);
        return $client->appointments()->create([
            'datetime' => '2026-06-20 10:00:00',
            'service_type' => 'Cleaning',
            'units' => 1,
            'status' => 'pending',
            'technician_id' => $technicianId,
        ]);
    }

    public function test_appointment_visible_to_scopes_to_own(): void
    {
        $alice = User::factory()->technician()->create();
        $this->appointmentFor($alice->id);
        $this->appointmentFor(null);

        $this->assertSame(1, Appointment::visibleTo($alice)->count());
    }

    public function test_appointment_visible_to_returns_all_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $alice = User::factory()->technician()->create();
        $this->appointmentFor($alice->id);
        $this->appointmentFor(null);

        $this->assertSame(2, Appointment::visibleTo($admin)->count());
    }

    private function tech(array $perms = ['view_clients', 'record_service']): User
    {
        return User::factory()->technician()->create(['permissions' => $perms]);
    }

    public function test_store_forces_scoped_technician_to_self_ignoring_payload(): void
    {
        \App\Models\ServiceFee::insert([
            ['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $alice = $this->tech();
        $bob = $this->tech();
        $client = Client::create(['name' => 'X', 'phone' => '011-0000000', 'address' => 'KL']);

        $this->actingAs($alice)->post(route('service-records.store'), [
            'client_mode' => 'existing',
            'client_id' => $client->id,
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'payment_method' => 'Cash',
            'technician_id' => $bob->id, // forged — must be ignored
            'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
        ])->assertRedirect();

        $this->assertSame($alice->id, ServiceVisit::latest('id')->first()->technician_id);
    }

    public function test_admin_store_honors_chosen_technician(): void
    {
        \App\Models\ServiceFee::insert([
            ['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $admin = User::factory()->admin()->create();
        $bob = $this->tech();
        $client = Client::create(['name' => 'X', 'phone' => '011-0000000', 'address' => 'KL']);

        $this->actingAs($admin)->post(route('service-records.store'), [
            'client_mode' => 'existing',
            'client_id' => $client->id,
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'payment_method' => 'Cash',
            'technician_id' => $bob->id,
            'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
        ])->assertRedirect();

        $this->assertSame($bob->id, ServiceVisit::latest('id')->first()->technician_id);
    }

    public function test_show_forbidden_for_non_owner_technician(): void
    {
        $alice = $this->tech();
        $bob = $this->tech();
        $client = Client::create(['name' => 'X', 'phone' => '011-0000000', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-01', 'warranty_months' => 0, 'total_amount' => 100,
            'created_by' => $bob->id, 'technician_id' => $bob->id,
        ]);

        $this->actingAs($alice)->get(route('service-records.show', $visit))->assertForbidden();
        $this->actingAs($bob)->get(route('service-records.show', $visit))->assertOk();
    }

    public function test_index_lists_only_own_visits_for_scoped_tech(): void
    {
        $alice = $this->tech();
        $bob = $this->tech();
        foreach ([$alice->id, $bob->id, $alice->id] as $tid) {
            $client = Client::create(['name' => 'C'.$tid, 'phone' => '011-0000000', 'address' => 'KL']);
            $client->visits()->create([
                'visit_date' => '2026-06-01', 'warranty_months' => 0, 'total_amount' => 100,
                'created_by' => $tid, 'technician_id' => $tid,
            ]);
        }

        $this->actingAs($alice)->get(route('service-records.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('visits.total', 2));
    }
}
