<?php

namespace Tests\Feature;

use App\Actions\Payments\HandleGatewayCallback;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\ServiceFee;
use App\Models\ServiceType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\Data\CallbackResult;
use App\Services\Payments\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentPaymentCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ServiceTypeSeeder::class);

        // ServiceTypeSeeder seeds Cleaning as hp_tiered; these tests use Wall
        // Mounted Cleaning without hp_value, so flatten it and seed a fee.
        $cleaning = ServiceType::where('name', 'Cleaning')->first();
        $cleaning->update(['pricing_mode' => 'flat']);
        ServiceFee::firstOrCreate(
            ['service_type_id' => $cleaning->id, 'unit_type' => 'Wall Mounted', 'hp_value' => null],
            ['price' => 60]
        );
    }

    /** Boss admin that is its own tenant root, plus an own-tenant client. */
    private function bossWithClient(): array
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);
        $boss = $boss->fresh();

        $client = Client::create([
            'name' => 'C', 'phone' => '011-0000000', 'address' => 'KL',
            'tenant_id' => $boss->tenantId(),
        ]);

        return [$boss, $client];
    }

    private function makeAppointmentFor(Client $client, User $boss, array $attrs = []): Appointment
    {
        return $client->appointments()->create(array_merge([
            'datetime' => '2026-06-20 10:00:00',
            'status' => 'pending',
            'technician_id' => null,
            'tenant_id' => $boss->tenantId(),
        ], $attrs));
    }

    private function makeAppointmentForOtherTenant(): Appointment
    {
        $other = User::factory()->admin()->create();
        $other->update(['tenant_id' => $other->id]);
        $other = $other->fresh();

        $client = Client::create([
            'name' => 'Other', 'phone' => '011-1111111', 'address' => 'JB',
            'tenant_id' => $other->tenantId(),
        ]);

        return $this->makeAppointmentFor($client, $other);
    }

    private function validVisitPayload(Client $client, array $overrides = []): array
    {
        return array_merge([
            'client_mode' => 'existing',
            'client_id' => $client->id,
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'payment_method' => 'DuitNow QR',
            'lines' => [
                ['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1],
            ],
        ], $overrides);
    }

    /**
     * A ServiceVisit (carrying the given appointment_id) plus a pending cash
     * Transaction for it. Boss is an admin so it implicitly holds
     * collect_payment (see User::hasPermission).
     */
    private function pendingCashTransactionForVisitWith(Client $client, User $boss, array $attrs = []): Transaction
    {
        $visit = $client->visits()->create(array_merge([
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'total_amount' => 60,
            'created_by' => $boss->id,
            'technician_id' => null,
            'tenant_id' => $boss->tenantId(),
        ], $attrs));

        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-'.now()->format('Ymd').'-'.str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
            'amount' => 60,
            'method' => 'Cash',
            'status' => 'pending',
        ]);
    }

    public function test_cash_payment_completes_linked_appointment(): void
    {
        [$boss, $client] = $this->bossWithClient();
        $appt = $this->makeAppointmentFor($client, $boss);     // status pending
        $txn  = $this->pendingCashTransactionForVisitWith($client, $boss, ['appointment_id' => $appt->id]);

        $this->actingAs($boss)->post(route('payments.cash', $txn))->assertRedirect();

        $this->assertSame('completed', $appt->fresh()->status);
    }

    public function test_cancelled_appointment_stays_cancelled_after_payment(): void
    {
        [$boss, $client] = $this->bossWithClient();
        $appt = $this->makeAppointmentFor($client, $boss, ['status' => 'cancelled']);
        $txn  = $this->pendingCashTransactionForVisitWith($client, $boss, ['appointment_id' => $appt->id]);

        $this->actingAs($boss)->post(route('payments.cash', $txn))->assertRedirect();

        $this->assertSame('cancelled', $appt->fresh()->status);
    }

    public function test_payment_without_linked_appointment_is_noop(): void
    {
        [$boss, $client] = $this->bossWithClient();
        $txn = $this->pendingCashTransactionForVisitWith($client, $boss, ['appointment_id' => null]);

        $this->actingAs($boss)->post(route('payments.cash', $txn))->assertRedirect();
        $this->assertSame('paid', $txn->fresh()->status);
    }

    public function test_store_persists_valid_appointment_id_on_visit(): void
    {
        [$boss, $client] = $this->bossWithClient();
        $appt = $this->makeAppointmentFor($client, $boss);

        $this->actingAs($boss)->post(route('service-records.store'), $this->validVisitPayload($client, [
            'appointment_id' => $appt->id,
        ]))->assertRedirect();

        $this->assertDatabaseHas('service_visits', [
            'client_id'      => $client->id,
            'appointment_id' => $appt->id,
        ]);
    }

    public function test_store_rejects_cross_tenant_appointment_id(): void
    {
        [$boss, $client] = $this->bossWithClient();
        $otherAppt = $this->makeAppointmentForOtherTenant();

        $this->actingAs($boss)->post(route('service-records.store'), $this->validVisitPayload($client, [
            'appointment_id' => $otherAppt->id,
        ]))->assertSessionHasErrors('appointment_id');
    }

    public function test_gateway_paid_callback_completes_linked_appointment(): void
    {
        [$boss, $client] = $this->bossWithClient();
        $appt = $this->makeAppointmentFor($client, $boss);     // status pending
        $txn  = $this->pendingCashTransactionForVisitWith($client, $boss, ['appointment_id' => $appt->id]);

        // Drive the webhook action directly with a verified PAID result whose
        // orderNumber matches the txn and whose amount matches.
        $result = new CallbackResult(
            verified: true,
            orderNumber: $txn->txn_id,
            gatewayRef: 'STUB-REF',
            status: PaymentStatus::PAID,
            amount: (float) $txn->amount,
            raw: [],
        );

        $accepted = app(HandleGatewayCallback::class)($result);

        $this->assertTrue($accepted);
        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertSame('completed', $appt->fresh()->status);
    }

    public function test_tenant_mismatch_guard_blocks_completion(): void
    {
        [$boss, $client] = $this->bossWithClient();
        // Appointment belongs to a DIFFERENT tenant; we attach its id to a visit
        // owned by boss A so visit.tenant_id !== appointment.tenant_id and the
        // guard must fire. (The HTTP store path validates against this, so we
        // wire the mismatched appointment_id straight onto the visit instead.)
        $otherAppt = $this->makeAppointmentForOtherTenant();
        $txn = $this->pendingCashTransactionForVisitWith($client, $boss, ['appointment_id' => $otherAppt->id]);

        $this->actingAs($boss)->post(route('payments.cash', $txn))->assertRedirect();

        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertSame('pending', $otherAppt->fresh()->status);
    }
}
