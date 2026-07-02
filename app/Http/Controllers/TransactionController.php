<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request, ReportService $reports): Response
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $user = $request->user();
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $hasRange = $dateFrom && $dateTo;

        $period = in_array($request->query('period'), ReportService::PERIODS, true)
            ? $request->query('period')
            : 'all';

        $techId = $user->seesAllData() ? null : $user->id;
        $tenantId = $user->tenantId();

        $transactions = $reports->transactions(
            $period,
            null,
            $techId,
            $tenantId,
            $hasRange ? Carbon::parse($dateFrom)->startOfDay() : null,
            $hasRange ? Carbon::parse($dateTo)->endOfDay() : null,
        );

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'period' => $hasRange ? null : $period,
            'periods' => ReportService::PERIODS,
            'dateFrom' => $hasRange ? $dateFrom : null,
            'dateTo' => $hasRange ? $dateTo : null,
        ]);
    }
}
