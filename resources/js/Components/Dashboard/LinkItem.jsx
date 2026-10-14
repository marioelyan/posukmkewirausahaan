import React from "react";
import { Link, usePage } from "@inertiajs/react";
import { isSuperAdmin } from "@/Utils/authorization";

export default function LinkItem({
    href,
    icon,
    access,
    active,
    title,
    sidebarOpen,
    variant,
    ...props
}) {
    const { url } = usePage();
    const { auth } = usePage().props;

    // Prefer the precise `active` flag computed in Menu; fall back to the
    // historical path-prefix check for callers that do not supply one.
    const isActive = typeof active === "boolean" ? active : url.startsWith(href);
    const canAccess = isSuperAdmin(auth) || access === true;

    if (!canAccess) return null;

    // ── Quick action button (solid / tinted) ────────────────────────────
    // Reserved for the role's primary actions; painted with the role accent so
    // the call to action carries the active role's identity.
    if (variant === "solid" || variant === "tinted") {
        const actionClasses =
            variant === "solid"
                ? "bg-[rgb(var(--role-accent-600))] text-white hover:bg-[rgb(var(--role-accent-700))] shadow-sm"
                : "bg-[rgb(var(--role-accent-100))] text-[rgb(var(--role-accent-700))] hover:bg-[rgb(var(--role-accent-200))] dark:bg-[rgb(var(--role-accent-950)/0.5)] dark:text-[rgb(var(--role-accent-300))] dark:hover:bg-[rgb(var(--role-accent-900)/0.6)]";

        const activeRing = isActive
            ? "ring-2 ring-[rgb(var(--role-accent-500))] ring-offset-2 ring-offset-white dark:ring-offset-slate-900"
            : "";

        const focusRing =
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[rgb(var(--role-accent-500))] focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-slate-900";

        if (sidebarOpen) {
            return (
                <Link
                    href={href}
                    aria-current={isActive ? "page" : undefined}
                    className={`${focusRing} ${actionClasses} ${activeRing} mx-3 flex items-center gap-3 rounded-lg px-4 py-2.5 text-sm font-semibold transition-all duration-200`}
                    {...props}
                >
                    {icon}
                    <span className="truncate">{title}</span>
                </Link>
            );
        }

        // Collapsed sidebar
        return (
            <Link
                href={href}
                aria-current={isActive ? "page" : undefined}
                title={title}
                className={`${focusRing} ${actionClasses} ${activeRing} mx-auto flex h-10 w-10 items-center justify-center rounded-lg transition-all duration-200`}
                {...props}
            >
                {icon}
            </Link>
        );
    }

    // ── Regular navigation item ─────────────────────────────────────────
    const baseClasses = `
        flex items-center gap-3
        transition-all duration-200
        focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[rgb(var(--role-accent-500)/0.4)] focus-visible:ring-inset
        text-slate-600 dark:text-slate-400
    `;

    const activeClasses = isActive
        ? "bg-[rgb(var(--role-accent-50))] dark:bg-[rgb(var(--role-accent-950)/0.5)] text-[rgb(var(--role-accent-700))] dark:text-[rgb(var(--role-accent-400))] border-l-[3px] border-[rgb(var(--role-accent-500))]"
        : "hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 dark:hover:text-slate-200 border-l-[3px] border-transparent";

    if (sidebarOpen) {
        return (
            <Link
                href={href}
                aria-current={isActive ? "page" : undefined}
                className={`${baseClasses} ${activeClasses} px-4 py-2.5 text-sm font-medium`}
                {...props}
            >
                <span
                    className={
                        isActive
                            ? "text-[rgb(var(--role-accent-600))] dark:text-[rgb(var(--role-accent-400))]"
                            : ""
                    }
                >
                    {icon}
                </span>
                <span className="truncate">{title}</span>
            </Link>
        );
    }

    // Collapsed sidebar
    return (
        <Link
            href={href}
            className={`
                w-full flex justify-center py-3
                transition-all duration-200
                ${
                    isActive
                        ? "text-[rgb(var(--role-accent-600))] dark:text-[rgb(var(--role-accent-400))] bg-[rgb(var(--role-accent-50))] dark:bg-[rgb(var(--role-accent-950)/0.5)]"
                        : "text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800"
                }
            `}
            title={title}
            {...props}
        >
            {icon}
        </Link>
    );
}
