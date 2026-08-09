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
        Schema::create('cafe_staff', function (Blueprint $table) {
            $table->id('staff_id');
            $table->string('uuid')->unique();

            // The staff member's login account (role Manager or Cashier).
            // System-level permissions come from users.role_id — this table
            // only tracks *which branch* that account is assigned to.
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');

            // Branch the staff member is assigned to.
            $table->foreignId('branch_id')->constrained('cafe_branches', 'branch_id')->onDelete('cascade');

            // Free-text job title, distinct from the coarse system role
            // (e.g. "Shift Lead", "Head Barista"), since roles only cover
            // Admin / Cafe Owner / Manager / Cashier.
            $table->string('position')->nullable();

            // 'active', 'inactive', 'suspended', 'terminated'
            $table->string('employment_status')->default('active');

            $table->date('hired_at')->nullable();

            $table->timestamps();

            // A user can't be assigned to the same branch twice.
            $table->unique(['user_id', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cafe_staff');
    }
};