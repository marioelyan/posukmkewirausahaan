import { useId } from "react";

export default function Checkbox({ label, errors, className, id, ...props }) {
    const generatedId = useId();
    const inputId = id || generatedId;

    return (
        <div className="flex flex-row items-center gap-2">
            <input
                id={inputId}
                {...props}
                type="checkbox"
                className={`rounded-md bg-white border-slate-200 dark:bg-slate-950 dark:border-slate-800 checked:bg-primary-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 ${className || ""}`}
            />
            {label && (
                <label
                    htmlFor={inputId}
                    className="text-sm text-slate-700 dark:text-slate-400"
                >
                    {label}
                </label>
            )}

            {errors && (
                <small className="text-xs text-danger-500">{errors}</small>
            )}
        </div>
    );
}
