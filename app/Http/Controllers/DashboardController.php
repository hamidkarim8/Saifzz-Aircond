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

        $month = (string) $request->input('month', '');
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }

        $user      = $request->user();
        $scopeId   = $user->seesAllData() ? null : $user->id;
        $tenantId  = $user->tenantId();
        $canReport  = $user->hasPermission('view_reports');
        $canCollect = $user->hasPermission('collect_payment');

        return Inertia::render('Dashboard', [
            'canReport'    => $canReport,
            'period'       => $period,
            'month'        => $month,
            'report'       => [
                'kpis'           => $reports->kpis($scopeId, $tenantId),
                'servicesByType' => $reports->servicesByType($period, $scopeId, $tenantId),
                'transactions'   => $canReport
                    ? $reports->transactions($period, 50, $scopeId, $tenantId)
                    : [],
                'receivables'    => $canCollect
                    ? $reports->receivables($scopeId, $tenantId)
                    : null,
            ],
            'appointments' => Appointment::query()
                ->visibleTo($user)
                ->with('client:id,serial_no,name')
                ->forMonth($month)
                ->orderBy('datetime')
                ->get(),
        ]);
    }
}
