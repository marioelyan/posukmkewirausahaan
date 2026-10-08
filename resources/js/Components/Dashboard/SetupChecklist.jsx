import React from "react";
import { Link } from "@inertiajs/react";
import {
    IconCircleCheck,
    IconCircleDashed,
    IconArrowRight,
} from "@tabler/icons-react";
import { useTranslation } from "react-i18next";

const ITEMS = [
    { key: "store_profile", href: route("settings.store") },
    { key: "category", href: route("categories.index") },
    { key: "product", href: route("products.index") },
    { key: "customer", href: route("customers.index") },
    { key: "transaction", href: route("transactions.index") },
];

export default function SetupChecklist({ checklist = {} }) {
    const { t } = useTranslation();

    const done = ITEMS.filter((item) => checklist[item.key]).length;
    const total = ITEMS.length;
    const remaining = total - done;

    if (remaining === 0) return null;

    const percent = Math.round((done / total) * 100);

    return (
        <div className="rounded-2xl border border-primary-100 dark:border-primary-900/40 bg-primary-50/60 dark:bg-primary-950/20 p-5">
            <div className="flex items-center justify-between gap-4 mb-3">
                <div>
                    <h2 className="text-base font-semibold text-primary-900 dark:text-primary-200">
                        {t("setupChecklist.title")}
                    </h2>
                    <p className="text-sm text-primary-700/80 dark:text-primary-300/80">
                        {t("setupChecklist.subtitle", { done, total })}
                    </p>
                </div>
                <span className="text-sm font-bold text-primary-600 dark:text-primary-300 whitespace-nowrap">
                    {percent}%
                </span>
            </div>

            <div className="h-2 rounded-full bg-primary-100 dark:bg-primary-900/40 overflow-hidden mb-4">
                <div
                    className="h-full bg-primary-500 rounded-full transition-all"
                    style={{ width: `${percent}%` }}
                />
            </div>

            <ul className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                {ITEMS.map((item) => {
                    const isDone = !!checklist[item.key];

                    return (
                        <li key={item.key}>
                            <Link
                                href={item.href}
                                className={`flex items-center gap-2 px-3 py-2.5 rounded-xl text-sm transition-colors ${
                                    isDone
                                        ? "text-slate-400 dark:text-slate-500 line-through bg-white/40 dark:bg-slate-900/20 cursor-default"
                                        : "font-medium text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900 hover:bg-primary-100 dark:hover:bg-primary-900/30"
                                }`}
                            >
                                {isDone ? (
                                    <IconCircleCheck
                                        size={18}
                                        className="text-success-500 shrink-0"
                                    />
                                ) : (
                                    <IconCircleDashed
                                        size={18}
                                        className="text-primary-500 shrink-0"
                                    />
                                )}
                                <span className="flex-1 truncate">
                                    {t(`setupChecklist.items.${item.key}`)}
                                </span>
                                {!isDone && (
                                    <IconArrowRight
                                        size={14}
                                        className="text-slate-400 shrink-0"
                                    />
                                )}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
