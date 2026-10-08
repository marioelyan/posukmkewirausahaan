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
    IconTruckDelivery,
} from "@tabler/icons-react";

const formatDateTime = (value) =>
    value
        ? new Intl.DateTimeFormat("id-ID", {
              dateStyle: "medium",
              timeStyle: "short",
          }).format(new Date(value))
        : "-";

export default function Index({ receivings, filters }) {
    const { can } = useAuthorization();

    const handleFilterChange = (key, value) => {
        router.get(
            route("goods-receivings.index"),
            { ...filters, [key]: value },
            { preserveState: true, replace: true }
        );
    };

    return (
        <>
            <Head title="Penerimaan Barang" />
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                        Penerimaan Barang
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Catat penerimaan barang dari supplier.
                    </p>
                </div>
                {can("goods-receivings-create") && (
                    <Button
                        type="link"
                        href={route("goods-receivings.create")}
                        icon={<IconCirclePlus size={18} />}
                        className="bg-primary-500 hover:bg-primary-600 text-white shadow-lg shadow-primary-500/30"
                        label="Terima Barang"
                    />
                )}
            </div>

            <div className="mb-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div className="relative">
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
            </div>

            <Table.Card title="Daftar Penerimaan Barang">
                <Table>
                    <Table.Thead>
                        <tr>
                            <Table.Th>Dokumen</Table.Th>
                            <Table.Th>PO Referensi</Table.Th>
                            <Table.Th>Supplier</Table.Th>
                            <Table.Th>Tanggal Terima</Table.Th>
                            <Table.Th>Diterima Oleh</Table.Th>
                            <Table.Th className="w-24 text-center">Aksi</Table.Th>
                        </tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {receivings.data.length > 0 ? (
                            receivings.data.map((gr) => (
                                <tr key={gr.id} className="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <Table.Td>
                                        <p className="font-semibold text-slate-800 dark:text-slate-200">
                                            {gr.document_number}
                                        </p>
                                    </Table.Td>
                                    <Table.Td>
                                        <Link
                                            href={route("purchase-orders.show", gr.purchase_order_id)}
                                            className="text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                        >
                                            {gr.purchase_order?.document_number || "-"}
                                        </Link>
                                    </Table.Td>
                                    <Table.Td>{gr.supplier?.name || "-"}</Table.Td>
                                    <Table.Td>{formatDateTime(gr.received_at)}</Table.Td>
                                    <Table.Td>{gr.receiver?.name || "-"}</Table.Td>
                                    <Table.Td className="text-center">
                                        <Link
                                            href={route("goods-receivings.show", gr.id)}
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
                                    Belum ada penerimaan barang.
                                </div>
                            }>
                                <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                    <IconTruckDelivery size={28} className="text-slate-400" />
                                </div>
                            </Table.Empty>
                        )}
                    </Table.Tbody>
                </Table>
            </Table.Card>

            {receivings.last_page > 1 && <Pagination links={receivings.links} />}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
