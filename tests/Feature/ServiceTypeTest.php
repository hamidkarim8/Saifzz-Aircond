<?php

namespace Tests\Feature;

use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTypeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function tech(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => User::DEFAULT_TECHNICIAN_PERMISSIONS,
        ]);
    }

    public function test_index_renders_for_admin(): void
    {
        ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->admin())
            ->get('/service-types')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('ServiceTypes/Index')
                ->has('serviceTypes', 1)
            );
    }

    public function test_index_renders_for_technician(): void
    {
        ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->tech())
            ->get('/service-types')
            ->assertOk();
    }

    public function test_unauthenticated_redirected(): void
    {
        $this->get('/service-types')->assertRedirect('/login');
    }

    public function test_store_creates_type(): void
    {
        $this->actingAs($this->admin())
            ->post('/service-types', ['name' => 'Dismantle'])
            ->assertRedirect();

        $this->assertDatabaseHas('service_types', ['name' => 'Dismantle']);
    }

    public function test_store_rejects_duplicate_name(): void
    {
        ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->admin())
            ->post('/service-types', ['name' => 'Cleaning'])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_rejects_empty_name(): void
    {
        $this->actingAs($this->admin())
            ->post('/service-types', ['name' => ''])
            ->assertSessionHasErrors(['name']);
    }

    public function test_update_renames_type(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->admin())
            ->put("/service-types/{$type->id}", ['name' => 'Deep Clean'])
            ->assertRedirect();

        $this->assertDatabaseHas('service_types', ['id' => $type->id, 'name' => 'Deep Clean']);
    }

    public function test_update_rejects_name_taken_by_other(): void
    {
        $a = ServiceType::create(['name' => 'Cleaning']);
        ServiceType::create(['name' => 'Repair']);

        $this->actingAs($this->admin())
            ->put("/service-types/{$a->id}", ['name' => 'Repair'])
            ->assertSessionHasErrors(['name']);
    }

    public function test_update_allows_same_name_on_self(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->admin())
            ->put("/service-types/{$type->id}", ['name' => 'Cleaning'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_no_destroy_route(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->admin())
            ->delete("/service-types/{$type->id}")
            ->assertStatus(405); // Method Not Allowed
    }
}
