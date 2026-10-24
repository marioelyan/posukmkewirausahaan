<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Payable;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConsignmentReportController extends Controller
{
    /**
     * Laporan konsinyasi: hutang titipan per penitip.
     *
     * Hanya hutang yang lahir dari penjualan (transaction_id terisi) yang
     * masuk laporan ini. Hutang manual / dari PO tetap terlihat di daftar
     * hutang dan supplier statement, bukan di sini — supaya angka laporan
     * ini persis menjawab "berapa hutang titipan per penitip".
     */
    public function index(Request $request)
    {
        $base = Payable::with(['supplier:id,name', 'transaction:id,invoice'])
            ->whereNotNull('transaction_id')
            ->when($request->input('supplier_id'), fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('start_date'), fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->input('end_date'), fn ($q, $date) => $q->whereDate('created_at', '<=', $date));

        $perSupplier = (clone $base)
            ->selectRaw('supplier_id, COUNT(*) as documents, SUM(total) as total, SUM(paid) as paid')
            ->groupBy('supplier_id')
            ->with('supplier:id,name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'supplier_id' => $row->supplier_id,
                'supplier_name' => $row->supplier?->name ?? '-',
                'documents' => (int) $row->documents,
                'total' => (float) $row->total,
                'paid' => (float) $row->paid,
                'remaining' => max(0, (float) $row->total - (float) $row->paid),
            ])
            ->values()
            ->all();

        $grand = [
            'documents' => array_sum(array_column($perSupplier, 'documents')),
            'total' => array_sum(array_column($perSupplier, 'total')),
            'paid' => array_sum(array_column($perSupplier, 'paid')),
            'remaining' => array_sum(array_column($perSupplier, 'remaining')),
        ];

        $payables = $base->latest()->paginate($this->perPage())->withQueryString();

        return Inertia::render('Dashboard/Reports/Consignment', [
            'payables' => $payables,
            'perSupplier' => $perSupplier,
            'grand' => $grand,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['supplier_id', 'status', 'start_date', 'end_date']),
        ]);
    }
}
