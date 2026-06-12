<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceVisitRequest;
use App\Models\Client;
use App\Models\ServiceFee;
use App\Models\ServiceType;
use App\Models\ServiceVisit;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ServiceVisitController extends Controller
{
    public function index(): Response
    {
        $search  = request()->string('search')->trim()->value();
        $sortMap = ['visit_date' => 'visit_date', 'total' => 'total_amount', 'serial' => null];
        $sortKey = request()->input('sort');
        $dir     = strtolower(request()->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) request()->input('per_page', 10), 1), 100);

        $query = ServiceVisit::query()
            ->visibleTo(request()->user())
            ->with([
                'client:id,serial_no,name',
                'transaction:id,visit_id,status,method,txn_id',
                'lines:id,visit_id,service_type',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('client', fn ($c) => $c
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('serial_no', 'ilike', "%{$search}%"))
                  ->orWhereHas('transaction', fn ($t) => $t
                    ->where('txn_id', 'ilike', "%{$search}%"));
            });
        }

        if ($sortKey && array_key_exists($sortKey, $sortMap) && $sortMap[$sortKey] !== null) {
            $query->orderBy($sortMap[$sortKey], $dir)->orderBy('id', $dir);
        } else {
            $query->latest('visit_date')->latest('id');
        }

        $visits = $query->paginate($perPage)->withQueryString();

        return Inertia::render('ServiceRecords/Index', [
            'visits' => $visits,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ServiceRecords/Create', [
            'fees' => ServiceFee::orderBy('service_type')->get(['service_type', 'option', 'rate', 'pricing_mode']),
            'serviceTypes' => ServiceType::orderBy('name')->pluck('name')->all(),
            'unitTypes' => StoreServiceVisitRequest::UNIT_TYPES,
            'gasOptions' => StoreServiceVisitRequest::GAS_OPTIONS,
            'unitTypeServices' => StoreServiceVisitRequest::UNIT_TYPE_SERVICES,
            // Optional pre-selected client (e.g. arriving from a client profile).
            'presetClient' => request('client')
                ? Client::where('id', request('client'))->first(['id', 'serial_no', 'name', 'phone'])
                : null,
            'technicians' => request()->user()->seesAllData()
                ? \App\Models\User::where('role', \App\Models\User::ROLE_TECHNICIAN)
                    ->where('active', true)->orderBy('name')->get(['id', 'name'])
                : null,
        ]);
    }

    public function store(StoreServiceVisitRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $visit = DB::transaction(function () use ($data, $request) {
            $client = $data['client_mode'] === 'existing'
                ? Client::findOrFail($data['client_id'])
                : Client::create($data['new_client']);

            $user = $request->user();
            // Scoped techs always own their own jobs; all-data users may assign.
            $technicianId = $user->seesAllData()
                ? ($data['technician_id'] ?? $user->id)
                : $user->id;

            $visit = $client->visits()->create([
                'visit_date' => $data['visit_date'],
                'warranty_months' => $data['warranty_months'],
                'created_by' => $user->id,
                'technician_id' => $technicianId,
            ]);

            foreach ($data['lines'] as $line) {
                $visit->lines()->create($this->normalizeLine($line));
            }

            $visit->recalculateTotal(); // R8

            // R4 — every visit gets a Transaction (pending; payment module confirms).
            $visit->transaction()->create([
                'txn_id' => $this->nextTxnId(),
                'amount' => $visit->total_amount,
                'method' => $data['payment_method'],
                'status' => 'pending',
            ]);

            return $visit;
        });

        return redirect()
            ->route('service-records.show', $visit)
            ->with('success', 'Service record created. Proceed to payment.');
    }

    public function show(ServiceVisit $serviceRecord): Response
    {
        abort_unless(
            ServiceVisit::whereKey($serviceRecord->getKey())->visibleTo(request()->user())->exists(),
            403,
        );

        $serviceRecord->load(['client', 'lines', 'transaction', 'creator:id,name']);

        return Inertia::render('ServiceRecords/Show', [
            'visit' => $serviceRecord,
        ]);
    }

    /**
     * Build a persistable line: snapshot the fee rate (R1) and strip
     * fields that don't apply to the service type (R2/R3).
     */
    private function normalizeLine(array $line): array
    {
        $type = $line['service_type'];
        $isRepair = $type === 'Repair';
        $isGas = $type === 'Gas Top-Up';
        $carriesUnitType = in_array($type, StoreServiceVisitRequest::UNIT_TYPE_SERVICES, true);

        $unitType = $carriesUnitType ? ($line['unit_type'] ?? null) : null;
        $gasOption = $isGas ? ($line['gas_option'] ?? null) : null;

        // R1 — rate is server-authoritative from the fee book, except Repair (flexible/manual).
        if ($isRepair) {
            $rate = (float) $line['rate'];
        } else {
            $option = $isGas ? $gasOption : $unitType;
            $rate = (float) ServiceFee::where('service_type', $type)->where('option', $option)->value('rate');
        }

        return [
            'service_type' => $type,
            'unit_type' => $unitType,
            'gas_option' => $gasOption,
            'units' => (int) $line['units'],
            'rate' => $rate,
            'repair_desc' => $isRepair ? ($line['repair_desc'] ?? null) : null,
            'discount' => (float) ($line['discount'] ?? 0),
            // R2 — next-service only for unit-type services.
            'next_service_date' => $carriesUnitType ? ($line['next_service_date'] ?? null) : null,
            // R3 — no notes for Repair.
            'notes' => $isRepair ? null : ($line['notes'] ?? null),
            // subtotal derived in the model (R8).
        ];
    }

    private function nextTxnId(): string
    {
        $prefix = 'TXN-'.now()->format('Ymd').'-';
        $last = Transaction::where('txn_id', 'like', $prefix.'%')->orderByDesc('txn_id')->value('txn_id');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
