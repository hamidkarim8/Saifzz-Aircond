<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceFeeRequest;
use App\Http\Requests\UpdateServiceFeeRequest;
use App\Models\ServiceFee;
use Illuminate\Http\RedirectResponse;

class ServiceFeeController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('service-types.index');
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
