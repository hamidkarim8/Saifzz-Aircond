<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Users/Index', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'active', 'permissions']),
            'grantablePermissions' => array_values(array_diff(User::PERMISSIONS, User::ADMIN_ONLY_PERMISSIONS)),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => User::ROLE_TECHNICIAN,
            'tenant_id' => $request->user()->tenantId(),
        ]);

        // booted() sets DEFAULT_TECHNICIAN_PERMISSIONS when permissions is null.
        // If caller sent explicit permissions, replace defaults and re-grant each through
        // grantPermission() so admin-only entries are silently dropped (P1).
        if ($request->has('permissions')) {
            $user->permissions = [];
            foreach ($request->permissions ?? [] as $p) {
                $user->grantPermission($p);
            }
            $user->save();
        }

        return back()->with('success', 'User created.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        if ($user->isAdmin()) {
            abort(403);
        }

        $user->name = $request->name;
        $user->permissions = [];
        foreach ($request->permissions ?? [] as $p) {
            $user->grantPermission($p);
        }
        $user->save();

        return back()->with('success', 'User updated.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, 'Cannot deactivate your own account.');

        $user->update(['active' => ! $user->active]);

        return back()->with('success', $user->active ? 'Account activated.' : 'Account deactivated.');
    }
}
