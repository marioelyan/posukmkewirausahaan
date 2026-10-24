<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Perbaikan 2026-10-25: versi pertama migrasi ini GAGAL di MySQL
        // (error 1830/150) karena expense_category_id NOT NULL dipasangi
        // ON DELETE SET NULL — MySQL menolak FK itu, sementara SQLite
        // meloloskannya sehingga suite tidak menangkapnya. Kegagalan itu
        // meninggalkan tabel expenses parsial (hanya FK business_id) tanpa
        // catatan migrasi; dropIfExists di bawah membersihkan artefak itu
        // sebelum membuat ulang yang benar. Di database baru ini no-op,
        // dan tabel parsial itu dijamin kosong (buat Expense wajib kategori
        // yang ada, dan seeder kategori belum pernah jalan).
        Schema::dropIfExists('expenses');

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('amount');
            $table->string('source');
            $table->string('note')->nullable();
            $table->string('proof_reference')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('expenses');
    }
};
