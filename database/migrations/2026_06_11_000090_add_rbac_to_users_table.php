<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('technician')->after('email'); // admin | technician
            $table->json('permissions')->nullable()->after('role'); // granted capabilities (technicians)
            $table->boolean('active')->default(true)->after('permissions'); // P4 — disabled users cannot log in
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'permissions', 'active']);
        });
    }
};
