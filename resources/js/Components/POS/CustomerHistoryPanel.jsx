import React, { useState, useEffect } from "react";
import {
    IconHistory,
    IconCoin,
    IconCalendar,
    IconShoppingBag,
    IconX,
    IconLoader2,
    IconReceipt,
    IconGift,
    IconCrown,
} from "@tabler/icons-react";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

/**
 * CustomerHistoryPanel - Shows customer purchase history
 * Can be used as a modal or inline panel
 */
export default function CustomerHistoryPanel({
    customerId,
    customerName,
    onClose,
}) {
    const [loading, setLoading] = useState(true);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!customerId) return;

        const fetchHistory = async () => {
            setLoading(true);
            setError(null);

            try {
                const response = await fetch(
                    route("customers.history", customerId),
                    {
                        headers: {
                            Accept: "application/json",
                            "X-Requested-With": "XMLHttpRequest",
                        },
                    }
                );

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const result = await response.json();

                if (result.success) {
                    setData(result);
                } else {
                    setError(result.message || "Gagal memuat data");
                }
            } catch (err) {
                console.error("Customer history error:", err);
                setError("Gagal memuat data pelanggan");
            } finally {
                setLoading(false);
            }
        };

        fetchHistory();
    }, [customerId]);

    if (loading) {
        return (
            <div className="p-6 flex items-center justify-center">
                <IconLoader2
                    size={24}
                    className="animate-spin text-primary-500"
                />
            </div>
        );
    }

    if (error) {
        return (
            <div className="p-6 text-center">
                <p className="text-sm text-danger-500">{error}</p>
            </div>
        );
    }

    if (!data) return null;

    const {
        stats,
        recent_transactions,
        frequent_products,
        loyalty,
        loyalty_history,
        eligible_vouchers,
    } = data;

    return (
        <div className="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xl overflow-hidden">
            {/* Header */}
            <div className="bg-gradient-to-r from-primary-500 to-primary-600 px-4 py-3 flex items-center justify-between">
                <div className="flex items-center gap-2 text-white">
                    <IconHistory size={18} />
                    <span className="font-semibold text-sm">
                        Riwayat Pelanggan
                    </span>
                </div>
                {onClose && (
                    <button
                        onClick={onClose}
                        className="text-white/80 hover:text-white"
                    >
                        <IconX size={18} />
                    </button>
                )}
            </div>

            {/* Customer Name */}
            <div className="px-4 py-3 border-b border-slate-100 dark:border-slate-800">
                <p className="text-base font-semibold text-slate-900 dark:text-white">
                    {customerName}
                </p>
            </div>

            {/* Stats Grid */}
            <div className="grid grid-cols-2 gap-px bg-slate-100 dark:bg-slate-800 sm:grid-cols-4">
                <div className="bg-white dark:bg-slate-900 p-3 text-center">
                    <div className="flex items-center justify-center mb-1">
                        <IconReceipt size={16} className="text-primary-500" />
                    </div>
                    <p className="text-lg font-bold text-slate-900 dark:text-white">
                        {stats.total_transactions}
                    </p>
                    <p className="text-xs text-slate-500">Transaksi</p>
                </div>
                <div className="bg-white dark:bg-slate-900 p-3 text-center">
                    <div className="flex items-center justify-center mb-1">
                        <IconCoin size={16} className="text-success-500" />
                    </div>
                    <p className="text-sm font-bold text-success-600 dark:text-success-400">
                        {formatPrice(stats.total_spent)}
                    </p>
                    <p className="text-xs text-slate-500">Total Belanja</p>
                </div>
                <div className="bg-white dark:bg-slate-900 p-3 text-center">
                    <div className="flex items-center justify-center mb-1">
                        <IconCalendar size={16} className="text-slate-500" />
                    </div>
                    <p className="text-sm font-medium text-slate-700 dark:text-slate-300">
                        {stats.last_visit || "-"}
                    </p>
                    <p className="text-xs text-slate-500">Kunjungan Terakhir</p>
                </div>
                <div className="bg-white dark:bg-slate-900 p-3 text-center">
                    <div className="flex items-center justify-center mb-1">
                        <IconCrown size={16} className="text-warning-500" />
                    </div>
                    <p className="text-sm font-medium uppercase text-slate-700 dark:text-slate-300">
                        {loyalty?.is_member ? loyalty.tier : "non-member"}
                    </p>
                    <p className="text-xs text-slate-500">Tier</p>
                </div>
                <div className="bg-white dark:bg-slate-900 p-3 text-center">
                    <div className="flex items-center justify-center mb-1">
                        <IconGift size={16} className="text-primary-500" />
                    </div>
                    <p className="text-sm font-bold text-primary-600 dark:text-primary-300">
                        {loyalty?.points || 0}
                    </p>
                    <p className="text-xs text-slate-500">Poin</p>
                </div>
            </div>

            {eligible_vouchers && eligible_vouchers.length > 0 && (
                <div className="px-4 py-3 border-t border-slate-100 dark:border-slate-800">
                    <p className="text-xs font-semibold text-slate-500 uppercase mb-2 flex items-center gap-1">
                        <IconGift size={12} />
                        Voucher Aktif
                    </p>
                    <div className="space-y-2">
                        {eligible_vouchers.map((voucher) => (
                            <div
                                key={voucher.id}
                                className="rounded-lg bg-slate-50 dark:bg-slate-800/70 px-3 py-2"
                            >
                                <p className="text-xs font-semibold text-slate-800 dark:text-slate-100">
                                    {voucher.code} - {voucher.name}
                                </p>
                                <p className="text-[11px] text-slate-500 dark:text-slate-400">
                                    Min belanja {formatPrice(voucher.minimum_order)}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Frequent Products */}
            {frequent_products && frequent_products.length > 0 && (
                <div className="px-4 py-3 border-t border-slate-100 dark:border-slate-800">
                    <p className="text-xs font-semibold text-slate-500 uppercase mb-2 flex items-center gap-1">
                        <IconShoppingBag size={12} />
                        Produk Favorit
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {frequent_products.map((product) => (
                            <span
                                key={product.id}
                                className="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-primary-50 dark:bg-primary-950/50 text-xs font-medium text-primary-700 dark:text-primary-300"
                            >
                                {product.title}
                                <span className="text-primary-500">
                                    ×{product.total_qty}
                                </span>
                            </span>
                        ))}
                    </div>
                </div>
            )}

            {/* Recent Transactions */}
            {recent_transactions && recent_transactions.length > 0 && (
                <div className="px-4 py-3 border-t border-slate-100 dark:border-slate-800">
                    <p className="text-xs font-semibold text-slate-500 uppercase mb-2">
                        Transaksi Terakhir
                    </p>
                    <div className="space-y-2 max-h-[150px] overflow-y-auto">
                        {recent_transactions.map((tx) => (
                            <div
                                key={tx.id}
                                className="flex items-center justify-between py-1.5 border-b border-slate-50 dark:border-slate-800/50 last:border-0"
                            >
                                <div>
                                    <p className="text-xs font-medium text-slate-700 dark:text-slate-300">
                                        {tx.invoice}
                                    </p>
                                    <p className="text-xs text-slate-400">
                                        {tx.date}
                                    </p>
                                </div>
                                <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                    {formatPrice(tx.total)}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {loyalty_history && loyalty_history.length > 0 && (
                <div className="px-4 py-3 border-t border-slate-100 dark:border-slate-800">
                    <p className="text-xs font-semibold text-slate-500 uppercase mb-2">
                        Aktivitas Loyalty
                    </p>
                    <div className="space-y-2 max-h-[150px] overflow-y-auto">
                        {loyalty_history.map((entry) => (
                            <div
                                key={entry.id}
                                className="flex items-center justify-between rounded-lg bg-slate-50 dark:bg-slate-800/70 px-3 py-2"
                            >
                                <div>
                                    <p className="text-xs font-medium text-slate-700 dark:text-slate-300">
                                        {entry.reference || entry.type}
                                    </p>
                                    <p className="text-[11px] text-slate-400">
                                        {entry.created_at}
                                    </p>
                                </div>
                                <p
                                    className={`text-xs font-bold ${
                                        entry.points_delta >= 0
                                            ? "text-success-600 dark:text-success-400"
                                            : "text-danger-500"
                                    }`}
                                >
                                    {entry.points_delta >= 0 ? "+" : ""}
                                    {entry.points_delta} poin
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Empty State */}
            {stats.total_transactions === 0 && (
                <div className="px-4 py-6 text-center">
                    <IconHistory
                        size={32}
                        className="mx-auto text-slate-300 mb-2"
                    />
                    <p className="text-sm text-slate-500">
                        Belum ada transaksi
                    </p>
                </div>
            )}
        </div>
    );
}

/**
 * CustomerHistoryButton - Small button to trigger history panel
 */
export function CustomerHistoryButton({
    customerId,
    customerName,
    className = "",
}) {
    const [showHistory, setShowHistory] = useState(false);

    if (!customerId) return null;

    return (
        <>
            <button
                onClick={() => setShowHistory(true)}
                className={`p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 hover:text-primary-500 transition-colors ${className}`}
                title="Lihat riwayat"
            >
                <IconHistory size={16} />
            </button>

            {/* History Modal */}
            {showHistory && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
                    <div className="w-full max-w-sm animate-in zoom-in-95 duration-200">
                        <CustomerHistoryPanel
                            customerId={customerId}
                            customerName={customerName}
                            onClose={() => setShowHistory(false)}
                        />
                    </div>
                </div>
            )}
        </>
    );
}
