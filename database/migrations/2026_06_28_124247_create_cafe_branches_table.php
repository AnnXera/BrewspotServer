<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cafe_branches', function (Blueprint $table) {
            $table->id('branch_id');
            $table->string('uuid')->unique();
            $table->foreignId('cafe_id')->constrained('cafes', 'cafe_id')->onDelete('cascade');
            $table->string('branch_name');
            $table->string('cafe_picture')->nullable();
            $table->string('cafe_email')->unique();
            $table->string('cafe_phonenumber');
            $table->text('address');
            $table->string('branch_type');
            $table->string('status')->default('pending_approval');
            $table->timestamps();
        });

        Schema::create('branch_documents', function (Blueprint $table) {
            $table->id('branch_doc_id');
            $table->foreignId('branch_id')->constrained('cafe_branches', 'branch_id')->onDelete('cascade');
            $table->string('doc_type'); // Local sanitary, business permits, etc.
            $table->string('file');     // Storage path string reference
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_documents');
        Schema::dropIfExists('cafe_branches');
    }
};
