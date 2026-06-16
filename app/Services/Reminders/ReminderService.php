<?php

namespace App\Services\Reminders;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 8 — derives the due/overdue follow-up list from next-service dates.
 *
 * The list is computed, never stored. Primary source is client_units (populated by
 * the backfill migration). A legacy fallback covers clients that have service_lines
 * with next_service_date but no client_units rows. The "contacted" flag is overlaid
 * from the reminder_contacts table.
 */
class ReminderService
{
    /**
     * @return array{overdue: list<array<string,mixed>>, due_this_month: list<array<string,mixed>>, stats: array<string,int>}
     */
    public function dueList(?int $tenantId = null, ?int $technicianId = null): array
    {
        $today = Carbon::today();
        $endOfMonth = $today->copy()->endOfMonth();

        // Scoped technicians (no view_all_data) only see reminders for their own clients —
        // clients they have personally serviced. Reused by both the primary and legacy queries.
        $ownClients = fn ($q) => $q->whereExists(function ($s) use ($technicianId) {
            $s->select(DB::raw(1))->from('service_visits as sv_own')
              ->whereColumn('sv_own.client_id', 'c.id')
              ->where('sv_own.technician_id', $technicianId);
        });

        // ── Primary query: clients that have client_units with a next_service_date ──
        $unitRows = DB::table('client_units as cu')
            ->join('clients as c', 'c.id', '=', 'cu.client_id')
            ->leftJoin('reminder_contacts as rc', 'rc.client_id', '=', 'c.id')
            ->whereNull('c.deleted_at')
            ->when($tenantId !== null, fn ($q) => $q->where('c.tenant_id', $tenantId))
            ->when($technicianId !== null, $ownClients)
            ->where('cu.is_active', true)
            ->whereNotNull('cu.next_service_date')
            ->whereNotExists(function ($q) use ($today) {
                $q->select(DB::raw(1))
                  ->from('appointments')
                  ->whereColumn('appointments.client_id', 'c.id')
                  ->whereNotIn('appointments.status', ['cancelled'])
                  ->where('appointments.datetime', '>=', $today->toDateTimeString());
            })
            ->groupBy('c.id', 'c.serial_no', 'c.name', 'c.phone', 'c.address')
            ->havingRaw('MAX(cu.next_service_date) <= ?', [$endOfMonth->toDateString()])
            ->orderByRaw('MAX(cu.next_service_date) asc')
            ->get([
                'c.id as client_id',
                'c.serial_no',
                'c.name',
                'c.phone',
                'c.address',
                DB::raw('MAX(cu.next_service_date) as next_due'),
                DB::raw('(SELECT MAX(sv2.visit_date) FROM service_visits sv2 WHERE sv2.client_id = c.id) as last_service_date'),
                DB::raw('(SELECT u.name FROM service_visits sv_lb LEFT JOIN users u ON u.id = sv_lb.technician_id WHERE sv_lb.client_id = c.id ORDER BY sv_lb.visit_date DESC, sv_lb.id DESC LIMIT 1) as last_service_by'),
                DB::raw('(SELECT u.role FROM service_visits sv_lb LEFT JOIN users u ON u.id = sv_lb.technician_id WHERE sv_lb.client_id = c.id ORDER BY sv_lb.visit_date DESC, sv_lb.id DESC LIMIT 1) as last_service_by_role'),
                DB::raw('MAX(CASE WHEN rc.id IS NULL THEN 0 ELSE 1 END) as contacted_flag'),
                DB::raw('COUNT(DISTINCT cu.id) as units'),
                DB::raw('(SELECT cu2.next_service_type FROM client_units cu2 WHERE cu2.client_id = c.id AND cu2.is_active = TRUE AND cu2.next_service_date IS NOT NULL ORDER BY cu2.next_service_date DESC LIMIT 1) as service_type'),
            ]);

        $coveredIds = $unitRows->pluck('client_id')->all();

        // ── Fallback query: legacy clients with service_lines but no client_units ──
        $legacyRows = DB::table('service_lines as sl')
            ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id')
            ->join('clients as c', 'c.id', '=', 'sv.client_id')
            ->leftJoin('reminder_contacts as rc', 'rc.client_id', '=', 'c.id')
            ->whereNull('c.deleted_at')
            ->when($tenantId !== null, fn ($q) => $q->where('c.tenant_id', $tenantId))
            ->when($technicianId !== null, $ownClients)
            ->whereNotNull('sl.next_service_date')
            ->whereNotExists(function ($q) use ($today) {
                $q->select(DB::raw(1))
                  ->from('appointments')
                  ->whereColumn('appointments.client_id', 'c.id')
                  ->whereNotIn('appointments.status', ['cancelled'])
                  ->where('appointments.datetime', '>=', $today->toDateTimeString());
            })
            ->when(!empty($coveredIds), fn ($q) => $q->whereNotIn('c.id', $coveredIds))
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
                DB::raw('(SELECT MAX(sv2.visit_date) FROM service_visits sv2 WHERE sv2.client_id = c.id) as last_service_date'),
                DB::raw('(SELECT u.name FROM service_visits sv_lb LEFT JOIN users u ON u.id = sv_lb.technician_id WHERE sv_lb.client_id = c.id ORDER BY sv_lb.visit_date DESC, sv_lb.id DESC LIMIT 1) as last_service_by'),
                DB::raw('(SELECT u.role FROM service_visits sv_lb LEFT JOIN users u ON u.id = sv_lb.technician_id WHERE sv_lb.client_id = c.id ORDER BY sv_lb.visit_date DESC, sv_lb.id DESC LIMIT 1) as last_service_by_role'),
                DB::raw('MAX(CASE WHEN rc.id IS NULL THEN 0 ELSE 1 END) as contacted_flag'),
                DB::raw('SUM(sl.units) as units'),
                DB::raw('(SELECT sl2.service_type FROM service_lines sl2 JOIN service_visits sv2 ON sv2.id = sl2.visit_id WHERE sv2.client_id = c.id AND sl2.next_service_date IS NOT NULL ORDER BY sl2.next_service_date DESC LIMIT 1) as service_type'),
            ]);

        $allRows = $unitRows->concat($legacyRows)->sortBy('next_due');

        $todayStr = $today->toDateString();
        $overdue = [];
        $dueThisMonth = [];

        foreach ($allRows as $row) {
            $nextDue = substr((string) $row->next_due, 0, 10);

            $item = [
                'client_id' => (int) $row->client_id,
                'serial_no' => $row->serial_no,
                'name' => $row->name,
                'phone' => $row->phone,
                'address' => $row->address,
                'service_type' => $row->service_type,
                'units' => (int) $row->units,
                'next_due' => $nextDue,
                'last_service_date' => $row->last_service_date ? substr((string) $row->last_service_date, 0, 10) : null,
                'last_service_by' => $row->last_service_by,
                'last_service_by_role' => $row->last_service_by_role,
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
