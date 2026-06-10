<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->date('visit_date');
            $table->unsignedTinyInteger('warranty_months')->default(0); // R5 — 0..6, per visit
            $table->date('warranty_end')->nullable(); // derived = visit_date + warranty_months
            $table->decimal('total_amount', 10, 2)->default(0); // derived = sum of line subtotals
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_visits');
    }
};
