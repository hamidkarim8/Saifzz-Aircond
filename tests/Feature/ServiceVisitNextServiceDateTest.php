<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientUnit;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceVisitNextServiceDateTest extends TestCase
{
    use RefreshDatabase;

    private function boss(): User
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);

        return $boss->fresh();
    }

    /** A paid visit (Cash) with one Cleaning line, optionally attached to a unit. */
    private function paidVisitWithLine(User $boss, ?ClientUnit $unit = null): \App\Models\ServiceVisit
    {
        $client = Client::create([
            'name' => 'Zainab', 'phone' => '012-345 6789', 'address' => 'KL',
            'tenant_id' => $boss->tenantId(),
        ]);

        $visit = $client->visits()->create([
            'visit_date' => '2026-07-01',
            'warranty_months' => 0,
            'total_amount' => 60,
            'created_by' => $boss->id,
            'tenant_id' => $boss->tenantId(),
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'unit_id' => $unit?->id,
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260701-' . str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
            'amount' => 60, 'method' => 'Cash', 'status' => 'pending',
        ]);

        app(PaymentService::class)->confirmCash($txn);

        return $visit->fresh(['transaction', 'lines']);
    }

    public function test_can_set_next_service_date_on_a_paid_line(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisitWithLine($boss);
        $line = $visit->lines->first();

        $this->actingAs($boss)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visit, 'line' => $line]), [
                'next_service_date' => '2026-10-01',
            ])
            ->assertRedirect(route('service-records.show', $visit));

        $this->assertSame('2026-10-01', $line->fresh()->next_service_date->format('Y-m-d'));
    }

    public function test_can_overwrite_an_already_set_date_on_a_paid_line(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisitWithLine($boss);
        $line = $visit->lines->first();
        $line->update(['next_service_date' => '2026-08-01']);

        $this->actingAs($boss)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visit, 'line' => $line]), [
                'next_service_date' => '2026-11-15',
            ]);

        $this->assertSame('2026-11-15', $line->fresh()->next_service_date->format('Y-m-d'));
    }

    public function test_resyncs_client_unit_when_line_has_a_unit(): void
    {
        $boss = $this->boss();
        $client = Client::create(['name' => 'Ali', 'phone' => '013-0000000', 'address' => 'KL', 'tenant_id' => $boss->tenantId()]);
        $unit = ClientUnit::create(['client_id' => $client->id, 'label' => 'Living Room', 'unit_type' => 'Wall Mounted']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-07-01', 'warranty_months' => 0, 'total_amount' => 60,
            'created_by' => $boss->id, 'tenant_id' => $boss->tenantId(),
        ]);
        $line = $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'unit_id' => $unit->id,
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260701-901', 'amount' => 60, 'method' => 'Cash', 'status' => 'pending',
        ]);
        app(PaymentService::class)->confirmCash($txn);

        $this->actingAs($boss)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visit, 'line' => $line]), [
                'next_service_date' => '2026-12-25',
            ]);

        $fresh = $unit->fresh();
        $this->assertSame('2026-12-25', $fresh->next_service_date->format('Y-m-d'));
        $this->assertSame('Cleaning', $fresh->next_service_type);
    }

    public function test_clearing_to_null_updates_line_but_does_not_blank_unit(): void
    {
        $boss = $this->boss();
        $client = Client::create(['name' => 'Ali', 'phone' => '013-0000000', 'address' => 'KL', 'tenant_id' => $boss->tenantId()]);
        $unit = ClientUnit::create([
            'client_id' => $client->id, 'label' => 'Living Room', 'unit_type' => 'Wall Mounted',
            'next_service_date' => '2026-09-01', 'next_service_type' => 'Cleaning',
        ]);
        $visit = $client->visits()->create([
            'visit_date' => '2026-07-01', 'warranty_months' => 0, 'total_amount' => 60,
            'created_by' => $boss->id, 'tenant_id' => $boss->tenantId(),
        ]);
        $line = $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'unit_id' => $unit->id,
            'next_service_date' => '2026-09-01',
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260701-902', 'amount' => 60, 'method' => 'Cash', 'status' => 'pending',
        ]);
        app(PaymentService::class)->confirmCash($txn);

        $this->actingAs($boss)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visit, 'line' => $line]), [
                'next_service_date' => null,
            ]);

        $this->assertNull($line->fresh()->next_service_date);
        $this->assertSame('2026-09-01', $unit->fresh()->next_service_date->format('Y-m-d'));
    }

    public function test_404_when_line_does_not_belong_to_service_record(): void
    {
        $boss = $this->boss();
        $visitA = $this->paidVisitWithLine($boss);
        $visitB = $this->paidVisitWithLine($boss);
        $lineFromB = $visitB->lines->first();

        $this->actingAs($boss)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visitA, 'line' => $lineFromB]), [
                'next_service_date' => '2026-10-01',
            ])
            ->assertStatus(404);
    }

    public function test_403_when_record_not_visible_to_scoped_technician(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisitWithLine($boss);
        $line = $visit->lines->first();

        $otherTech = User::factory()->create(['tenant_id' => $boss->tenantId()]);

        $this->actingAs($otherTech)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visit, 'line' => $line]), [
                'next_service_date' => '2026-10-01',
            ])
            ->assertStatus(403);
    }
}
