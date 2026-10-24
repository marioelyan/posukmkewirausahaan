<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * Default expense categories. Idempotent: safe to re-run on deploy.
     *
     * @return void
     */
    public function run()
    {
        $categories = [
            'Perlengkapan',
            'Sewa Tempat',
            'Gaji & Upah',
            'Listrik & Air',
            'Transport & Pengiriman',
            'Pemasaran',
            'Peralatan Kecil',
            'Lainnya',
        ];

        foreach ($categories as $name) {
            ExpenseCategory::firstOrCreate(
                ['name' => $name],
                ['is_active' => true],
            );
        }
    }
}
