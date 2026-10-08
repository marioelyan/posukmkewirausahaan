import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, useForm } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Table from "@/Components/Dashboard/Table";
import { IconArrowLeft, IconDatabaseOff, IconTrash, IconUsersGroup } from "@tabler/icons-react";

const formatDateTime = (value) =>
    value
        ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value))
        : "-";

export default function Show({ segment, customers = [] }) {
    const { data, setData, post, processing } = useForm({
        customer_id: "",
    });
    const isManual = segment.type === "manual";

    const submit = (event) => {
        event.preventDefault();
        post(route("customer-segments.members.store", segment.id));
    };

    return (
        <>
            <Head title={segment.name} />

            <div className="mb-6">
                <Link href={route("customer-segments.index")} className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600">
                    <IconArrowLeft size={16} />
                    Kembali ke segment customer
                </Link>

                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div className="mb-2 flex items-center gap-2">
                            <h1 className="text-2xl font-bold text-slate-900 dark:text-white">{segment.name}</h1>
                            <span className="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                {segment.type}
                            </span>
                        </div>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            {segment.description || "Segment CRM customer"}
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                            <p className="text-xs uppercase tracking-wide text-slate-500">Total</p>
                            <p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{segment.stats.total_members}</p>
                        </div>
                        <div className="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                            <p className="text-xs uppercase tracking-wide text-slate-500">Manual</p>
                            <p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{segment.stats.manual_members}</p>
                        </div>
                        <div className="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                            <p className="text-xs uppercase tracking-wide text-slate-500">Auto</p>
                            <p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">{segment.stats.auto_members}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.7fr_1fr]">
                <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Anggota Segment</h2>
                    <Table>
                        <Table.Thead>
                            <tr>
                                <Table.Th>Customer</Table.Th>
                                <Table.Th>Source</Table.Th>
                                <Table.Th>Matched</Table.Th>
                                <Table.Th className="w-28 text-center">Aksi</Table.Th>
                            </tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {segment.memberships.length > 0 ? (
                                segment.memberships.map((membership) => (
                                    <tr key={membership.id}>
                                        <Table.Td>
                                            <Link href={route("customers.show", membership.customer?.id)} className="font-semibold text-slate-800 hover:text-primary-600 dark:text-slate-100">
                                                {membership.customer?.name || "-"}
                                            </Link>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                                {membership.customer?.no_telp || "-"}
                                            </p>
                                        </Table.Td>
                                        <Table.Td>{membership.source}</Table.Td>
                                        <Table.Td>{formatDateTime(membership.matched_at)}</Table.Td>
                                        <Table.Td className="text-center">
                                            {isManual && membership.customer ? (
                                                <Button
                                                    type="delete"
                                                    url={route("customer-segments.members.destroy", [segment.id, membership.customer.id])}
                                                    icon={<IconTrash size={16} />}
                                                    className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
                                                />
                                            ) : (
                                                <span className="text-xs text-slate-400">-</span>
                                            )}
                                        </Table.Td>
                                    </tr>
                                ))
                            ) : (
                                <Table.Empty colSpan={4} message="Belum ada anggota segment.">
                                    <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                        <IconDatabaseOff size={28} className="text-slate-400" />
                                    </div>
                                </Table.Empty>
                            )}
                        </Table.Tbody>
                    </Table>
                </div>

                <div className="space-y-6">
                    {isManual && (
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Tambah Anggota Manual</h2>
                            <form onSubmit={submit} className="space-y-4">
                                <select
                                    value={data.customer_id}
                                    onChange={(event) => setData("customer_id", event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    <option value="">Pilih customer</option>
                                    {customers.map((customer) => (
                                        <option key={customer.id} value={customer.id}>
                                            {customer.name} | {customer.no_telp || "-"} | {customer.is_loyalty_member ? customer.loyalty_tier : "non-member"}
                                        </option>
                                    ))}
                                </select>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full rounded-xl bg-primary-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-600 disabled:opacity-50"
                                >
                                    Tambahkan ke Segment
                                </button>
                            </form>
                        </div>
                    )}

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center gap-2">
                            <IconUsersGroup size={18} className="text-primary-500" />
                            <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Ringkasan Rule</h2>
                        </div>
                        <dl className="space-y-3 text-sm">
                            <div className="flex items-center justify-between gap-4">
                                <dt className="text-slate-500">Slug</dt>
                                <dd className="font-medium text-slate-800 dark:text-slate-200">{segment.slug}</dd>
                            </div>
                            <div className="flex items-center justify-between gap-4">
                                <dt className="text-slate-500">Rule Type</dt>
                                <dd className="font-medium text-slate-800 dark:text-slate-200">{segment.auto_rule_type || "-"}</dd>
                            </div>
                            <div className="flex items-center justify-between gap-4">
                                <dt className="text-slate-500">Status</dt>
                                <dd className="font-medium text-slate-800 dark:text-slate-200">{segment.is_active ? "Aktif" : "Nonaktif"}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </>
    );
}

Show.layout = (page) => <DashboardLayout children={page} />;
