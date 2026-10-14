<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The dine-in module has been removed from the application. The original
     * create-migrations were deleted, so this migration is a no-op on fresh
     * installs (the tables never exist) and cleans up existing installs that
     * still carry the tables.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('dine_order_items');
        Schema::dropIfExists('dine_orders');
        Schema::dropIfExists('dine_tables');
        Schema::dropIfExists('dine_areas');

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Irreversible: the dine-in module (models, service, routes, pages and
        // create-migrations) was removed from the codebase.
    }
};
