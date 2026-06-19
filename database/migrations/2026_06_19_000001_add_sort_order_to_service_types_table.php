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
            $table->unsignedInteger('sort_order')->default(0)->after('name');
        });

        // Seed the sequence from the current alphabetical order so existing
        // installs keep a stable, sensible default until an admin reorders.
        $i = 0;
        foreach (DB::table('service_types')->orderBy('name')->pluck('id') as $id) {
            DB::table('service_types')->where('id', $id)->update(['sort_order' => $i++]);
        }
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
