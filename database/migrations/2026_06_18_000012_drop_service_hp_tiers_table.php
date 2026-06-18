<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('service_hp_tiers');
    }

    public function down(): void
    {
        Schema::create('service_hp_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained('service_types')->cascadeOnDelete();
            $table->decimal('hp_value', 3, 1);
            $table->decimal('price', 8, 2);
            $table->timestamps();
            $table->unique(['service_type_id', 'hp_value']);
        });
    }
};
