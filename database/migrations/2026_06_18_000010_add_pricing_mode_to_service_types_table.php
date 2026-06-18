<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->string('pricing_mode', 20)->default('flat')->after('name');
        });

        DB::table('service_types')->where('name', 'Repair')->update(['pricing_mode' => 'flexible']);
        if (Schema::hasColumn('service_types', 'is_hp_based')) {
            DB::table('service_types')->where('is_hp_based', true)->update(['pricing_mode' => 'hp_tiered']);
            Schema::table('service_types', function (Blueprint $table) {
                $table->dropColumn('is_hp_based');
            });
        }
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->boolean('is_hp_based')->default(false)->after('name');
        });
        DB::table('service_types')->where('pricing_mode', 'hp_tiered')->update(['is_hp_based' => true]);
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn('pricing_mode');
        });
    }
};
