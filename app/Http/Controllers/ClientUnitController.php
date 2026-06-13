<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientUnitRequest;
use App\Http\Requests\UpdateClientUnitRequest;
use App\Models\Client;
use App\Models\ClientUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ClientUnitController extends Controller
{
    /** JSON list of active units for client — used by service visit unit selector. */
    public function index(Client $client): JsonResponse
    {
        $units = $client->units()->active()->orderBy('label')->get([
            'id', 'label', 'unit_type', 'hp', 'brand', 'model', 'serial_no', 'refrigerant_type', 'next_service_date',
        ]);

        return response()->json($units);
    }

    public function store(StoreClientUnitRequest $request, Client $client): RedirectResponse
    {
        $client->units()->create($request->validated());

        return back()->with('success', 'Unit added.');
    }

    public function update(UpdateClientUnitRequest $request, Client $client, ClientUnit $unit): RedirectResponse
    {
        abort_if($unit->client_id !== $client->id, 404);
        $unit->update($request->validated());

        return back()->with('success', 'Unit updated.');
    }

    public function deactivate(Client $client, ClientUnit $unit): RedirectResponse
    {
        abort_if($unit->client_id !== $client->id, 404);
        abort_unless(request()->user()->can('manage_units'), 403);
        $unit->update(['is_active' => false]);

        return back()->with('success', 'Unit deactivated.');
    }
}
