<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('label');
            $table->string('unit_type');
            $table->decimal('hp', 3, 2)->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_no')->nullable();
            $table->string('refrigerant_type')->nullable();
            $table->date('next_service_date')->nullable();
            $table->string('next_service_type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_units');
    }
};
