<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('appointments')->where('status', 'confirmed')->update(['status' => 'pending']);
        DB::table('appointments')->where('status', 'done')->update(['status' => 'completed']);
    }

    public function down(): void
    {
        DB::table('appointments')->where('status', 'completed')->update(['status' => 'done']);
    }
};
