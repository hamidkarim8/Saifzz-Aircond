<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\ServiceFee;
use App\Models\ServiceType;
use App\Models\User;
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
}
