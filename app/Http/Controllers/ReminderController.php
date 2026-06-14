<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientUnit;
use App\Models\ReminderContact;
use App\Services\Reminders\ReminderService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ReminderController extends Controller
{
    /**
     * Derived due/overdue follow-up list (module 8).
     */
    public function index(ReminderService $reminders): Response
    {
        return Inertia::render('Reminders/Index', $reminders->dueList(request()->user()->tenantId()));
    }

    /**
     * Toggle a client's "contacted" flag. Presence of a reminder_contacts row = contacted.
     */
    public function toggleContacted(Client $client): RedirectResponse
    {
        $existing = ReminderContact::where('client_id', $client->id)->first();

        if ($existing) {
            $existing->delete();

            return back()->with('success', 'Reminder reopened.');
        }

        ReminderContact::create([
            'client_id' => $client->id,
            'contacted_at' => now(),
            'contacted_by' => auth()->id(),
        ]);

        return back()->with('success', 'Marked contacted.');
    }

    /**
     * Dismiss a reminder — clears next_service_date from all active client_units.
     * The reminder reappears once the next service visit sets a new date.
     */
    public function dismiss(Client $client): RedirectResponse
    {
        ClientUnit::where('client_id', $client->id)
            ->where('is_active', true)
            ->update(['next_service_date' => null, 'next_service_type' => null]);

        return back()->with('success', 'Reminder dismissed.');
    }
}
