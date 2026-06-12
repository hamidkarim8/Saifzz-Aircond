<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Models\Appointment;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    /**
     * Month calendar + list + summary stats. `month` = 'YYYY-MM' (defaults to now).
     */
    public function index(Request $request): Response
    {
        $month = (string) $request->input('month', '');
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }

        $appointments = Appointment::query()
            ->visibleTo($request->user())
            ->with('client:id,serial_no,name')
            ->forMonth($month)
            ->orderBy('datetime')
            ->get();

        $today = Appointment::query()
            ->visibleTo($request->user())
            ->with('client:id,serial_no,name')
            ->whereDate('datetime', now()->toDateString())
            ->orderBy('datetime')
            ->get();

        $stats = [
            'month_total' => $appointments->count(),
            'month_confirmed' => $appointments->where('status', 'confirmed')->count(),
            'month_pending' => $appointments->where('status', 'pending')->count(),
            'today_total' => $today->count(),
        ];

        // --- Table query (paginated, searchable, sortable) ---
        $sortWhitelist = ['datetime', 'status'];
        $sort = in_array($request->input('sort'), $sortWhitelist, true)
            ? $request->input('sort')
            : 'datetime';
        $dir = $request->input('dir') === 'desc' ? 'desc' : 'asc';
        $perPage = max(1, min(100, (int) $request->input('per_page', 10)));
        $search = $request->input('search', '');

        $tableQuery = Appointment::query()
            ->visibleTo($request->user())
            ->with('client:id,serial_no,name')
            ->forMonth($month);

        if ($search) {
            $tableQuery->where(function ($q) use ($search) {
                $q->where('appointments.phone', 'like', '%'.$search.'%')
                  ->orWhere('appointments.address', 'like', '%'.$search.'%')
                  ->orWhereHas('client', fn ($cq) => $cq->where('name', 'like', '%'.$search.'%'));
            });
        }

        $table = $tableQuery->orderBy($sort, $dir)->paginate($perPage)->withQueryString();

        return Inertia::render('Appointments/Index', [
            'appointments' => $appointments,
            'table' => $table,
            'today' => $today,
            'month' => $month,
            'stats' => $stats,
            'serviceTypes' => Appointment::SERVICE_TYPES,
            'transitions' => Appointment::TRANSITIONS,
            // Optional pre-selected client (e.g. arriving from a client profile or reminder).
            'presetClient' => $request->filled('client')
                ? Client::where('id', $request->input('client'))->first(['id', 'serial_no', 'name', 'phone', 'address'])
                : null,
            'technicians' => $request->user()->seesAllData()
                ? \App\Models\User::where('role', \App\Models\User::ROLE_TECHNICIAN)
                    ->where('active', true)->orderBy('name')->get(['id', 'name'])
                : null,
        ]);
    }

    public function store(StoreAppointmentRequest $request): RedirectResponse
    {
        $user = $request->user();
        // Scoped techs always own their bookings; all-data users may assign.
        $technicianId = $user->seesAllData() ? $request->input('technician_id') : $user->id;

        Appointment::create($request->appointmentData() + [
            'status' => 'pending',
            'technician_id' => $technicianId,
        ]);

        return redirect()
            ->route('appointments.index', ['month' => substr($request->datetime(), 0, 7)])
            ->with('success', 'Appointment scheduled.');
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        abort_unless(
            Appointment::whereKey($appointment->getKey())->visibleTo($request->user())->exists(),
            403,
        );

        $user = $request->user();
        $data = $request->appointmentData();
        $data['technician_id'] = $user->seesAllData()
            ? $request->input('technician_id')
            : $appointment->technician_id;

        // Status is owned by the lifecycle endpoint, not the edit form.
        $appointment->update($data);

        return redirect()
            ->route('appointments.index', ['month' => substr($request->datetime(), 0, 7)])
            ->with('success', 'Appointment updated.');
    }

    /**
     * Lifecycle transition (pending → confirmed → done / cancelled), guarded server-side.
     */
    public function updateStatus(Request $request, Appointment $appointment): RedirectResponse
    {
        abort_unless(
            Appointment::whereKey($appointment->getKey())->visibleTo($request->user())->exists(),
            403,
        );

        $request->validate([
            'status' => ['required', Rule::in(Appointment::STATUSES)],
        ]);

        $target = $request->input('status');

        abort_unless(
            $appointment->canTransitionTo($target),
            422,
            "Cannot move appointment from {$appointment->status} to {$target}.",
        );

        $appointment->update(['status' => $target]);

        return redirect()
            ->route('appointments.index', ['month' => Carbon::parse($appointment->datetime)->format('Y-m')])
            ->with('success', "Appointment marked {$target}.");
    }
}
