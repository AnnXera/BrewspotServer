<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafes', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('cafe_branches', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('cafe_documents', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('branch_documents', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('user_documents', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('cafes', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::table('cafe_branches', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::table('cafe_documents', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::table('branch_documents', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::table('user_documents', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};