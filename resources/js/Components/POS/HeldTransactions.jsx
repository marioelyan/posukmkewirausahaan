import React, { useState } from "react";
import { router } from "@inertiajs/react";
import {
    IconClock,
    IconPlayerPlay,
    IconTrash,
    IconChevronDown,
    IconChevronUp,
    IconX,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

/**
 * HeldTransactions - Compact badge with expandable panel
 * Takes minimal space when collapsed, expands to show list with max height
 */
export default function HeldTransactions({
    heldCarts = [],
    hasActiveCart = false,
}) {
    const [isExpanded, setIsExpanded] = useState(false);
    const [resumingId, setResumingId] = useState(null);
    const [deletingId, setDeletingId] = useState(null);

    if (!heldCarts || heldCarts.length === 0) {
        return null;
    }

    const handleResume = (holdId) => {
        if (hasActiveCart) {
            toast.error(
                "Selesaikan atau tahan transaksi aktif terlebih dahulu"
            );
            return;
        }

        setResumingId(holdId);

        router.post(
            route("transactions.resume", holdId),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success("Transaksi dilanjutkan");
                    setResumingId(null);
                    setIsExpanded(false);
                },
                onError: (errors) => {
                    toast.error(
                        errors.message || "Gagal melanjutkan transaksi"
                    );
                    setResumingId(null);
                },
            }
        );
    };

    const handleDelete = (holdId) => {
        if (!confirm("Hapus transaksi yang ditahan ini?")) return;

        setDeletingId(holdId);

        router.delete(route("transactions.clearHold", holdId), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success("Transaksi dihapus");
                setDeletingId(null);
            },
            onError: () => {
                toast.error("Gagal menghapus transaksi");
                setDeletingId(null);
            },
        });
    };

    const totalHeldAmount = heldCarts.reduce(
        (sum, h) => sum + Number(h.total || 0),
        0
    );

    // Collapsed view - compact clickable badge (minimal space)
    if (!isExpanded) {
        return (
            <button
                onClick={() => setIsExpanded(true)}
                className="w-full px-3 py-2 flex items-center justify-between bg-amber-50 dark:bg-amber-950/30 border-b border-amber-200 dark:border-amber-800/50 hover:bg-amber-100 dark:hover:bg-amber-900/40 transition-colors"
            >
                <div className="flex items-center gap-2">
                    <div className="w-6 h-6 rounded-md bg-amber-500 flex items-center justify-center text-white text-xs font-bold">
                        {heldCarts.length}
                    </div>
                    <span className="text-sm font-medium text-amber-700 dark:text-amber-300">
                        Transaksi Ditahan
                    </span>
                    <span className="text-xs text-amber-600 dark:text-amber-400">
                        • {formatPrice(totalHeldAmount)}
                    </span>
                </div>
                <IconChevronDown size={16} className="text-amber-600" />
            </button>
        );
    }

    // Expanded view - list with max height and scroll
    return (
        <div className="border-b border-amber-200 dark:border-amber-800/50 bg-amber-50 dark:bg-amber-950/30">
            {/* Header */}
            <div className="flex items-center justify-between px-3 py-2 border-b border-amber-200/50 dark:border-amber-800/30">
                <div className="flex items-center gap-2">
                    <div className="w-6 h-6 rounded-md bg-amber-500 flex items-center justify-center text-white text-xs font-bold">
                        {heldCarts.length}
                    </div>
                    <span className="text-sm font-medium text-amber-700 dark:text-amber-300">
                        Transaksi Ditahan
                    </span>
                </div>
                <button
                    onClick={() => setIsExpanded(false)}
                    className="w-6 h-6 rounded flex items-center justify-center hover:bg-amber-200 dark:hover:bg-amber-900/50"
                >
                    <IconChevronUp size={16} className="text-amber-600" />
                </button>
            </div>

            {/* List with max height - won't take over cart space */}
            <div className="max-h-[140px] overflow-y-auto">
                {heldCarts.map((hold) => (
                    <div
                        key={hold.hold_id}
                        className="px-3 py-2 border-b border-amber-100/50 dark:border-amber-900/30 last:border-0 flex items-center justify-between gap-2"
                    >
                        <div className="flex-1 min-w-0">
                            <p className="text-xs font-medium text-amber-800 dark:text-amber-200 truncate">
                                {hold.label}
                            </p>
                            <p className="text-xs text-amber-600 dark:text-amber-400">
                                {hold.items_count} item •{" "}
                                {formatPrice(hold.total)}
                            </p>
                        </div>
                        <div className="flex items-center gap-1">
                            <button
                                onClick={() => handleResume(hold.hold_id)}
                                disabled={
                                    resumingId === hold.hold_id || hasActiveCart
                                }
                                className="px-2 py-1 rounded bg-amber-500 hover:bg-amber-600 text-white text-xs font-medium disabled:opacity-50 flex items-center gap-1"
                                title={
                                    hasActiveCart
                                        ? "Kosongkan keranjang dulu"
                                        : "Lanjutkan"
                                }
                            >
                                {resumingId === hold.hold_id ? (
                                    <div className="w-3 h-3 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                                ) : (
                                    <IconPlayerPlay size={12} />
                                )}
                            </button>
                            <button
                                onClick={() => handleDelete(hold.hold_id)}
                                disabled={deletingId === hold.hold_id}
                                className="p-1 rounded hover:bg-amber-200 dark:hover:bg-amber-900/50 text-amber-600 disabled:opacity-50"
                            >
                                <IconTrash size={12} />
                            </button>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

/**
 * HoldButton - Compact button to hold current transaction
 */
export function HoldButton({ hasItems = false, onHold, isHolding = false }) {
    const [showLabelInput, setShowLabelInput] = useState(false);
    const [label, setLabel] = useState("");

    const handleHold = () => {
        onHold(label || null);
        setLabel("");
        setShowLabelInput(false);
    };

    if (!hasItems) return null;

    if (showLabelInput) {
        return (
            <div className="flex gap-2">
                <input
                    type="text"
                    value={label}
                    onChange={(e) => setLabel(e.target.value)}
                    placeholder="Label (opsional)"
                    className="flex-1 h-8 px-3 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-sm"
                    autoFocus
                    onKeyDown={(e) => {
                        if (e.key === "Enter") handleHold();
                        if (e.key === "Escape") setShowLabelInput(false);
                    }}
                />
                <button
                    onClick={handleHold}
                    disabled={isHolding}
                    className="px-3 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold disabled:opacity-50"
                >
                    {isHolding ? "..." : "OK"}
                </button>
                <button
                    onClick={() => setShowLabelInput(false)}
                    className="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800"
                >
                    <IconX size={14} className="text-slate-500" />
                </button>
            </div>
        );
    }

    return (
        <button
            onClick={() => setShowLabelInput(true)}
            className="flex items-center justify-center gap-1.5 w-full py-2 px-3 rounded-lg border border-dashed border-amber-400 dark:border-amber-700 text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/30 text-xs font-medium transition-colors"
        >
            <IconClock size={14} />
            Tahan
        </button>
    );
}
