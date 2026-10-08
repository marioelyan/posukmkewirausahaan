import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, router } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import { useAuthorization } from "@/Utils/authorization";
import {
    IconCirclePlus,
    IconEye,
    IconSearch,
    IconShoppingCart,
    IconX,
} from "@tabler/icons-react";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const statusBadge = (status) => {
    const base = "inline-flex rounded-full px-2.5 py-1 text-xs font-semibold";
    const map = {
        draft: "bg-warning-100 text-warning-700 dark:bg-warning-950/30 dark:text-warning-400",
        ordered: "bg-primary-100 text-primary-700 dark:bg-primary-950/30 dark:text-primary-400",
        partial_received: "bg-accent-100 text-accent-700 dark:bg-accent-950/30 dark:text-accent-400",
        completed: "bg-success-100 text-success-700 dark:bg-success-950/30 dark:text-success-400",
        cancelled: "bg-rose-100 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400",
    };
    const labels = {
        draft: "Draft",
        ordered: "Dipesan",
        partial_received: "Sebagian Diterima",
        completed: "Selesai",
        cancelled: "Dibatalkan",
    };
    return <span className={`${base} ${map[status] || map.draft}`}>{labels[status] || status}</span>;
};

export default function Index({ orders, filters, suppliers }) {
    const { can } = useAuthorization();

    const handleFilterChange = (key, value) => {
        router.get(
            route("purchase-orders.index"),
            { ...filters, [key]: value },
            { preserveState: true, replace: true }
        );
    };

    return (
        <>
            <Head title="Purchase Orders" />
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                        Purchase Orders
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Kelola pemesanan pembelian ke supplier.
                    </p>
                </div>
                {can("purchase-orders-create") && (
                    <Button
                        type="link"
                        href={route("purchase-orders.create")}
                        icon={<IconCirclePlus size={18} />}
                        className="bg-primary-500 hover:bg-primary-600 text-white shadow-lg shadow-primary-500/30"
                        label="Buat PO"
                    />
                )}
            </div>

            <div className="mb-4 grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-4">
                <div className="relative md:col-span-2">
                    <input
                        type="text"
                        value={filters.search || ""}
                        onChange={(e) => handleFilterChange("search", e.target.value)}
                        placeholder="Cari nomor dokumen..."
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 pr-11 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    />
                    <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400">
                        <IconSearch size={18} />
                    </div>
                </div>
                <select
                    value={filters.status || ""}
                    onChange={(e) => handleFilterChange("status", e.target.value)}
                    className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                >
                    <option value="">Semua Status</option>
                    <option value="draft">Draft</option>
                    <option value="ordered">Dipesan</option>
                    <option value="partial_received">Sebagian Diterima</option>
                    <option value="completed">Selesai</option>
                    <option value="cancelled">Dibatalkan</option>
                </select>
                <select
                    value={filters.supplier || ""}
                    onChange={(e) => handleFilterChange("supplier", e.target.value)}
                    className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                >
                    <option value="">Semua Supplier</option>
                    {suppliers.map((s) => (
                        <option key={s.id} value={s.id}>{s.name}</option>
                    ))}
                </select>
            </div>

            <Table.Card title="Daftar Purchase Order">
                <Table>
                    <Table.Thead>
                        <tr>
                            <Table.Th>Dokumen</Table.Th>
                            <Table.Th>Supplier</Table.Th>
                            <Table.Th>Status</Table.Th>
                            <Table.Th>Item</Table.Th>
                            <Table.Th>Dibuat Oleh</Table.Th>
                            <Table.Th className="w-24 text-center">Aksi</Table.Th>
                        </tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {orders.data.length > 0 ? (
                            orders.data.map((order) => (
                                <tr key={order.id} className="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <Table.Td>
                                        <p className="font-semibold text-slate-800 dark:text-slate-200">
                                            {order.document_number}
                                        </p>
                                        <p className="text-xs text-slate-500">{order.created_at?.split("T")[0]}</p>
                                    </Table.Td>
                                    <Table.Td>{order.supplier?.name || "-"}</Table.Td>
                                    <Table.Td>{statusBadge(order.status)}</Table.Td>
                                    <Table.Td>{order.items_count}</Table.Td>
                                    <Table.Td>{order.creator?.name || "-"}</Table.Td>
                                    <Table.Td className="text-center">
                                        <Link
                                            href={route("purchase-orders.show", order.id)}
                                            className="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-2 text-slate-600 transition hover:border-primary-300 hover:text-primary-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-primary-700 dark:hover:text-primary-400"
                                        >
                                            <IconEye size={18} />
                                        </Link>
                                    </Table.Td>
                                </tr>
                            ))
                        ) : (
                            <Table.Empty colSpan={6} message={
                                <div className="text-slate-500 dark:text-slate-400">
                                    Belum ada purchase order.
                                </div>
                            }>
                                <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                    <IconShoppingCart size={28} className="text-slate-400" />
                                </div>
                            </Table.Empty>
                        )}
                    </Table.Tbody>
                </Table>
            </Table.Card>

            {orders.last_page > 1 && <Pagination links={orders.links} />}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
