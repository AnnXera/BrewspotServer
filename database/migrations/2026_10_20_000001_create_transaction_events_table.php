<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail of a sale's status changes (refund, void): one row per change.
        Schema::create('transaction_events', function (Blueprint $table) {
            $table->id('transaction_event_id');
            $table->foreignId('transaction_id')->constrained('transactions', 'transaction_id')->cascadeOnDelete();
            $table->string('event', 20); // refunded | voided
            // Manager or owner who approved it. Null once that account is deleted.
            $table->foreignId('user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('reason')->nullable();
            // Refunds: true when the order was never made and its servings went back
            // on sale, false when they were logged as spoilage.
            $table->boolean('restocked')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['transaction_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_events');
    }
};
