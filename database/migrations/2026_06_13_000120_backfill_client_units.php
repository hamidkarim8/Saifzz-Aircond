<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $clients = DB::table('clients')->whereNull('deleted_at')->pluck('id');

        foreach ($clients as $clientId) {
            // Skip clients that already have units (idempotent)
            if (DB::table('client_units')->where('client_id', $clientId)->exists()) {
                continue;
            }

            // Group service_lines by unit_type: MAX(units) tells us how many of each type
            $groups = DB::table('service_lines as sl')
                ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id')
                ->where('sv.client_id', $clientId)
                ->whereNotNull('sl.unit_type')
                ->groupBy('sl.unit_type')
                ->select(
                    'sl.unit_type',
                    DB::raw('MAX(sl.units) as max_units'),
                    DB::raw('MAX(sl.next_service_date) as max_next_service_date'),
                )
                ->get();

            foreach ($groups as $group) {
                // Find service_type for the line that had the MAX next_service_date
                $nextServiceType = null;
                if ($group->max_next_service_date) {
                    $nextServiceType = DB::table('service_lines as sl')
                        ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id')
                        ->where('sv.client_id', $clientId)
                        ->where('sl.unit_type', $group->unit_type)
                        ->where('sl.next_service_date', $group->max_next_service_date)
                        ->value('sl.service_type');
                }

                $count = max(1, (int) $group->max_units);
                for ($n = 1; $n <= $count; $n++) {
                    DB::table('client_units')->insert([
                        'client_id'         => $clientId,
                        'label'             => $group->unit_type . ' ' . $n,
                        'unit_type'         => $group->unit_type,
                        'next_service_date' => $n === 1 ? $group->max_next_service_date : null,
                        'next_service_type' => $n === 1 ? $nextServiceType : null,
                        'is_active'         => true,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Non-reversible data migration — down() is a no-op.
    }
};
