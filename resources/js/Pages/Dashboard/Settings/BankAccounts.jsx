import React, { useEffect } from "react";
import { Head, usePage, router, Link } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconBuildingBank,
    IconPlus,
    IconPencil,
    IconTrash,
    IconGripVertical,
} from "@tabler/icons-react";
import toast from "react-hot-toast";
import { useAuthorization } from "@/Utils/authorization";

export default function BankAccounts({ bankAccounts = [] }) {
    const { flash } = usePage().props;
    const { can } = useAuthorization();
    const canUpdatePaymentSettings = can("payment-settings-update");

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    const handleDelete = (bank) => {
        if (confirm(`Hapus rekening ${bank.bank_name}?`)) {
            router.delete(route("settings.bank-accounts.destroy", bank.id));
        }
    };

    const handleToggle = (bank) => {
        router.patch(route("settings.bank-accounts.toggle", bank.id));
    };

    return (
        <>
            <Head title="Pengaturan Rekening Bank" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconBuildingBank size={28} className="text-primary-500" />
                    Rekening Bank
                </h1>
                <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Kelola rekening bank untuk pembayaran transfer
                </p>
            </div>

            <div className="max-w-3xl space-y-6">
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div className="p-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                        <h3 className="font-semibold text-slate-800 dark:text-white">
                            Daftar Rekening ({bankAccounts.length})
                        </h3>
                        {canUpdatePaymentSettings && (
                            <Link
                                href={route("settings.bank-accounts.create")}
                                className="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors"
                            >
                                <IconPlus size={18} />
                                Tambah Bank
                            </Link>
                        )}
                    </div>

                    {bankAccounts.length > 0 ? (
                        <div className="divide-y divide-slate-200 dark:divide-slate-800">
                            {bankAccounts.map((bank) => (
                                <div
                                    key={bank.id}
                                    className={`p-4 flex items-center gap-4 ${
                                        !bank.is_active ? "opacity-50" : ""
                                    }`}
                                >
                                    <div className="text-slate-400 cursor-move">
                                        <IconGripVertical size={20} />
                                    </div>
                                    <div className="w-12 h-12 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center overflow-hidden">
                                        {bank.logo_url ? (
                                            <img
                                                src={bank.logo_url}
                                                alt={bank.bank_name}
                                                className="max-w-full max-h-full object-contain"
                                            />
                                        ) : (
                                            <IconBuildingBank
                                                size={24}
                                                className="text-slate-500"
                                            />
                                        )}
                                    </div>
                                    <div className="flex-1">
                                        <p className="font-semibold text-slate-800 dark:text-white">
                                            {bank.bank_name}
                                        </p>
                                        <p className="text-sm text-slate-500 dark:text-slate-400">
                                            {bank.account_number} • {bank.account_name}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {canUpdatePaymentSettings && (
                                            <>
                                                <button
                                                    onClick={() => handleToggle(bank)}
                                                    className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
                                                        bank.is_active
                                                            ? "bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400"
                                                            : "bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                                                    }`}
                                                >
                                                    {bank.is_active ? "Aktif" : "Nonaktif"}
                                                </button>
                                                <Link
                                                    href={route("settings.bank-accounts.edit", bank.id)}
                                                    className="p-2 rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                                                >
                                                    <IconPencil size={18} />
                                                </Link>
                                                <button
                                                    onClick={() => handleDelete(bank)}
                                                    className="p-2 rounded-lg text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-900/20 transition-colors"
                                                >
                                                    <IconTrash size={18} />
                                                </button>
                                            </>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="p-8 text-center">
                            <IconBuildingBank
                                size={48}
                                className="mx-auto text-slate-300 dark:text-slate-600 mb-3"
                            />
                            <p className="text-slate-500 dark:text-slate-400">
                                Belum ada rekening bank
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

BankAccounts.layout = (page) => <DashboardLayout children={page} />;
