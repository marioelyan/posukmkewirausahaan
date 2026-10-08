<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_loyalty_member')->default(false)->after('address');
            $table->string('member_code')->nullable()->unique()->after('is_loyalty_member');
            $table->string('loyalty_tier', 30)->default('regular')->after('member_code');
            $table->unsignedInteger('loyalty_points')->default(0)->after('loyalty_tier');
            $table->unsignedBigInteger('loyalty_total_spent')->default(0)->after('loyalty_points');
            $table->unsignedInteger('loyalty_transaction_count')->default(0)->after('loyalty_total_spent');
            $table->timestamp('loyalty_member_since')->nullable()->after('loyalty_transaction_count');
            $table->timestamp('last_purchase_at')->nullable()->after('loyalty_member_since');

            $table->index(['is_loyalty_member', 'loyalty_tier']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points_earned')->default(0)->after('discount');
            $table->unsignedInteger('loyalty_points_redeemed')->default(0)->after('loyalty_points_earned');
            $table->unsignedBigInteger('loyalty_discount_total')->default(0)->after('loyalty_points_redeemed');
            $table->unsignedBigInteger('customer_voucher_discount')->default(0)->after('loyalty_discount_total');
            $table->string('customer_voucher_code')->nullable()->after('customer_voucher_discount');
            $table->string('customer_voucher_name')->nullable()->after('customer_voucher_code');
        });

        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->json('eligible_loyalty_tiers')->nullable()->after('customer_scope');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->dropColumn('eligible_loyalty_tiers');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'loyalty_points_earned',
                'loyalty_points_redeemed',
                'loyalty_discount_total',
                'customer_voucher_discount',
                'customer_voucher_code',
                'customer_voucher_name',
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_is_loyalty_member_loyalty_tier_index');
            $table->dropColumn([
                'is_loyalty_member',
                'member_code',
                'loyalty_tier',
                'loyalty_points',
                'loyalty_total_spent',
                'loyalty_transaction_count',
                'loyalty_member_since',
                'last_purchase_at',
            ]);
        });
    }
};
