<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function technician(array $overrides = []): User
    {
        return User::factory()->technician()->create($overrides);
    }

    // --- Authorization ---

    public function test_guest_is_redirected_from_users_index(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    public function test_technician_with_all_grantable_permissions_cannot_access_any_route(): void
    {
        $grantable = array_values(array_diff(User::PERMISSIONS, User::ADMIN_ONLY_PERMISSIONS));
        $tech = $this->technician(['permissions' => $grantable]);

        $this->actingAs($tech)->get(route('users.index'))->assertForbidden();
        $this->actingAs($tech)->post(route('users.store'), [])->assertForbidden();
        $this->actingAs($tech)->put(route('users.update', $tech), [])->assertForbidden();
        $this->actingAs($tech)->patch(route('users.active', $tech))->assertForbidden();
    }

    // --- Index ---

    public function test_admin_can_view_users_index(): void
    {
        $this->actingAs($this->admin())->get(route('users.index'))->assertOk();
    }

    // --- Store ---

    public function test_admin_creates_technician_with_default_permissions(): void
    {
        $this->actingAs($this->admin())
            ->post(route('users.store'), [
                'name' => 'New Tech',
                'email' => 'tech@example.com',
                'password' => 'secret123',
            ])
            ->assertRedirect();

        $user = User::where('email', 'tech@example.com')->firstOrFail();
        $this->assertEquals(User::ROLE_TECHNICIAN, $user->role);
        $this->assertEqualsCanonicalizing(User::DEFAULT_TECHNICIAN_PERMISSIONS, $user->permissions);
        $this->assertTrue($user->active);
    }

    public function test_admin_creates_technician_with_custom_permissions(): void
    {
        $this->actingAs($this->admin())
            ->post(route('users.store'), [
                'name' => 'Fee Tech',
                'email' => 'feetech@example.com',
                'password' => 'secret123',
                'permissions' => ['view_clients', 'edit_fees'],
            ])
            ->assertRedirect();

        $user = User::where('email', 'feetech@example.com')->firstOrFail();
        $this->assertEqualsCanonicalizing(['view_clients', 'edit_fees'], $user->permissions);
    }

    public function test_admin_only_permission_in_store_payload_is_silently_dropped(): void
    {
        $this->actingAs($this->admin())
            ->post(route('users.store'), [
                'name' => 'Bad Actor',
                'email' => 'badactor@example.com',
                'password' => 'secret123',
                'permissions' => ['view_clients', 'manage_users'],
            ])
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'badactor@example.com')->firstOrFail();
        $this->assertNotContains('manage_users', $user->permissions);
        $this->assertContains('view_clients', $user->permissions);
    }

    public function test_store_rejects_duplicate_email(): void
    {
        $this->technician(['email' => 'taken@example.com']);

        $this->actingAs($this->admin())
            ->post(route('users.store'), [
                'name' => 'Dupe',
                'email' => 'taken@example.com',
                'password' => 'secret123',
            ])
            ->assertSessionHasErrors('email');
    }

    // --- Update ---

    public function test_admin_updates_technician_name_and_permissions(): void
    {
        $tech = $this->technician(['name' => 'Old Name']);

        $this->actingAs($this->admin())
            ->put(route('users.update', $tech), [
                'name' => 'New Name',
                'permissions' => ['collect_payment', 'view_reports'],
            ])
            ->assertRedirect();

        $tech->refresh();
        $this->assertEquals('New Name', $tech->name);
        $this->assertEqualsCanonicalizing(['collect_payment', 'view_reports'], $tech->permissions);
    }

    public function test_cannot_update_another_admin(): void
    {
        $admin1 = $this->admin();
        $admin2 = $this->admin();

        $this->actingAs($admin1)
            ->put(route('users.update', $admin2), ['name' => 'Hacked', 'permissions' => []])
            ->assertForbidden();
    }

    // --- Toggle Active ---

    public function test_admin_can_toggle_technician_active_status(): void
    {
        $tech = $this->technician(['active' => true]);

        $this->actingAs($this->admin())
            ->patch(route('users.active', $tech))
            ->assertRedirect();
        $this->assertFalse($tech->fresh()->active);

        $this->actingAs($this->admin())
            ->patch(route('users.active', $tech))
            ->assertRedirect();
        $this->assertTrue($tech->fresh()->active);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('users.active', $admin))
            ->assertStatus(422);
    }

    // --- P4 regression ---

    public function test_deactivated_technician_cannot_log_in(): void
    {
        $this->technician([
            'email' => 'inactive@example.com',
            'password' => Hash::make('secret123'),
            'active' => false,
        ]);

        $this->post(route('login'), [
            'email' => 'inactive@example.com',
            'password' => 'secret123',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }
}
