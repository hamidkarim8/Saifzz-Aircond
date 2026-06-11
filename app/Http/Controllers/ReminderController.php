<?php

namespace App\Http\Controllers;

use App\Models\Client;
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
        return Inertia::render('Reminders/Index', $reminders->dueList());
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
}
