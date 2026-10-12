import React from "react";
import { router } from "@inertiajs/react";
import { IconBuildings } from "@tabler/icons-react";

export default function BusinessSwitcher({ business = null, businesses = [] }) {
    if (businesses.length < 2) {
        return null;
    }

    return (
        <label className="flex items-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 dark:border-indigo-800 dark:bg-indigo-950/40">
            <IconBuildings size={18} className="shrink-0 text-primary-500" />
            <select
                value={business?.id || ""}
                onChange={(event) =>
                    router.post(
                        route("business.switch"),
                        { business_id: event.target.value },
                        { preserveScroll: true }
                    )
                }
                className="max-w-40 border-0 bg-transparent p-0 text-sm font-medium text-slate-700 outline-none focus:ring-0 dark:text-slate-200"
                title="Pilih bisnis"
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