<?php

namespace App\Http\Controllers;

use App\Models\ServiceFee;
use App\Models\ServiceType;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(): Response
    {
        $fees = ServiceFee::orderBy('unit_type')->get();

        return Inertia::render('Catalog/Index', [
            'serviceTypes' => ServiceType::orderBy('name')->get(['id', 'name', 'requires_next_service', 'pricing_mode']),
            'feeGroups'    => $fees->groupBy('service_type_id'),
            'modes'        => ServiceType::MODES,
        ]);
    }
}
