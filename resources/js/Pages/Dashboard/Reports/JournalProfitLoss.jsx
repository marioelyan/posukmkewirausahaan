import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router } from "@inertiajs/react";
import { IconChartBar } from "@tabler/icons-react";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

function Row({ label, value, bold = false, note = "", negative = false }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-slate-100 py-3 last:border-0 dark:border-slate-800">
            <div>
                <p className={`text-sm ${bold ? "font-bold text-slate-900 dark:text-white" : "text-slate-700 dark:text-slate-200"}`}>
                    {label}
                </p>
                {note && <p className="mt-0.5 text-xs text-slate-400">{note}</p>}
            </div>
            <p className={`text-sm whitespace-nowrap ${bold ? "font-bold text-slate-900 dark:text-white" : negative ? "text-danger-600 dark:text-danger-400" : "text-slate-900 dark:text-white"}`}>
                {formatCurrency(value)}
            </p>
        </div>
    );
}

export default function JournalProfitLoss({ summary, reconciliation = null, filters = {} }) {
    const [startDate, setStartDate] = useState(filters.start_date || "");
    const [endDate, setEndDate] = useState(filters.end_date || "");

    const applyFilters = () => {
        router.get(
            route("reports.journal-profit-loss.index"),
            {
                start_date: startDate || undefined,
                end_date: endDate || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        setStartDate("");
        setEndDate("");
        router.get(route("reports.journal-profit-loss.index"), {}, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Laporan Laba Rugi (Jurnal)" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconChartBar size={28} className="text-primary-500" />
                    Laporan Laba Rugi (Jurnal)
                </h1>
                <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    Dihitung dari jurnal, bukan estimasi: HPP memakai harga beli beku saat penjualan, dan setiap angka
                    bisa ditelusur ke dokumen sumbernya lewat Buku Besar.
                </p>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-4">
                <div>
                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Dari tanggal</label>
                    <input
                        type="date"
                        value={startDate}
                        onChange={(e) => setStartDate(e.target.value)}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    />
                </div>
                <div>
                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Sampai tanggal</label>
                    <input
                        type="date"
                        value={endDate}
                        onChange={(e) => setEndDate(e.target.value)}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    />
                </div>
                <div className="flex items-end gap-2 md:col-span-2">
                    <button
                        type="button"
                        onClick={applyFilters}
                        className="h-11 flex-1 rounded-xl bg-primary-600 px-4 text-sm font-medium text-white hover:bg-primary-700"
                    >
                        Filter
                    </button>
                    <button
                        type="button"
                        onClick={resetFilters}
                        className="h-11 rounded-xl border border-slate-200 px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        Reset
                    </button>
                </div>
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <Row label="Pendapatan Penjualan (4100 − 4110)" value={summary.pendapatan_penjualan} />
                <Row label="Pendapatan Lainnya (4200)" value={summary.pendapatan_lainnya} />
                <Row label="Total Pendapatan" value={summary.total_pendapatan} bold />
                <Row label="Harga Pokok Penjualan (5100)" value={summary.hpp} negative note="Dari snapshot harga beli saat penjualan." />
                <Row label="Laba Kotor" value={summary.laba_kotor} bold />
                <div className="border-b border-slate-100 py-3 dark:border-slate-800">
                    <p className="text-sm font-medium text-slate-700 dark:text-slate-200 mb-2">Beban Operasional</p>
                    {summary.beban_rows?.map((row) => (
                        <div key={row.code} className="flex justify-between gap-4 py-1 pl-4 text-sm">
                            <span className="text-slate-600 dark:text-slate-300">
                                {row.code} — {row.name}
                            </span>
                            <span className="text-danger-600 dark:text-danger-400">{formatCurrency(row.total)}</span>
                        </div>
                    ))}
                    <div className="flex justify-between gap-4 pt-2 pl-4 text-sm font-semibold">
                        <span className="text-slate-900 dark:text-white">Total Beban Operasional</span>
                        <span className="text-danger-600 dark:text-danger-400">{formatCurrency(summary.beban_total)}</span>
                    </div>
                </div>
                <Row label="Laba Operasional" value={summary.laba_operasional} bold />
                <Row label="Beban Pajak PPh Final 0,5% (5300)" value={summary.pajak} negative />
                <Row label="Laba Bersih" value={summary.laba_bersih} bold />
            </div>

            {reconciliation && (
                <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="text-lg font-bold text-slate-900 dark:text-white">Rekonsiliasi dengan Laporan Lite</h2>
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Setiap selisih dijelaskan oleh komponennya. Residual yang tidak nol adalah bug, bukan perbedaan
                        metode — kecuali HPP, di mana pembulatan per baris dapat menyisakan selisih kecil.
                    </p>
                    <div className="mt-4">
                        <Row label="Pendapatan jurnal" value={reconciliation.journal.pendapatan} />
                        <Row label="Pendapatan lite" value={reconciliation.lite.revenue} />
                        <Row
                            label="Penjualan belum dijurnal (bank / QRIS / split)"
                            value={reconciliation.bridges.unjournaled_paid_sales}
                            note="Dijelaskan, bukan dipaksakan cocok: penjualan non-tunai belum dijurnal karena pemetaan tender-ke-bank belum ada."
                        />
                        <Row label="Piutang belum lunas (dijurnal, belum di lite)" value={reconciliation.bridges.unpaid_credit_sales} />
                        <Row label="Retur, kontra 4110" value={reconciliation.bridges.returns_contra} />
                        <Row label="Residual pendapatan" value={reconciliation.residuals.pendapatan} bold />
                        <Row label="HPP jurnal" value={reconciliation.journal.hpp} negative />
                        <Row label="HPP lite (estimasi)" value={reconciliation.lite.hpp_estimate} negative />
                        <Row label="Selisih harga beku vs harga saat ini" value={reconciliation.bridges.price_diff_snapshot_vs_current} />
                        <Row label="HPP penjualan belum dijurnal" value={reconciliation.bridges.unjournaled_hpp} />
                        <Row label="HPP piutang belum lunas" value={reconciliation.bridges.unpaid_credit_hpp} />
                        <Row label="Kredit HPP dari retur" value={reconciliation.bridges.return_hpp_credits} />
                        <Row label="Residual HPP" value={reconciliation.residuals.hpp} bold />
                        <Row label="Beban jurnal (5200–5270)" value={reconciliation.journal.beban} negative />
                        <Row label="Beban lite" value={reconciliation.lite.expenses_total} negative />
                        <Row label="Gerakan lainnya-keluar (bukan beban lite)" value={reconciliation.bridges.lainnya_out_movements} />
                        <Row label="Beban belum dijurnal" value={reconciliation.bridges.unjournaled_expenses} />
                        <Row label="Residual beban" value={reconciliation.residuals.beban} bold />
                        <Row label="Selisih kas jurnal vs lite" value={reconciliation.residuals.selisih_kas} bold />
                        <Row label="PPh di jurnal (tidak ada di lite)" value={reconciliation.bridges.pph} negative note="PPh dihitung dari omset transaksi, termasuk penjualan non-tunai yang belum dijurnal." />
                        <Row label="Laba bersih jurnal" value={reconciliation.journal.laba_bersih} bold />
                        <Row label="Laba bersih lite" value={reconciliation.lite.net_profit} bold />
                        <Row label="Residual bersih" value={reconciliation.residuals.bersih} bold />
                        {!reconciliation.balanced && (
                            <p className="mt-3 text-sm font-medium text-danger-600 dark:text-danger-400">
                                Rekonsiliasi belum seimbang — periksa residual di atas sebelum memakai laporan ini.
                            </p>
                        )}
                    </div>
                </div>
            )}
        </>
    );
}

JournalProfitLoss.layout = (page) => <DashboardLayout children={page} />;
