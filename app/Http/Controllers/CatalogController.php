<?php

namespace App\Http\Controllers;

use App\Models\ServiceType;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Catalog/Index', [
            'serviceTypes' => ServiceType::orderBy('name')
                ->with('fees:id,service_type_id,unit_type,hp_value,price')
                ->get(['id', 'name', 'pricing_mode', 'requires_next_service']),
        ]);
    }
}
