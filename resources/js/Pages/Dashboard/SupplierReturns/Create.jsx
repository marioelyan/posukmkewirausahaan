import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import {
    IconArrowLeft,
    IconPlus,
    IconTrash,
    IconTruckReturn,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

export default function Create({ suppliers, goodsReceivings, products }) {
    const { errors } = usePage().props;
    const { data, setData, post, processing } = useForm({
        supplier_id: "",
        goods_receiving_id: "",
        payable_id: "",
        notes: "",
        items: [],
    });

    const [selectedGrId, setSelectedGrId] = useState("");
    const [searchProduct, setSearchProduct] = useState("");

    const selectedGr = goodsReceivings.find((gr) => gr.id === Number(selectedGrId));

    const filteredProducts = products.filter(
        (p) =>
            p.title.toLowerCase().includes(searchProduct.toLowerCase()) ||
            (p.sku && p.sku.toLowerCase().includes(searchProduct.toLowerCase()))
    );

    const addItemFromProduct = (product) => {
        if (data.items.some((i) => i.product_id === product.id)) {
            toast.error("Produk sudah ada di daftar.");
            return;
        }
        setData("items", [
            ...data.items,
            {
                product_id: product.id,
                product_title: product.title,
                product_sku: product.sku || "-",
                qty_returned: 1,
                unit_price: Number(product.buy_price) || 0,
                reason: "",
                notes: "",
            },
        ]);
    };

    const addItemFromGr = (grItem) => {
        if (data.items.some((i) => i.goods_receiving_item_id === grItem.id)) {
            toast.error("Item sudah ada di daftar.");
            return;
        }
        setData("items", [
            ...data.items,
            {
                goods_receiving_item_id: grItem.id,
                product_id: grItem.product_id,
                product_title: grItem.product?.title || "Produk #" + grItem.product_id,
                product_sku: grItem.product?.sku || "-",
                qty_returned: 1,
                unit_price: Number(grItem.purchase_order_item?.unit_price) || 0,
                reason: "",
                notes: "",
            },
        ]);
    };

    const removeItem = (index) => {
        setData("items", data.items.filter((_, i) => i !== index));
    };

    const updateItem = (index, key, value) => {
        const items = [...data.items];
        if (key === "qty_returned") {
            items[index] = { ...items[index], qty_returned: parseInt(value) || 0 };
        } else if (key === "unit_price") {
            items[index] = { ...items[index], unit_price: Number(value) || 0 };
        } else {
            items[index] = { ...items[index], [key]: value };
        }
        setData("items", items);
    };

    const submit = (e) => {
        e.preventDefault();
        if (data.items.length === 0) {
            toast.error("Tambahkan minimal satu item.");
            return;
        }
        if (!data.supplier_id) {
            toast.error("Pilih supplier.");
            return;
        }
        post(route("supplier-returns.store"), {
            onError: () => toast.error("Gagal membuat retur supplier"),
        });
    };

    const total = data.items.reduce((sum, item) => sum + item.qty_returned * item.unit_price, 0);

    return (
        <>
            <Head title="Buat Retur Supplier" />
            <div className="mb-6">
                <Link
                    href={route("supplier-returns.index")}
                    className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke daftar retur
                </Link>
                <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                    <IconTruckReturn size={28} className="text-primary-500" />
                    Buat Retur Supplier
                </h1>
            </div>

            <form onSubmit={submit} className="max-w-5xl">
                <div className="space-y-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Informasi Retur</h2>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <div>
                                <label className="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-200">Supplier</label>
                                <select
                                    value={data.supplier_id}
                                    onChange={(e) => {
                                        setData({ supplier_id: e.target.value, goods_receiving_id: "", payable_id: "", items: [] });
                                        setSelectedGrId("");
                                    }}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    <option value="">Pilih Supplier</option>
                                    {suppliers.map((s) => (
                                        <option key={s.id} value={s.id}>{s.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    Penerimaan Barang (Opsional)
                                </label>
                                <select
                                    value={selectedGrId}
                                    onChange={(e) => {
                                        setSelectedGrId(e.target.value);
                                        setData("goods_receiving_id", e.target.value);
                                    }}
                                    disabled={!data.supplier_id}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 disabled:opacity-50"
                                >
                                    <option value="">Tidak terkait GR</option>
                                    {goodsReceivings.map((gr) => (
                                        <option key={gr.id} value={gr.id}>
                                            {gr.document_number} ({gr.items?.length || 0} item)
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-200">Catatan</label>
                                <input
                                    type="text"
                                    value={data.notes}
                                    onChange={(e) => setData("notes", e.target.value)}
                                    placeholder="Catatan retur"
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                            </div>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Item Retur</h2>

                        {selectedGr && (
                            <div className="mb-4">
                                <p className="mb-2 text-sm font-medium text-slate-600 dark:text-slate-400">
                                    Item dari GR {selectedGr.document_number}
                                </p>
                                <div className="max-h-48 space-y-2 overflow-y-auto rounded-xl border border-slate-100 p-3 dark:border-slate-700">
                                    {selectedGr.items?.map((grItem) => {
                                        const outstanding = grItem.qty_received || 0;
                                        return outstanding > 0 ? (
                                            <button
                                                key={grItem.id}
                                                type="button"
                                                onClick={() => addItemFromGr(grItem)}
                                                className="flex w-full items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-4 py-2 text-left text-sm transition hover:border-primary-200 hover:bg-primary-50 dark:border-slate-700 dark:bg-slate-800 dark:hover:border-primary-700 dark:hover:bg-primary-950/20"
                                            >
                                                <div>
                                                    <p className="font-medium text-slate-800 dark:text-slate-200">
                                                        {grItem.product?.title || "Produk #" + grItem.product_id}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {grItem.product?.sku || "-"} &bull; Harga: {formatCurrency(grItem.purchase_order_item?.unit_price || 0)}
                                                    </p>
                                                </div>
                                                <span className="text-xs text-primary-600">+ Tambah</span>
                                            </button>
                                        ) : null;
                                    })}
                                </div>
                            </div>
                        )}

                        <div className="mb-4 flex gap-3">
                            <input
                                type="text"
                                value={searchProduct}
                                onChange={(e) => setSearchProduct(e.target.value)}
                                placeholder="Cari produk untuk ditambahkan..."
                                className="h-11 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                            />
                        </div>
                        {searchProduct && filteredProducts.length > 0 && (
                            <div className="mb-4 max-h-48 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                {filteredProducts.map((product) => (
                                    <button
                                        key={product.id}
                                        type="button"
                                        onClick={() => addItemFromProduct(product)}
                                        className="flex w-full items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-4 py-3 text-left text-sm transition hover:border-primary-200 hover:bg-primary-50 dark:border-slate-700 dark:bg-slate-800 dark:hover:border-primary-700 dark:hover:bg-primary-950/20"
                                    >
                                        <div>
                                            <p className="font-medium text-slate-800 dark:text-slate-200">{product.title}</p>
                                            <p className="text-xs text-slate-500">{product.sku || "-"} &bull; Stok: {product.stock}</p>
                                        </div>
                                        <span className="text-xs text-slate-500">{formatCurrency(product.buy_price)}</span>
                                    </button>
                                ))}
                            </div>
                        )}

                        {data.items.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 dark:border-slate-700">
                                            <th className="px-3 py-2 text-left font-semibold text-slate-700 dark:text-slate-200">Produk</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Qty</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Harga</th>
                                            <th className="px-3 py-2 text-right font-semibold text-slate-700 dark:text-slate-200">Subtotal</th>
                                            <th className="px-3 py-2 text-left font-semibold text-slate-700 dark:text-slate-200">Alasan</th>
                                            <th className="w-16 px-3 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.items.map((item, index) => (
                                            <tr key={index} className="border-b border-slate-100 dark:border-slate-800">
                                                <td className="px-3 py-3">
                                                    <p className="font-medium text-slate-800 dark:text-slate-200">{item.product_title}</p>
                                                    <p className="text-xs text-slate-500">{item.product_sku}</p>
                                                </td>
                                                <td className="px-3 py-3 text-right">
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        value={item.qty_returned}
                                                        onChange={(e) => updateItem(index, "qty_returned", e.target.value)}
                                                        className="h-10 w-20 rounded-lg border border-slate-200 bg-slate-50 px-3 text-right text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                                    />
                                                </td>
                                                <td className="px-3 py-3 text-right">
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="100"
                                                        value={item.unit_price}
                                                        onChange={(e) => updateItem(index, "unit_price", e.target.value)}
                                                        className="h-10 w-28 rounded-lg border border-slate-200 bg-slate-50 px-3 text-right text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                                    />
                                                </td>
                                                <td className="px-3 py-3 text-right font-semibold text-slate-800 dark:text-slate-200">
                                                    {formatCurrency(item.qty_returned * item.unit_price)}
                                                </td>
                                                <td className="px-3 py-3">
                                                    <input
                                                        type="text"
                                                        value={item.reason || ""}
                                                        onChange={(e) => updateItem(index, "reason", e.target.value)}
                                                        placeholder="Alasan retur"
                                                        className="h-10 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                                    />
                                                </td>
                                                <td className="px-3 py-3 text-center">
                                                    <button
                                                        type="button"
                                                        onClick={() => removeItem(index)}
                                                        className="rounded-lg p-1.5 text-slate-400 transition hover:bg-danger-50 hover:text-danger-500"
                                                    >
                                                        <IconTrash size={16} />
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="border-t-2 border-slate-200 dark:border-slate-700">
                                            <td colSpan={3} className="px-3 py-3 text-right font-bold text-slate-800 dark:text-slate-200">Total</td>
                                            <td className="px-3 py-3 text-right font-bold text-danger-600">{formatCurrency(total)}</td>
                                            <td colSpan={2}></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        ) : (
                            <div className="rounded-xl border border-dashed border-slate-200 p-8 text-center dark:border-slate-700">
                                <p className="text-sm text-slate-500 dark:text-slate-400">
                                    Pilih supplier, lalu tambahkan item dari GR atau cari produk di atas.
                                </p>
                            </div>
                        )}
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link
                            href={route("supplier-returns.index")}
                            className="flex h-11 items-center rounded-xl border border-slate-200 bg-white px-6 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                        >
                            Batal
                        </Link>
                        <Button
                            type="submit"
                            icon={<IconPlus size={18} />}
                            className="bg-primary-500 hover:bg-primary-600 text-white shadow-lg shadow-primary-500/30"
                            label={processing ? "Menyimpan..." : "Simpan Retur"}
                            disabled={processing}
                        />
                    </div>
                </div>
            </form>
        </>
    );
}

Create.layout = (page) => <DashboardLayout children={page} />;
