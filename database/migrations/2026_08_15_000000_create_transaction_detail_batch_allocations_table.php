<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_detail_batch_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_detail_id');
            $table->foreignId('product_batch_id');
            $table->integer('qty');
            $table->timestamps();

            $table->foreign('transaction_detail_id', 'tdba_detail_fk')
                ->references('id')
                ->on('transaction_details')
                ->cascadeOnDelete();

            $table->foreign('product_batch_id', 'tdba_batch_fk')
                ->references('id')
                ->on('product_batches')
                ->cascadeOnDelete();

            $table->unique(
                ['transaction_detail_id', 'product_batch_id'],
                'tdba_detail_batch_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_detail_batch_allocations');
    }
};
