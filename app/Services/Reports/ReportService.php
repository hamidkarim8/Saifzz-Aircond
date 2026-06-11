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
     *
     * @return array<string, int|float|null>
     */
    public function kpis(): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $lastStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $revenueMonth = (float) Transaction::where('status', 'paid')
            ->whereBetween('paid_at', [$monthStart, $monthEnd])->sum('amount');
        $revenueLast = (float) Transaction::where('status', 'paid')
            ->whereBetween('paid_at', [$lastStart, $lastEnd])->sum('amount');

        $reminderStats = $this->reminders->dueList()['stats'];

        return [
            'total_clients' => Client::count(),
            'clients_this_month' => Client::whereBetween('created_at', [$monthStart, $monthEnd])->count(),
            'revenue_month' => $revenueMonth,
            'revenue_mom_pct' => $revenueLast > 0 ? (int) round((($revenueMonth - $revenueLast) / $revenueLast) * 100) : null,
            'revenue_all_time' => (float) Transaction::where('status', 'paid')->sum('amount'),
            'pending_reminders' => $reminderStats['overdue'] + $reminderStats['due_this_month'],
        ];
    }

    /**
     * Count of service lines grouped by service type, scoped to the period by visit_date.
     *
     * @return list<array{type: string, count: int}>
     */
    public function servicesByType(string $period): array
    {
        [$from, $to] = $this->range($period);

        $q = DB::table('service_lines as sl')
            ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id');

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
     *
     * @return list<array<string, mixed>>
     */
    public function transactions(string $period, ?int $limit = 50): array
    {
        [$from, $to] = $this->range($period);

        $q = DB::table('transactions as t')
            ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
            ->join('clients as c', 'c.id', '=', 'sv.client_id')
            ->select(
                't.txn_id',
                't.amount',
                't.method',
                't.status',
                'c.name as client_name',
                'c.serial_no',
                DB::raw('COALESCE(t.paid_at, t.created_at) as occurred_at'),
            );

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
