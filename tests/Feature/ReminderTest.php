<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ReminderContact;
use App\Models\ServiceLine;
use App\Models\ServiceVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-06-15'));
    }

    private function viewer(array $permissions = ['view_clients']): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => $permissions,
        ]);
    }

    private function dueClient(string $name = 'Due Dana'): Client
    {
        $client = Client::create(['name' => $name, 'phone' => '011-22334455', 'address' => 'A']);
        $visit = ServiceVisit::create(['client_id' => $client->id, 'visit_date' => '2026-05-01', 'warranty_months' => 0]);
        ServiceLine::create([
            'visit_id' => $visit->id,
            'service_type' => 'Cleaning',
            'units' => 1,
            'rate' => 100,
            'discount' => 0,
            'next_service_date' => '2026-06-20',
        ]);

        return $client;
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('reminders.index'))->assertRedirect(route('login'));
    }

    public function test_technician_without_view_clients_is_forbidden(): void
    {
        $this->actingAs($this->viewer(['record_service']))
            ->get(route('reminders.index'))
            ->assertForbidden();
    }

    public function test_viewer_sees_the_derived_list(): void
    {
        $this->dueClient();

        $this->actingAs($this->viewer())
            ->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Reminders/Index')
                ->has('due_this_month', 1)
                ->has('overdue', 0)
                ->where('stats.due_this_month', 1)
            );
    }

    public function test_toggle_contacted_creates_then_removes_the_row(): void
    {
        $viewer = $this->viewer();
        $client = $this->dueClient();

        // First toggle — marks contacted.
        $this->actingAs($viewer)
            ->patch(route('reminders.contacted', $client))
            ->assertRedirect();

        $this->assertDatabaseHas('reminder_contacts', [
            'client_id' => $client->id,
            'contacted_by' => $viewer->id,
        ]);

        // Second toggle — reopens (deletes the row). Idempotent per resulting state.
        $this->actingAs($viewer)
            ->patch(route('reminders.contacted', $client))
            ->assertRedirect();

        $this->assertDatabaseMissing('reminder_contacts', ['client_id' => $client->id]);
        $this->assertSame(0, ReminderContact::count());
    }
}
