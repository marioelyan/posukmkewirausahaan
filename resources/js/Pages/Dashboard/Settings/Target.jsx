import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, usePage } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Input from "@/Components/Dashboard/Input";
import toast from "react-hot-toast";
import { IconTarget, IconDeviceFloppy, IconCoin } from "@tabler/icons-react";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

export default function Target({ settings }) {
    const { data, setData, post, processing, errors } = useForm({
        monthly_sales_target: settings?.monthly_sales_target || "",
    });

    const submit = (e) => {
        e.preventDefault();
        post(route("settings.target.update"), {
            preserveScroll: true,
            onSuccess: () => toast.success("Target berhasil disimpan"),
            onError: () => toast.error("Gagal menyimpan target"),
        });
    };

    return (
        <>
            <Head title="Target Penjualan" />

            <div className="space-y-6">
                {/* Header */}
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                        Target Penjualan
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Atur target penjualan bulanan untuk bisnis Anda
                    </p>
                </div>

                {/* Form Card */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <form onSubmit={submit} className="space-y-6">
                        <div className="flex items-start gap-4">
                            <div className="p-3 rounded-xl bg-primary-100 dark:bg-primary-900/30">
                                <IconTarget
                                    size={24}
                                    className="text-primary-600 dark:text-primary-400"
                                />
                            </div>
                            <div className="flex-1">
                                <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                                    Target Penjualan Bulanan
                                </label>
                                <div className="relative">
                                    <div className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                        <IconCoin size={20} />
                                    </div>
                                    <input
                                        type="number"
                                        value={data.monthly_sales_target}
                                        onChange={(e) =>
                                            setData(
                                                "monthly_sales_target",
                                                e.target.value
                                            )
                                        }
                                        placeholder="Contoh: 50000000"
                                        className="w-full h-12 pl-12 pr-4 rounded-xl border-2 border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-400 focus:border-primary-500 focus:ring-4 focus:ring-primary-500/20 transition-all"
                                    />
                                </div>
                                {data.monthly_sales_target > 0 && (
                                    <p className="mt-2 text-sm text-slate-500">
                                        Target:{" "}
                                        {formatCurrency(
                                            data.monthly_sales_target
                                        )}
                                    </p>
                                )}
                                {errors.monthly_sales_target && (
                                    <p className="mt-1 text-sm text-danger-500">
                                        {errors.monthly_sales_target}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="flex justify-end pt-4 border-t border-slate-100 dark:border-slate-800">
                            <Button
                                type="submit"
                                variant="primary"
                                disabled={processing}
                                className="flex items-center gap-2"
                            >
                                <IconDeviceFloppy size={18} />
                                {processing ? "Menyimpan..." : "Simpan Target"}
                            </Button>
                        </div>
                    </form>
                </div>

                {/* Info */}
                <div className="bg-primary-50 dark:bg-primary-950/30 rounded-xl p-4 border border-primary-200 dark:border-primary-900">
                    <p className="text-sm text-primary-700 dark:text-primary-300">
                        <strong>Tip:</strong> Target penjualan akan ditampilkan
                        di Dashboard sebagai progress bar untuk memantau
                        pencapaian bulanan Anda.
                    </p>
                </div>
            </div>
        </>
    );
}

Target.layout = (page) => <DashboardLayout children={page} />;
