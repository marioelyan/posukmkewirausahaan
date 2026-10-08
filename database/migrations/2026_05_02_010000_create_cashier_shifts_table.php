<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('opening_cash')->default(0);
            $table->unsignedBigInteger('expected_cash')->default(0);
            $table->unsignedBigInteger('actual_cash')->nullable();
            $table->unsignedBigInteger('cash_sales_total')->default(0);
            $table->unsignedBigInteger('non_cash_sales_total')->default(0);
            $table->unsignedBigInteger('cash_refund_total')->default(0);
            $table->unsignedBigInteger('non_cash_refund_total')->default(0);
            $table->unsignedInteger('transactions_count')->default(0);
            $table->unsignedInteger('sales_returns_count')->default(0);
            $table->bigInteger('cash_difference')->nullable();
            $table->text('notes')->nullable();
            $table->text('close_notes')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};
