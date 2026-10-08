<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Services\StockMutationService;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

/**
 * Persists each row itself (updateOrCreate + warehouse pivot) inside model(),
 * so the import must NOT use batch mass-inserts: ModelManager::massFlush()
 * would re-insert the already-persisted attributes (duplicate primary key).
 * Without WithBatchInserts, rows are flushed one by one via saveOrFail().
 */
class ProductsImport implements ToModel, WithChunkReading, WithHeadingRow, WithValidation
{
    private int $rowCount = 0;

    public function __construct(
        private readonly ?int $warehouseId = null,
        private readonly ?int $userId = null,
    ) {}

    public function model(array $row)
    {
        $this->rowCount++;
        $categoryName = trim($row['kategori'] ?? 'Umum');
        $category = Category::firstOrCreate(
            ['name' => $categoryName],
            ['description' => '', 'image' => 'default.png']
        );

        $barcode = (string) ($row['barcode'] ?? '');

        return DB::transaction(function () use ($row, $barcode, $category) {
            $product = Product::updateOrCreate(
                ['barcode' => $barcode],
                [
                    'sku' => $row['sku'] ?? $barcode,
                    'title' => $row['nama'] ?? '',
                    'description' => $row['deskripsi'] ?? '',
                    'category_id' => $category->id,
                    'buy_price' => (int) ($row['harga_beli'] ?? 0),
                    'sell_price' => (int) ($row['harga_jual'] ?? 0),
                    'stock' => (int) ($row['stok'] ?? 0),
                    'min_stock' => (int) ($row['min_stok'] ?? 0),
                    'max_stock' => (int) ($row['max_stok'] ?? 0),
                    'tax_type' => $row['tipe_pajak'] ?? 'exclusive',
                    'tax_rate' => (float) ($row['tarif_pajak'] ?? 11.00),
                ]
            );

            if ($this->warehouseId) {
                ProductWarehouse::updateOrCreate(
                    ['product_id' => $product->id, 'warehouse_id' => $this->warehouseId],
                    ['stock' => (int) $product->stock]
                );

                if ($product->wasRecentlyCreated) {
                    app(StockMutationService::class)->recordInitialStock(
                        $product,
                        $this->userId,
                        $this->warehouseId,
                    );
                }
            }

            return $product;
        });
    }

    public function rules(): array
    {
        return [
            'barcode' => ['required'],
            'nama' => ['required', 'string', 'max:255'],
            'harga_beli' => ['nullable', 'numeric', 'min:0'],
            'harga_jual' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            'barcode.required' => 'Barcode wajib diisi.',
            'barcode.unique' => 'Barcode sudah terdaftar.',
            'nama.required' => 'Nama produk wajib diisi.',
        ];
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }
}
