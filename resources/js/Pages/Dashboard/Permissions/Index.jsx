import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, usePage } from "@inertiajs/react";
import { IconDatabaseOff, IconKey, IconShield } from "@tabler/icons-react";
import Search from "@/Components/Dashboard/Search";
import Pagination from "@/Components/Dashboard/Pagination";

export default function Index() {
    const { permissions } = usePage().props;

    return (
        <>
            <Head title="Hak Akses" />

            {/* Header */}
            <div className="mb-6">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <IconKey size={28} className="text-primary-500" />
                            Hak Akses
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            {permissions.total || permissions.data?.length || 0}{" "}
                            hak akses terdaftar
                        </p>
                    </div>
                </div>
            </div>

            {/* Search */}
            <div className="mb-4 w-full sm:w-80">
                <Search
                    url={route("permissions.index")}
                    placeholder="Cari hak akses..."
                />
            </div>

            {/* Permissions Grid */}
            {permissions.data.length > 0 ? (
                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
                    {permissions.data.map((permission, i) => (
                        <div
                            key={permission.id || i}
                            className="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4 hover:shadow-md hover:border-primary-300 dark:hover:border-primary-700 transition-all"
                        >
                            <div className="flex items-center gap-2">
                                <div className="w-8 h-8 rounded-lg bg-primary-100 dark:bg-primary-900/50 flex items-center justify-center">
                                    <IconShield
                                        size={16}
                                        className="text-primary-600 dark:text-primary-400"
                                    />
                                </div>
                                <span className="text-sm font-medium text-slate-700 dark:text-slate-300 truncate">
                                    {permission.name}
                                </span>
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <div className="flex flex-col items-center justify-center py-16 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800">
                    <div className="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                        <IconDatabaseOff
                            size={32}
                            className="text-slate-400"
                            strokeWidth={1.5}
                        />
                    </div>
                    <h3 className="text-lg font-medium text-slate-800 dark:text-slate-200 mb-1">
                        Belum Ada Hak Akses
                    </h3>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Hak akses tidak ditemukan.
                    </p>
                </div>
            )}

            {permissions.last_page !== 1 && (
                <Pagination links={permissions.links} />
            )}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
