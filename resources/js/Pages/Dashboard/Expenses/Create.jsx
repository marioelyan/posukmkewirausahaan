import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, usePage, Link } from "@inertiajs/react";
import Input from "@/Components/Dashboard/Input";
import InputSelect from "@/Components/Dashboard/InputSelect";
import Textarea from "@/Components/Dashboard/TextArea";
import toast from "react-hot-toast";
import {
    IconCashBanknote,
    IconDeviceFloppy,
    IconArrowLeft,
} from "@tabler/icons-react";

export default function Create({ categories = [], warehouses = [] }) {
    const { errors } = usePage().props;

    const { data, setData, post, processing } = useForm({
        expense_category_id: "",
        warehouse_id: "",
        date: new Date().toISOString().slice(0, 10),
        amount: "",
        source: "bank",
        note: "",
        proof_reference: "",
    });

    const [selectedCategory, setSelectedCategory] = useState(null);
    const [selectedWarehouse, setSelectedWarehouse] = useState(null);

    const submit = (e) => {
        e.preventDefault();
        post(route("expenses.store"), {
            onSuccess: () => toast.success("Beban berhasil dicatat"),
            onError: () => toast.error("Gagal menyimpan beban"),
        });
    };

    return (
        <>
            <Head title="Catat Beban" />

            <div className="mb-6">
                <Link
                    href={route("expenses.index")}
                    className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke Pengeluaran
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconCashBanknote size={28} className="text-primary-500" />
                    Catat Beban Baru
                </h1>
                <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    Beban laci (shift) tidak dicatat di sini — catat lewat gerakan kas di halaman shift,
                    supaya kas dan beban terbentuk bersamaan. Di sini hanya untuk beban bank dan kas lain.
                </p>
            </div>

            <form onSubmit={submit}>
                <div className="max-w-2xl">
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <InputSelect
                                    label="Kategori Beban"
                                    data={categories}
                                    selected={selectedCategory}
                                    setSelected={(item) => {
                                        setSelectedCategory(item);
                                        setData("expense_category_id", item?.id ?? "");
                                    }}
                                    placeholder="Pilih kategori"
                                    displayKey="name"
                                />
                                {errors.expense_category_id && (
                                    <p className="mt-1 text-sm text-danger-600">{errors.expense_category_id}</p>
                                )}
                            </div>
                            <div>
                                <InputSelect
                                    label="Gudang (opsional)"
                                    data={warehouses}
                                    selected={selectedWarehouse}
                                    setSelected={(item) => {
                                        setSelectedWarehouse(item);
                                        setData("warehouse_id", item?.id ?? "");
                                    }}
                                    placeholder="Pilih gudang"
                                    displayKey="name"
                                />
                                {errors.warehouse_id && (
                                    <p className="mt-1 text-sm text-danger-600">{errors.warehouse_id}</p>
                                )}
                            </div>
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Tanggal</label>
                                <input
                                    type="date"
                                    value={data.date}
                                    onChange={(e) => setData("date", e.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                    required
                                />
                                {errors.date && (
                                    <p className="mt-1 text-sm text-danger-600">{errors.date}</p>
                                )}
                            </div>
                            <div>
                                <Input
                                    label="Nominal"
                                    type="number"
                                    min="1"
                                    value={data.amount}
                                    onChange={(e) => setData("amount", e.target.value)}
                                    placeholder="0"
                                />
                                {errors.amount && (
                                    <p className="mt-1 text-sm text-danger-600">{errors.amount}</p>
                                )}
                            </div>
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Sumber</label>
                                <select
                                    value={data.source}
                                    onChange={(e) => setData("source", e.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    <option value="bank">Bank</option>
                                    <option value="other_cash">Kas lain</option>
                                </select>
                                {errors.source && (
                                    <p className="mt-1 text-sm text-danger-600">{errors.source}</p>
                                )}
                            </div>
                            <div>
                                <Input
                                    label="Bukti / Referensi (opsional)"
                                    value={data.proof_reference}
                                    onChange={(e) => setData("proof_reference", e.target.value)}
                                    placeholder="No. nota / referensi"
                                />
                                {errors.proof_reference && (
                                    <p className="mt-1 text-sm text-danger-600">{errors.proof_reference}</p>
                                )}
                            </div>
                            <div className="md:col-span-2">
                                <Textarea
                                    label="Keterangan (opsional)"
                                    value={data.note}
                                    onChange={(e) => setData("note", e.target.value)}
                                    placeholder="Keterangan beban"
                                />
                                {errors.note && (
                                    <p className="mt-1 text-sm text-danger-600">{errors.note}</p>
                                )}
                            </div>
                        </div>

                        <div className="mt-6 flex justify-end">
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50"
                            >
                                <IconDeviceFloppy size={18} />
                                Simpan Beban
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </>
    );
}

Create.layout = (page) => <DashboardLayout children={page} />;
