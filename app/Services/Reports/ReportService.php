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
    public function kpis(?int $technicianId = null, ?int $tenantId = null): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $lastStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $paidRevenue = function (Carbon $start, Carbon $end) use ($technicianId, $tenantId): float {
            $q = DB::table('transactions as t')
                ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
                ->where('t.status', 'paid')
                ->whereBetween('t.paid_at', [$start, $end]);
            if ($technicianId !== null) {
                $q->where('sv.technician_id', $technicianId);
            }
            if ($tenantId !== null) {
                $q->where('sv.tenant_id', $tenantId);
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
        if ($tenantId !== null) {
            $allTimeQ->where('sv.tenant_id', $tenantId);
        }
        $revenueAllTime = (float) $allTimeQ->sum('t.amount');

        if ($technicianId === null) {
            $totalClients      = Client::query()->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->count();
            $clientsThisMonth  = Client::query()->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->whereBetween('created_at', [$monthStart, $monthEnd])->count();
            $reminderStats     = $this->reminders->dueList($tenantId)['stats'];
            $pending           = $reminderStats['overdue'] + $reminderStats['due_this_month'];
        } else {
            $totalClients = (int) DB::table('service_visits')
                ->where('technician_id', $technicianId)
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->distinct()->count('client_id');
            $clientsThisMonth = (int) DB::table('service_visits')
                ->where('technician_id', $technicianId)
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereBetween('visit_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->distinct()->count('client_id');
            $pending = (int) DB::table('client_units')
                ->where('is_active', true)
                ->where('next_service_date', '<=', $now->copy()->endOfMonth()->toDateString())
                ->whereIn('client_id', function ($q) use ($technicianId, $tenantId) {
                    $q->select('client_id')->from('service_visits')
                      ->where('technician_id', $technicianId)
                      ->when($tenantId !== null, fn ($sq) => $sq->where('tenant_id', $tenantId))
                      ->distinct();
                })
                ->count();
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
     * Only lines whose visit has a paid transaction are counted (pending/failed/void/no-transaction
     * visits are excluded) so this matches the paid-revenue definition used by kpis().
     * When $technicianId is provided, only visits assigned to that technician are counted.
     *
     * @return list<array{type: string, count: int}>
     */
    public function servicesByType(string $period, ?int $technicianId = null, ?int $tenantId = null): array
    {
        [$from, $to] = $this->range($period);

        $q = DB::table('service_lines as sl')
            ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id')
            ->join('transactions as t', 't.visit_id', '=', 'sv.id')
            ->where('t.status', 'paid');

        if ($technicianId !== null) {
            $q->where('sv.technician_id', $technicianId);
        }
        if ($tenantId !== null) {
            $q->where('sv.tenant_id', $tenantId);
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
    public function transactions(
        string $period,
        ?int $limit = 50,
        ?int $technicianId = null,
        ?int $tenantId = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array
    {
        if (! $from || ! $to) {
            [$from, $to] = $this->range($period);
        }

        $q = DB::table('transactions as t')
            ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
            ->join('clients as c', 'c.id', '=', 'sv.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'sv.created_by')
            ->leftJoinSub(
                DB::table('service_lines')->select('visit_id', DB::raw('MIN(service_type) as service_type'))->groupBy('visit_id'),
                'sl',
                'sl.visit_id',
                '=',
                'sv.id'
            )
            ->select(
                't.txn_id',
                't.visit_id',
                't.amount',
                't.method',
                't.status',
                'c.name as client_name',
                'c.serial_no',
                'sl.service_type',
                'u.name as created_by_name',
                'u.role as created_by_role',
                DB::raw('COALESCE(t.paid_at, t.created_at) as occurred_at'),
            );

        if ($technicianId !== null) {
            $q->where('sv.technician_id', $technicianId);
        }
        if ($tenantId !== null) {
            $q->where('sv.tenant_id', $tenantId);
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
            'visit_id' => $r->visit_id,
            'date' => substr((string) $r->occurred_at, 0, 10),
            'client_name' => $r->client_name,
            'serial_no' => $r->serial_no,
            'service_type' => $r->service_type,
            'amount' => (float) $r->amount,
            'method' => $r->method,
            'status' => $r->status,
            'created_by' => $r->created_by_name,
            'created_by_role' => $r->created_by_role,
        ])->all();
    }

    /**
     * Outstanding (pending) transactions grouped into 4 aging buckets.
     * days_outstanding is computed from PHP's now() so tests using travelTo() work correctly.
     * When $technicianId is provided, only visits assigned to that technician are returned.
     *
     * @return array{buckets: list<array{label:string,days_from:int,days_to:int|null,count:int,total:float}>, items: list<array<string,mixed>>, total_outstanding: float}
     */
    public function receivables(?int $technicianId = null, ?int $tenantId = null): array
    {
        $today = now()->toDateString();

        $rows = DB::table('transactions as t')
            ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
            ->join('clients as c', 'c.id', '=', 'sv.client_id')
            ->whereNull('c.deleted_at')
            ->where('t.status', 'pending')
            ->when($technicianId !== null, fn ($q) => $q->where('sv.technician_id', $technicianId))
            ->when($tenantId !== null, fn ($q) => $q->where('sv.tenant_id', $tenantId))
            ->select([
                'sv.id as visit_id',
                't.txn_id',
                'c.name as client_name',
                'c.serial_no',
                'sv.visit_date',
                't.amount',
                DB::raw("(DATE '{$today}' - sv.visit_date::date) AS days_outstanding"),
            ])
            ->orderByRaw("(DATE '{$today}' - sv.visit_date::date) DESC")
            ->get();

        $buckets = [
            ['label' => 'Current',  'days_from' => 0,  'days_to' => 30,  'count' => 0, 'total' => 0.0],
            ['label' => 'Overdue',  'days_from' => 31, 'days_to' => 60,  'count' => 0, 'total' => 0.0],
            ['label' => 'Late',     'days_from' => 61, 'days_to' => 90,  'count' => 0, 'total' => 0.0],
            ['label' => 'Critical', 'days_from' => 91, 'days_to' => null,'count' => 0, 'total' => 0.0],
        ];
        $items            = [];
        $totalOutstanding = 0.0;

        foreach ($rows as $r) {
            $days             = (int) $r->days_outstanding;
            $amount           = (float) $r->amount;
            $totalOutstanding += $amount;

            $idx = match (true) {
                $days <= 30 => 0,
                $days <= 60 => 1,
                $days <= 90 => 2,
                default     => 3,
            };
            $buckets[$idx]['count']++;
            $buckets[$idx]['total'] += $amount;

            $items[] = [
                'visit_id'         => (int) $r->visit_id,
                'txn_id'           => $r->txn_id,
                'client_name'      => $r->client_name,
                'serial_no'        => $r->serial_no,
                'visit_date'       => substr((string) $r->visit_date, 0, 10),
                'amount'           => round($amount, 2),
                'days_outstanding' => $days,
            ];
        }

        foreach ($buckets as &$bucket) {
            $bucket['total'] = round($bucket['total'], 2);
        }
        unset($bucket);

        return [
            'buckets'           => $buckets,
            'items'             => $items,
            'total_outstanding' => round($totalOutstanding, 2),
        ];
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
