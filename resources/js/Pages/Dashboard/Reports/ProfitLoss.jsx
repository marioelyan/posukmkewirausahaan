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

export default function ProfitLoss({ summary, filters = {} }) {
    const [startDate, setStartDate] = useState(filters.start_date || "");
    const [endDate, setEndDate] = useState(filters.end_date || "");

    const applyFilters = () => {
        router.get(
            route("reports.profit-loss.index"),
            {
                start_date: startDate || undefined,
                end_date: endDate || undefined,
            },
            { preserveState: true, replace: true }
        );
    };

    const resetFilters = () => {
        setStartDate("");
        setEndDate("");
        router.get(route("reports.profit-loss.index"), {}, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Laporan Laba Rugi" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconChartBar size={28} className="text-primary-500" />
                    Laporan Laba Rugi
                </h1>
                <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    Pendapatan = total transaksi lunas (sudah neto diskon). HPP masih estimasi dari harga beli master
                    saat ini — laba kotor persis per transaksi ada di laporan profit dashboard.
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

            <div className="max-w-2xl">
                <div className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <Row label="Pendapatan" value={summary.revenue} />
                    <Row label="Diskon (memo, sudah termasuk di pendapatan)" value={summary.discount_memo} note="Tidak dikurangkan lagi." />
                    <Row
                        label="Harga Pokok Penjualan (estimasi)"
                        value={summary.hpp_estimate}
                        negative
                        note="Estimasi dari harga beli master saat ini, bukan profit per transaksi."
                    />
                    <Row label="Laba Kotor" value={summary.gross_profit} bold />
                    <div className="border-b border-slate-100 py-3 dark:border-slate-800">
                        <p className="text-sm font-medium text-slate-700 dark:text-slate-200 mb-2">Beban</p>
                        {summary.expenses_by_category?.length ? (
                            summary.expenses_by_category.map((row) => (
                                <div key={row.expense_category_id ?? row.category_name} className="flex justify-between gap-4 py-1 pl-4 text-sm">
                                    <span className="text-slate-600 dark:text-slate-300">{row.category_name}</span>
                                    <span className="text-danger-600 dark:text-danger-400">{formatCurrency(row.total)}</span>
                                </div>
                            ))
                        ) : (
                            <p className="pl-4 text-sm text-slate-400">Tidak ada beban pada periode ini.</p>
                        )}
                        <div className="flex justify-between gap-4 pt-2 pl-4 text-sm font-semibold">
                            <span className="text-slate-900 dark:text-white">Total Beban</span>
                            <span className="text-danger-600 dark:text-danger-400">{formatCurrency(summary.expenses_total)}</span>
                        </div>
                    </div>
                    <Row
                        label="Selisih Kas Shift (bertanda)"
                        value={summary.cash_difference}
                        note="Selisih kas shift yang ditutup pada periode ini. Bukan beban — garis tersendiri, supaya tidak tercampur dengan beban."
                    />
                    <Row label="Laba Bersih" value={summary.net_profit} bold />
                </div>
            </div>
        </>
    );
}

ProfitLoss.layout = (page) => <DashboardLayout children={page} />;
