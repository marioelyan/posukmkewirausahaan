import React, { useState, useEffect, useCallback } from "react";
import { IconBackspace, IconX, IconCheck } from "@tabler/icons-react";

/**
 * Numpad Modal for POS - Touch-friendly number input
 *
 * @param {boolean} isOpen - Controls modal visibility
 * @param {function} onClose - Called when modal should close
 * @param {function} onConfirm - Called with the final value when confirmed
 * @param {string} title - Modal title (e.g., "Jumlah Bayar", "Quantity")
 * @param {number} initialValue - Starting value
 * @param {number} minValue - Minimum allowed value
 * @param {number} maxValue - Maximum allowed value
 * @param {boolean} isCurrency - If true, formats as currency
 */
export default function NumpadModal({
    isOpen,
    onClose,
    onConfirm,
    title = "Masukkan Angka",
    initialValue = 0,
    minValue = 0,
    maxValue = 999999999,
    isCurrency = false,
}) {
    const [value, setValue] = useState(String(initialValue || ""));

    // Reset value when modal opens
    useEffect(() => {
        if (isOpen) {
            setValue(String(initialValue || ""));
        }
    }, [isOpen, initialValue]);

    // Keyboard support
    useEffect(() => {
        if (!isOpen) return;

        const handleKeyDown = (e) => {
            if (e.key >= "0" && e.key <= "9") {
                handleDigit(e.key);
            } else if (e.key === "Backspace") {
                handleBackspace();
            } else if (e.key === "Enter") {
                handleConfirm();
            } else if (e.key === "Escape") {
                onClose();
            } else if (e.key === "c" || e.key === "C") {
                handleClear();
            }
        };

        window.addEventListener("keydown", handleKeyDown);
        return () => window.removeEventListener("keydown", handleKeyDown);
    }, [isOpen, value]);

    const handleDigit = useCallback(
        (digit) => {
            setValue((prev) => {
                const newValue = prev === "0" ? digit : prev + digit;
                const numValue = parseInt(newValue, 10);
                if (numValue > maxValue) return prev;
                return newValue;
            });
        },
        [maxValue]
    );

    const handleBackspace = useCallback(() => {
        setValue((prev) => (prev.length > 1 ? prev.slice(0, -1) : "0"));
    }, []);

    const handleClear = useCallback(() => {
        setValue("0");
    }, []);

    const handleConfirm = useCallback(() => {
        const numValue = parseInt(value, 10) || 0;
        if (numValue >= minValue && numValue <= maxValue) {
            onConfirm(numValue);
            onClose();
        }
    }, [value, minValue, maxValue, onConfirm, onClose]);

    const handleQuickAmount = useCallback(
        (amount) => {
            const current = parseInt(value, 10) || 0;
            const newValue = current + amount;
            if (newValue <= maxValue) {
                setValue(String(newValue));
            }
        },
        [value, maxValue]
    );

    const formatDisplay = (val) => {
        const num = parseInt(val, 10) || 0;
        if (isCurrency) {
            return new Intl.NumberFormat("id-ID", {
                style: "currency",
                currency: "IDR",
                minimumFractionDigits: 0,
            }).format(num);
        }
        return num.toLocaleString("id-ID");
    };

    if (!isOpen) return null;

    const numValue = parseInt(value, 10) || 0;
    const isValid = numValue >= minValue && numValue <= maxValue;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            {/* Backdrop */}
            <div
                className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"
                onClick={onClose}
            />

            {/* Modal */}
            <div className="relative bg-white dark:bg-slate-900 rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden animate-slide-up">
                {/* Header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800">
                    <h3 className="text-lg font-semibold text-slate-800 dark:text-white">
                        {title}
                    </h3>
                    <button
                        onClick={onClose}
                        className="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                    >
                        <IconX size={20} />
                    </button>
                </div>

                {/* Display */}
                <div className="px-5 py-6 bg-slate-50 dark:bg-slate-800/50">
                    <div className="text-right">
                        <p className="text-3xl font-bold text-slate-900 dark:text-white font-mono">
                            {formatDisplay(value)}
                        </p>
                    </div>
                </div>

                {/* Quick Amounts (for currency mode) */}
                {isCurrency && (
                    <div className="grid grid-cols-4 gap-2 px-5 py-3 border-b border-slate-100 dark:border-slate-800">
                        {[10000, 20000, 50000, 100000].map((amount) => (
                            <button
                                key={amount}
                                onClick={() => handleQuickAmount(amount)}
                                className="py-2 px-2 text-xs font-medium rounded-xl bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-900/50 transition-colors"
                            >
                                +{amount / 1000}rb
                            </button>
                        ))}
                    </div>
                )}

                {/* Numpad Grid */}
                <div className="p-5 grid grid-cols-3 gap-3">
                    {/* Numbers 1-9 */}
                    {[1, 2, 3, 4, 5, 6, 7, 8, 9].map((num) => (
                        <button
                            key={num}
                            onClick={() => handleDigit(String(num))}
                            className="h-14 text-2xl font-semibold rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 active:scale-95 transition-all"
                        >
                            {num}
                        </button>
                    ))}

                    {/* Clear */}
                    <button
                        onClick={handleClear}
                        className="h-14 text-sm font-semibold rounded-2xl bg-warning-100 dark:bg-warning-900/50 text-warning-700 dark:text-warning-400 hover:bg-warning-200 dark:hover:bg-warning-900 active:scale-95 transition-all"
                    >
                        C
                    </button>

                    {/* 0 */}
                    <button
                        onClick={() => handleDigit("0")}
                        className="h-14 text-2xl font-semibold rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 active:scale-95 transition-all"
                    >
                        0
                    </button>

                    {/* Backspace */}
                    <button
                        onClick={handleBackspace}
                        className="h-14 flex items-center justify-center rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700 active:scale-95 transition-all"
                    >
                        <IconBackspace size={24} />
                    </button>
                </div>

                {/* Footer */}
                <div className="p-5 pt-0">
                    <button
                        onClick={handleConfirm}
                        disabled={!isValid}
                        className={`w-full h-14 flex items-center justify-center gap-2 text-lg font-semibold rounded-2xl transition-all ${
                            isValid
                                ? "bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-lg shadow-primary-500/30 hover:shadow-xl active:scale-[0.98]"
                                : "bg-slate-200 dark:bg-slate-700 text-slate-400 cursor-not-allowed"
                        }`}
                    >
                        <IconCheck size={22} />
                        Konfirmasi
                    </button>
                </div>
            </div>
        </div>
    );
}
