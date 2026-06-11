<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Landing page. Reporting payload (module 9) is rendered only for users with view_reports;
     * everyone else gets the module launcher.
     */
    public function index(Request $request, ReportService $reports): Response
    {
        $period = $request->input('period');
        if (! in_array($period, ReportService::PERIODS, true)) {
            $period = 'all';
        }

        if (! $request->user()->hasPermission('view_reports')) {
            return Inertia::render('Dashboard', ['canReport' => false]);
        }

        $month = (string) $request->input('month', '');
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }

        return Inertia::render('Dashboard', [
            'canReport' => true,
            'period' => $period,
            'month' => $month,
            'report' => [
                'kpis' => $reports->kpis(),
                'servicesByType' => $reports->servicesByType($period),
                'transactions' => $reports->transactions($period),
            ],
            'appointments' => Appointment::query()
                ->with('client:id,serial_no,name')
                ->forMonth($month)
                ->orderBy('datetime')
                ->get(),
        ]);
    }
}
