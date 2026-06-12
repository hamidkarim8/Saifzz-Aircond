<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_visits', function (Blueprint $table) {
            $table->foreignId('technician_id')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('technician_id')->nullable()->after('client_id')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill: best available owner signal for existing visits is who recorded them.
        DB::statement('update service_visits set technician_id = created_by where technician_id is null');
    }

    public function down(): void
    {
        Schema::table('service_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technician_id');
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technician_id');
        });
    }
};
