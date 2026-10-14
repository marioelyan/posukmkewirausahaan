import React from "react";
import { IconLayoutGrid, IconList } from "@tabler/icons-react";

const OPTIONS = [
    { value: "grid", label: "Tampilan grid", icon: IconLayoutGrid },
    { value: "list", label: "Tampilan daftar", icon: IconList },
];

/**
 * ViewToggle — accessible grid/list segmented control shared by list pages.
 */
export default function ViewToggle({ value, onChange, className = "" }) {
    return (
        <div
            role="group"
            aria-label="Ubah tampilan"
            className={`inline-flex items-center gap-1 p-1 rounded-xl bg-slate-100 dark:bg-slate-800 ${className}`}
        >
            {OPTIONS.map((option) => {
                const Icon = option.icon;
                const active = value === option.value;
                return (
                    <button
                        key={option.value}
                        type="button"
                        onClick={() => onChange(option.value)}
                        aria-label={option.label}
                        aria-pressed={active}
                        title={option.label}
                        className={`p-2 rounded-lg transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 ${
                            active
                                ? "bg-white text-primary-600 shadow-sm dark:bg-slate-900 dark:text-primary-400"
                                : "text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                        }`}
                    >
                        <Icon size={18} />
                    </button>
                );
            })}
        </div>
    );
}
