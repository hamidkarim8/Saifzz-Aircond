<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Tenant root = a boss (admin) user. tenant_id references users.id:
            // bosses self-root (tenant_id = own id), technicians/clients/rows inherit it.
            // No separate tenants table by design (CHG-002 — two bosses, each = own tenant).
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
