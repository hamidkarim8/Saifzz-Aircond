<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_lines', function (Blueprint $table) {
            $table->decimal('hp_value', 3, 1)->nullable()->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('service_lines', function (Blueprint $table) {
            $table->dropColumn('hp_value');
        });
    }
};
