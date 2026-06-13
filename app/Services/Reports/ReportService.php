<?php

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\Transaction;
use App\Services\Reminders\ReminderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 9 — read-only aggregates for the dashboard (KPIs, services-by-type, transactions).
 *
 * Reads existing module data only; adds no tables. The period filter (all/month/week/today)
 * scopes the chart, the transaction list, and the CSV export so they always agree.
 */
class ReportService
{
    public const PERIODS = ['all', 'month', 'week', 'today'];

    public function __construct(private ReminderService $reminders) {}

    /**
     * Four KPI cards. Revenue counts only paid transactions; the month card is the current
     * calendar month with a month-over-month delta. Pending reminders reuses module 8.
     * When $technicianId is provided, revenue and client counts are scoped to that technician;
     * pending_reminders is null (client-global, not meaningful per-technician in v1).
     *
     * @return array<string, int|float|null>
     */
    public function kpis(?int $technicianId = null): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $lastStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $paidRevenue = function (Carbon $start, Carbon $end) use ($technicianId): float {
            $q = DB::table('transactions as t')
                ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
                ->where('t.status', 'paid')
                ->whereBetween('t.paid_at', [$start, $end]);
            if ($technicianId !== null) {
                $q->where('sv.technician_id', $technicianId);
            }
            return (float) $q->sum('t.amount');
        };

        $revenueMonth = $paidRevenue($monthStart, $monthEnd);
        $revenueLast  = $paidRevenue($lastStart, $lastEnd);

        $allTimeQ = DB::table('transactions as t')
            ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
            ->where('t.status', 'paid');
        if ($technicianId !== null) {
            $allTimeQ->where('sv.technician_id', $technicianId);
        }
        $revenueAllTime = (float) $allTimeQ->sum('t.amount');

        if ($technicianId === null) {
            $totalClients      = Client::count();
            $clientsThisMonth  = Client::whereBetween('created_at', [$monthStart, $monthEnd])->count();
            $reminderStats     = $this->reminders->dueList()['stats'];
            $pending           = $reminderStats['overdue'] + $reminderStats['due_this_month'];
        } else {
            $totalClients = (int) DB::table('service_visits')
                ->where('technician_id', $technicianId)->distinct()->count('client_id');
            $clientsThisMonth = (int) DB::table('service_visits')
                ->where('technician_id', $technicianId)
                ->whereBetween('visit_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->distinct()->count('client_id');
            $pending = null; // reminders are client-global; omitted for scoped techs (v1)
        }

        return [
            'total_clients'      => $totalClients,
            'clients_this_month' => $clientsThisMonth,
            'revenue_month'      => $revenueMonth,
            'revenue_mom_pct'    => $revenueLast > 0 ? (int) round((($revenueMonth - $revenueLast) / $revenueLast) * 100) : null,
            'revenue_all_time'   => $revenueAllTime,
            'pending_reminders'  => $pending,
        ];
    }

    /**
     * Count of service lines grouped by service type, scoped to the period by visit_date.
     * When $technicianId is provided, only visits assigned to that technician are counted.
     *
     * @return list<array{type: string, count: int}>
     */
    public function servicesByType(string $period, ?int $technicianId = null): array
    {
        [$from, $to] = $this->range($period);

        $q = DB::table('service_lines as sl')
            ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id');

        if ($technicianId !== null) {
            $q->where('sv.technician_id', $technicianId);
        }

        if ($from) {
            $q->whereBetween('sv.visit_date', [$from->toDateString(), $to->toDateString()]);
        }

        return $q->groupBy('sl.service_type')
            ->select('sl.service_type as type', DB::raw('count(*) as count'))
            ->orderByRaw('count(*) desc')
            ->get()
            ->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->count])
            ->all();
    }

    /**
     * Transactions within the period (by COALESCE(paid_at, created_at)), newest first.
     * When $technicianId is provided, only transactions for visits assigned to that technician are returned.
     *
     * @return list<array<string, mixed>>
     */
    public function transactions(string $period, ?int $limit = 50, ?int $technicianId = null): array
    {
        [$from, $to] = $this->range($period);

        $q = DB::table('transactions as t')
            ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
            ->join('clients as c', 'c.id', '=', 'sv.client_id')
            ->leftJoinSub(
                DB::table('service_lines')->select('visit_id', DB::raw('MIN(service_type) as service_type'))->groupBy('visit_id'),
                'sl',
                'sl.visit_id',
                '=',
                'sv.id'
            )
            ->select(
                't.txn_id',
                't.amount',
                't.method',
                't.status',
                'c.name as client_name',
                'c.serial_no',
                'sl.service_type',
                DB::raw('COALESCE(t.paid_at, t.created_at) as occurred_at'),
            );

        if ($technicianId !== null) {
            $q->where('sv.technician_id', $technicianId);
        }

        if ($from) {
            $q->whereRaw('COALESCE(t.paid_at, t.created_at) between ? and ?', [$from, $to]);
        }

        $q->orderByRaw('COALESCE(t.paid_at, t.created_at) desc');

        if ($limit) {
            $q->limit($limit);
        }

        return $q->get()->map(fn ($r) => [
            'txn_id' => $r->txn_id,
            'date' => substr((string) $r->occurred_at, 0, 10),
            'client_name' => $r->client_name,
            'serial_no' => $r->serial_no,
            'service_type' => $r->service_type,
            'amount' => (float) $r->amount,
            'method' => $r->method,
            'status' => $r->status,
        ])->all();
    }

    /**
     * Period → [from, to] Carbon bounds. 'all' → [null, null] (unbounded).
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function range(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [null, null],
        };
    }
}
