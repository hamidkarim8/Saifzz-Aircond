<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained('service_visits')->cascadeOnDelete();
            $table->string('service_type'); // Cleaning | Gas Top-Up | Repair | Installation | Troubleshoot
            $table->string('unit_type')->nullable(); // Wall Mounted | Cassette; null for Gas & Repair (R3)
            $table->string('gas_option')->nullable(); // 20 PSI | Half Top-Up | Full Top-Up; Gas only
            $table->unsignedInteger('units')->default(1);
            $table->decimal('rate', 10, 2); // R1 — snapshot of ServiceFee rate at service time
            $table->text('repair_desc')->nullable(); // Repair only (R3)
            $table->decimal('discount', 10, 2)->default(0);
            $table->date('next_service_date')->nullable(); // R2 — Cleaning/Installation/Troubleshoot only
            $table->text('notes')->nullable(); // R3 — absent for Repair
            $table->decimal('subtotal', 10, 2); // R8 — derived = max(0, rate*units - discount)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_lines');
    }
};
