<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    /**
     * Client registry — search by name / serial / phone, filter by service type.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $serviceType = $request->input('service_type');

        $clients = Client::query()
            ->withCount('visits')
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
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'filters' => ['search' => $search, 'service_type' => $serviceType],
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
