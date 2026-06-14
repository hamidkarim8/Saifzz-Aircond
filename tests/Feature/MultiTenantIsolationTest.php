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

    public function test_store_paths_stamp_creator_tenant(): void
    {
        $this->seed(\Database\Seeders\ServiceTypeSeeder::class);
        \App\Models\ServiceFee::insert([
            ['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $khalid = $this->boss();

        $this->actingAs($khalid)->post(route('clients.store'), [
            'name' => 'New', 'phone' => '012-3456789', 'address' => 'KL',
        ])->assertRedirect();
        $client = \App\Models\Client::where('name', 'New')->first();
        $this->assertSame($khalid->id, $client->tenant_id);

        $this->actingAs($khalid)->post(route('service-records.store'), [
            'client_mode' => 'existing', 'client_id' => $client->id,
            'visit_date' => '2026-06-11', 'warranty_months' => 0, 'payment_method' => 'Cash',
            'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
        ])->assertRedirect();
        $this->assertSame($khalid->id, \App\Models\ServiceVisit::latest('id')->first()->tenant_id);

        $this->actingAs($khalid)->post(route('appointments.store'), [
            'client_id' => $client->id, 'date' => '2026-07-01', 'time' => '09:00',
            'phone' => '012-3456789', 'address' => 'KL',
        ])->assertRedirect();
        $this->assertSame($khalid->id, \App\Models\Appointment::latest('id')->first()->tenant_id);
    }

    public function test_boss_cannot_book_appointment_for_other_tenant_client(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $saifzzClient = $this->clientFor($saifzz);

        $this->actingAs($khalid)->post(route('appointments.store'), [
            'client_id' => $saifzzClient->id, 'date' => '2026-07-01', 'time' => '09:00',
            'phone' => '012-3456789', 'address' => 'KL',
        ])->assertNotFound();
    }

    public function test_technician_store_inherits_boss_tenant(): void
    {
        $khalid = $this->boss();
        $this->actingAs($khalid)->post(route('users.store'), [
            'name' => 'Tech A', 'email' => 'techa@example.com', 'password' => 'password123',
        ])->assertRedirect();

        $tech = \App\Models\User::where('email', 'techa@example.com')->first();
        $this->assertSame($khalid->id, $tech->tenant_id);
    }

    public function test_client_index_and_lookup_are_tenant_scoped(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $kClient = $this->clientFor($khalid);
        $sClient = $this->clientFor($saifzz);

        $this->actingAs($khalid)->get(route('clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('clients.total', 1));

        $res = $this->actingAs($khalid)->getJson(route('clients.lookup'));
        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();
        $this->assertContains($kClient->id, $ids);
        $this->assertNotContains($sClient->id, $ids);
    }

    public function test_boss_cannot_attach_visit_to_other_tenant_client(): void
    {
        $this->seed(\Database\Seeders\ServiceTypeSeeder::class);
        \App\Models\ServiceFee::insert([
            ['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $saifzzClient = $this->clientFor($saifzz);

        $this->actingAs($khalid)->post(route('service-records.store'), [
            'client_mode' => 'existing', 'client_id' => $saifzzClient->id,
            'visit_date' => '2026-06-11', 'warranty_months' => 0, 'payment_method' => 'Cash',
            'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
        ])->assertNotFound();
    }

    public function test_user_index_lists_only_own_tenant_technicians(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $kTech = $this->techFor($khalid, 'kt@example.com');
        $sTech = $this->techFor($saifzz, 'st@example.com');

        $this->actingAs($khalid)->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('users', fn ($users) => collect($users)->pluck('id')->contains($kTech->id)
                    && ! collect($users)->pluck('id')->contains($sTech->id)));
    }

    public function test_boss_cannot_update_other_tenant_technician(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $sTech = $this->techFor($saifzz, 'st2@example.com');

        $this->actingAs($khalid)->put(route('users.update', $sTech), [
            'name' => 'Hacked', 'permissions' => [],
        ])->assertNotFound();
    }

    public function test_boss_cannot_deactivate_other_tenant_technician(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $sTech = $this->techFor($saifzz, 'st3@example.com');

        $this->actingAs($khalid)->patch(route('users.active', $sTech))
            ->assertNotFound();
    }

    public function test_technician_dropdowns_are_tenant_filtered(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $kTech = $this->techFor($khalid, 'kdrop@example.com');
        $sTech = $this->techFor($saifzz, 'sdrop@example.com');

        $this->actingAs($khalid)->get(route('appointments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('technicians',
                fn ($techs) => collect($techs)->pluck('id')->contains($kTech->id)
                    && ! collect($techs)->pluck('id')->contains($sTech->id)));

        $this->actingAs($khalid)->get(route('service-records.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('technicians',
                fn ($techs) => collect($techs)->pluck('id')->contains($kTech->id)
                    && ! collect($techs)->pluck('id')->contains($sTech->id)));
    }

    public function test_client_unit_index_blocked_cross_tenant(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $sClient = $this->clientFor($saifzz);

        $this->actingAs($khalid)->getJson(route('clients.units.index', $sClient))
            ->assertNotFound();
    }

    public function test_reminders_page_is_tenant_scoped(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();

        $kClient = $this->clientFor($khalid);
        $sClient = $this->clientFor($saifzz);
        $kClient->units()->create(['label' => 'KU', 'unit_type' => 'Wall Mounted', 'is_active' => true, 'next_service_date' => now()->subDay()->toDateString()]);
        $sClient->units()->create(['label' => 'SU', 'unit_type' => 'Wall Mounted', 'is_active' => true, 'next_service_date' => now()->subDay()->toDateString()]);

        $this->actingAs($khalid)->get(route('reminders.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($kClient, $sClient) {
                $overdue = collect($page->toArray()['props']['overdue'] ?? []);
                $ids = $overdue->pluck('client_id')->all();
                $this->assertContains($kClient->id, $ids);
                $this->assertNotContains($sClient->id, $ids);
            });
    }

    public function test_boss_cannot_edit_update_or_delete_other_tenant_client(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $sClient = $this->clientFor($saifzz);

        $this->actingAs($khalid)->get(route('clients.edit', $sClient))->assertNotFound();
        $this->actingAs($khalid)->put(route('clients.update', $sClient), [
            'name' => 'HACKED', 'phone' => '012-0000000', 'address' => 'X',
        ])->assertNotFound();
        $this->actingAs($khalid)->delete(route('clients.destroy', $sClient))->assertNotFound();
        $this->assertSame('C', $sClient->fresh()->name); // unchanged
    }

    public function test_service_record_create_preset_is_tenant_scoped(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $sClient = $this->clientFor($saifzz);

        $this->actingAs($khalid)->get(route('service-records.create', ['client' => $sClient->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('presetClient', null));
    }

    public function test_payment_return_blocked_cross_tenant(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $visit = $this->visitFor($this->clientFor($saifzz), $saifzz);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-'.now()->format('Ymd').'-001',
            'amount' => 100, 'method' => 'DuitNow QR', 'status' => 'pending',
        ]);

        $this->actingAs($khalid)->get(route('payments.return', $txn))->assertForbidden();
    }

    public function test_boss_cannot_assign_other_tenant_technician_to_visit(): void
    {
        $this->seed(\Database\Seeders\ServiceTypeSeeder::class);
        \App\Models\ServiceFee::insert([
            ['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $saifzzTech = \App\Models\User::factory()->technician()->create(['tenant_id' => $saifzz->id]);
        $kClient = $this->clientFor($khalid);

        $this->actingAs($khalid)->post(route('service-records.store'), [
            'client_mode' => 'existing', 'client_id' => $kClient->id,
            'visit_date' => '2026-06-11', 'warranty_months' => 0, 'payment_method' => 'Cash',
            'technician_id' => $saifzzTech->id,
            'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
        ])->assertNotFound();
    }

    public function test_boss_cannot_dismiss_other_tenant_reminder(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $sClient = $this->clientFor($saifzz);
        $sClient->units()->create(['label' => 'SU', 'unit_type' => 'Wall Mounted', 'is_active' => true, 'next_service_date' => now()->subDay()->toDateString()]);

        $this->actingAs($khalid)->delete(route('reminders.dismiss', $sClient))->assertNotFound();
        // Saifzz's unit date must be untouched
        $this->assertNotNull($sClient->units()->first()->fresh()->next_service_date);
    }

    public function test_boss_cannot_toggle_contacted_other_tenant_reminder(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $sClient = $this->clientFor($saifzz);

        $this->actingAs($khalid)->patch(route('reminders.contacted', $sClient))->assertNotFound();
    }

    private function techFor(\App\Models\User $boss, string $email): \App\Models\User
    {
        return \App\Models\User::factory()->technician()->create([
            'email' => $email, 'tenant_id' => $boss->id,
        ]);
    }

    private function paidVisitFor(\App\Models\User $boss, float $amount): void
    {
        $client = $this->clientFor($boss);
        $visit = $client->visits()->create([
            'visit_date' => now()->toDateString(), 'warranty_months' => 0, 'total_amount' => $amount,
            'created_by' => $boss->id, 'technician_id' => null, 'tenant_id' => $boss->tenantId(),
        ]);
        $visit->transaction()->create([
            'txn_id' => 'TXN-'.now()->format('Ymd').'-'.str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
            'amount' => $amount, 'method' => 'Cash', 'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    public function test_boss_cannot_read_other_tenant_service_record(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $visit = $this->visitFor($this->clientFor($saifzz), $saifzz);

        $this->actingAs($saifzz)->get(route('service-records.show', $visit))->assertOk();
        $this->actingAs($khalid)->get(route('service-records.show', $visit))->assertForbidden();
    }

    public function test_boss_cannot_read_other_tenant_client_profile(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $sClient = $this->clientFor($saifzz);

        $this->actingAs($khalid)->get(route('clients.show', $sClient))->assertNotFound();
    }

    public function test_report_revenue_is_tenant_scoped(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();
        $this->paidVisitFor($khalid, 100.0);
        $this->paidVisitFor($saifzz, 999.0);

        $reports = app(\App\Services\Reports\ReportService::class);

        $kKpis = $reports->kpis(null, $khalid->tenantId());
        $this->assertSame(100.0, $kKpis['revenue_all_time']);
        $this->assertSame(1, $kKpis['total_clients']);

        $kTxns = $reports->transactions('all', 50, null, $khalid->tenantId());
        $this->assertCount(1, $kTxns);
        $this->assertSame(100.0, $kTxns[0]['amount']);
    }
}
