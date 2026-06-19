<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            // Dedicated single WhatsApp number for the customer portal's
            // "Set Appointment" link — kept separate from the display `phone`,
            // which may list several numbers.
            $table->string('whatsapp_phone', 50)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn('whatsapp_phone');
        });
    }
};
