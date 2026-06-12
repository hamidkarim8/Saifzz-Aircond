<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceFeeRequest;
use App\Http\Requests\UpdateServiceFeeRequest;
use App\Models\ServiceFee;
use App\Models\ServiceType;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServiceFeeController extends Controller
{
    /**
     * Price book — grouped by service type for the management screen.
     * Editing a fee affects only future service lines (rates are snapshotted, R1).
     */
    public function index(): Response
    {
        $fees = ServiceFee::orderBy('service_type')->orderBy('option')->get();

        return Inertia::render('Fees/Index', [
            'feeGroups' => $fees->groupBy('service_type'),
            'serviceTypes' => ServiceType::orderBy('name')->pluck('name')->all(),
            'modes' => StoreServiceFeeRequest::MODES,
        ]);
    }

    public function store(StoreServiceFeeRequest $request): RedirectResponse
    {
        ServiceFee::create($request->validated());

        return back()->with('success', 'Fee added.');
    }

    public function update(UpdateServiceFeeRequest $request, ServiceFee $fee): RedirectResponse
    {
        $data = $request->validated();
        // Flexible fees carry no rate.
        if ($data['pricing_mode'] === 'flexible') {
            $data['rate'] = null;
        }
        $fee->update($data);

        return back()->with('success', 'Fee updated.');
    }

    public function destroy(ServiceFee $fee): RedirectResponse
    {
        $fee->delete();

        return back()->with('success', 'Fee removed.');
    }
}
