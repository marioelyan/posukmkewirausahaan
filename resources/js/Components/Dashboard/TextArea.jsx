import React from "react";

export default function Textarea({
    label,
    className,
    errors,
    rows = 4,
    ...props
}) {
    return (
        <div className="flex flex-col gap-2">
            {label && (
                <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
                    {label}
                </label>
            )}
            <textarea
                rows={rows}
                className={`
                    w-full px-4 py-3 text-sm rounded-xl
                    border border-slate-200 dark:border-slate-700
                    bg-slate-50 dark:bg-slate-800
                    text-slate-800 dark:text-slate-200
                    placeholder-slate-400 dark:placeholder-slate-500
                    focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500
                    transition-all duration-200 resize-none
                    ${
                        errors
                            ? "border-danger-500 focus:border-danger-500 focus:ring-danger-500/20"
                            : ""
                    }
                    ${className || ""}
                `}
                {...props}
            />
            {errors && (
                <small className="text-xs text-danger-500 dark:text-danger-400">
                    {errors}
                </small>
            )}
        </div>
    );
}
