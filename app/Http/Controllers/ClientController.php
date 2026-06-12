<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    /**
     * Client registry — search by name / serial / phone, filter by service type.
     * Enriches each row with latest-visit aggregates for the DataTable.
     */
    public function index(Request $request): Response
    {
        $search      = trim((string) $request->input('search', ''));
        $serviceType = $request->input('service_type');
        $perPage     = (int) $request->input('per_page', 10);
        $sort        = $request->input('sort', '');
        $dir         = $request->input('dir', 'asc') === 'desc' ? 'desc' : 'asc';

        // next_service_date is a MAX-over-lines derived field — not server-sortable; omitted.
        $sortWhitelist = ['serial_no', 'name', 'last_service_date', 'last_amount'];

        $clients = Client::query()
            ->withCount('visits')
            ->with(['latestVisit.lines'])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('serial_no', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'ilike', "%{$search}%");
                });
            })
            ->when($serviceType, function ($q) use ($serviceType) {
                $q->whereHas('visits.lines', fn ($q) => $q->where('service_type', $serviceType));
            })
            ->when(in_array($sort, $sortWhitelist, true), function ($q) use ($sort, $dir) {
                match ($sort) {
                    'last_service_date' => $q->orderBy(
                        \App\Models\ServiceVisit::select('visit_date')
                            ->whereColumn('client_id', 'clients.id')
                            ->latest('visit_date')
                            ->limit(1),
                        $dir
                    ),
                    'last_amount' => $q->orderBy(
                        \App\Models\ServiceVisit::select('total_amount')
                            ->whereColumn('client_id', 'clients.id')
                            ->latest('visit_date')
                            ->limit(1),
                        $dir
                    ),
                    default => $q->orderBy($sort, $dir),
                };
            }, function ($q) {
                $q->orderByDesc('created_at');
            })
            ->paginate($perPage)
            ->withQueryString();

        // Enrich each client with computed fields from their latest visit.
        $today = Carbon::today();
        $clients->getCollection()->transform(function ($client) use ($today) {
            $latestVisit = $client->latestVisit;

            if ($latestVisit === null) {
                $client->last_service_date = null;
                $client->service_types     = [];
                $client->units             = 0;
                $client->next_service_date = null;
                $client->last_amount       = null;
                $client->warranty_state    = 'none';
                $client->warranty_label    = 'No warranty';
            } else {
                $lines = $latestVisit->lines;

                $client->last_service_date = $latestVisit->visit_date?->toDateString();
                $client->service_types     = $lines->pluck('service_type')->unique()->values()->all();
                $client->units             = (int) $lines->sum('units');

                // MAX next_service_date across lines (only lines that have one)
                $nextDates = $lines->pluck('next_service_date')->filter();
                $client->next_service_date = $nextDates->count()
                    ? $nextDates->max(fn ($d) => $d instanceof \Illuminate\Support\Carbon ? $d->toDateString() : (string) $d)
                    : null;
                if ($client->next_service_date instanceof \Illuminate\Support\Carbon) {
                    $client->next_service_date = $client->next_service_date->toDateString();
                }

                $client->last_amount = $latestVisit->total_amount;

                // Warranty state
                $warrantyEnd = $latestVisit->warranty_end;
                if ($warrantyEnd === null) {
                    $client->warranty_state = 'none';
                    $client->warranty_label = 'No warranty';
                } elseif ($warrantyEnd->lt($today)) {
                    $client->warranty_state = 'expired';
                    $client->warranty_label = 'Expired';
                } elseif ($warrantyEnd->lte($today->copy()->addDays(30))) {
                    $client->warranty_state = 'expiring';
                    $client->warranty_label = 'Expires ' . $warrantyEnd->format('d M');
                } else {
                    $client->warranty_state = 'active';
                    $months = (int) $today->diffInMonths($warrantyEnd);
                    $client->warranty_label = $months > 0 ? "{$months} mos left" : 'Active';
                }
            }

            // Unset the loaded relation to keep the payload clean
            unset($client->latestVisit);

            return $client;
        });

        return Inertia::render('Clients/Index', [
            'clients'      => $clients,
            'filters'      => ['search' => $search, 'service_type' => $serviceType],
            'serviceTypes' => self::SERVICE_TYPES,
        ]);
    }

    /**
     * Lightweight JSON search for pickers (e.g. the service-record builder).
     */
    public function lookup(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));

        $clients = Client::query()
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('name', 'ilike', "%{$q}%")
                    ->orWhere('serial_no', 'ilike', "%{$q}%")
                    ->orWhere('phone', 'ilike', "%{$q}%");
            }))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'serial_no', 'name', 'phone']);

        return response()->json($clients);
    }

    public function create(): Response
    {
        return Inertia::render('Clients/Create');
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        $client = Client::create($request->validated());

        return redirect()
            ->route('clients.show', $client)
            ->with('success', "Client created — serial {$client->serial_no}.");
    }

    public function show(Client $client): Response
    {
        $client->load([
            'visits' => fn ($q) => $q->latest('visit_date'),
            'visits.lines',
            'visits.transaction',
            'appointments' => fn ($q) => $q->latest('datetime'),
        ]);

        return Inertia::render('Clients/Show', [
            'client' => $client,
        ]);
    }

    public function edit(Client $client): Response
    {
        return Inertia::render('Clients/Edit', [
            'client' => $client->only('id', 'serial_no', 'name', 'phone', 'address'),
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Client updated.');
    }

    /**
     * Soft-delete (R7 — financial history preserved).
     */
    public function destroy(Client $client): RedirectResponse
    {
        $client->delete();

        return redirect()
            ->route('clients.index')
            ->with('success', "Client {$client->serial_no} archived.");
    }

    private const SERVICE_TYPES = [
        'Cleaning',
        'Gas Top-Up',
        'Repair',
        'Installation',
        'Troubleshoot',
    ];
}
