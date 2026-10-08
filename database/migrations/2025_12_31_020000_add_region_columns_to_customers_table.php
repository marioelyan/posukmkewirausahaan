<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('province_id', 10)->nullable()->after('address');
            $table->string('province_name')->nullable()->after('province_id');
            $table->string('regency_id', 10)->nullable()->after('province_name');
            $table->string('regency_name')->nullable()->after('regency_id');
            $table->string('district_id', 10)->nullable()->after('regency_name');
            $table->string('district_name')->nullable()->after('district_id');
            $table->string('village_id', 10)->nullable()->after('district_name');
            $table->string('village_name')->nullable()->after('village_id');
            $table->string('postal_code', 10)->nullable()->after('village_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'province_id',
                'province_name',
                'regency_id',
                'regency_name',
                'district_id',
                'district_name',
                'village_id',
                'village_name',
                'postal_code',
            ]);
        });
    }
};
