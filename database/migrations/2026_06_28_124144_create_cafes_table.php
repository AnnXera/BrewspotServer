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
        Schema::create('cafes', function (Blueprint $table) {
            $table->id('cafe_id'); 
            $table->string('uuid')->unique();
            $table->foreignId('user_id')->constrained('users', 'user_id')->onDelete('cascade');
            $table->string('cafe_name');
            $table->timestamps(); 
        });

        Schema::create('cafe_documents', function (Blueprint $table) {
            $table->id('cafe_doc_id');
            $table->foreignId('cafe_id')->constrained('cafes', 'cafe_id')->onDelete('cascade');
            $table->string('doc_type'); // e.g. 'DTI'
            $table->string('file');     // Stores the generated string upload path
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
        Schema::dropIfExists('cafe_documents');
        Schema::dropIfExists('cafes');
    }
};
