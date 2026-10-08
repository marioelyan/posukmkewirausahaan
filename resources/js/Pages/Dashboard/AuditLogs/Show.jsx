import React from "react";
import { Head, Link } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconArrowLeft,
    IconDeviceDesktopAnalytics,
    IconHistory,
    IconUser,
} from "@tabler/icons-react";

const formatDateTime = (value) =>
    value
        ? new Intl.DateTimeFormat("id-ID", {
              dateStyle: "medium",
              timeStyle: "short",
          }).format(new Date(value))
        : "-";

const renderValue = (value) => {
    if (value === null || value === undefined || value === "") return "-";
    if (typeof value === "boolean") return value ? "true" : "false";
    if (Array.isArray(value) || typeof value === "object") {
        return JSON.stringify(value, null, 2);
    }

    return String(value);
};

function KeyValueTable({ data }) {
    const entries = Object.entries(data || {});

    if (entries.length === 0) {
        return (
            <div className="rounded-2xl border border-dashed border-slate-200 p-4 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                Tidak ada data.
            </div>
        );
    }

    return (
        <div className="overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-800">
            <table className="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                <tbody className="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                    {entries.map(([key, value]) => (
                        <tr key={key}>
                            <td className="w-64 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-700 dark:bg-slate-800/60 dark:text-slate-200">
                                {key}
                            </td>
                            <td className="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                <pre className="whitespace-pre-wrap break-words font-sans">
                                    {renderValue(value)}
                                </pre>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function Show({ auditLog }) {
    return (
        <>
            <Head title={`Audit Log ${auditLog.id}`} />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                            <IconHistory size={28} className="text-primary-500" />
                            Detail Audit Log
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Event {auditLog.event} pada modul {auditLog.module}.
                        </p>
                    </div>
                    <Link
                        href={route("audit-logs.index")}
                        className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        <IconArrowLeft size={18} />
                        Kembali
                    </Link>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            Waktu
                        </p>
                        <p className="mt-2 text-sm font-semibold text-slate-900 dark:text-white">
                            {formatDateTime(auditLog.created_at)}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <IconUser size={14} />
                            Aktor
                        </p>
                        <p className="mt-2 text-sm font-semibold text-slate-900 dark:text-white">
                            {auditLog.user?.name || "System"}
                        </p>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            {auditLog.user?.email || "-"}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            Target
                        </p>
                        <p className="mt-2 text-sm font-semibold text-slate-900 dark:text-white">
                            {auditLog.target_label || "-"}
                        </p>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            {auditLog.auditable_type || "-"}#{auditLog.auditable_id || "-"}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <p className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            <IconDeviceDesktopAnalytics size={14} />
                            Client
                        </p>
                        <p className="mt-2 text-sm font-semibold text-slate-900 dark:text-white">
                            {auditLog.ip_address || "-"}
                        </p>
                        <p className="line-clamp-2 text-xs text-slate-500 dark:text-slate-400">
                            {auditLog.user_agent || "-"}
                        </p>
                    </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <p className="text-sm font-semibold text-slate-900 dark:text-white">
                        Deskripsi
                    </p>
                    <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">
                        {auditLog.description}
                    </p>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <div className="space-y-3">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                            Before
                        </h2>
                        <KeyValueTable data={auditLog.before || {}} />
                    </div>
                    <div className="space-y-3">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                            After
                        </h2>
                        <KeyValueTable data={auditLog.after || {}} />
                    </div>
                </div>

                <div className="space-y-3">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                        Meta
                    </h2>
                    <KeyValueTable data={auditLog.meta || {}} />
                </div>
            </div>
        </>
    );
}

Show.layout = (page) => <DashboardLayout children={page} />;
