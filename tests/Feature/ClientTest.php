<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceLine;
use App\Models\ServiceVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function technician(array $permissions = []): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => $permissions,
        ]);
    }

    public function test_guest_is_redirected_from_clients(): void
    {
        $this->get(route('clients.index'))->assertRedirect(route('login'));
    }

    public function test_technician_without_view_clients_is_forbidden(): void
    {
        $this->actingAs($this->technician([]))
            ->get(route('clients.index'))
            ->assertForbidden();
    }

    public function test_technician_with_view_clients_can_list(): void
    {
        $this->actingAs($this->technician(['view_clients']))
            ->get(route('clients.index'))
            ->assertOk();
    }

    public function test_view_only_technician_cannot_create(): void
    {
        $this->actingAs($this->technician(['view_clients']))
            ->get(route('clients.create'))
            ->assertForbidden();
    }

    public function test_storing_a_client_assigns_a_serial(): void
    {
        $this->actingAs($this->admin())
            ->post(route('clients.store'), [
                'name' => 'Zainab',
                'phone' => '012-3456789',
                'address' => 'Kuala Lumpur',
            ])
            ->assertRedirect();

        $client = Client::firstWhere('name', 'Zainab');
        $this->assertNotNull($client);
        $this->assertSame('000001', $client->serial_no); // R6
    }

    public function test_phone_must_be_malaysian_mobile(): void
    {
        $this->actingAs($this->admin())
            ->post(route('clients.store'), [
                'name' => 'Bad Phone',
                'phone' => '123',
                'address' => 'KL',
            ])
            ->assertSessionHasErrors('phone');
    }

    public function test_search_matches_serial_name_and_phone(): void
    {
        $a = Client::create(['name' => 'Ahmad', 'phone' => '012-1112222', 'address' => 'A']);
        Client::create(['name' => 'Siti', 'phone' => '013-3334444', 'address' => 'B']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('clients.index', ['search' => 'Ahmad']))
            ->assertInertia(fn ($page) => $page->where('clients.total', 1));

        $this->actingAs($admin)
            ->get(route('clients.index', ['search' => $a->serial_no]))
            ->assertInertia(fn ($page) => $page->where('clients.total', 1));
    }

    public function test_destroy_soft_deletes_client(): void
    {
        $client = Client::create(['name' => 'Gone', 'phone' => '012-9998888', 'address' => 'C']);

        $this->actingAs($this->admin())
            ->delete(route('clients.destroy', $client))
            ->assertRedirect(route('clients.index'));

        $this->assertSoftDeleted($client); // R7
    }

    public function test_index_includes_enriched_table_fields(): void
    {
        $admin = $this->admin();
        $client = Client::create(['name' => 'Enriched', 'phone' => '011-11223344', 'address' => 'KL']);

        $visit = ServiceVisit::create([
            'client_id' => $client->id,
            'visit_date' => '2026-01-10',
            'warranty_months' => 3,
            'created_by' => $admin->id,
        ]);

        ServiceLine::create([
            'visit_id' => $visit->id,
            'service_type' => 'Cleaning',
            'units' => 2,
            'rate' => 60,
            'discount' => 0,
            'next_service_date' => '2026-07-10',
        ]);

        $this->actingAs($admin)
            ->get(route('clients.index'))
            ->assertInertia(fn ($page) => $page
                ->has('clients.data.0', fn ($row) => $row
                    ->hasAll(['serial_no', 'name', 'phone', 'last_service_date', 'service_types', 'next_service_date', 'warranty_state'])
                    ->where('last_service_date', '2026-01-10')
                    ->where('service_types', ['Cleaning'])
                    ->where('units', 2)
                    ->where('next_service_date', '2026-07-10')
                    ->where('warranty_state', 'expired')
                    ->etc()));
    }

    public function test_index_sort_by_name_descending(): void
    {
        $admin = $this->admin();
        Client::create(['name' => 'Ahmad', 'phone' => '012-1112222', 'address' => 'A']);
        Client::create(['name' => 'Zara', 'phone' => '013-3334444', 'address' => 'B']);

        $this->actingAs($admin)
            ->get(route('clients.index', ['sort' => 'name', 'dir' => 'desc']))
            ->assertInertia(fn ($page) => $page
                ->where('clients.data.0.name', 'Zara'));
    }
}
