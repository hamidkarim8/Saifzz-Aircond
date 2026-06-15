<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_presets', function (Blueprint $table) {
            $table->id();
            // tenant root = a boss (admin) user; preset is meaningless without it.
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('level'); // 1, 2, 3
            $table->json('permissions');
            $table->timestamps();

            $table->unique(['tenant_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_presets');
    }
};
