import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, usePage, Link } from "@inertiajs/react";
import Input from "@/Components/Dashboard/Input";
import toast from "react-hot-toast";
import {
    IconFolder,
    IconDeviceFloppy,
    IconArrowLeft,
} from "@tabler/icons-react";

export default function Edit({ category }) {
    const { errors } = usePage().props;

    const { data, setData, put, processing } = useForm({
        name: category.name ?? "",
        account_code: category.account_code ?? "",
        is_active: category.is_active ?? true,
    });

    const submit = (e) => {
        e.preventDefault();
        put(route("expense-categories.update", category.id), {
            onSuccess: () => toast.success("Kategori beban berhasil diperbarui"),
            onError: () => toast.error("Gagal memperbarui kategori beban"),
        });
    };

    return (
        <>
            <Head title="Ubah Kategori Beban" />

            <div className="mb-6">
                <Link
                    href={route("expense-categories.index")}
                    className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke Kategori Beban
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconFolder size={28} className="text-primary-500" />
                    Ubah Kategori Beban
                </h1>
            </div>

            <form onSubmit={submit}>
                <div className="max-w-2xl">
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <Input
                                    type="text"
                                    label="Nama Kategori"
                                    placeholder="Misal: Perlengkapan"
                                    errors={errors.name}
                                    onChange={(e) => setData("name", e.target.value)}
                                    value={data.name}
                                />
                                {errors.name && (
                                    <p className="mt-1 text-sm text-danger-600">{errors.name}</p>
                                )}
                            </div>
                            <div>
                                <Input
                                    type="text"
                                    label="Kode Akun (opsional)"
                                    placeholder="Untuk pemetaan bagan akun nanti"
                                    errors={errors.account_code}
                                    onChange={(e) => setData("account_code", e.target.value)}
                                    value={data.account_code}
                                />
                                {errors.account_code && (
                                    <p className="mt-1 text-sm text-danger-600">{errors.account_code}</p>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    id="is_active"
                                    checked={data.is_active}
                                    onChange={(e) => setData("is_active", e.target.checked)}
                                    className="h-5 w-5 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
                                />
                                <label htmlFor="is_active" className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Aktif (bisa dipakai untuk beban baru)
                                </label>
                            </div>
                        </div>

                        <div className="mt-6 flex justify-end">
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50"
                            >
                                <IconDeviceFloppy size={18} />
                                Simpan Perubahan
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </>
    );
}

Edit.layout = (page) => <DashboardLayout children={page} />;
