import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router } from "@inertiajs/react";
import { IconScale } from "@tabler/icons-react";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

function Section({ title, rows, total }) {
    return (
        <div className="border-b border-slate-100 py-3 last:border-0 dark:border-slate-800">
            <p className="text-sm font-medium text-slate-700 dark:text-slate-200 mb-2">{title}</p>
            {rows.map((row) => (
                <div key={row.code} className="flex justify-between gap-4 py-1 pl-4 text-sm">
                    <span className="text-slate-600 dark:text-slate-300">
                        {row.code} — {row.name}
                    </span>
                    <span className="text-slate-900 dark:text-white">{formatCurrency(row.total)}</span>
                </div>
            ))}
            <div className="flex justify-between gap-4 pt-2 pl-4 text-sm font-semibold">
                <span className="text-slate-900 dark:text-white">Total {title}</span>
                <span className="text-slate-900 dark:text-white">{formatCurrency(total)}</span>
            </div>
        </div>
    );
}

export default function BalanceSheet({ summary, filters = {} }) {
    const [endDate, setEndDate] = useState(filters.end_date || "");

    const applyFilters = () => {
        router.get(
            route("reports.balance-sheet.index"),
            { end_date: endDate || undefined },
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        setEndDate("");
        router.get(route("reports.balance-sheet.index"), {}, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Neraca" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconScale size={28} className="text-primary-500" />
                    Neraca
                </h1>
                <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    Saldo kumulatif per tanggal yang dipilih. Laba Tahun Berjalan dihitung dari selisih pendapatan dan
                    beban kumulatif — belum ada jurnal penutup di fase 1.
                </p>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-4">
                <div>
                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Per tanggal</label>
                    <input
                        type="date"
                        value={endDate}
                        onChange={(e) => setEndDate(e.target.value)}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    />
                </div>
                <div className="flex items-end gap-2 md:col-span-3">
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
                <Section title="Aset" rows={summary.asset_rows} total={summary.assets_total} />
                <Section title="Kewajiban" rows={summary.liability_rows} total={summary.liabilities_total} />
                <Section title="Modal" rows={summary.equity_rows} total={summary.equity_total} />
                <div className="flex justify-between gap-4 border-b border-slate-100 py-3 dark:border-slate-800">
                    <span className="text-sm font-medium text-slate-700 dark:text-slate-200">Laba Tahun Berjalan</span>
                    <span className="text-sm font-semibold text-slate-900 dark:text-white">{formatCurrency(summary.laba_berjalan)}</span>
                </div>
                <div className="flex justify-between gap-4 pt-4">
                    <span className="text-sm font-bold text-slate-900 dark:text-white">Cek Keseimbangan (Aset − Kewajiban − Modal − Laba)</span>
                    <span className={`text-sm font-bold ${summary.check === 0 ? "text-success-600 dark:text-success-400" : "text-danger-600 dark:text-danger-400"}`}>
                        {formatCurrency(summary.check)}
                    </span>
                </div>
                {summary.check !== 0 && (
                    <p className="mt-2 text-sm font-medium text-danger-600 dark:text-danger-400">
                        Neraca tidak seimbang — jangan pakai laporan ini sebelum selisihnya dijelaskan.
                    </p>
                )}
            </div>
        </>
    );
}

BalanceSheet.layout = (page) => <DashboardLayout children={page} />;
