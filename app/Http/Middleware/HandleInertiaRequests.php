<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                // Effective permission map (admins implicitly hold all — P1/P3).
                'can' => $user
                    ? collect(User::PERMISSIONS)->mapWithKeys(
                        fn ($p) => [$p => $user->hasPermission($p)]
                    )
                    : [],
                'isAdmin' => (bool) $user?->isAdmin(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'reminderCount' => $this->reminderCount($request),
        ];
    }

    private function reminderCount(Request $request): int
    {
        $user = $request->user();
        if (! $user || ! $user->can('view_clients')) {
            return 0;
        }
        $list = app(\App\Services\Reminders\ReminderService::class)->dueList($user->tenantId());

        // dueList() returns keys: overdue, due_this_month, stats.
        return count($list['overdue']) + count($list['due_this_month']);
    }
}
