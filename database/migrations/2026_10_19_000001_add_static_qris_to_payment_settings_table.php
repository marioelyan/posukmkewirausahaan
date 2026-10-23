<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_settings', 'qris_enabled')) {
                $table->boolean('qris_enabled')->default(false)->after('bank_transfer_enabled');
            }

            if (! Schema::hasColumn('payment_settings', 'qris_image_path')) {
                $table->string('qris_image_path')->nullable()->after('qris_enabled');
            }

            if (! Schema::hasColumn('payment_settings', 'qris_merchant_name')) {
                $table->string('qris_merchant_name')->nullable()->after('qris_image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            if (Schema::hasColumn('payment_settings', 'qris_merchant_name')) {
                $table->dropColumn('qris_merchant_name');
            }

            if (Schema::hasColumn('payment_settings', 'qris_image_path')) {
                $table->dropColumn('qris_image_path');
            }

            if (Schema::hasColumn('payment_settings', 'qris_enabled')) {
                $table->dropColumn('qris_enabled');
            }
        });
    }
};
