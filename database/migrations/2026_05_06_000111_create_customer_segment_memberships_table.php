<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_segment_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_segment_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['customer_id', 'customer_segment_id'],
                'cust_seg_membership_unique'
            );
            $table->index(
                ['customer_segment_id', 'source'],
                'cust_seg_membership_source_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_segment_memberships');
    }
};
