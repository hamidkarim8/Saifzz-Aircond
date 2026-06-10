<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
