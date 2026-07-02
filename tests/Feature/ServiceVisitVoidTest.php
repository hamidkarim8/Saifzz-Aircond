<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceVisitVoidTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_accepts_void_fields(): void
    {
        $client = Client::create(['name' => 'A', 'phone' => '011-0000000', 'address' => 'KL']);
        $visit = $client->visits()->create(['visit_date' => '2026-07-01', 'warranty_months' => 0, 'total_amount' => 60]);
        $actor = User::factory()->create();

        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260701-001',
            'amount' => 60,
            'method' => 'Cash',
            'status' => 'void',
            'void_reason' => 'Billed by mistake',
            'voided_at' => now(),
            'voided_by' => $actor->id,
        ]);

        $fresh = Transaction::find($txn->id);
        $this->assertSame('Billed by mistake', $fresh->void_reason);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->voided_at);
        $this->assertSame($actor->id, $fresh->voided_by);
    }

    private function boss(): User
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);

        return $boss->fresh();
    }

    /** A paid visit (Cash), optionally linked to an appointment. */
    private function paidVisit(User $boss, ?Appointment $appointment = null): \App\Models\ServiceVisit
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
            'appointment_id' => $appointment?->id,
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260701-' . str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
            'amount' => 60, 'method' => 'Cash', 'status' => 'pending',
        ]);

        app(PaymentService::class)->confirmCash($txn);

        return $visit->fresh(['transaction']);
    }

    public function test_void_paid_sets_status_reason_actor_timestamp(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisit($boss);

        app(PaymentService::class)->voidPaid($visit->transaction, 'Billed by mistake', $boss);

        $txn = $visit->transaction->fresh();
        $this->assertSame('void', $txn->status);
        $this->assertSame('Billed by mistake', $txn->void_reason);
        $this->assertNotNull($txn->voided_at);
        $this->assertSame($boss->id, $txn->voided_by);
    }

    public function test_void_reopens_appointment_that_was_auto_completed(): void
    {
        $boss = $this->boss();
        $appt = Appointment::create(['datetime' => '2026-07-01 09:00', 'status' => 'pending', 'tenant_id' => $boss->tenantId()]);
        $visit = $this->paidVisit($boss, $appt); // confirmCash completes it
        $this->assertSame('completed', $appt->fresh()->status);

        app(PaymentService::class)->voidPaid($visit->transaction, 'mistaken billing', $boss);

        $this->assertSame('pending', $appt->fresh()->status);
    }

    public function test_void_leaves_appointment_alone_if_not_completed(): void
    {
        $boss = $this->boss();
        $appt = Appointment::create(['datetime' => '2026-07-01 09:00', 'status' => 'cancelled', 'tenant_id' => $boss->tenantId()]);
        $visit = $this->paidVisit($boss, $appt);

        app(PaymentService::class)->voidPaid($visit->transaction, 'mistaken billing', $boss);

        $this->assertSame('cancelled', $appt->fresh()->status);
    }

    public function test_void_without_linked_appointment_is_noop(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisit($boss);

        app(PaymentService::class)->voidPaid($visit->transaction, 'mistaken billing', $boss);

        $this->assertSame('void', $visit->transaction->fresh()->status);
    }

    public function test_pending_record_still_cancels_without_reason(): void
    {
        $boss = $this->boss();
        $client = Client::create(['name' => 'A', 'phone' => '011-0000000', 'address' => 'KL', 'tenant_id' => $boss->tenantId()]);
        $visit = $client->visits()->create(['visit_date' => '2026-07-01', 'warranty_months' => 0, 'total_amount' => 60, 'tenant_id' => $boss->tenantId()]);
        $visit->transaction()->create(['txn_id' => 'TXN-20260701-900', 'amount' => 60, 'method' => 'Cash', 'status' => 'pending']);

        $this->actingAs($boss)
            ->delete(route('service-records.destroy', $visit))
            ->assertRedirect(route('service-records.index'));

        $this->assertSame('cancelled', $visit->transaction->fresh()->status);
    }

    public function test_void_requires_reason(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisit($boss);

        $this->actingAs($boss)
            ->delete(route('service-records.destroy', $visit))
            ->assertSessionHasErrors('reason');

        $this->assertSame('paid', $visit->transaction->fresh()->status);
    }

    public function test_void_via_http_persists_reason(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisit($boss);

        $this->actingAs($boss)
            ->delete(route('service-records.destroy', $visit), ['reason' => 'Billed by mistake'])
            ->assertRedirect(route('service-records.index'));

        $txn = $visit->transaction->fresh();
        $this->assertSame('void', $txn->status);
        $this->assertSame('Billed by mistake', $txn->void_reason);
        $this->assertSame($boss->id, $txn->voided_by);
    }

    public function test_void_blocked_once_already_void(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisit($boss);
        $this->actingAs($boss)->delete(route('service-records.destroy', $visit), ['reason' => 'first']);

        $this->actingAs($boss)
            ->delete(route('service-records.destroy', $visit), ['reason' => 'second'])
            ->assertStatus(422);
    }
}
