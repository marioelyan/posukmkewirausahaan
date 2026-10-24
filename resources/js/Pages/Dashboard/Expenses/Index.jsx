import React, { useEffect, useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, router, usePage } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Search from "@/Components/Dashboard/Search";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import InputSelect from "@/Components/Dashboard/InputSelect";
import toast from "react-hot-toast";
import {
    IconCirclePlus,
    IconDatabaseOff,
    IconPencilCog,
    IconTrash,
    IconCashBanknote,
} from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";

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

const sourceLabels = {
    drawer: "Laci (shift)",
    bank: "Bank",
    other_cash: "Kas lain",
};

export default function Index({ expenses, categories = [], warehouses = [] }) {
    const { flash } = usePage();
    const { can } = useAuthorization();
    const canCreate = can("expenses-create");
    const canEdit = can("expenses-edit");
    const canDelete = can("expenses-delete");

    const [categoryId, setCategoryId] = useState("");
    const [source, setSource] = useState("");
    const [warehouseId, setWarehouseId] = useState("");
    const [startDate, setStartDate] = useState("");
    const [endDate, setEndDate] = useState("");

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    const applyFilters = () => {
        router.get(
            route("expenses.index"),
            {
                category_id: categoryId || undefined,
                source: source || undefined,
                warehouse_id: warehouseId || undefined,
                start_date: startDate || undefined,
                end_date: endDate || undefined,
            },
            { preserveState: true, replace: true }
        );
    };

    const resetFilters = () => {
        setCategoryId("");
        setSource("");
        setWarehouseId("");
        setStartDate("");
        setEndDate("");
        router.get(route("expenses.index"), {}, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Pengeluaran" />

            <div className="mb-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconCashBanknote size={28} className="text-primary-500" />
                    Pengeluaran
                </h1>
                {canCreate && (
                    <Link
                        href={route("expenses.create")}
                        className="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700"
                    >
                        <IconCirclePlus size={18} />
                        Catat Beban
                    </Link>
                )}
            </div>

            <div className="mb-6 grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-3 xl:grid-cols-6">
                <div>
                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Kategori</label>
                    <select
                        value={categoryId}
                        onChange={(e) => setCategoryId(e.target.value)}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    >
                        <option value="">Semua kategori</option>
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Sumber</label>
                    <select
                        value={source}
                        onChange={(e) => setSource(e.target.value)}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    >
                        <option value="">Semua sumber</option>
                        <option value="drawer">Laci (shift)</option>
                        <option value="bank">Bank</option>
                        <option value="other_cash">Kas lain</option>
                    </select>
                </div>
                <div>
                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Gudang</label>
                    <select
                        value={warehouseId}
                        onChange={(e) => setWarehouseId(e.target.value)}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    >
                        <option value="">Semua gudang</option>
                        {warehouses.map((w) => (
                            <option key={w.id} value={w.id}>{w.name}</option>
                        ))}
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

            <Search url={route("expenses.index")} />

            <Table.Card icon={<IconCashBanknote size={18} />} title="Daftar Beban">
                <Table>
                    <Table.Thead>
                        <tr>
                            <Table.Th>Tanggal</Table.Th>
                            <Table.Th>Kategori</Table.Th>
                            <Table.Th>Sumber</Table.Th>
                            <Table.Th>Gudang</Table.Th>
                            <Table.Th className="text-right">Nominal</Table.Th>
                            <Table.Th>Keterangan</Table.Th>
                            <Table.Th>Pencatat</Table.Th>
                            {(canEdit || canDelete) && <Table.Th>Aksi</Table.Th>}
                        </tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {expenses.data?.length ? (
                            expenses.data.map((expense) => (
                                <tr key={expense.id} className="border-b border-slate-100 last:border-0 dark:border-slate-800">
                                    <Table.Td>{formatDate(expense.date)}</Table.Td>
                                    <Table.Td>{expense.category?.name || "-"}</Table.Td>
                                    <Table.Td>{sourceLabels[expense.source] || expense.source}</Table.Td>
                                    <Table.Td>{expense.warehouse?.name || "-"}</Table.Td>
                                    <Table.Td className="text-right font-semibold text-danger-600 dark:text-danger-400">
                                        {formatCurrency(expense.amount)}
                                    </Table.Td>
                                    <Table.Td>{expense.note || "-"}</Table.Td>
                                    <Table.Td>{expense.user?.name || "-"}</Table.Td>
                                    {(canEdit || canDelete) && (
                                        <Table.Td>
                                            <div className="flex items-center gap-2">
                                                {canEdit && (
                                                    <Link
                                                        href={route("expenses.edit", expense.id)}
                                                        className="p-2 rounded-xl text-warning-600 hover:bg-warning-50"
                                                    >
                                                        <IconPencilCog size={18} />
                                                    </Link>
                                                )}
                                                {canDelete && (
                                                    <Button
                                                        type={"delete"}
                                                        icon={<IconTrash size={18} />}
                                                        className={"p-2 rounded-xl text-danger-600 hover:bg-danger-50"}
                                                        url={route("expenses.destroy", expense.id)}
                                                    />
                                                )}
                                            </div>
                                        </Table.Td>
                                    )}
                                </tr>
                            ))
                        ) : (
                            <Table.Empty colSpan={8} message="Belum ada data beban.">
                                <IconDatabaseOff size={48} className="text-slate-300 dark:text-slate-600 mx-auto" />
                            </Table.Empty>
                        )}
                    </Table.Tbody>
                </Table>
            </Table.Card>

            <div className="mt-6">
                <Pagination links={expenses.links} />
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
