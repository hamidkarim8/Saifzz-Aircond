<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_fees', function (Blueprint $table) {
            $table->id();
            $table->string('service_type'); // Cleaning | Gas Top-Up | Repair | Installation | Troubleshoot
            $table->string('option')->nullable(); // unit_type or gas_option; null for Repair
            $table->decimal('rate', 10, 2)->nullable(); // null for flexible (Repair)
            $table->string('pricing_mode'); // fixed_per_unit | tiered | flexible
            $table->timestamps();

            $table->unique(['service_type', 'option']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_fees');
    }
};
