import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link } from "@inertiajs/react";
import {
    IconArrowLeft,
    IconCoins,
    IconCrown,
    IconDatabaseOff,
    IconGift,
    IconPencil,
    IconReceipt,
    IconTags,
} from "@tabler/icons-react";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

const formatDateTime = (value) =>
    value
        ? new Intl.DateTimeFormat("id-ID", {
              dateStyle: "medium",
              timeStyle: "short",
          }).format(new Date(value))
        : "-";

export default function Show({
    member,
    stats,
    recentTransactions = [],
    frequentProducts = [],
    rewardHistory = [],
    vouchers = [],
    segments = [],
}) {
    const hasRecentTransactions = recentTransactions.length > 0;
    const hasRewardHistory = rewardHistory.length > 0;
    const hasFrequentProducts = frequentProducts.length > 0;
    const hasVouchers = vouchers.length > 0;
    const hasSegments = segments.length > 0;

    return (
        <>
            <Head title={`Member - ${member.name}`} />

            <div className="w-full">
                <div className="mb-6">
                    <Link
                        href={route("members.index")}
                        className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600"
                    >
                        <IconArrowLeft size={16} />
                        Kembali ke Member
                    </Link>

                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div className="mb-2 flex items-center gap-2">
                                <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                                    {member.name}
                                </h1>
                                <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                    <IconCrown size={14} />
                                    {member.loyalty_tier || "regular"}
                                </span>
                                <span
                                    className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${
                                        member.is_loyalty_member
                                            ? "bg-success-100 text-success-700 dark:bg-success-950/30 dark:text-success-400"
                                            : "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                                    }`}
                                >
                                    {member.is_loyalty_member
                                        ? "Aktif"
                                        : "Nonaktif"}
                                </span>
                            </div>
                            <p className="text-sm text-slate-500 dark:text-slate-400">
                                {member.no_telp || "-"}{" "}
                                {member.address ? `• ${member.address}` : ""}
                            </p>
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                Nomor Anggota: {member.member_code || "-"}
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <span className="inline-flex items-center gap-1 rounded-full bg-primary-100 px-3 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">
                                <IconCoins size={14} />
                                {member.loyalty_points || 0} poin
                            </span>
                            <Link
                                href={route("members.edit", member.id)}
                                className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-primary-200 hover:text-primary-600 dark:border-slate-700 dark:text-slate-200 dark:hover:border-primary-800 dark:hover:text-primary-300"
                            >
                                <IconPencil size={16} />
                                Edit Member
                            </Link>
                        </div>
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-[1.7fr_1fr]">
                    <div className="space-y-6">
                        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">
                                Ringkasan Member
                            </h2>
                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                <div className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/60">
                                    <p className="text-xs uppercase tracking-wide text-slate-500">
                                        Total Transaksi
                                    </p>
                                    <p className="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                        {stats?.total_transactions || 0}
                                    </p>
                                </div>
                                <div className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/60">
                                    <p className="text-xs uppercase tracking-wide text-slate-500">
                                        Total Belanja
                                    </p>
                                    <p className="mt-2 text-lg font-bold text-slate-900 dark:text-white">
                                        {formatPrice(stats?.total_spent || 0)}
                                    </p>
                                </div>
                                <div className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/60">
                                    <p className="text-xs uppercase tracking-wide text-slate-500">
                                        Member Sejak
                                    </p>
                                    <p className="mt-2 text-sm font-semibold text-slate-900 dark:text-white">
                                        {member.loyalty_member_since
                                            ? new Date(
                                                  member.loyalty_member_since
                                              ).toLocaleDateString("id-ID")
                                            : "-"}
                                    </p>
                                </div>
                                <div className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/60">
                                    <p className="text-xs uppercase tracking-wide text-slate-500">
                                        Kunjungan Terakhir
                                    </p>
                                    <p className="mt-2 text-sm font-semibold text-slate-900 dark:text-white">
                                        {stats?.last_visit
                                            ? new Date(
                                                  stats.last_visit
                                              ).toLocaleDateString("id-ID")
                                            : "-"}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <div className="mb-4 flex items-center gap-2">
                                <IconReceipt size={18} className="text-primary-500" />
                                <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                                    Transaksi Member
                                </h2>
                            </div>
                            {hasRecentTransactions ? (
                                <div className="space-y-3">
                                    {recentTransactions.map((transaction) => (
                                        <div
                                            key={transaction.id}
                                            className="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3 dark:bg-slate-800/50"
                                        >
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                                    {transaction.invoice}
                                                </p>
                                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                                    {formatDateTime(transaction.date)}
                                                </p>
                                            </div>
                                            <p className="text-sm font-bold text-primary-600 dark:text-primary-300">
                                                {formatPrice(transaction.total)}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="rounded-2xl bg-slate-50 px-4 py-8 text-center dark:bg-slate-800/50">
                                    <IconDatabaseOff size={28} className="mx-auto mb-3 text-slate-400" />
                                    <p className="text-sm text-slate-500 dark:text-slate-400">
                                        Belum ada transaksi member.
                                    </p>
                                </div>
                            )}
                        </section>

                        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <div className="mb-4 flex items-center gap-2">
                                <IconGift size={18} className="text-primary-500" />
                                <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                                    Histori Reward
                                </h2>
                            </div>
                            {hasRewardHistory ? (
                                <div className="space-y-3">
                                    {rewardHistory.map((history) => (
                                        <div
                                            key={history.id}
                                            className="rounded-2xl bg-slate-50 px-4 py-3 dark:bg-slate-800/50"
                                        >
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                                        {history.reference || history.type}
                                                    </p>
                                                    <p className="text-xs text-slate-500 dark:text-slate-400">
                                                        {history.notes}
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <p
                                                        className={`text-sm font-bold ${
                                                            history.points_delta >= 0
                                                                ? "text-emerald-600 dark:text-emerald-300"
                                                                : "text-rose-600 dark:text-rose-300"
                                                        }`}
                                                    >
                                                        {history.points_delta >= 0
                                                            ? "+"
                                                            : ""}
                                                        {history.points_delta} poin
                                                    </p>
                                                    <p className="text-xs text-slate-500 dark:text-slate-400">
                                                        {formatDateTime(history.created_at)}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="rounded-2xl bg-slate-50 px-4 py-8 text-center dark:bg-slate-800/50">
                                    <IconDatabaseOff size={28} className="mx-auto mb-3 text-slate-400" />
                                    <p className="text-sm text-slate-500 dark:text-slate-400">
                                        Belum ada histori reward.
                                    </p>
                                </div>
                            )}
                        </section>
                    </div>

                    <div className="space-y-6">
                        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">
                                Informasi Member
                            </h2>
                            <div className="space-y-3 text-sm text-slate-500 dark:text-slate-400">
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800">
                                    <p className="text-xs uppercase tracking-wide text-slate-500">
                                        Tier Loyalty
                                    </p>
                                    <p className="mt-1 font-semibold text-slate-900 dark:text-white">
                                        {member.loyalty_tier || "regular"}
                                    </p>
                                </div>
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800">
                                    <p className="text-xs uppercase tracking-wide text-slate-500">
                                        Saldo Poin
                                    </p>
                                    <p className="mt-1 font-semibold text-slate-900 dark:text-white">
                                        {member.loyalty_points || 0} poin
                                    </p>
                                </div>
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800">
                                    <p className="text-xs uppercase tracking-wide text-slate-500">
                                        Total Nilai Transaksi
                                    </p>
                                    <p className="mt-1 font-semibold text-slate-900 dark:text-white">
                                        {formatPrice(stats?.total_spent || 0)}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <div className="mb-4 flex items-center gap-2">
                                <IconTags size={18} className="text-primary-500" />
                                <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                                    Segment Terkait
                                </h2>
                            </div>
                            {hasSegments ? (
                                <div className="flex flex-wrap gap-2">
                                    {segments.map((segment) => (
                                        <span
                                            key={segment.id}
                                            className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ${
                                                segment.source === "manual"
                                                    ? "bg-primary-100 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"
                                                    : "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                                            }`}
                                        >
                                            {segment.name}
                                            <span className="rounded-full bg-white/70 px-2 py-0.5 text-[10px] uppercase tracking-wide dark:bg-slate-900/40">
                                                {segment.source}
                                            </span>
                                        </span>
                                    ))}
                                </div>
                            ) : (
                                <div className="rounded-2xl bg-slate-50 px-4 py-8 text-center dark:bg-slate-800/50">
                                    <IconDatabaseOff size={28} className="mx-auto mb-3 text-slate-400" />
                                    <p className="text-sm text-slate-500 dark:text-slate-400">
                                        Belum ada segment untuk member ini.
                                    </p>
                                </div>
                            )}
                        </section>

                        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">
                                Produk Favorit
                            </h2>
                            {hasFrequentProducts ? (
                                <div className="flex flex-wrap gap-2">
                                    {frequentProducts.map((product) => (
                                        <span
                                            key={product.id}
                                            className="inline-flex rounded-full bg-primary-100 px-3 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-950/40 dark:text-primary-300"
                                        >
                                            {product.title} x{product.total_qty}
                                        </span>
                                    ))}
                                </div>
                            ) : (
                                <div className="rounded-2xl bg-slate-50 px-4 py-8 text-center dark:bg-slate-800/50">
                                    <IconDatabaseOff size={28} className="mx-auto mb-3 text-slate-400" />
                                    <p className="text-sm text-slate-500 dark:text-slate-400">
                                        Belum ada data produk favorit.
                                    </p>
                                </div>
                            )}
                        </section>

                        <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">
                                Voucher Member
                            </h2>
                            {hasVouchers ? (
                                <div className="space-y-3">
                                    {vouchers.map((voucher) => (
                                        <div
                                            key={voucher.id}
                                            className="rounded-2xl border border-slate-200 p-4 dark:border-slate-800"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                                        {voucher.code}
                                                    </p>
                                                    <p className="text-xs text-slate-500 dark:text-slate-400">
                                                        {voucher.name}
                                                    </p>
                                                </div>
                                                <span className="text-xs font-medium text-primary-600 dark:text-primary-300">
                                                    {voucher.discount_type === "percentage"
                                                        ? `${voucher.discount_value}%`
                                                        : formatPrice(voucher.discount_value)}
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="rounded-2xl bg-slate-50 px-4 py-8 text-center dark:bg-slate-800/50">
                                    <IconDatabaseOff size={28} className="mx-auto mb-3 text-slate-400" />
                                    <p className="text-sm text-slate-500 dark:text-slate-400">
                                        Belum ada voucher untuk member ini.
                                    </p>
                                </div>
                            )}
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}

Show.layout = (page) => <DashboardLayout children={page} />;
