import React, { useEffect, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconHistory,
    IconSearch,
    IconCalendar,
    IconAlertCircle,
    IconChartBar,
    IconUsers,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const formatDate = (value) => {
    if (!value) return "-";
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
    });
};

const statusBadge = (value) => {
    const base = "px-2 py-1 text-xs font-semibold rounded-full";
    switch (value) {
        case "paid":
            return <span className={`${base} bg-success-100 text-success-700`}>Lunas</span>;
        case "partial":
            return <span className={`${base} bg-primary-100 text-primary-700`}>Parsial</span>;
        case "overdue":
            return <span className={`${base} bg-rose-100 text-rose-700`}>Jatuh Tempo</span>;
        default:
            return <span className={`${base} bg-amber-100 text-amber-700`}>Belum Lunas</span>;
    }
};

export default function ReceivablesIndex({ receivables, filters = {} }) {
    const { flash } = usePage().props;
    const [search, setSearch] = useState(filters.invoice || "");
    const [status, setStatus] = useState(filters.status || "");
    const [activeTab, setActiveTab] = useState("list");
    const [agingData, setAgingData] = useState(null);
    const [loadingAging, setLoadingAging] = useState(false);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    useEffect(() => {
        if (activeTab !== "aging" || agingData) return;
        setLoadingAging(true);
        fetch(route("receivables.aging"))
            .then((res) => res.json())
            .then((data) => {
                setAgingData(data);
                setLoadingAging(false);
            })
            .catch(() => {
                setLoadingAging(false);
                toast.error("Gagal memuat data aging");
            });
    }, [activeTab]);

    const applyFilter = (e) => {
        e.preventDefault();
        router.get(
            route("receivables.index"),
            { invoice: search, status },
            { preserveScroll: true, preserveState: true }
        );
    };

    const rows = receivables?.data || [];

    return (
        <>
            <Head title="Nota Barang" />
            <div className="space-y-6">
                <div className="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <IconHistory size={26} className="text-primary-500" />
                            Nota Barang (Piutang)
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Pantau piutang pelanggan dan pembayaran parsialnya.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="flex rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 p-1">
                            <button
                                onClick={() => setActiveTab("list")}
                                className={`px-4 py-2 rounded-lg text-sm font-semibold transition-colors ${
                                    activeTab === "list"
                                        ? "bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-sm"
                                        : "text-slate-500 hover:text-slate-700 dark:hover:text-slate-300"
                                }`}
                            >
                                Daftar
                            </button>
                            <button
                                onClick={() => setActiveTab("aging")}
                                className={`px-4 py-2 rounded-lg text-sm font-semibold transition-colors flex items-center gap-1.5 ${
                                    activeTab === "aging"
                                        ? "bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-sm"
                                        : "text-slate-500 hover:text-slate-700 dark:hover:text-slate-300"
                                }`}
                            >
                                <IconChartBar size={16} />
                                Aging
                            </button>
                        </div>
                        <Link
                            href={route("transactions.index")}
                            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-semibold transition-colors"
                        >
                            Buat Dari POS
                        </Link>
                    </div>
                </div>

                {activeTab === "aging" ? (
                    <div className="space-y-6">
                        {loadingAging ? (
                            <div className="flex items-center justify-center py-12">
                                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-500"></div>
                            </div>
                        ) : agingData ? (
                            <>
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                        <p className="text-xs font-medium text-slate-500 uppercase tracking-wide">Total Piutang</p>
                                        <p className="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                            {formatCurrency(agingData.collection_rate?.total_receivables_amount || 0)}
                                        </p>
                                    </div>
                                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                        <p className="text-xs font-medium text-slate-500 uppercase tracking-wide">Sudah Dibayar</p>
                                        <p className="mt-2 text-2xl font-bold text-success-600">
                                            {formatCurrency(agingData.collection_rate?.total_paid_amount || 0)}
                                        </p>
                                    </div>
                                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                        <p className="text-xs font-medium text-slate-500 uppercase tracking-wide">Collection Rate</p>
                                        <p className="mt-2 text-2xl font-bold text-primary-600">
                                            {agingData.collection_rate?.collection_rate || 0}%
                                        </p>
                                    </div>
                                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                        <p className="text-xs font-medium text-slate-500 uppercase tracking-wide">Lunas / Total</p>
                                        <p className="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                            {agingData.collection_rate?.paid_count || 0} / {agingData.collection_rate?.total_count || 0}
                                        </p>
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-5 gap-6">
                                    <div className="md:col-span-2 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                        <h3 className="text-lg font-semibold text-slate-900 dark:text-white mb-4">Aging Piutang</h3>
                                        <div className="space-y-3">
                                            {agingData.aging_summary?.map((bucket) => {
                                                const bucketColors = {
                                                    current: "bg-success-100 text-success-700",
                                                    "0-30": "bg-success-100 text-success-700",
                                                    "31-60": "bg-warning-100 text-warning-700",
                                                    "61-90": "bg-orange-100 text-orange-700",
                                                    "90+": "bg-danger-100 text-danger-700",
                                                };
                                                const bucketLabels = {
                                                    current: "Belum Jatuh Tempo",
                                                    "0-30": "1-30 Hari",
                                                    "31-60": "31-60 Hari",
                                                    "61-90": "61-90 Hari",
                                                    "90+": "90+ Hari",
                                                };
                                                return (
                                                    <div key={bucket.bucket} className="flex items-center justify-between">
                                                        <span className={`px-2.5 py-1 rounded-full text-xs font-semibold ${bucketColors[bucket.bucket] || "bg-slate-100 text-slate-700"}`}>
                                                            {bucketLabels[bucket.bucket] || bucket.bucket}
                                                        </span>
                                                        <div className="text-right">
                                                            <p className="text-sm font-bold text-slate-800 dark:text-white">{formatCurrency(bucket.remaining)}</p>
                                                            <p className="text-xs text-slate-500">{bucket.count} nota</p>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>

                                    <div className="md:col-span-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                        <h3 className="text-lg font-semibold text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                                            <IconUsers size={20} />
                                            Pelanggan Terbesar
                                        </h3>
                                        <div className="space-y-3">
                                            {agingData.top_customers?.length > 0 ? (
                                                agingData.top_customers.map((customer) => (
                                                    <div key={customer.id} className="flex items-center justify-between py-2 border-b border-slate-100 dark:border-slate-800 last:border-0">
                                                        <div>
                                                            <p className="font-medium text-slate-800 dark:text-white">{customer.name}</p>
                                                            <p className="text-xs text-slate-500">Piutang</p>
                                                        </div>
                                                        <div className="text-right">
                                                            <p className="font-semibold text-warning-600">{formatCurrency(customer.remaining)}</p>
                                                            <p className="text-xs text-slate-500">Total: {formatCurrency(customer.total_receivable)}</p>
                                                        </div>
                                                    </div>
                                                ))
                                            ) : (
                                                <p className="text-sm text-slate-500 text-center py-4">Belum ada data piutang.</p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </>
                        ) : null}
                    </div>
                ) : (
                    <>
                        <form
                            onSubmit={applyFilter}
                            className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end"
                        >
                            <div className="relative w-full">
                                <IconSearch size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                                <input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Cari invoice / nomor nota"
                                    className="w-full h-11 pl-10 pr-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                                />
                            </div>
                            <div className="relative w-full">
                                <IconCalendar size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                                <select
                                    value={status}
                                    onChange={(e) => setStatus(e.target.value)}
                                    className="w-full h-11 pl-10 pr-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                                >
                                    <option value="">Semua Status</option>
                                    <option value="unpaid">Belum Lunas</option>
                                    <option value="partial">Parsial</option>
                                    <option value="paid">Lunas</option>
                                    <option value="overdue">Jatuh Tempo</option>
                                </select>
                            </div>
                            <button
                                type="submit"
                                className="w-full sm:w-auto inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-900 text-white text-sm font-semibold"
                            >
                                Terapkan
                            </button>
                        </form>

                        <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden">
                            <div className="w-full overflow-x-auto">
                                <div className="min-w-[720px]">
                                    <div className="grid grid-cols-12 px-4 py-3 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider border-b border-slate-100 dark:border-slate-800">
                                        <div className="col-span-2">Invoice</div>
                                        <div className="col-span-2">Pelanggan</div>
                                        <div className="col-span-2 text-right">Total</div>
                                        <div className="col-span-2 text-right">Sisa</div>
                                        <div className="col-span-2 text-right">Jatuh Tempo</div>
                                        <div className="col-span-2 text-center">Status</div>
                                    </div>
                                    {rows.length > 0 ? (
                                        rows.map((item) => (
                                            <Link
                                                key={item.id}
                                                href={route("receivables.show", item.id)}
                                                className="grid grid-cols-12 gap-2 px-4 py-3 items-center border-b border-slate-100 dark:border-slate-800 hover:bg-primary-50/50 dark:hover:bg-slate-800/50 transition-colors"
                                            >
                                                <div className="col-span-2">
                                                    <p className="text-sm font-semibold text-slate-800 dark:text-white">{item.invoice}</p>
                                                    {item.transaction_id && (
                                                        <p className="text-[11px] text-slate-500">POS #{item.transaction_id}</p>
                                                    )}
                                                </div>
                                                <div className="col-span-2">
                                                    <p className="text-sm text-slate-700 dark:text-slate-200">{item.customer?.name || "Umum"}</p>
                                                </div>
                                                <div className="col-span-2 text-right text-sm font-semibold text-slate-900 dark:text-white">
                                                    {formatCurrency(item.total)}
                                                </div>
                                                <div className="col-span-2 text-right text-sm font-semibold text-primary-600 dark:text-primary-400">
                                                    {formatCurrency(item.remaining)}
                                                </div>
                                                <div className="col-span-2 text-right text-sm text-slate-600 dark:text-slate-400">
                                                    {formatDate(item.due_date)}
                                                </div>
                                                <div className="col-span-2 flex justify-center">
                                                    {statusBadge(item.status)}
                                                </div>
                                            </Link>
                                        ))
                                    ) : (
                                        <div className="p-8 text-center text-slate-500 dark:text-slate-400">
                                            <IconAlertCircle size={28} className="mx-auto mb-2" />
                                            Belum ada data nota barang.
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        <div className="flex justify-between items-center text-sm text-slate-500">
                            <div>
                                Menampilkan {rows.length} dari {receivables?.total || 0} data
                            </div>
                            <div className="flex gap-2">
                                {receivables?.links?.map((link, idx) => (
                                    <button
                                        key={idx}
                                        disabled={!link.url}
                                        onClick={() => link.url && router.visit(link.url)}
                                        className={`px-3 py-1.5 rounded-lg text-sm ${
                                            link.active
                                                ? "bg-primary-500 text-white"
                                                : "bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300"
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

ReceivablesIndex.layout = (page) => <DashboardLayout children={page} />;
