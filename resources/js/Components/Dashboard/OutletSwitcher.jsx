import React from "react";
import { router } from "@inertiajs/react";
import { IconBuildingStore } from "@tabler/icons-react";
import { useTranslation } from "react-i18next";

export default function OutletSwitcher({ outlet, outlets = [], locked = false, showIndicator = false, fullWidth = false }) {
    const { t } = useTranslation();
    // `fullWidth` is for the mobile drawer, where the navbar's narrow cap is wrong.
    const widthClass = fullWidth ? "w-full" : "max-w-36";

    if (outlets.length >= 2) {
        return (
            <label className={`flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 ${fullWidth ? "w-full" : ""}`}>
                <IconBuildingStore size={18} className="shrink-0 text-primary-500" />
                <select
                    value={outlet?.id || ""}
                    disabled={locked}
                    onChange={(event) => router.post(route("outlet.switch"), { outlet_id: event.target.value }, { preserveScroll: true })}
                    className={`${widthClass} border-0 bg-transparent p-0 text-sm font-medium text-slate-700 outline-none focus:ring-0 dark:text-slate-200 disabled:cursor-not-allowed disabled:opacity-60`}
                    title={locked ? t("business.switcher.lockedOutlet") : t("business.switcher.selectOutlet")}
                >
                    {outlets.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.code} - {item.name}
                        </option>
                    ))}
                </select>
            </label>
        );
    }

    if (!showIndicator || !outlet) {
        return null;
    }

    return (
        <div
            className={`flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 ${fullWidth ? "w-full" : ""}`}
            title={`${outlet.code} - ${outlet.name}`}
        >
            <IconBuildingStore size={18} className="shrink-0 text-primary-500" />
            <span className={`${widthClass} truncate text-sm font-medium text-slate-700 dark:text-slate-200`}>
                {outlet.code} - {outlet.name}
            </span>
        </div>
    );
}
