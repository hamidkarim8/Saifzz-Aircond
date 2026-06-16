<?php
namespace App\Http\Controllers;

use App\Models\ServiceHpTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServiceHpTierController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('edit_fees'), 403);

        $data = $request->validate([
            'service_type_id' => ['required', 'integer', 'exists:service_types,id'],
            'hp_value'        => ['required', 'numeric', 'min:0.5', 'max:20'],
            'price'           => ['required', 'numeric', 'min:0'],
        ]);

        ServiceHpTier::updateOrCreate(
            ['service_type_id' => $data['service_type_id'], 'hp_value' => $data['hp_value']],
            ['price' => $data['price']],
        );

        return back()->with('success', 'HP tier saved.');
    }

    public function destroy(ServiceHpTier $tier): RedirectResponse
    {
        abort_unless(request()->user()->can('edit_fees'), 403);

        $tier->delete();

        return back()->with('success', 'HP tier removed.');
    }
}
