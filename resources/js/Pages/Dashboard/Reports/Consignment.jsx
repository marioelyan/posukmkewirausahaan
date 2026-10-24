import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router } from "@inertiajs/react";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import { IconChartBar, IconDatabaseOff } from "@tabler/icons-react";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const formatDate = (value) => {
    if (!value) return "-";
    return new Intl.DateTimeFormat("id-ID", { dateStyle: "medium" }).format(new Date(value));
};

const statusLabels = {
    unpaid: "Belum dibayar",
    partial: "Dibayar sebagian",
    paid: "Lunas",
};

const statusStyles = {
    unpaid: "bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-400",
    partial: "bg-warning-100 text-warning-700 dark:bg-warning-900/50 dark:text-warning-400",
    paid: "bg-success-100 text-success-700 dark:bg-success-900/50 dark:text-success-400",
};

export default function Consignment({ payables, perSupplier = [], grand = {}, suppliers = [], filters = {} }) {
    const [supplierId, setSupplierId] = useState(filters.supplier_id || "");
    const [status, setStatus] = useState(filters.status || "");
    const [startDate, setStartDate] = useState(filters.start_date || "");
    const [endDate, setEndDate] = useState(filters.end_date || "");

    const applyFilters = () => {
        router.get(
            route("reports.consignment.index"),
            {
                supplier_id: supplierId || undefined,
                status: status || undefined,
                start_date: startDate || undefined,
                end_date: endDate || undefined,
            },
            { preserveState: true, replace: true }
        );
    };

    const resetFilters = () => {
        setSupplierId("");
        setStatus("");
        setStartDate("");
        setEndDate("");
        router.get(route("reports.consignment.index"), {}, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Laporan Konsinyasi" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconChartBar size={28} className="text-primary-500" />
                    Laporan Konsinyasi
                </h1>
                <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    Hutang titipan per penitip — hanya hutang yang lahir dari penjualan.
                    Hutang manual atau dari PO tidak masuk laporan ini. Retur yang sudah
                    diselesaikan sudah mengurangi total hutang.
                </p>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-4">
                <div>
                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Penitip</label>
                    <select
                        value={supplierId}
                        onChange={(e) => setSupplierId(e.target.value)}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    >
                        <option value="">Semua penitip</option>
                        {suppliers.map((s) => (
                            <option key={s.id} value={s.id}>{s.name}</option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Status</label>
                    <select
                        value={status}
                        onChange={(e) => setStatus(e.target.value)}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    >
                        <option value="">Semua status</option>
                        <option value="unpaid">Belum dibayar</option>
                        <option value="partial">Dibayar sebagian</option>
                        <option value="paid">Lunas</option>
                    </select>
                </div>
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
                <div className="flex items-end gap-2 md:col-span-4">
                    <button
                        type="button"
                        onClick={applyFilters}
                        className="h-11 rounded-xl bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700"
                    >
                        Terapkan
                    </button>
                    <button
                        type="button"
                        onClick={resetFilters}
                        className="h-11 rounded-xl border border-slate-200 px-5 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                    >
                        Atur ulang
                    </button>
                </div>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-sm text-slate-500 dark:text-slate-400">Total Hutang Titipan</p>
                    <p className="mt-1 text-2xl font-bold text-slate-900 dark:text-white">{formatCurrency(grand.total)}</p>
                    <p className="mt-1 text-xs text-slate-400">{grand.documents || 0} dokumen</p>
                </div>
                <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-sm text-slate-500 dark:text-slate-400">Sudah Dibayar</p>
                    <p className="mt-1 text-2xl font-bold text-success-600 dark:text-success-400">{formatCurrency(grand.paid)}</p>
                </div>
                <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-sm text-slate-500 dark:text-slate-400">Sisa Hutang</p>
                    <p className="mt-1 text-2xl font-bold text-danger-600 dark:text-danger-400">{formatCurrency(grand.remaining)}</p>
                </div>
                <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-sm text-slate-500 dark:text-slate-400">Penitip</p>
                    <p className="mt-1 text-2xl font-bold text-slate-900 dark:text-white">{perSupplier.length}</p>
                </div>
            </div>

            <Table.Card title="Per Penitip">
                <Table>
                    <Table.Thead>
                        <tr>
                            <Table.Th>Penitip</Table.Th>
                            <Table.Th>Dokumen</Table.Th>
                            <Table.Th>Total Hutang</Table.Th>
                            <Table.Th>Sudah Dibayar</Table.Th>
                            <Table.Th>Sisa</Table.Th>
                        </tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {perSupplier.length > 0 ? (
                            perSupplier.map((row) => (
                                <tr key={row.supplier_id} className="border-b border-slate-100 last:border-0 dark:border-slate-800">
                                    <Table.Td className="font-medium">{row.supplier_name}</Table.Td>
                                    <Table.Td>{row.documents}</Table.Td>
                                    <Table.Td>{formatCurrency(row.total)}</Table.Td>
                                    <Table.Td>{formatCurrency(row.paid)}</Table.Td>
                                    <Table.Td className="font-semibold text-danger-600 dark:text-danger-400">{formatCurrency(row.remaining)}</Table.Td>
                                </tr>
                            ))
                        ) : (
                            <Table.Empty colSpan={5} message="Belum ada hutang titipan pada filter ini.">
                                <IconDatabaseOff size={48} className="text-slate-300 dark:text-slate-600 mx-auto" />
                            </Table.Empty>
                        )}
                    </Table.Tbody>
                </Table>
            </Table.Card>

            <div className="mt-6" />

            <Table.Card title="Dokumen Hutang Titipan">
                <Table>
                    <Table.Thead>
                        <tr>
                            <Table.Th>Dokumen</Table.Th>
                            <Table.Th>Penitip</Table.Th>
                            <Table.Th>Penjualan</Table.Th>
                            <Table.Th>Tanggal</Table.Th>
                            <Table.Th>Total</Table.Th>
                            <Table.Th>Dibayar</Table.Th>
                            <Table.Th>Sisa</Table.Th>
                            <Table.Th>Status</Table.Th>
                        </tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {payables.data.length > 0 ? (
                            payables.data.map((payable) => (
                                <tr key={payable.id} className="border-b border-slate-100 last:border-0 dark:border-slate-800">
                                    <Table.Td className="font-medium">{payable.document_number}</Table.Td>
                                    <Table.Td>{payable.supplier?.name || "-"}</Table.Td>
                                    <Table.Td>{payable.transaction?.invoice || "-"}</Table.Td>
                                    <Table.Td>{formatDate(payable.created_at)}</Table.Td>
                                    <Table.Td>{formatCurrency(payable.total)}</Table.Td>
                                    <Table.Td>{formatCurrency(payable.paid)}</Table.Td>
                                    <Table.Td className="font-semibold">{formatCurrency(payable.remaining)}</Table.Td>
                                    <Table.Td>
                                        <span className={`px-2 py-0.5 text-xs font-medium rounded ${statusStyles[payable.status] || ""}`}>
                                            {statusLabels[payable.status] || payable.status}
                                        </span>
                                    </Table.Td>
                                </tr>
                            ))
                        ) : (
                            <Table.Empty colSpan={8} message="Belum ada dokumen hutang titipan pada filter ini.">
                                <IconDatabaseOff size={48} className="text-slate-300 dark:text-slate-600 mx-auto" />
                            </Table.Empty>
                        )}
                    </Table.Tbody>
                </Table>
            </Table.Card>

            <div className="mt-6">
                <Pagination links={payables.links} />
            </div>
        </>
    );
}

Consignment.layout = (page) => <DashboardLayout children={page} />;
