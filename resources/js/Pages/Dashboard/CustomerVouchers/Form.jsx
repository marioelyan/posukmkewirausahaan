import React from "react";
import { Head, useForm } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import {
    IconArrowLeft,
    IconCreditCard,
    IconDeviceFloppy,
} from "@tabler/icons-react";

function InputError({ message }) {
    if (!message) return null;

    return <p className="mt-1 text-xs text-rose-500">{message}</p>;
}

export default function Form({ mode = "create", voucher = null, customers = [] }) {
    const isEdit = mode === "edit";
    const { data, setData, post, put, processing, errors } = useForm({
        customer_id: voucher?.customer_id ? String(voucher.customer_id) : "",
        code: voucher?.code ?? "",
        name: voucher?.name ?? "",
        discount_type: voucher?.discount_type ?? "fixed_amount",
        discount_value: voucher?.discount_value
            ? String(voucher.discount_value)
            : "",
        minimum_order: voucher?.minimum_order
            ? String(voucher.minimum_order)
            : "0",
        is_active: Boolean(voucher?.is_active ?? true),
        starts_at: voucher?.starts_at
            ? new Date(voucher.starts_at).toISOString().slice(0, 16)
            : "",
        expires_at: voucher?.expires_at
            ? new Date(voucher.expires_at).toISOString().slice(0, 16)
            : "",
        notes: voucher?.notes ?? "",
    });

    const submit = (event) => {
        event.preventDefault();

        if (isEdit) {
            put(route("customer-vouchers.update", voucher.id));
            return;
        }

        post(route("customer-vouchers.store"));
    };

    return (
        <>
            <Head
                title={
                    isEdit ? "Edit Voucher Customer" : "Buat Voucher Customer"
                }
            />

            <div className="w-full">
                <div className="mb-6">
                    <Button
                        type="link"
                        href={route("customer-vouchers.index")}
                        icon={<IconArrowLeft size={18} />}
                        className="mb-3 border-none bg-transparent px-0 text-slate-500 shadow-none hover:bg-transparent hover:text-primary-600 dark:text-slate-400"
                        label="Kembali ke voucher customer"
                    />
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                        {isEdit
                            ? "Edit Voucher Customer"
                            : "Buat Voucher Customer"}
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Distribusikan voucher promosi untuk pelanggan tertentu.
                    </p>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center gap-3">
                            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-100 text-primary-600 dark:bg-primary-950/40 dark:text-primary-300">
                                <IconCreditCard size={22} />
                            </div>
                            <div>
                                <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                                    Informasi Voucher
                                </h2>
                                <p className="text-sm text-slate-500 dark:text-slate-400">
                                    Tentukan pelanggan, kode, dan identitas voucher personal.
                                </p>
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="md:col-span-2">
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Pelanggan
                                </label>
                                <select
                                    value={data.customer_id}
                                    onChange={(event) =>
                                        setData("customer_id", event.target.value)
                                    }
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    <option value="">Pilih pelanggan</option>
                                    {customers.map((customer) => (
                                        <option key={customer.id} value={customer.id}>
                                            {customer.name} | {customer.no_telp || "-"} |{" "}
                                            {customer.is_loyalty_member
                                                ? `${customer.loyalty_tier} / ${customer.loyalty_points} poin`
                                                : "non-member"}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.customer_id} />
                            </div>

                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Kode Voucher
                                </label>
                                <input
                                    type="text"
                                    value={data.code}
                                    onChange={(event) =>
                                        setData(
                                            "code",
                                            event.target.value.toUpperCase()
                                        )
                                    }
                                    placeholder="Kosongkan untuk generate otomatis"
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                                <InputError message={errors.code} />
                            </div>

                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Nama Voucher
                                </label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={(event) =>
                                        setData("name", event.target.value)
                                    }
                                    placeholder="Contoh: Voucher Member Mei"
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                                <InputError message={errors.name} />
                            </div>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">
                            Benefit & Periode
                        </h2>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Tipe Diskon
                                </label>
                                <select
                                    value={data.discount_type}
                                    onChange={(event) =>
                                        setData("discount_type", event.target.value)
                                    }
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    <option value="fixed_amount">Potongan Nominal</option>
                                    <option value="percentage">Persentase (%)</option>
                                </select>
                                <InputError message={errors.discount_type} />
                            </div>

                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Nilai Diskon
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={data.discount_value}
                                    onChange={(event) =>
                                        setData("discount_value", event.target.value)
                                    }
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                                <InputError message={errors.discount_value} />
                            </div>

                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Minimum Belanja
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    value={data.minimum_order}
                                    onChange={(event) =>
                                        setData("minimum_order", event.target.value)
                                    }
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                                <InputError message={errors.minimum_order} />
                            </div>

                            <div className="flex items-end">
                                <label className="inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800">
                                    <input
                                        type="checkbox"
                                        checked={data.is_active}
                                        onChange={(event) =>
                                            setData("is_active", event.target.checked)
                                        }
                                        className="h-4 w-4 rounded border-slate-300 text-primary-500 focus:ring-primary-500/20"
                                    />
                                    <span className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Voucher aktif
                                    </span>
                                </label>
                            </div>

                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Mulai Berlaku
                                </label>
                                <input
                                    type="datetime-local"
                                    value={data.starts_at}
                                    onChange={(event) =>
                                        setData("starts_at", event.target.value)
                                    }
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                                <InputError message={errors.starts_at} />
                            </div>

                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Berakhir Pada
                                </label>
                                <input
                                    type="datetime-local"
                                    value={data.expires_at}
                                    onChange={(event) =>
                                        setData("expires_at", event.target.value)
                                    }
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                                <InputError message={errors.expires_at} />
                            </div>

                            <div className="md:col-span-2">
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Catatan
                                </label>
                                <textarea
                                    rows="4"
                                    value={data.notes}
                                    onChange={(event) =>
                                        setData("notes", event.target.value)
                                    }
                                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                                <InputError message={errors.notes} />
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <Button
                            type="link"
                            href={route("customer-vouchers.index")}
                            className="border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                            label="Batal"
                        />
                        <Button
                            type="submit"
                            disabled={processing}
                            icon={<IconDeviceFloppy size={18} />}
                            className="bg-primary-500 text-white hover:bg-primary-600 disabled:opacity-60"
                            label={processing ? "Menyimpan..." : "Simpan"}
                        />
                    </div>
                </form>
            </div>
        </>
    );
}
