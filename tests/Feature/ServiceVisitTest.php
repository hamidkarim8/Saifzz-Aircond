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
}
