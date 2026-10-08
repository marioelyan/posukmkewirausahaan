import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import {
    IconArrowLeft,
    IconTruckDelivery,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

export default function Create({ orders }) {
    const { data, setData, post, processing, errors } = useForm({
        purchase_order_id: "",
        notes: "",
        items: [],
    });

    const [selectedPoId, setSelectedPoId] = useState("");
    const selectedOrder = orders.find((o) => o.id === Number(selectedPoId));

    const selectPO = (poId) => {
        setSelectedPoId(poId);
        const order = orders.find((o) => o.id === Number(poId));
        if (order) {
            const initialItems = order.items
                .filter((item) => {
                    const outstanding = item.qty_ordered - (item.qty_received || 0);
                    return outstanding > 0;
                })
                .map((item) => ({
                    purchase_order_item_id: item.id,
                    product_title: item.product?.title || "Produk #" + item.product_id,
                    product_sku: item.product?.sku || "-",
                    qty_ordered: item.qty_ordered,
                    qty_received_already: item.qty_received || 0,
                    outstanding: item.qty_ordered - (item.qty_received || 0),
                    qty_received: item.qty_ordered - (item.qty_received || 0),
                    notes: "",
                }));
            setData({
                purchase_order_id: poId,
                notes: "",
                items: initialItems,
            });
        }
    };

    const updateItem = (index, value) => {
        const items = [...data.items];
        const maxQty = items[index].outstanding;
        items[index] = { ...items[index], qty_received: Math.min(parseInt(value) || 0, maxQty) };
        setData("items", items);
    };

    const submit = (e) => {
        e.preventDefault();
        if (!data.purchase_order_id) {
            toast.error("Pilih purchase order terlebih dahulu.");
            return;
        }
        const validItems = data.items.filter((item) => item.qty_received > 0);
        if (validItems.length === 0) {
            toast.error("Terima minimal satu item.");
            return;
        }
        setData("items", validItems);
        post(route("goods-receivings.store"), {
            onSuccess: () => toast.success("Penerimaan barang berhasil dicatat"),
            onError: () => toast.error("Gagal mencatat penerimaan"),
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Terima Barang" />
            <div className="mb-6">
                <Link
                    href={route("goods-receivings.index")}
                    className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke daftar penerimaan
                </Link>
                <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                    <IconTruckDelivery size={28} className="text-primary-500" />
                    Terima Barang
                </h1>
            </div>

            <form onSubmit={submit} className="max-w-4xl">
                <div className="space-y-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Pilih Purchase Order</h2>
                        <select
                            value={selectedPoId}
                            onChange={(e) => selectPO(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        >
                            <option value="">Pilih PO yang sudah dipesan...</option>
                            {orders.map((order) => (
                                <option key={order.id} value={order.id}>
                                    {order.document_number} - {order.supplier?.name || "Tanpa Supplier"}
                                </option>
                            ))}
                        </select>
                        {errors.purchase_order_id && <p className="mt-1 text-xs text-danger-500">{errors.purchase_order_id}</p>}
                    </div>

                    {selectedOrder && data.items.length > 0 && (
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">
                                Item Diterima
                            </h2>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 dark:border-slate-700">
                                            <th className="px-3 py-2 text-left font-semibold text-slate-700 dark:text-slate-200">Produk</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Qty PO</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Sudah Diterima</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Sisa</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Qty Diterima</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.items.map((item, index) => (
                                            <tr key={item.purchase_order_item_id} className="border-b border-slate-100 dark:border-slate-800">
                                                <td className="px-3 py-3">
                                                    <p className="font-medium text-slate-800 dark:text-slate-200">{item.product_title}</p>
                                                    <p className="text-xs text-slate-500">{item.product_sku}</p>
                                                </td>
                                                <td className="px-3 py-3 text-right">{item.qty_ordered}</td>
                                                <td className="px-3 py-3 text-right text-slate-500">{item.qty_received_already}</td>
                                                <td className="px-3 py-3 text-right font-semibold text-warning-600">{item.outstanding}</td>
                                                <td className="px-3 py-3 text-right">
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        max={item.outstanding}
                                                        value={item.qty_received}
                                                        onChange={(e) => updateItem(index, e.target.value)}
                                                        className="h-10 w-24 rounded-lg border border-slate-200 bg-slate-50 px-3 text-right text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                                    />
                                                </td>
                                                <td className="px-3 py-3 text-right">
                                                    <input
                                                        type="text"
                                                        value={item.notes || ""}
                                                        onChange={(e) => {
                                                            const items = [...data.items];
                                                            items[index] = { ...items[index], notes: e.target.value };
                                                            setData("items", items);
                                                        }}
                                                        placeholder="-"
                                                        className="h-10 w-32 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {selectedOrder && data.items.length > 0 && (
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Catatan Penerimaan</h2>
                            <textarea
                                value={data.notes}
                                onChange={(e) => setData("notes", e.target.value)}
                                rows={3}
                                className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                placeholder="Catatan penerimaan barang (opsional)"
                            />
                        </div>
                    )}

                    <div className="flex justify-end gap-3">
                        <Link
                            href={route("goods-receivings.index")}
                            className="flex h-11 items-center rounded-xl border border-slate-200 bg-white px-6 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                        >
                            Batal
                        </Link>
                        {selectedOrder && data.items.length > 0 && (
                            <Button
                                type="submit"
                                icon={<IconTruckDelivery size={18} />}
                                className="bg-success-500 hover:bg-success-600 text-white shadow-lg shadow-success-500/30"
                                label={processing ? "Menyimpan..." : "Konfirmasi Penerimaan"}
                                disabled={processing}
                            />
                        )}
                    </div>
                </div>
            </form>
        </>
    );
}

Create.layout = (page) => <DashboardLayout children={page} />;
