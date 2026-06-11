<?php

namespace App\Services\Reminders;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 8 — derives the due/overdue follow-up list from service-line next-service dates.
 *
 * The list is computed, never stored. A client's single reminder date is the MAX
 * next_service_date across all of their service lines (latest recommendation wins; null
 * dates from Repair/Gas lines don't contribute). The "contacted" flag is overlaid from the
 * reminder_contacts table.
 */
class ReminderService
{
    /**
     * @return array{overdue: list<array<string,mixed>>, due_this_month: list<array<string,mixed>>, stats: array<string,int>}
     */
    public function dueList(): array
    {
        $today = Carbon::today();
        $endOfMonth = $today->copy()->endOfMonth();

        $rows = DB::table('service_lines as sl')
            ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id')
            ->join('clients as c', 'c.id', '=', 'sv.client_id')
            ->leftJoin('reminder_contacts as rc', 'rc.client_id', '=', 'c.id')
            ->whereNull('c.deleted_at')
            ->whereNotNull('sl.next_service_date')
            ->groupBy('c.id', 'c.serial_no', 'c.name', 'c.phone', 'c.address')
            ->havingRaw('MAX(sl.next_service_date) <= ?', [$endOfMonth->toDateString()])
            ->orderByRaw('MAX(sl.next_service_date) asc')
            ->get([
                'c.id as client_id',
                'c.serial_no',
                'c.name',
                'c.phone',
                'c.address',
                DB::raw('MAX(sl.next_service_date) as next_due'),
                DB::raw('MAX(sv.visit_date) as last_service_date'),
                DB::raw('MAX(CASE WHEN rc.id IS NULL THEN 0 ELSE 1 END) as contacted_flag'),
            ]);

        $todayStr = $today->toDateString();
        $overdue = [];
        $dueThisMonth = [];

        foreach ($rows as $row) {
            $nextDue = substr((string) $row->next_due, 0, 10);

            $item = [
                'client_id' => (int) $row->client_id,
                'serial_no' => $row->serial_no,
                'name' => $row->name,
                'phone' => $row->phone,
                'address' => $row->address,
                'next_due' => $nextDue,
                'last_service_date' => $row->last_service_date ? substr((string) $row->last_service_date, 0, 10) : null,
                'contacted' => (bool) $row->contacted_flag,
            ];

            if ($nextDue < $todayStr) {
                $overdue[] = $item;
            } else {
                $dueThisMonth[] = $item;
            }
        }

        $contactedCount = collect($overdue)->where('contacted', true)->count()
            + collect($dueThisMonth)->where('contacted', true)->count();

        return [
            'overdue' => $overdue,
            'due_this_month' => $dueThisMonth,
            'stats' => [
                'overdue' => count($overdue),
                'due_this_month' => count($dueThisMonth),
                'contacted' => $contactedCount,
            ],
        ];
    }
}
