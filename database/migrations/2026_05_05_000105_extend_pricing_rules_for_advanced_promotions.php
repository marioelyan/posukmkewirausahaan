<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->string('kind', 30)
                ->default('standard_discount')
                ->after('name');
            $table->unsignedInteger('preview_quantity_multiplier')
                ->default(1)
                ->after('ends_at');

            $table->index(['kind', 'is_active', 'priority']);
        });

        Schema::table('transaction_details', function (Blueprint $table) {
            $table->string('pricing_rule_kind', 30)
                ->nullable()
                ->after('pricing_rule_name');
            $table->string('pricing_group_key')
                ->nullable()
                ->after('pricing_rule_kind');
            $table->string('pricing_group_label')
                ->nullable()
                ->after('pricing_group_key');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->dropColumn([
                'pricing_rule_kind',
                'pricing_group_key',
                'pricing_group_label',
            ]);
        });

        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropIndex(['kind', 'is_active', 'priority']);
            $table->dropColumn([
                'kind',
                'preview_quantity_multiplier',
            ]);
        });
    }
};
