<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request, ReportService $reports): Response
    {
        $user = $request->user();
        $period = in_array($request->query('period'), ReportService::PERIODS, true)
            ? $request->query('period')
            : 'all';

        $techId = $user->seesAllData() ? null : $user->id;
        $tenantId = $user->tenantId();

        $transactions = $reports->transactions($period, null, $techId, $tenantId);

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'period' => $period,
            'periods' => ReportService::PERIODS,
        ]);
    }
}
