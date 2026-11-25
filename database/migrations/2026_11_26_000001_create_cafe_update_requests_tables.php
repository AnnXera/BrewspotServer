<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner requests to change registered cafe details that an admin must review.
 * Today the only type is 'name_change': a new cafe name plus an updated BIR
 * Certificate of Registration (Form 2303) for every branch, since each branch's
 * COR carries the trade name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cafe_update_requests', function (Blueprint $table) {
            $table->id('request_id');
            $table->uuid('uuid')->unique();
            $table->foreignId('cafe_id')->constrained('cafes', 'cafe_id')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete(); // the owner who asked
            $table->string('type')->default('name_change');
            $table->string('old_name');
            $table->string('new_name');
            $table->string('status')->default('pending_approval'); // pending_approval | approved | rejected | withdrawn
            $table->text('reason')->nullable();
            $table->boolean('can_resubmit')->default(true);
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['cafe_id', 'status']);
        });

        Schema::create('cafe_update_request_documents', function (Blueprint $table) {
            $table->id('request_doc_id');
            $table->foreignId('request_id')->constrained('cafe_update_requests', 'request_id')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('cafe_branches', 'branch_id')->cascadeOnDelete();
            $table->string('file');
            $table->date('registered_at');
            $table->timestamps();

            $table->unique(['request_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cafe_update_request_documents');
        Schema::dropIfExists('cafe_update_requests');
    }
};
