<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('api_token');
            $table->text('portal_key');
            $table->text('api_secret');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_gateways');
    }
};
