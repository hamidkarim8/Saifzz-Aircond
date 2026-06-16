<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\Reports\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Reporting landing page (module 9). Gated by view_reports — users without it
     * (L1/L2 technicians) have no dashboard and are redirected to their work surface.
     */
    public function index(Request $request, ReportService $reports): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('view_reports')) {
            return redirect()->route(
                $user->hasPermission('set_appointment') ? 'appointments.index' : 'catalog.index'
            );
        }

        $period = $request->input('period');
        if (! in_array($period, ReportService::PERIODS, true)) {
            $period = 'all';
        }

        $month = (string) $request->input('month', '');
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }

        $scopeId   = $user->seesAllData() ? null : $user->id;
        $tenantId  = $user->tenantId();
        $canReport  = true; // gated above — only view_reports users reach here
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
