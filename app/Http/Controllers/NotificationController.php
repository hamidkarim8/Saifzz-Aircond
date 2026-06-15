<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $notifications = $user->notifications()->latest()->take(50)->get()->map(fn ($n) => [
            'id'          => $n->id,
            'data'        => $n->data,
            'read_at'     => $n->read_at?->toDateTimeString(),
            'created_at'  => $n->created_at->toDateTimeString(),
        ]);

        $user->unreadNotifications()->update(['read_at' => now()]);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
        ]);
    }
}
