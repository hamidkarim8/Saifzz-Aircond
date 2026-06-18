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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ServiceTypeSeeder::class);
    }

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
        $cleaning = \App\Models\ServiceType::where('name', 'Cleaning')->first();
        $cleaning->update(['pricing_mode' => 'flat']);
        \App\Models\ServiceFee::firstOrCreate(
            ['service_type_id' => $cleaning->id, 'unit_type' => 'Wall Mounted', 'hp_value' => null],
            ['price' => 60]
        );
        $alice = $this->tech();
        $bob = $this->tech();
        $client = Client::create(['name' => 'X', 'phone' => '011-0000000', 'address' => 'KL']);

        $this->actingAs($alice)->post(route('service-records.store'), [
            'client_mode' => 'existing',
            'client_id' => $client->id,
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'payment_method' => 'DuitNow QR',
            'technician_id' => $bob->id, // forged — must be ignored
            'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
        ])->assertRedirect();

        $this->assertSame($alice->id, ServiceVisit::latest('id')->first()->technician_id);
    }

    public function test_admin_store_honors_chosen_technician(): void
    {
        $cleaning = \App\Models\ServiceType::where('name', 'Cleaning')->first();
        $cleaning->update(['pricing_mode' => 'flat']);
        \App\Models\ServiceFee::firstOrCreate(
            ['service_type_id' => $cleaning->id, 'unit_type' => 'Wall Mounted', 'hp_value' => null],
            ['price' => 60]
        );
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

    public function test_appointment_index_scoped_to_own(): void
    {
        $alice = User::factory()->technician()->create(['permissions' => ['view_clients', 'set_appointment']]);
        $this->appointmentFor($alice->id);
        $this->appointmentFor(null); // unassigned

        $this->actingAs($alice)->get(route('appointments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('table.total', 1));
    }

    public function test_store_appointment_forces_scoped_tech_to_self(): void
    {
        $alice = User::factory()->technician()->create(['permissions' => ['view_clients', 'set_appointment']]);
        $bob = User::factory()->technician()->create(['permissions' => ['set_appointment']]);
        $client = Client::create(['name' => 'X', 'phone' => '012-3456789', 'address' => 'KL']);

        $this->actingAs($alice)->post(route('appointments.store'), [
            'client_id' => $client->id,
            'date' => '2026-07-01',
            'time' => '09:00',
            'phone' => '012-3456789',
            'address' => 'KL',
            'technician_id' => $bob->id, // forged
        ])->assertRedirect();

        $this->assertSame($alice->id, Appointment::latest('id')->first()->technician_id);
    }

    public function test_export_scoped_to_technician(): void
    {
        $alice = User::factory()->technician()->create(['permissions' => ['export_data']]);
        $bob = User::factory()->technician()->create();

        foreach ([[$alice->id, 100.0], [$bob->id, 200.0]] as [$tid, $amt]) {
            $client = Client::create(['name' => 'C'.$tid, 'phone' => '011-0000000', 'address' => 'KL']);
            $visit = $client->visits()->create([
                'visit_date' => '2026-06-01', 'warranty_months' => 0, 'total_amount' => $amt,
                'created_by' => $tid, 'technician_id' => $tid,
            ]);
            $visit->transaction()->create([
                'txn_id' => 'TXN-20260601-'.str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
                'amount' => $amt, 'method' => 'Cash', 'status' => 'paid', 'paid_at' => now(),
            ]);
        }

        $res = $this->actingAs($alice)->get(route('reports.transactions.export', ['period' => 'all']));
        $res->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringContainsString('100.00', $csv);
        $this->assertStringNotContainsString('200.00', $csv);
    }

    private function paidTxnFor(int $techId): \App\Models\Transaction
    {
        $client = Client::create(['name' => 'C'.$techId, 'phone' => '011-0000000', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-01', 'warranty_months' => 0, 'total_amount' => 100,
            'created_by' => $techId, 'technician_id' => $techId,
        ]);
        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260601-'.str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
            'amount' => 100, 'method' => 'Cash', 'status' => 'pending',
        ]);
    }

    public function test_payment_show_forbidden_for_non_owner(): void
    {
        $alice = User::factory()->technician()->create(['permissions' => ['collect_payment']]);
        $bob = User::factory()->technician()->create(['permissions' => ['collect_payment']]);
        $txn = $this->paidTxnFor($bob->id);

        $this->actingAs($alice)->get(route('payments.show', $txn))->assertForbidden();
        $this->actingAs($bob)->get(route('payments.show', $txn))->assertOk();
    }

    public function test_document_invoice_forbidden_for_non_owner(): void
    {
        $alice = User::factory()->technician()->create(['permissions' => ['view_clients']]);
        $bob = User::factory()->technician()->create(['permissions' => ['view_clients']]);
        $txn = $this->paidTxnFor($bob->id);

        $this->actingAs($alice)->get(route('documents.invoice', $txn))->assertForbidden();
    }

    // ---- Issue 1: ClientController::show history scoping ----

    public function test_client_show_scopes_visit_and_appointment_history(): void
    {
        $alice = $this->tech(['view_clients', 'record_service']);
        $bob = $this->tech(['view_clients']);
        $client = Client::create(['name' => 'Shared', 'phone' => '011-0000000', 'address' => 'KL']);
        $client->visits()->create(['visit_date' => '2026-06-01', 'warranty_months' => 0, 'total_amount' => 100, 'created_by' => $alice->id, 'technician_id' => $alice->id]);
        $client->visits()->create(['visit_date' => '2026-06-02', 'warranty_months' => 0, 'total_amount' => 200, 'created_by' => $bob->id, 'technician_id' => $bob->id]);
        $client->appointments()->create(['datetime' => '2026-06-20 10:00:00', 'status' => 'pending', 'technician_id' => $alice->id]);
        $client->appointments()->create(['datetime' => '2026-06-21 10:00:00', 'status' => 'pending', 'technician_id' => $bob->id]);

        // Alice (scoped) sees only her own visit + appointment on the profile.
        $this->actingAs($alice)->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('client.visits', 1)
                ->has('client.appointments', 1)
                ->where('client.visits.0.technician_id', $alice->id));

        // Admin sees both visits + both appointments.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('clients.show', $client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('client.visits', 2)
                ->has('client.appointments', 2));
    }

    // ---- Issue 2: AppointmentController update/updateStatus unscoped ----

    private function aptTech(): User
    {
        return User::factory()->technician()->create(['permissions' => ['view_clients', 'set_appointment']]);
    }

    public function test_appointment_update_forbidden_for_non_owner(): void
    {
        $alice = $this->aptTech();
        $bob = $this->aptTech();
        $client = Client::create(['name' => 'X', 'phone' => '012-3456789', 'address' => 'KL']);
        $appt = $client->appointments()->create(['datetime' => '2026-07-01 09:00:00', 'status' => 'pending', 'phone' => '012-3456789', 'address' => 'KL', 'technician_id' => $bob->id]);

        $this->actingAs($alice)->put(route('appointments.update', $appt), [
            'client_id' => $client->id, 'date' => '2026-07-02', 'time' => '10:00',
            'phone' => '012-3456789', 'address' => 'KL',
        ])->assertForbidden();
    }

    public function test_appointment_status_update_forbidden_for_non_owner(): void
    {
        $alice = $this->aptTech();
        $bob = $this->aptTech();
        $client = Client::create(['name' => 'X', 'phone' => '012-3456789', 'address' => 'KL']);
        $appt = $client->appointments()->create(['datetime' => '2026-07-01 09:00:00', 'status' => 'pending', 'phone' => '012-3456789', 'address' => 'KL', 'technician_id' => $bob->id]);

        $this->actingAs($alice)->patch(route('appointments.status', $appt), ['status' => 'completed'])
            ->assertForbidden();
    }

    // ---- Issue 3: update() must honour technician_id for all-data users ----

    public function test_admin_can_reassign_appointment_technician(): void
    {
        $admin = User::factory()->admin()->create();
        $bob = $this->aptTech();
        $client = Client::create(['name' => 'X', 'phone' => '012-3456789', 'address' => 'KL']);
        $appt = $client->appointments()->create(['datetime' => '2026-07-01 09:00:00', 'status' => 'pending', 'phone' => '012-3456789', 'address' => 'KL', 'technician_id' => null]);

        $this->actingAs($admin)->put(route('appointments.update', $appt), [
            'client_id' => $client->id, 'date' => '2026-07-01', 'time' => '09:00',
            'phone' => '012-3456789', 'address' => 'KL',
            'technician_id' => $bob->id,
        ])->assertRedirect();

        $this->assertSame($bob->id, $appt->fresh()->technician_id);
    }
}
