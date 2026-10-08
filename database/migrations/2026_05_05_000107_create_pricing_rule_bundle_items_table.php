<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rule_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_rule_id')
                ->constrained('pricing_rules')
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['pricing_rule_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rule_bundle_items');
    }
};
