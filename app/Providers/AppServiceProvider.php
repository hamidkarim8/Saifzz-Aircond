<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->registerPermissionGates();
    }

    /**
     * Register one Gate per permission (P3 — server-side enforcement).
     * Admins implicitly pass every gate via Gate::before.
     */
    private function registerPermissionGates(): void
    {
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        foreach (User::PERMISSIONS as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
