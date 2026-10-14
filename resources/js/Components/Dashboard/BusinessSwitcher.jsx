import React from "react";
import { router } from "@inertiajs/react";
import { IconBuildings } from "@tabler/icons-react";
import { useTranslation } from "react-i18next";

export default function BusinessSwitcher({ business = null, businesses = [], locked = false, fullWidth = false }) {
    const { t } = useTranslation();
    // `fullWidth` is for the mobile drawer, where the navbar's narrow cap is wrong.
    const widthClass = fullWidth ? "w-full" : "max-w-40";

    if (businesses.length >= 2) {
        return (
            <label className={`flex items-center gap-2 rounded-xl border border-primary-200 bg-primary-50 px-3 py-2 dark:border-primary-800 dark:bg-primary-950/40 ${fullWidth ? "w-full" : ""}`}>
                <IconBuildings size={18} className="shrink-0 text-primary-500" />
                <select
                    value={business?.id || ""}
                    disabled={locked}
                    onChange={(event) =>
                        router.post(
                            route("business.switch"),
                            { business_id: event.target.value },
                            { preserveScroll: true }
                        )
                    }
                    className={`${widthClass} border-0 bg-transparent p-0 text-sm font-medium text-slate-700 outline-none focus:ring-0 dark:text-slate-200 disabled:cursor-not-allowed disabled:opacity-60`}
                    title={locked ? t("business.switcher.lockedBusiness") : t("business.switcher.selectBusiness")}
                >
                    {businesses.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </label>
        );
    }

    if (!business) {
        return null;
    }

    return (
        <div
            className={`flex items-center gap-2 rounded-xl border border-primary-200 bg-primary-50 px-3 py-2 dark:border-primary-800 dark:bg-primary-950/40 ${fullWidth ? "w-full" : ""}`}
            title={business.name}
        >
            <IconBuildings size={18} className="shrink-0 text-primary-500" />
            <span className={`${widthClass} truncate text-sm font-medium text-slate-700 dark:text-slate-200`}>
                {business.name}
            </span>
        </div>
    );
}