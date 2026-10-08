import React, { useEffect } from "react";
import { Head, useForm, Link, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconArrowLeft,
    IconCheck,
    IconBuildingBank,
} from "@tabler/icons-react";
import toast from "react-hot-toast";
import Input from "@/Components/Dashboard/Input";
import { useAuthorization } from "@/Utils/authorization";

export default function BankAccountForm({ bankAccount = null }) {
    const isEdit = !!bankAccount;
    const { flash } = usePage().props;
    const { can } = useAuthorization();
    const canUpdatePaymentSettings = can("payment-settings-update");
    const { data, setData, post, processing, errors } = useForm({
    _method: isEdit ? "PUT" : "POST", // Tambahkan ini
    bank_name: bankAccount?.bank_name || "",
    account_number: bankAccount?.account_number || "",
    account_name: bankAccount?.account_name || "",
    logo: null,
    is_active: bankAccount?.is_active ?? true,
});

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    const handleSubmit = (e) => {
    e.preventDefault();

    // Selalu gunakan post() karena Inertia akan otomatis
    // menangani spoofing method lewat data._method
    if (isEdit) {
        post(route("settings.bank-accounts.update", bankAccount.id), {
            forceFormData: true,
        });
    } else {
        post(route("settings.bank-accounts.store"), {
            forceFormData: true,
        });
    }
};

    return (
        <>
            <Head title={isEdit ? "Edit Rekening Bank" : "Tambah Rekening Bank"} />
            <div className="max-w-3xl space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <IconBuildingBank size={28} className="text-primary-500" />
                            {isEdit ? "Edit Rekening Bank" : "Tambah Rekening Bank"}
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                            Masukkan detail rekening bank untuk pembayaran transfer.
                        </p>
                    </div>
                    <Link
                        href={route("settings.bank-accounts.index")}
                        className="inline-flex items-center gap-2 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                    >
                        <IconArrowLeft size={18} />
                        Kembali
                    </Link>
                </div>

                <form
                    onSubmit={handleSubmit}
                    className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 space-y-4"
                >
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Input
                            label="Nama Bank"
                            placeholder="BCA, Mandiri, BNI..."
                            value={data.bank_name}
                            onChange={(e) => setData("bank_name", e.target.value)}
                            errors={errors.bank_name}
                            disabled={!canUpdatePaymentSettings}
                        />
                        <Input
                            label="Nomor Rekening"
                            placeholder="1234567890"
                            value={data.account_number}
                            onChange={(e) => setData("account_number", e.target.value)}
                            errors={errors.account_number}
                            disabled={!canUpdatePaymentSettings}
                        />
                    </div>
                    <Input
                        label="Atas Nama"
                        placeholder="Nama pemilik rekening"
                        value={data.account_name}
                        onChange={(e) => setData("account_name", e.target.value)}
                        errors={errors.account_name}
                        disabled={!canUpdatePaymentSettings}
                    />

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                                Logo Bank (opsional)
                            </label>
                            <input
                                type="file"
                                accept="image/*"
                                onChange={(e) =>
                                    setData("logo", e.target.files?.[0] || null)
                                }
                                disabled={!canUpdatePaymentSettings}
                                className="w-full h-11 px-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm text-slate-700 dark:text-slate-200 focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                            />
                            {errors.logo && (
                                <p className="text-xs text-danger-500 mt-1">
                                    {errors.logo}
                                </p>
                            )}
                        </div>
                        <div className="flex items-end">
                            <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <input
                                    type="checkbox"
                                    checked={data.is_active}
                                    onChange={(e) => setData("is_active", e.target.checked)}
                                    disabled={!canUpdatePaymentSettings}
                                    className="rounded border-slate-300 dark:border-slate-600 text-primary-600 focus:ring-primary-500"
                                />
                                Aktif
                            </label>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={processing || !canUpdatePaymentSettings}
                            className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-semibold transition-colors disabled:opacity-50"
                        >
                            <IconCheck size={18} />
                            {isEdit ? "Update" : "Simpan"}
                        </button>
                        <Link
                            href={route("settings.bank-accounts.index")}
                            className="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        >
                            Batal
                        </Link>
                    </div>
                </form>
            </div>
        </>
    );
}

BankAccountForm.layout = (page) => <DashboardLayout children={page} />;
