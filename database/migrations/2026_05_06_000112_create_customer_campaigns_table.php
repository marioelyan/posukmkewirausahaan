<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 40);
            $table->string('status', 20)->default('draft');
            $table->string('channel', 20)->default('internal');
            $table->string('context_key')->nullable()->unique();
            $table->json('audience_filters')->nullable();
            $table->json('audience_snapshot')->nullable();
            $table->text('message_template')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_campaigns');
    }
};
