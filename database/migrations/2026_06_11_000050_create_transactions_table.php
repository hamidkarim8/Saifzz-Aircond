<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('txn_id')->unique(); // TXN-YYYYMMDD-NNN
            $table->foreignId('visit_id')->unique()->constrained('service_visits')->cascadeOnDelete(); // R4 — 1:1
            $table->decimal('amount', 10, 2);
            $table->string('method'); // DuitNow QR | Cash
            $table->string('status')->default('pending'); // pending | paid | failed
            $table->string('gateway_ref')->nullable(); // DuitNow QR webhook reference
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
