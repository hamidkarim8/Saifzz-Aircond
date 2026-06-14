<?php

namespace App\Http\Controllers;

use App\Models\ServiceType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ServiceTypeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('ServiceTypes/Index', [
            'serviceTypes' => ServiceType::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:service_types,name'],
        ]);

        ServiceType::create(['name' => $request->input('name')]);

        return back()->with('success', 'Service type added.');
    }

    public function update(Request $request, ServiceType $serviceType): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100', "unique:service_types,name,{$serviceType->id}"],
        ]);

        $oldName = $serviceType->name;
        $newName = $request->input('name');

        $serviceType->update(['name' => $newName]);

        if ($oldName !== $newName) {
            DB::table('service_fees')->where('service_type', $oldName)->update(['service_type' => $newName]);
            DB::table('service_lines')->where('service_type', $oldName)->update(['service_type' => $newName]);
            DB::table('appointments')->where('service_type', $oldName)->update(['service_type' => $newName]);
        }

        return back()->with('success', 'Service type updated.');
    }
}
