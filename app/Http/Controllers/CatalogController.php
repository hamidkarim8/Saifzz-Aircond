<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceFeeRequest;
use App\Models\ServiceFee;
use App\Models\ServiceType;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(): Response
    {
        $fees = ServiceFee::orderBy('service_type')->orderBy('option')->get();

        return Inertia::render('Catalog/Index', [
            'serviceTypes' => ServiceType::orderBy('name')->get(['id', 'name', 'requires_next_service']),
            'feeGroups'    => $fees->groupBy('service_type'),
            'modes'        => StoreServiceFeeRequest::MODES,
        ]);
    }
}
