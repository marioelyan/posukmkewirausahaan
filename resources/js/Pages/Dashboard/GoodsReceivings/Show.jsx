import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link } from "@inertiajs/react";
import Table from "@/Components/Dashboard/Table";
import {
    IconArrowLeft,
    IconTruckDelivery,
    IconPackage,
} from "@tabler/icons-react";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const formatDateTime = (value) =>
    value
        ? new Intl.DateTimeFormat("id-ID", {
              dateStyle: "medium",
              timeStyle: "short",
          }).format(new Date(value))
        : "-";

export default function Show({ receiving }) {
    return (
        <>
            <Head title={receiving.document_number} />
            <div className="mb-6">
                <Link
                    href={route("goods-receivings.index")}
                    className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke daftar penerimaan
                </Link>
                <div className="flex items-center gap-2">
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                        {receiving.document_number}
                    </h1>
                </div>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    PO Referensi: {" "}
                    <Link
                        href={route("purchase-orders.show", receiving.purchase_order_id)}
                        className="font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400"
                    >
                        {receiving.purchase_order?.document_number || "-"}
                    </Link>
                    {" "}&bull; Supplier: {receiving.supplier?.name || "-"}
                    {" "}&bull; Diterima oleh {receiving.receiver?.name || "-"}
                    {" "}&bull; {formatDateTime(receiving.received_at)}
                </p>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.7fr_1fr]">
                <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">
                        Item Diterima
                    </h2>
                    <Table>
                        <Table.Thead>
                            <tr>
                                <Table.Th>Produk</Table.Th>
                                <Table.Th>Qty Diterima</Table.Th>
                                <Table.Th>Harga Satuan</Table.Th>
                                <Table.Th>Subtotal</Table.Th>
                                <Table.Th>Catatan</Table.Th>
                            </tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {receiving.items.length > 0 ? (
                                receiving.items.map((item) => {
                                    const unitPrice = item.purchase_order_item?.unit_price || 0;
                                    return (
                                        <tr key={item.id} className="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                            <Table.Td>
                                                <p className="font-medium text-slate-800 dark:text-slate-200">
                                                    {item.product?.title || "Produk #" + item.product_id}
                                                </p>
                                                <p className="text-xs text-slate-500">{item.product?.sku || "-"}</p>
                                            </Table.Td>
                                            <Table.Td className="font-semibold">{item.qty_received}</Table.Td>
                                            <Table.Td>{formatCurrency(unitPrice)}</Table.Td>
                                            <Table.Td className="font-semibold">{formatCurrency(item.qty_received * unitPrice)}</Table.Td>
                                            <Table.Td className="text-xs text-slate-500">{item.notes || "-"}</Table.Td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <Table.Empty colSpan={5} message={
                                    <div className="text-slate-500 dark:text-slate-400">Tidak ada item pada penerimaan ini.</div>
                                }>
                                    <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                        <IconPackage size={28} className="text-slate-400" />
                                    </div>
                                </Table.Empty>
                            )}
                        </Table.Tbody>
                    </Table>
                </div>

                <div className="space-y-6">
                    {receiving.notes && (
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-3 text-lg font-semibold text-slate-900 dark:text-white">Catatan</h2>
                            <p className="text-sm text-slate-600 dark:text-slate-400">{receiving.notes}</p>
                        </div>
                    )}
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-3 text-lg font-semibold text-slate-900 dark:text-white">Informasi</h2>
                        <div className="space-y-2 text-sm">
                            <div className="flex justify-between">
                                <span className="text-slate-500">Dokumen</span>
                                <span className="font-medium text-slate-800 dark:text-slate-200">{receiving.document_number}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-slate-500">PO Referensi</span>
                                <Link
                                    href={route("purchase-orders.show", receiving.purchase_order_id)}
                                    className="font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                >
                                    {receiving.purchase_order?.document_number || "-"}
                                </Link>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-slate-500">Tanggal Terima</span>
                                <span className="font-medium text-slate-800 dark:text-slate-200">{formatDateTime(receiving.received_at)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-slate-500">Diterima Oleh</span>
                                <span className="font-medium text-slate-800 dark:text-slate-200">{receiving.receiver?.name || "-"}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

Show.layout = (page) => <DashboardLayout children={page} />;
