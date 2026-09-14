<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Stores the resolved funding instrument label, e.g. 'Visa', 'Mastercard',
            // 'American Express', 'PayPal', etc. Populated from PayPal's capture response.
            $table->string('payment_instrument')->nullable()->after('payment_method_type');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('payment_instrument');
        });
    }
};
