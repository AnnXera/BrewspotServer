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
        // 1. Create Roles table first so Users can reference its PK
        Schema::create('roles', function (Blueprint $table) {
            $table->id('role_id'); 
            $table->uuid('uuid')->unique();
            $table->string('role_name');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });

        // 2. Updated Users table matching the Approval Workflow
        Schema::create('users', function (Blueprint $table) {
            $table->id('user_id'); 
            $table->uuid('uuid')->unique();
            
            // Profile fields must be nullable initially since they are completed in Step 3
            $table->string('firstname')->nullable();
            $table->string('middlename')->nullable(); 
            $table->string('lastname')->nullable();
            $table->string('username')->nullable()->unique(); // Nullable but still unique once filled
            
            // Password must be nullable because it is generated at the very end after admin approval
            $table->string('password_hash')->nullable(); 
            
            $table->string('email')->unique();
            $table->string('phone_number')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            
            // Options: 'email_unverified','pending_application', 'approved', 'rejected', 'active', 'inactive'
            $table->string('status')->default('email_unverified');
            
            // Foreign Key pointing to the roles table defined above
            $table->foreignId('role_id')->constrained('roles', 'role_id')->onDelete('cascade');
            
            $table->rememberToken(); 
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });

        // 3. Default Laravel tables
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index()->constrained('users', 'user_id')->onDelete('cascade');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('user_documents', function (Blueprint $table) {
            $table->id('user_doc_id'); // Matches your custom primary key
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->string('file');    // Path string to the stored document file
            $table->string('id_type'); // 'national_id', 'passport', 'drivers_license', etc.
            
            // 💡 Standard Laravel timestamps provide `created_at` (uploaded time) and `updated_at` out of the box
            $table->timestamps(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_documents');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
    }
};