import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, router } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Pagination from "@/Components/Dashboard/Pagination";
import Table from "@/Components/Dashboard/Table";
import { IconBroadcast, IconCirclePlus, IconPencil, IconTrash } from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";

const statusBadge = (status) => {
    const classes = {
        draft: "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
        ready: "bg-primary-100 text-primary-700 dark:bg-primary-950/30 dark:text-primary-300",
        processed: "bg-success-100 text-success-700 dark:bg-success-950/30 dark:text-success-400",
        cancelled: "bg-rose-100 text-rose-700 dark:bg-rose-950/30 dark:text-rose-300",
    };

    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${classes[status] || classes.draft}`}>{status}</span>;
};

export default function Index({ campaigns, filters }) {
    const { can } = useAuthorization();
    const handleFilterChange = (key, value) => {
        router.get(route("crm-campaigns.index"), { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="CRM Campaigns" />
            <div className="w-full">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">CRM Campaigns</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Kelola promo broadcast, reminder, dan share invoice berbasis audience CRM.
                        </p>
                    </div>
                    {can("crm-campaigns-create") && (
                        <Button
                            type="link"
                            href={route("crm-campaigns.create")}
                            icon={<IconCirclePlus size={18} />}
                            className="bg-primary-500 text-white hover:bg-primary-600 shadow-lg shadow-primary-500/30"
                            label="Buat Campaign"
                        />
                    )}
                </div>

                <div className="mb-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-3 md:grid-cols-2">
                        <select
                            value={filters.type || ""}
                            onChange={(event) => handleFilterChange("type", event.target.value)}
                            className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        >
                            <option value="">Semua Tipe</option>
                            <option value="promo_broadcast">Promo Broadcast</option>
                            <option value="invoice_share">Invoice Share</option>
                            <option value="due_date_reminder">Due Date Reminder</option>
                            <option value="repeat_order_reminder">Repeat Order Reminder</option>
                        </select>
                        <select
                            value={filters.status || ""}
                            onChange={(event) => handleFilterChange("status", event.target.value)}
                            className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        >
                            <option value="">Semua Status</option>
                            <option value="draft">Draft</option>
                            <option value="ready">Ready</option>
                            <option value="processed">Processed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <Table.Card title="Daftar CRM Campaign">
                    <Table>
                        <Table.Thead>
                            <tr>
                                <Table.Th>Campaign</Table.Th>
                                <Table.Th>Tipe</Table.Th>
                                <Table.Th>Status</Table.Th>
                                <Table.Th>Log</Table.Th>
                                <Table.Th className="w-36 text-center">Aksi</Table.Th>
                            </tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {campaigns.data.length > 0 ? (
                                campaigns.data.map((campaign) => (
                                    <tr key={campaign.id}>
                                        <Table.Td>
                                            <Link href={route("crm-campaigns.show", campaign.id)} className="font-semibold text-slate-800 hover:text-primary-600 dark:text-slate-100">
                                                {campaign.name}
                                            </Link>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                                Dibuat oleh {campaign.creator?.name || "-"}
                                            </p>
                                        </Table.Td>
                                        <Table.Td>{campaign.type}</Table.Td>
                                        <Table.Td>{statusBadge(campaign.status)}</Table.Td>
                                        <Table.Td>{campaign.logs_count}</Table.Td>
                                        <Table.Td className="text-center">
                                            <div className="flex items-center justify-center gap-2">
                                                {can("crm-campaigns-update") && (
                                                    <Link
                                                        href={route("crm-campaigns.edit", campaign.id)}
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-amber-200 bg-amber-50 text-amber-600 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
                                                    >
                                                        <IconPencil size={16} />
                                                    </Link>
                                                )}
                                                {can("crm-campaigns-delete") && (
                                                    <Button
                                                        type="delete"
                                                        url={route("crm-campaigns.destroy", campaign.id)}
                                                        icon={<IconTrash size={16} />}
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
                                                    />
                                                )}
                                            </div>
                                        </Table.Td>
                                    </tr>
                                ))
                            ) : (
                                <Table.Empty colSpan={5} message="Belum ada campaign CRM.">
                                    <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                        <IconBroadcast size={28} className="text-slate-400" />
                                    </div>
                                </Table.Empty>
                            )}
                        </Table.Tbody>
                    </Table>
                </Table.Card>

                {campaigns.last_page > 1 && <Pagination links={campaigns.links} />}
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
