import React, { useMemo, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import {
    IconChevronDown,
    IconChevronUp,
    IconCornerDownRight,
} from "@tabler/icons-react";
import { isSuperAdmin } from "@/Utils/authorization";

export default function LinkItemDropdown({ icon, title, data, access, sidebarOpen, ...props }) {
    const { url } = usePage();
    const { auth } = usePage().props;
    const superAdmin = isSuperAdmin(auth);

    const visibleItems = useMemo(
        () => data.filter((item) => superAdmin || item.permissions === true),
        [data, superAdmin]
    );

    // Auto-open dropdown when current URL is inside this submenu
    const [isOpen, setIsOpen] = useState(() =>
        visibleItems.some((item) => url === item.href)
    );

    const canRenderParent = superAdmin || access === true || visibleItems.length > 0;

    if (!canRenderParent || visibleItems.length === 0) {
        return null;
    }

    const childActive = visibleItems.some((item) => url === item.href);

    const parentClass = sidebarOpen
        ? `flex w-full items-center justify-between gap-3 px-4 py-2.5 text-sm font-medium transition-colors duration-200 border-l-[3px] ${
              childActive
                  ? "text-slate-700 dark:text-slate-200 border-transparent"
                  : "text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-slate-200 border-transparent"
          }`
        : `flex w-full justify-center py-3 transition-colors duration-200 ${
              childActive
                  ? "text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-950/50"
                  : "text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
          }`;

    return (
        <>
            <button
                type="button"
                className={parentClass}
                onClick={() => setIsOpen(!isOpen)}
                aria-expanded={isOpen}
                title={sidebarOpen ? undefined : title}
            >
                {sidebarOpen ? (
                    <>
                        <span className="flex items-center gap-3 min-w-0">
                            <span className={childActive ? "text-primary-600 dark:text-primary-400" : ""}>
                                {icon}
                            </span>
                            <span className="truncate">{title}</span>
                        </span>
                        {isOpen ? (
                            <IconChevronUp size={18} strokeWidth={1.5} />
                        ) : (
                            <IconChevronDown size={18} strokeWidth={1.5} />
                        )}
                    </>
                ) : (
                    icon
                )}
            </button>

            {isOpen &&
                visibleItems.map((item, index) => {
                    const active = url === item.href;

                    return (
                        <Link
                            key={index}
                            href={item.href}
                            onClick={() => {
                                // Collapse submenu after navigating on mobile
                                if (window.innerWidth < 768) {
                                    setIsOpen(false);
                                }
                            }}
                            className={
                                sidebarOpen
                                    ? `flex w-full items-center gap-3 px-4 py-2.5 pl-8 text-sm font-medium transition-colors duration-200 border-l-[3px] ${
                                          active
                                              ? "bg-primary-50 text-primary-700 border-primary-500 dark:bg-primary-950/50 dark:text-primary-400"
                                              : "text-slate-500 border-transparent hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                                      }`
                                    : `flex w-full justify-center py-3 transition-colors duration-200 ${
                                          active
                                              ? "text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-950/50"
                                              : "text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
                                      }`
                            }
                            {...props}
                        >
                            {sidebarOpen ? (
                                <>
                                    <IconCornerDownRight size={16} strokeWidth={1.5} className="shrink-0" />
                                    <span className="truncate">{item.title}</span>
                                </>
                            ) : (
                                item.icon
                            )}
                        </Link>
                    );
                })}
        </>
    );
}
