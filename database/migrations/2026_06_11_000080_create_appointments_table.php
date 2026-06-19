<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // loosely linked
            $table->dateTime('datetime');
            $table->string('service_type');
            $table->unsignedInteger('units')->nullable(); // estimate
            $table->string('address')->nullable(); // snapshot at booking, editable
            $table->string('phone')->nullable();
            $table->decimal('amount', 10, 2)->nullable(); // estimate
            $table->string('status')->default('pending'); // pending | completed | cancelled
            $table->boolean('contacted_flag')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
