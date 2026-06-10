<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->char('serial_no', 6)->unique(); // R6 — auto-generated, zero-padded, monotonic
            $table->string('name');
            $table->string('phone');
            $table->text('address');
            $table->timestamps();
            $table->softDeletes(); // R7 — never hard-delete clients with financial history
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
