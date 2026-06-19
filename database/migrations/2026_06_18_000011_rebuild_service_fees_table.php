<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('service_fees');

        Schema::create('service_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_type_id')->constrained('service_types')->cascadeOnDelete();
            $table->string('unit_type');
            $table->decimal('hp_value', 3, 1)->nullable();
            $table->decimal('price', 8, 2);
            $table->timestamps();

            $table->unique(['service_type_id', 'unit_type', 'hp_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_fees');

        Schema::create('service_fees', function (Blueprint $table) {
            $table->id();
            $table->string('service_type');
            $table->string('option')->nullable();
            $table->decimal('rate', 8, 2)->nullable();
            $table->string('pricing_mode')->default('fixed_per_unit');
            $table->timestamps();
            $table->unique(['service_type', 'option']);
        });
    }
};
