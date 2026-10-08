import React, { useMemo } from "react";
import { Head, Link, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import { IconEye, IconFileSearch } from "@tabler/icons-react";

const formatDateTime = (value) =>
    value
        ? new Intl.DateTimeFormat("id-ID", {
              dateStyle: "medium",
              timeStyle: "short",
          }).format(new Date(value))
        : "-";

export default function Index({
    auditLogs,
    filters,
    users = [],
    modules = [],
    events = [],
}) {
    const currentFilters = useMemo(
        () => ({
            user_id: filters?.user_id || "",
            module: filters?.module || "",
            event: filters?.event || "",
            date_from: filters?.date_from || "",
            date_to: filters?.date_to || "",
            search: filters?.search || "",
        }),
        [filters]
    );

    const updateFilters = (nextFilters) => {
        router.get(route("audit-logs.index"), nextFilters, {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title="Audit Log" />

            <div className="mb-6">
                <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                    <IconFileSearch size={28} className="text-primary-500" />
                    Audit Log
                </h1>
                <p className="text-sm text-slate-500 dark:text-slate-400">
                    Histori aktivitas sensitif untuk investigasi operasional dan administratif.
                </p>
            </div>

            <div className="mb-4 grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-3">
                <select
                    value={currentFilters.user_id}
                    onChange={(event) =>
                        updateFilters({
                            ...currentFilters,
                            user_id: event.target.value,
                        })
                    }
                    className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                >
                    <option value="">Semua Aktor</option>
                    {users.map((user) => (
                        <option key={user.id} value={user.id}>
                            {user.name}
                        </option>
                    ))}
                </select>

                <select
                    value={currentFilters.module}
                    onChange={(event) =>
                        updateFilters({
                            ...currentFilters,
                            module: event.target.value,
                        })
                    }
                    className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                >
                    <option value="">Semua Modul</option>
                    {modules.map((module) => (
                        <option key={module} value={module}>
                            {module}
                        </option>
                    ))}
                </select>

                <select
                    value={currentFilters.event}
                    onChange={(event) =>
                        updateFilters({
                            ...currentFilters,
                            event: event.target.value,
                        })
                    }
                    className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                >
                    <option value="">Semua Event</option>
                    {events.map((eventName) => (
                        <option key={eventName} value={eventName}>
                            {eventName}
                        </option>
                    ))}
                </select>

                <input
                    type="date"
                    value={currentFilters.date_from}
                    onChange={(event) =>
                        updateFilters({
                            ...currentFilters,
                            date_from: event.target.value,
                        })
                    }
                    className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                />

                <input
                    type="date"
                    value={currentFilters.date_to}
                    onChange={(event) =>
                        updateFilters({
                            ...currentFilters,
                            date_to: event.target.value,
                        })
                    }
                    className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                />

                <input
                    type="text"
                    value={currentFilters.search}
                    onChange={(event) =>
                        updateFilters({
                            ...currentFilters,
                            search: event.target.value,
                        })
                    }
                    placeholder="Cari target atau deskripsi"
                    className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                />
            </div>

            <Table.Card title="Histori Audit">
                <Table>
                    <Table.Thead>
                        <tr>
                            <Table.Th>Waktu</Table.Th>
                            <Table.Th>Aktor</Table.Th>
                            <Table.Th>Modul</Table.Th>
                            <Table.Th>Event</Table.Th>
                            <Table.Th>Target</Table.Th>
                            <Table.Th>Deskripsi</Table.Th>
                            <Table.Th className="w-20 text-center">Aksi</Table.Th>
                        </tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {auditLogs.data.length > 0 ? (
                            auditLogs.data.map((log) => (
                                <tr
                                    key={log.id}
                                    className="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50"
                                >
                                    <Table.Td>{formatDateTime(log.created_at)}</Table.Td>
                                    <Table.Td>
                                        <div>
                                            <p className="font-medium text-slate-800 dark:text-slate-200">
                                                {log.user?.name || "System"}
                                            </p>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                                {log.user?.email || "-"}
                                            </p>
                                        </div>
                                    </Table.Td>
                                    <Table.Td>{log.module}</Table.Td>
                                    <Table.Td>
                                        <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                            {log.event}
                                        </span>
                                    </Table.Td>
                                    <Table.Td>{log.target_label || "-"}</Table.Td>
                                    <Table.Td>{log.description}</Table.Td>
                                    <Table.Td className="text-center">
                                        <Link
                                            href={route("audit-logs.show", log.id)}
                                            className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                        >
                                            <IconEye size={14} />
                                            Detail
                                        </Link>
                                    </Table.Td>
                                </tr>
                            ))
                        ) : (
                            <Table.Empty
                                colSpan={7}
                                message="Belum ada data audit log."
                            />
                        )}
                    </Table.Tbody>
                </Table>
            </Table.Card>

            {auditLogs.last_page > 1 && <Pagination links={auditLogs.links} />}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
