import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router } from "@inertiajs/react";
import { IconBook } from "@tabler/icons-react";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

export default function Ledger({ rows = [], filters = {} }) {
    const [startDate, setStartDate] = useState(filters.start_date || "");
    const [endDate, setEndDate] = useState(filters.end_date || "");
    const [account, setAccount] = useState(filters.account || "");

    const applyFilters = () => {
        router.get(
            route("reports.ledger.index"),
            {
                start_date: startDate || undefined,
                end_date: endDate || undefined,
                account: account || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const resetFilters = () => {
        setStartDate("");
        setEndDate("");
        setAccount("");
        router.get(route("reports.ledger.index"), {}, { preserveState: true, replace: true });
    };

    const visibleRows = account ? rows.filter((row) => row.code === account) : rows;

    return (
        <>
            <Head title="Buku Besar" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconBook size={28} className="text-primary-500" />
                    Buku Besar
                </h1>
                <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    Saldo awal, mutasi, dan saldo akhir per akun dari jurnal. Saldo akun aset dan beban dibaca debit
                    dikurang kredit; kewajiban, modal, dan pendapatan dibaca kredit dikurang debit.
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
                <div>
                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Akun</label>
                    <select
                        value={account}
                        onChange={(e) => setAccount(e.target.value)}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    >
                        <option value="">Semua akun</option>
                        {rows.map((row) => (
                            <option key={row.code} value={row.code}>
                                {row.code} — {row.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="flex items-end gap-2">
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

            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table className="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr className="border-b border-slate-200 text-left text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            <th className="px-4 py-3 font-medium">Kode</th>
                            <th className="px-4 py-3 font-medium">Akun</th>
                            <th className="px-4 py-3 text-right font-medium">Saldo Awal</th>
                            <th className="px-4 py-3 text-right font-medium">Debit</th>
                            <th className="px-4 py-3 text-right font-medium">Kredit</th>
                            <th className="px-4 py-3 text-right font-medium">Saldo Akhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        {visibleRows.map((row) => (
                            <tr key={row.code} className="border-b border-slate-100 last:border-0 dark:border-slate-800">
                                <td className="px-4 py-3 text-slate-500">{row.code}</td>
                                <td className="px-4 py-3 text-slate-900 dark:text-white">{row.name}</td>
                                <td className="px-4 py-3 text-right">{formatCurrency(row.opening)}</td>
                                <td className="px-4 py-3 text-right">{formatCurrency(row.debit)}</td>
                                <td className="px-4 py-3 text-right">{formatCurrency(row.credit)}</td>
                                <td className="px-4 py-3 text-right font-semibold">{formatCurrency(row.closing)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}

Ledger.layout = (page) => <DashboardLayout children={page} />;
