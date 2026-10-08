import React, { useEffect, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Pagination from "@/Components/Dashboard/Pagination";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const formatDate = (value) =>
    value
        ? new Intl.DateTimeFormat("id-ID", {
              dateStyle: "medium",
          }).format(new Date(value))
        : "-";

export default function Index({ salesReturns, filters }) {
    const [form, setForm] = useState({
        code: filters.code || "",
        invoice: filters.invoice || "",
        date_from: filters.date_from || "",
        date_to: filters.date_to || "",
        return_type: filters.return_type || "",
    });

    useEffect(() => {
        setForm({
            code: filters.code || "",
            invoice: filters.invoice || "",
            date_from: filters.date_from || "",
            date_to: filters.date_to || "",
            return_type: filters.return_type || "",
        });
    }, [filters]);

    const submit = (event) => {
        event.preventDefault();
        router.get(route("sales-returns.index"), form, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const rows = salesReturns.data || [];

    return (
        <>
            <Head title="Retur Penjualan" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                        Retur Penjualan
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Histori retur penjualan berdasarkan transaksi asal.
                    </p>
                </div>

                <form
                    onSubmit={submit}
                    className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 md:grid-cols-2 xl:grid-cols-5 dark:border-slate-800 dark:bg-slate-900"
                >
                    <input
                        type="text"
                        value={form.code}
                        onChange={(event) =>
                            setForm((prev) => ({
                                ...prev,
                                code: event.target.value,
                            }))
                        }
                        placeholder="Kode retur"
                        className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <input
                        type="text"
                        value={form.invoice}
                        onChange={(event) =>
                            setForm((prev) => ({
                                ...prev,
                                invoice: event.target.value,
                            }))
                        }
                        placeholder="Invoice transaksi"
                        className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <input
                        type="date"
                        value={form.date_from}
                        onChange={(event) =>
                            setForm((prev) => ({
                                ...prev,
                                date_from: event.target.value,
                            }))
                        }
                        className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <input
                        type="date"
                        value={form.date_to}
                        onChange={(event) =>
                            setForm((prev) => ({
                                ...prev,
                                date_to: event.target.value,
                            }))
                        }
                        className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800"
                    />
                    <div className="flex gap-2">
                        <select
                            value={form.return_type}
                            onChange={(event) =>
                                setForm((prev) => ({
                                    ...prev,
                                    return_type: event.target.value,
                                }))
                            }
                            className="h-11 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800"
                        >
                            <option value="">Semua metode</option>
                            <option value="refund_cash">Refund Tunai</option>
                            <option value="store_credit">Saldo Toko</option>
                        </select>
                        <button
                            type="submit"
                            className="inline-flex h-11 items-center justify-center rounded-xl bg-primary-500 px-4 text-sm font-medium text-white hover:bg-primary-600"
                        >
                            Filter
                        </button>
                    </div>
                </form>

                <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="w-full text-sm">
                        <thead className="border-b border-slate-100 bg-slate-50 dark:border-slate-800 dark:bg-slate-950">
                            <tr>
                                <th className="px-4 py-3 text-left">Kode</th>
                                <th className="px-4 py-3 text-left">Invoice</th>
                                <th className="px-4 py-3 text-left">Tanggal</th>
                                <th className="px-4 py-3 text-left">Pelanggan</th>
                                <th className="px-4 py-3 text-left">Metode</th>
                                <th className="px-4 py-3 text-right">Nominal</th>
                                <th className="px-4 py-3 text-center">Status</th>
                                <th className="px-4 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {rows.length > 0 ? (
                                rows.map((item) => (
                                    <tr key={item.id}>
                                        <td className="px-4 py-4 font-medium text-slate-900 dark:text-white">
                                            {item.code}
                                        </td>
                                        <td className="px-4 py-4">
                                            {item.transaction?.invoice || "-"}
                                        </td>
                                        <td className="px-4 py-4">
                                            {formatDate(item.created_at)}
                                        </td>
                                        <td className="px-4 py-4">
                                            {item.customer?.name || "Umum"}
                                        </td>
                                        <td className="px-4 py-4">
                                            {item.return_type === "store_credit"
                                                ? "Saldo Toko"
                                                : "Refund Tunai"}
                                        </td>
                                        <td className="px-4 py-4 text-right font-medium text-slate-900 dark:text-white">
                                            {formatCurrency(
                                                item.total_return_amount
                                            )}
                                        </td>
                                        <td className="px-4 py-4 text-center">
                                            <span
                                                className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${
                                                    item.status === "completed"
                                                        ? "bg-success-100 text-success-700 dark:bg-success-950/30 dark:text-success-400"
                                                        : "bg-warning-100 text-warning-700 dark:bg-warning-950/30 dark:text-warning-400"
                                                }`}
                                            >
                                                {item.status === "completed"
                                                    ? "Completed"
                                                    : "Draft"}
                                            </span>
                                        </td>
                                        <td className="px-4 py-4 text-center">
                                            <Link
                                                href={route(
                                                    "sales-returns.show",
                                                    item.id
                                                )}
                                                className="inline-flex rounded-lg bg-primary-50 px-3 py-2 text-xs font-semibold text-primary-700 hover:bg-primary-100 dark:bg-primary-950/40 dark:text-primary-300"
                                            >
                                                Lihat
                                            </Link>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-4 py-16 text-center text-slate-500 dark:text-slate-400"
                                    >
                                        Belum ada retur penjualan.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {salesReturns.links?.length > 3 && (
                    <Pagination links={salesReturns.links} />
                )}
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
