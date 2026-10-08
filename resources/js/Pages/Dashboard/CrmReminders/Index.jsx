import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, router } from "@inertiajs/react";
import Pagination from "@/Components/Dashboard/Pagination";
import Table from "@/Components/Dashboard/Table";
import { IconBellRinging, IconBrandWhatsapp } from "@tabler/icons-react";

export default function Index({ campaigns, filters }) {
    const handleFilterChange = (key, value) => {
        router.get(route("crm-reminders.index"), { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="CRM Reminders" />
            <div className="w-full">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">CRM Reminders</h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Queue reminder internal untuk piutang, repeat order, invoice share, dan promo broadcast.
                    </p>
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

                <Table.Card title="Reminder & Campaign Queue">
                    <Table>
                        <Table.Thead>
                            <tr>
                                <Table.Th>Campaign</Table.Th>
                                <Table.Th>Status</Table.Th>
                                <Table.Th>Target</Table.Th>
                                <Table.Th>Aksi Cepat</Table.Th>
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
                                            <p className="text-xs text-slate-500 dark:text-slate-400">{campaign.type}</p>
                                        </Table.Td>
                                        <Table.Td>{campaign.status}</Table.Td>
                                        <Table.Td>{campaign.logs?.length || 0} target</Table.Td>
                                        <Table.Td>
                                            <div className="flex flex-wrap gap-2">
                                                {campaign.logs?.slice(0, 2).map((log) =>
                                                    log.payload?.whatsapp_url ? (
                                                        <a
                                                            key={log.id}
                                                            href={log.payload.whatsapp_url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="inline-flex items-center gap-2 rounded-lg bg-green-50 px-3 py-2 text-xs font-semibold text-green-700 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-300"
                                                        >
                                                            <IconBrandWhatsapp size={14} />
                                                            {log.customer?.name || "WhatsApp"}
                                                        </a>
                                                    ) : null
                                                )}
                                            </div>
                                        </Table.Td>
                                    </tr>
                                ))
                            ) : (
                                <Table.Empty colSpan={4} message="Belum ada reminder atau campaign queue.">
                                    <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                        <IconBellRinging size={28} className="text-slate-400" />
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
