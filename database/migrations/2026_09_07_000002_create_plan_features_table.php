<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id('plan_feature_id');
            $table->foreignId('sub_plan_id')->constrained('subscription_plans', 'sub_plan_id')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('features', 'feature_id')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['sub_plan_id', 'feature_id']);
        });

        if (Schema::hasColumn('subscription_plans', 'features')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->dropColumn('features');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');

        if (! Schema::hasColumn('subscription_plans', 'features')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->json('features')->nullable()->after('max_branches');
            });
        }
    }
};
