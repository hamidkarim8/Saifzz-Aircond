<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceFee;
use App\Models\ServiceVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceVisitTest extends TestCase
{
    use RefreshDatabase;

    private function recorder(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients', 'record_service'],
        ]);
    }

    private function seedFees(): void
    {
        ServiceFee::insert([
            ['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
            ['service_type' => 'Gas Top-Up', 'option' => 'Half Top-Up', 'rate' => 150, 'pricing_mode' => 'tiered', 'created_at' => now(), 'updated_at' => now()],
            ['service_type' => 'Repair', 'option' => null, 'rate' => null, 'pricing_mode' => 'flexible', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function payload(array $lines, array $overrides = []): array
    {
        $client = Client::create(['name' => 'Existing', 'phone' => '012-3456789', 'address' => 'KL']);

        return array_merge([
            'client_mode' => 'existing',
            'client_id' => $client->id,
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'payment_method' => 'Cash',
            'lines' => $lines,
        ], $overrides);
    }

    public function test_guest_redirected_and_unpermitted_forbidden(): void
    {
        $this->get(route('service-records.create'))->assertRedirect(route('login'));

        $tech = User::factory()->create(['role' => User::ROLE_TECHNICIAN, 'permissions' => ['view_clients']]);
        $this->actingAs($tech)->get(route('service-records.create'))->assertForbidden();
    }

    public function test_recorder_can_open_builder(): void
    {
        $this->seedFees();
        $this->actingAs($this->recorder())->get(route('service-records.create'))->assertOk();
    }

    public function test_rate_is_snapshotted_from_fee_book_ignoring_client_input(): void
    {
        $this->seedFees();
        $data = $this->payload([
            ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 2, 'rate' => 5, 'discount' => 10], // tampered rate=5
        ]);

        $this->actingAs($this->recorder())->post(route('service-records.store'), $data)->assertRedirect();

        $visit = ServiceVisit::with('lines', 'transaction')->latest('id')->first();
        $line = $visit->lines->first();
        $this->assertSame('60.00', $line->rate);       // R1 — server fee, not the 5 sent
        $this->assertSame('110.00', $line->subtotal);   // R8 — 60*2-10
        $this->assertSame('110.00', $visit->total_amount);
        $this->assertSame('pending', $visit->transaction->status); // R4
        $this->assertMatchesRegularExpression('/^TXN-\d{8}-001$/', $visit->transaction->txn_id);
        $this->assertSame('110.00', $visit->transaction->amount);
    }

    public function test_repair_uses_manual_rate_and_drops_unit_type_and_notes(): void
    {
        $this->seedFees();
        $data = $this->payload([
            ['service_type' => 'Repair', 'repair_desc' => 'Fix compressor', 'units' => 1, 'rate' => 200, 'discount' => 0, 'unit_type' => 'Wall Mounted', 'notes' => 'drop me'],
        ]);

        $this->actingAs($this->recorder())->post(route('service-records.store'), $data)->assertRedirect();

        $line = ServiceVisit::latest('id')->first()->lines->first();
        $this->assertSame('200.00', $line->rate);     // flexible manual
        $this->assertNull($line->unit_type);          // R3
        $this->assertNull($line->notes);              // R3
        $this->assertSame('Fix compressor', $line->repair_desc);
    }

    public function test_next_service_date_stripped_for_gas(): void
    {
        $this->seedFees();
        $data = $this->payload([
            ['service_type' => 'Gas Top-Up', 'gas_option' => 'Half Top-Up', 'units' => 1, 'next_service_date' => '2026-12-01'],
        ]);

        $this->actingAs($this->recorder())->post(route('service-records.store'), $data)->assertRedirect();

        $line = ServiceVisit::latest('id')->first()->lines->first();
        $this->assertSame('150.00', $line->rate); // tiered fee
        $this->assertNull($line->next_service_date); // R2
    }

    public function test_warranty_end_is_derived(): void
    {
        $this->seedFees();
        $data = $this->payload(
            [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
            ['warranty_months' => 3],
        );

        $this->actingAs($this->recorder())->post(route('service-records.store'), $data)->assertRedirect();

        $visit = ServiceVisit::latest('id')->first();
        $this->assertSame('2026-09-11', $visit->warranty_end->format('Y-m-d')); // R5
    }

    public function test_validation_requires_at_least_one_line_and_conditional_fields(): void
    {
        $this->seedFees();
        $recorder = $this->recorder();

        $this->actingAs($recorder)
            ->post(route('service-records.store'), $this->payload([]))
            ->assertSessionHasErrors('lines');

        $this->actingAs($recorder)
            ->post(route('service-records.store'), $this->payload([
                ['service_type' => 'Cleaning', 'units' => 1], // missing unit_type
            ]))
            ->assertSessionHasErrors('lines.0.unit_type');

        $this->actingAs($recorder)
            ->post(route('service-records.store'), $this->payload([
                ['service_type' => 'Repair', 'units' => 1], // missing desc + rate
            ]))
            ->assertSessionHasErrors(['lines.0.repair_desc', 'lines.0.rate']);
    }

    public function test_new_client_is_created_with_serial(): void
    {
        $this->seedFees();
        $data = [
            'client_mode' => 'new',
            'new_client' => ['name' => 'Fresh', 'phone' => '013-1112222', 'address' => 'PJ'],
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'payment_method' => 'Cash',
            'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
        ];

        $this->actingAs($this->recorder())->post(route('service-records.store'), $data)->assertRedirect();

        $client = Client::firstWhere('name', 'Fresh');
        $this->assertNotNull($client);
        $this->assertSame('000001', $client->serial_no);
        $this->assertSame(1, $client->visits()->count());
    }

    public function test_client_lookup_returns_json(): void
    {
        Client::create(['name' => 'Findable', 'phone' => '012-7778888', 'address' => 'KL']);

        $this->actingAs($this->recorder())
            ->getJson(route('clients.lookup', ['q' => 'Findable']))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Findable']);
    }

    // ---- index sort/search/per_page tests ----

    private function makeVisit(string $clientName, string $visitDate, float $total = 100.0): ServiceVisit
    {
        $client = Client::create(['name' => $clientName, 'phone' => '011-0000001', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => $visitDate,
            'warranty_months' => 0,
            'total_amount' => $total,
            'created_by' => null,
        ]);
        $visit->transaction()->create([
            'txn_id' => 'TXN-' . str_replace('-', '', $visitDate) . '-' . str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
            'amount' => $total,
            'method' => 'Cash',
            'status' => 'pending',
        ]);

        return $visit;
    }

    public function test_index_returns_paginated_visits_sorted_by_visit_date_desc_with_per_page(): void
    {
        $this->makeVisit('Alpha Client', '2026-01-01', 50.00);
        $this->makeVisit('Beta Client', '2026-03-01', 150.00);
        $this->makeVisit('Gamma Client', '2026-02-01', 100.00);

        $this->actingAs($this->recorder())
            ->get(route('service-records.index', ['sort' => 'visit_date', 'dir' => 'desc', 'per_page' => 5]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('visits.per_page', 5)
                ->where('visits.total', 3)
                ->has('visits.data.0', fn ($row) => $row->where('client.name', 'Beta Client')->etc())
                ->has('visits.data.1', fn ($row) => $row->where('client.name', 'Gamma Client')->etc())
                ->has('visits.data.2', fn ($row) => $row->where('client.name', 'Alpha Client')->etc()));
    }

    public function test_index_sorts_by_total_amount_asc(): void
    {
        $this->makeVisit('Alpha Client', '2026-01-01', 50.00);
        $this->makeVisit('Beta Client', '2026-03-01', 150.00);
        $this->makeVisit('Gamma Client', '2026-02-01', 100.00);

        $this->actingAs($this->recorder())
            ->get(route('service-records.index', ['sort' => 'total', 'dir' => 'asc', 'per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('visits.total', 3)
                ->where('visits.data.0.total_amount', '50.00')
                ->where('visits.data.1.total_amount', '100.00')
                ->where('visits.data.2.total_amount', '150.00'));
    }

    public function test_index_search_by_client_name(): void
    {
        $this->makeVisit('Hamid Karim', '2026-01-01', 80.00);
        $this->makeVisit('Zainab Abdullah', '2026-01-02', 90.00);

        $this->actingAs($this->recorder())
            ->get(route('service-records.index', ['search' => 'hamid', 'per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('visits.total', 1)
                ->where('visits.data.0.client.name', 'Hamid Karim'));
    }

    public function test_index_search_by_txn_id(): void
    {
        $visit1 = $this->makeVisit('Client One', '2026-01-01', 80.00);
        $this->makeVisit('Client Two', '2026-01-02', 90.00);
        $txnId = $visit1->transaction->txn_id;

        $this->actingAs($this->recorder())
            ->get(route('service-records.index', ['search' => $txnId, 'per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('visits.total', 1)
                ->where('visits.data.0.transaction.txn_id', $txnId));
    }

    public function test_index_ignores_unknown_sort_column(): void
    {
        $this->makeVisit('Client A', '2026-01-01', 50.00);

        $this->actingAs($this->recorder())
            ->get(route('service-records.index', ['sort' => 'injected_col', 'dir' => 'asc']))
            ->assertOk();
    }
}
