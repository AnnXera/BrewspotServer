<?php

use App\Models\Ingredient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One ingredient list per cafe, so "Milk" and "milk" are the same thing in
 * recipes and in consumption totals. Recipes and consumption logs keep their
 * ingredient_name/unit columns: recipes mirror the ingredient's name, logs
 * keep it as a snapshot of what it was called at the time of the sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id('ingredient_id');
            $table->string('uuid')->unique();
            $table->foreignId('cafe_id')->constrained('cafes', 'cafe_id')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->string('unit', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['cafe_id', 'normalized_name']);
        });

        Schema::table('menu_recipes', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->nullable()->after('men_item_id')
                ->constrained('ingredients', 'ingredient_id')->nullOnDelete();
        });

        Schema::table('ingredient_consumption_logs', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->nullable()->after('transaction_item_id')
                ->constrained('ingredients', 'ingredient_id')->nullOnDelete();
        });

        $this->backfillRecipes();

        // Added after the backfill so existing duplicate rows can't block it.
        Schema::table('menu_recipes', function (Blueprint $table) {
            $table->unique(['men_item_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::table('menu_recipes', function (Blueprint $table) {
            $table->dropUnique(['men_item_id', 'ingredient_id']);
            $table->dropConstrainedForeignId('ingredient_id');
        });

        Schema::table('ingredient_consumption_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_id');
        });

        Schema::dropIfExists('ingredients');
    }

    /**
     * Groups existing recipe names per cafe (ignoring case and spacing),
     * creates one ingredient per group using its most common unit, and links
     * the rows. Repeat rows for the same ingredient on one item are folded into
     * the first (the new unique key forbids them).
     */
    private function backfillRecipes(): void
    {
        $rows = DB::table('menu_recipes')
            ->join('menu_items', 'menu_items.men_item_id', '=', 'menu_recipes.men_item_id')
            ->whereNotNull('menu_items.cafe_id')
            ->select('menu_recipes.men_recipe_id', 'menu_recipes.men_item_id', 'menu_recipes.ingredient_name',
                'menu_recipes.unit', 'menu_items.cafe_id')
            ->orderBy('menu_recipes.men_recipe_id')
            ->get();

        $groups = $rows->groupBy(fn ($r) => $r->cafe_id . '|' . Ingredient::normalize($r->ingredient_name));

        foreach ($groups as $group) {
            $first = $group->first();

            $ingredientId = DB::table('ingredients')->insertGetId([
                'uuid'            => (string) Str::uuid(),
                'cafe_id'         => $first->cafe_id,
                'name'            => Ingredient::tidy($first->ingredient_name),
                'normalized_name' => Ingredient::normalize($first->ingredient_name),
                'unit'            => $group->countBy('unit')->sortDesc()->keys()->first(),
                'is_active'       => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ], 'ingredient_id');

            foreach ($group->groupBy('men_item_id') as $itemRows) {
                $keep   = $itemRows->shift();
                $update = ['ingredient_id' => $ingredientId];

                // Same unit → add the amounts together; otherwise keep the first row's.
                if ($itemRows->isNotEmpty() && $itemRows->every(fn ($r) => $r->unit === $keep->unit)) {
                    $update['quantity'] = DB::table('menu_recipes')
                        ->whereIn('men_recipe_id', $itemRows->pluck('men_recipe_id')->push($keep->men_recipe_id))
                        ->sum('quantity');
                }

                DB::table('menu_recipes')->where('men_recipe_id', $keep->men_recipe_id)->update($update);

                if ($itemRows->isNotEmpty()) {
                    DB::table('menu_recipes')->whereIn('men_recipe_id', $itemRows->pluck('men_recipe_id'))->delete();
                }
            }
        }
    }
};
