<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_lines', function (Blueprint $table) {
            $table->dropColumn('gas_option');
        });
    }

    public function down(): void
    {
        Schema::table('service_lines', function (Blueprint $table) {
            $table->string('gas_option')->nullable()->after('unit_type');
        });
    }
};
