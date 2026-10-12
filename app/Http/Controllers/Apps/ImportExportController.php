<?php

namespace App\Http\Controllers\Apps;

use App\Exports\CustomersExport;
use App\Exports\ProductsExport;
use App\Exports\TransactionsExport;
use App\Http\Controllers\Controller;
use App\Imports\CustomersImport;
use App\Imports\ProductsImport;
use App\Models\Outlet;
use App\Rules\BelongsToActiveBusiness;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class ImportExportController extends Controller
{
    public function __construct(private readonly OutletAccessService $outletAccessService) {}

    public function exportProducts()
    {
        return Excel::download(new ProductsExport, 'produk.xlsx');
    }

    public function exportCustomers()
    {
        return Excel::download(new CustomersExport, 'customer.xlsx');
    }

    public function exportTransactions(Request $request)
    {
        // A client must not be able to widen an export by naming another
        // business's warehouse. The rule is a no-op in legacy mode.
        $request->validate([
            'warehouse_id' => ['nullable', 'exists:warehouses,id', new BelongsToActiveBusiness('warehouses')],
        ]);

        $tenancyEnabled = (bool) config('multi_business.enabled');

        // When tenancy is enabled every operator (super-admin included) is
        // constrained to the active business's warehouses so an export can never
        // blend businesses. Legacy mode keeps the historical super-admin bypass.
        $warehouseIds = $request->user()->isSuperAdmin() && ! $tenancyEnabled
            ? null
            : $this->outletAccessService->warehousesFor($request->user())->pluck('id')->all();

        $includeLegacy = $tenancyEnabled ? false : Outlet::active()->count() <= 1;

        return Excel::download(new TransactionsExport($request, $warehouseIds, $includeLegacy), 'transaksi.xlsx');
    }

    public function importProducts(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id', new BelongsToActiveBusiness('warehouses')],
        ]);

        // same warehouse resolution/authorization as manual product creation
        $warehouse = $this->outletAccessService->defaultStockWarehouseFor(
            $request->user(),
            $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null,
        );
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);

        $import = new ProductsImport($warehouse?->id, $request->user()?->id);
        Excel::import($import, $request->file('file'));

        $successCount = $import->getRowCount();

        return back()->with('success', "Import selesai. {$successCount} produk diimport.");
    }

    public function importCustomers(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $import = new CustomersImport;
        Excel::import($import, $request->file('file'));

        return back()->with('success', 'Import customer selesai.');
    }

    public function downloadTemplate(string $type)
    {
        $headings = match ($type) {
            'products' => ['barcode', 'sku', 'nama', 'deskripsi', 'kategori', 'harga_beli', 'harga_jual', 'stok', 'min_stok', 'max_stok', 'tipe_pajak', 'tarif_pajak'],
            'customers' => ['nama', 'telepon', 'alamat'],
            default => abort(404),
        };

        return Excel::download(
            new class($headings) implements FromArray, WithHeadings
            {
                public function __construct(private array $headings) {}

                public function headings(): array
                {
                    return $this->headings;
                }

                public function array(): array
                {
                    return [];
                }
            },
            "template-{$type}.xlsx"
        );
    }
}
