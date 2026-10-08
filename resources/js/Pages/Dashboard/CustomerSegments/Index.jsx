import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, router } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Pagination from "@/Components/Dashboard/Pagination";
import Table from "@/Components/Dashboard/Table";
import { IconCirclePlus, IconPencil, IconSearch, IconTrash, IconUsersGroup } from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";

export default function Index({ segments, filters }) {
    const { can } = useAuthorization();
    const handleFilterChange = (key, value) => {
        router.get(route("customer-segments.index"), { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Customer Segments" />

            <div className="w-full">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Customer Segments</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Kelola tag manual dan auto segment untuk CRM dan automation.
                        </p>
                    </div>
                    {can("customer-segments-create") && (
                        <Button
                            type="link"
                            href={route("customer-segments.create")}
                            icon={<IconCirclePlus size={18} />}
                            className="bg-primary-500 text-white hover:bg-primary-600 shadow-lg shadow-primary-500/30"
                            label="Buat Segment"
                        />
                    )}
                </div>

                <div className="mb-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-3 md:grid-cols-3">
                        <div className="relative md:col-span-2">
                            <input
                                type="text"
                                value={filters.search || ""}
                                onChange={(event) => handleFilterChange("search", event.target.value)}
                                placeholder="Cari nama segment..."
                                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 pr-11 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                            />
                            <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400">
                                <IconSearch size={18} />
                            </div>
                        </div>
                        <select
                            value={filters.type || ""}
                            onChange={(event) => handleFilterChange("type", event.target.value)}
                            className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        >
                            <option value="">Semua Tipe</option>
                            <option value="manual">Manual</option>
                            <option value="auto">Auto</option>
                        </select>
                    </div>
                </div>

                <Table.Card title="Daftar Segment">
                    <Table>
                        <Table.Thead>
                            <tr>
                                <Table.Th>Segment</Table.Th>
                                <Table.Th>Tipe</Table.Th>
                                <Table.Th>Anggota</Table.Th>
                                <Table.Th>Status</Table.Th>
                                <Table.Th className="w-36 text-center">Aksi</Table.Th>
                            </tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {segments.data.length > 0 ? (
                                segments.data.map((segment) => (
                                    <tr key={segment.id}>
                                        <Table.Td>
                                            <Link
                                                href={route("customer-segments.show", segment.id)}
                                                className="font-semibold text-slate-800 hover:text-primary-600 dark:text-slate-100"
                                            >
                                                {segment.name}
                                            </Link>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                                {segment.description || segment.slug}
                                            </p>
                                        </Table.Td>
                                        <Table.Td>
                                            <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                                {segment.type}
                                            </span>
                                        </Table.Td>
                                        <Table.Td>{segment.memberships_count}</Table.Td>
                                        <Table.Td>
                                            <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${segment.is_active ? "bg-success-100 text-success-700 dark:bg-success-950/30 dark:text-success-400" : "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"}`}>
                                                {segment.is_active ? "Aktif" : "Nonaktif"}
                                            </span>
                                        </Table.Td>
                                        <Table.Td className="text-center">
                                            <div className="flex items-center justify-center gap-2">
                                                {can("customer-segments-update") && (
                                                    <Link
                                                        href={route("customer-segments.edit", segment.id)}
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-amber-200 bg-amber-50 text-amber-600 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
                                                    >
                                                        <IconPencil size={16} />
                                                    </Link>
                                                )}
                                                {can("customer-segments-delete") && (
                                                    <Button
                                                        type="delete"
                                                        url={route("customer-segments.destroy", segment.id)}
                                                        icon={<IconTrash size={16} />}
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
                                                    />
                                                )}
                                            </div>
                                        </Table.Td>
                                    </tr>
                                ))
                            ) : (
                                <Table.Empty colSpan={5} message="Belum ada segment customer.">
                                    <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                        <IconUsersGroup size={28} className="text-slate-400" />
                                    </div>
                                </Table.Empty>
                            )}
                        </Table.Tbody>
                    </Table>
                </Table.Card>

                {segments.last_page > 1 && <Pagination links={segments.links} />}
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
