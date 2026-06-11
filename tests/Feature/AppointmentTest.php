<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private function setter(array $permissions = ['view_clients', 'set_appointment']): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => $permissions,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-06-16',
            'time' => '10:00',
            'service_type' => 'Installation',
            'units' => 3,
            'amount' => 450,
            'phone' => '011-22334455',
            'address' => 'Unit 3A, Sri Maju Condo',
            'notes' => null,
        ], $overrides);
    }

    public function test_guest_is_redirected_from_appointments(): void
    {
        $this->get(route('appointments.index'))->assertRedirect(route('login'));
    }

    public function test_technician_without_set_appointment_is_forbidden(): void
    {
        $this->actingAs($this->setter(['view_clients']))
            ->get(route('appointments.index'))
            ->assertForbidden();
    }

    public function test_setter_can_view_calendar(): void
    {
        $this->actingAs($this->setter())
            ->get(route('appointments.index'))
            ->assertOk();
    }

    public function test_store_creates_pending_appointment_with_combined_datetime(): void
    {
        $client = Client::create(['name' => 'Kavitha', 'phone' => '011-22334455', 'address' => 'Unit 3A']);

        $this->actingAs($this->setter())
            ->post(route('appointments.store'), $this->payload(['client_id' => $client->id]))
            ->assertRedirect();

        $a = Appointment::first();
        $this->assertNotNull($a);
        $this->assertSame($client->id, $a->client_id);
        $this->assertSame('pending', $a->status);
        $this->assertSame('2026-06-16 10:00', $a->datetime->format('Y-m-d H:i'));
        $this->assertSame('Installation', $a->service_type);
        $this->assertSame(3, $a->units);
    }

    public function test_store_allows_appointment_without_a_client(): void
    {
        // client_id is loosely linked (nullable) — a prospective lead has no record yet.
        $this->actingAs($this->setter())
            ->post(route('appointments.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame(1, Appointment::whereNull('client_id')->count());
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->setter())
            ->post(route('appointments.store'), [
                'service_type' => 'Nope',
            ])
            ->assertSessionHasErrors(['date', 'time', 'service_type', 'phone', 'address']);
    }

    public function test_store_rejects_non_malaysian_phone(): void
    {
        $this->actingAs($this->setter())
            ->post(route('appointments.store'), $this->payload(['phone' => '123']))
            ->assertSessionHasErrors('phone');
    }

    public function test_update_edits_an_appointment(): void
    {
        $a = Appointment::create([
            'datetime' => '2026-06-16 10:00',
            'service_type' => 'Cleaning',
            'phone' => '012-3456789',
            'address' => 'Old address',
            'status' => 'pending',
        ]);

        $this->actingAs($this->setter())
            ->put(route('appointments.update', $a), $this->payload([
                'service_type' => 'Repair',
                'address' => 'New address',
            ]))
            ->assertRedirect();

        $a->refresh();
        $this->assertSame('Repair', $a->service_type);
        $this->assertSame('New address', $a->address);
    }

    public function test_status_transition_pending_to_confirmed_is_allowed(): void
    {
        $a = Appointment::create([
            'datetime' => '2026-06-16 10:00',
            'service_type' => 'Cleaning',
            'phone' => '012-3456789',
            'address' => 'KL',
            'status' => 'pending',
        ]);

        $this->actingAs($this->setter())
            ->patch(route('appointments.status', $a), ['status' => 'confirmed'])
            ->assertRedirect();

        $this->assertSame('confirmed', $a->refresh()->status);
    }

    public function test_invalid_status_transition_is_rejected(): void
    {
        $a = Appointment::create([
            'datetime' => '2026-06-16 10:00',
            'service_type' => 'Cleaning',
            'phone' => '012-3456789',
            'address' => 'KL',
            'status' => 'done', // terminal
        ]);

        $this->actingAs($this->setter())
            ->patch(route('appointments.status', $a), ['status' => 'pending'])
            ->assertStatus(422);

        $this->assertSame('done', $a->refresh()->status);
    }

    public function test_index_scopes_to_the_selected_month_and_returns_stats(): void
    {
        Appointment::create(['datetime' => '2026-06-10 09:00', 'service_type' => 'Cleaning', 'phone' => '012-1112222', 'address' => 'A', 'status' => 'confirmed']);
        Appointment::create(['datetime' => '2026-06-20 14:00', 'service_type' => 'Repair', 'phone' => '012-3334444', 'address' => 'B', 'status' => 'pending']);
        Appointment::create(['datetime' => '2026-07-01 09:00', 'service_type' => 'Cleaning', 'phone' => '012-5556666', 'address' => 'C', 'status' => 'pending']);

        $this->actingAs($this->setter())
            ->get(route('appointments.index', ['month' => '2026-06']))
            ->assertInertia(fn ($page) => $page
                ->component('Appointments/Index')
                ->where('month', '2026-06')
                ->has('appointments', 2)
                ->where('stats.month_total', 2)
                ->where('stats.month_confirmed', 1)
                ->where('stats.month_pending', 1)
            );
    }
}
