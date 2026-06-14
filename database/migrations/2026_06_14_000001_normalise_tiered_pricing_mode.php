<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_fees')
            ->where('pricing_mode', 'tiered')
            ->update(['pricing_mode' => 'fixed_per_unit']);
    }

    public function down(): void
    {
        // tiered is gone — no rollback
    }
};
