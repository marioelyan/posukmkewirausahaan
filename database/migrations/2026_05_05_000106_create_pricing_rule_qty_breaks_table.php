<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rule_qty_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_rule_id')
                ->constrained('pricing_rules')
                ->cascadeOnDelete();
            $table->unsignedInteger('min_qty');
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 15, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['pricing_rule_id', 'min_qty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rule_qty_breaks');
    }
};
