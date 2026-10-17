<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Multi-sheet export of the Advanced Sales Insights page. The controller passes
 * the already-filtered, already-scoped metric arrays so the export can never
 * widen the tenancy scope of the screen it was triggered from.
 */
class InsightsExport implements WithMultipleSheets
{
    private const COVERAGE_LABELS = [
        'critical' => 'Kritis',
        'low' => 'Rendah',
        'healthy' => 'Sehat',
        'no_movement' => 'Tidak Bergerak',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private readonly array $data) {}

    public function sheets(): array
    {
        return [
            $this->summarySheet(),
            $this->salesByDaySheet(),
            $this->salesByHourSheet(),
            $this->topSellingSheet(),
            $this->lowPerformingSheet(),
            $this->marginByProductSheet(),
            $this->marginByCategorySheet(),
            $this->cashierPerformanceSheet(),
            $this->stockCoverageSheet(),
        ];
    }

    private function summarySheet(): InsightsSheet
    {
        $summary = $this->data['summary'];

        return new InsightsSheet([
            ['Periode', $this->data['rangeStart'].' s.d. '.$this->data['rangeEnd']],
            ['Jumlah Transaksi', $summary['orders_count']],
            ['Pendapatan', $summary['revenue_total']],
            ['Profit', $summary['profit_total']],
            ['Item Terjual', $summary['items_sold']],
            ['Diskon Manual', $summary['manual_discount_total']],
            ['Rata-rata Nilai Order', $summary['average_order']],
        ], ['Metrik', 'Nilai'], 'Ringkasan');
    }

    private function salesByDaySheet(): InsightsSheet
    {
        $rows = array_map(fn (array $row) => [
            $row['date'],
            $row['orders_count'],
            $row['revenue_total'],
        ], $this->data['salesByDay']);

        return new InsightsSheet($rows, ['Tanggal', 'Transaksi', 'Omzet'], 'Penjualan Harian');
    }

    private function salesByHourSheet(): InsightsSheet
    {
        $rows = array_map(fn (array $row) => [
            $row['label'],
            $row['orders_count'],
            $row['revenue_total'],
        ], $this->data['salesByHour']);

        return new InsightsSheet($rows, ['Jam', 'Transaksi', 'Omzet'], 'Penjualan Per Jam');
    }

    private function topSellingSheet(): InsightsSheet
    {
        $rows = array_map(fn (array $row) => [
            $row['product_title'],
            $row['product_sku'],
            $row['category_name'],
            $row['qty_sold'],
            $row['revenue_total'],
            $row['profit_total'],
            $row['current_stock'],
            $row['last_sold_at'] ?? '',
        ], $this->data['topSellingProducts']);

        return new InsightsSheet(
            $rows,
            ['Produk', 'SKU', 'Kategori', 'Terjual', 'Omzet', 'Profit', 'Stok', 'Terakhir Terjual'],
            'Produk Terlaris'
        );
    }

    private function lowPerformingSheet(): InsightsSheet
    {
        $rows = array_map(fn (array $row) => [
            $row['product_title'],
            $row['product_sku'],
            $row['category_name'],
            $row['qty_sold'],
            $row['revenue_total'],
            $row['profit_total'],
            $row['current_stock'],
            $row['last_sold_at'] ?? '',
        ], $this->data['lowPerformingProducts']);

        return new InsightsSheet(
            $rows,
            ['Produk', 'SKU', 'Kategori', 'Terjual', 'Omzet', 'Profit', 'Stok', 'Terakhir Terjual'],
            'Produk Kurang Laku'
        );
    }

    private function marginByProductSheet(): InsightsSheet
    {
        $rows = array_map(fn (array $row) => [
            $row['product_title'],
            $row['category_name'],
            $row['qty_sold'],
            $row['revenue_total'],
            $row['profit_total'],
            $row['margin_percentage'],
        ], $this->data['marginByProduct']);

        return new InsightsSheet($rows, ['Produk', 'Kategori', 'Terjual', 'Omzet', 'Profit', 'Margin %'], 'Margin Produk');
    }

    private function marginByCategorySheet(): InsightsSheet
    {
        $rows = array_map(fn (array $row) => [
            $row['category_name'],
            $row['qty_sold'],
            $row['revenue_total'],
            $row['profit_total'],
            $row['margin_percentage'],
        ], $this->data['marginByCategory']);

        return new InsightsSheet($rows, ['Kategori', 'Terjual', 'Omzet', 'Profit', 'Margin %'], 'Margin Kategori');
    }

    private function cashierPerformanceSheet(): InsightsSheet
    {
        $rows = array_map(fn (array $row) => [
            $row['cashier_name'],
            $row['orders_count'],
            $row['items_sold'],
            $row['revenue_total'],
            $row['profit_total'],
            $row['average_basket'],
        ], $this->data['cashierPerformance']);

        return new InsightsSheet(
            $rows,
            ['Kasir', 'Transaksi', 'Item', 'Omzet', 'Profit', 'Rata-rata Basket'],
            'Performa Kasir'
        );
    }

    private function stockCoverageSheet(): InsightsSheet
    {
        $rows = array_map(fn (array $row) => [
            $row['product_title'],
            $row['product_sku'],
            $row['category_name'],
            $row['current_stock'],
            $row['qty_sold'],
            $row['average_daily_qty'],
            $row['coverage_days'] ?? '',
            self::COVERAGE_LABELS[$row['coverage_status']] ?? $row['coverage_status'],
        ], $this->data['stockCoverage']['products'] ?? []);

        return new InsightsSheet(
            $rows,
            ['Produk', 'SKU', 'Kategori', 'Stok', 'Terjual', 'Rata-rata/Hari', 'Coverage Hari', 'Status'],
            'Coverage Stok'
        );
    }
}
