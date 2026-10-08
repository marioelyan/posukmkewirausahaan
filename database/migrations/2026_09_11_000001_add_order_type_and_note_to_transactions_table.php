<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->enum('order_type', ['in_store', 'takeaway', 'delivery'])
                ->nullable()
                ->after('qr_string');
            $table->text('note')->nullable()->after('order_type');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['order_type', 'note']);
        });
    }
};
