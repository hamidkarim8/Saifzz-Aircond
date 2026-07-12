<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceFee;
use App\Models\ServiceVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceVisitUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ServiceTypeSeeder::class);
        $this->seedFees();
    }

    private function recorder(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients', 'record_service'],
        ]);
    }

    private function cashRecorder(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients', 'record_service', 'collect_payment'],
        ]);
    }

    private function seedFees(): void
    {
        $cleaning = \App\Models\ServiceType::where('name', 'Cleaning')->first();
        $cleaning->update(['pricing_mode' => 'flat']);
        ServiceFee::firstOrCreate(
            ['service_type_id' => $cleaning->id, 'unit_type' => 'Wall Mounted', 'hp_value' => null],
            ['price' => 60]
        );

        $gas = \App\Models\ServiceType::where('name', 'Gas Top-Up')->first();
        ServiceFee::firstOrCreate(
            ['service_type_id' => $gas->id, 'unit_type' => 'Half Top-Up', 'hp_value' => null],
            ['price' => 150]
        );
    }

    /** Build a pending visit owned by $owner with one Cleaning line (rate 60). */
    private function makePendingVisit(User $owner): ServiceVisit
    {
        $client = Client::create(['name' => 'Existing', 'phone' => '012-3456789', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'created_by' => $owner->id,
            'technician_id' => $owner->id,
            'tenant_id' => $owner->tenantId(),
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $visit->recalculateTotal();
        $visit->transaction()->create([
            'txn_id' => 'TXN-20260611-001', 'amount' => $visit->total_amount,
            'method' => 'DuitNow QR', 'status' => 'pending',
        ]);

        return $visit->fresh();
    }

    private function payload(array $lines, array $overrides = []): array
    {
        return array_merge([
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'payment_method' => 'DuitNow QR',
            'lines' => $lines,
        ], $overrides);
    }

    public function test_update_recomputes_total_and_transaction_amount(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 3, 'discount' => 0],
            ]))
            ->assertRedirect(route('service-records.show', $visit));

        $visit->refresh()->load('lines', 'transaction');
        $this->assertSame('180.00', $visit->total_amount);
        $this->assertSame('180.00', $visit->transaction->amount);
        $this->assertCount(1, $visit->lines);
    }

    public function test_update_without_payment_method_preserves_existing_method(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);
        $visit->transaction->update(['method' => 'Cash']); // CHG-008 — method set earlier, must survive update

        $data = $this->payload([
            ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 2, 'discount' => 0],
        ]);
        unset($data['payment_method']); // form no longer sends it

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $data)
            ->assertRedirect(route('service-records.show', $visit));

        $this->assertSame('Cash', $visit->transaction->fresh()->method);
    }

    public function test_update_can_add_a_line(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'discount' => 0],
                ['service_type' => 'Gas Top-Up', 'unit_type' => 'Half Top-Up', 'units' => 1, 'discount' => 0],
            ]))
            ->assertRedirect();

        $visit->refresh()->load('lines');
        $this->assertCount(2, $visit->lines);
        $this->assertSame('210.00', $visit->total_amount);
    }

    public function test_update_can_remove_a_line(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);
        $visit->lines()->create(['service_type' => 'Gas Top-Up', 'unit_type' => 'Half Top-Up', 'units' => 1, 'rate' => 150, 'discount' => 0]);
        $visit->recalculateTotal();

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'discount' => 0],
            ]))
            ->assertRedirect();

        $visit->refresh()->load('lines');
        $this->assertCount(1, $visit->lines);
        $this->assertSame('60.00', $visit->total_amount);
    }

    public function test_update_flexible_line_uses_manual_price_and_description(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Repair', 'repair_desc' => 'New capacitor', 'units' => 1, 'rate' => 220, 'discount' => 0],
            ]))
            ->assertRedirect();

        $line = $visit->refresh()->lines->first();
        $this->assertSame('220.00', $line->rate);
        $this->assertSame('New capacitor', $line->repair_desc);
        $this->assertNull($line->unit_type);
        $this->assertSame('220.00', $visit->total_amount);
    }

    public function test_update_changing_service_type_resnapshots_rate_from_fee_book(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Gas Top-Up', 'unit_type' => 'Half Top-Up', 'units' => 1, 'rate' => 5, 'discount' => 0],
            ]))
            ->assertRedirect();

        $line = $visit->refresh()->lines->first();
        $this->assertSame('Gas Top-Up', $line->service_type);
        $this->assertSame('150.00', $line->rate);
    }

    public function test_cannot_update_a_paid_record(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);
        $visit->transaction->update(['status' => 'paid']);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 9, 'discount' => 0],
            ]))
            ->assertStatus(422);

        $this->assertSame('60.00', $visit->refresh()->total_amount);
    }

    public function test_update_forbidden_for_non_owner_scoped_tech(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);
        $other = $this->recorder();

        $this->actingAs($other)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'discount' => 0],
            ]))
            ->assertForbidden();
    }

    public function test_update_validation_requires_unit_type_for_fee_service(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'units' => 1],
            ]))
            ->assertSessionHasErrors('lines.0.unit_type');
    }

    public function test_update_refreshes_an_already_issued_invoice_keeping_its_number(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        // The invoice is minted and frozen the first time anyone opens it.
        $invoice = app(\App\Services\Documents\DocumentService::class)->invoiceFor($visit->transaction);
        $number = $invoice->number;
        $this->assertSame('60.00', $invoice->amount);
        $this->assertSame('60.00', $invoice->snapshot['lines'][0]['rate']);

        // Correct the record while it is still pending: 1 unit -> 3.
        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 3, 'discount' => 0],
            ]))
            ->assertRedirect();

        $invoice->refresh();

        // Same invoice, corrected figures — the customer's reference still resolves.
        $this->assertSame($number, $invoice->number);
        $this->assertSame('180.00', $invoice->amount);
        $this->assertSame(3, $invoice->snapshot['lines'][0]['units']);
        $this->assertSame('180.00', $invoice->snapshot['total_amount']);
        $this->assertSame(1, \App\Models\Invoice::count());
    }

    public function test_update_does_not_mint_an_invoice_that_was_never_issued(): void
    {
        $owner = $this->recorder();
        $visit = $this->makePendingVisit($owner);

        $this->actingAs($owner)
            ->patch(route('service-records.update', $visit), $this->payload([
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 2, 'discount' => 0],
            ]))
            ->assertRedirect();

        // Editing must not conjure an invoice — it stays lazy until first viewed.
        $this->assertSame(0, \App\Models\Invoice::count());
    }
}
