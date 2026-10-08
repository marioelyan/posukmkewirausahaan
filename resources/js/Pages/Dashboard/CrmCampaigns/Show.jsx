import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, router } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Table from "@/Components/Dashboard/Table";
import { IconArrowLeft, IconBrandWhatsapp, IconChecks, IconPlayerPlay, IconPlayerStop } from "@tabler/icons-react";
import toast from "react-hot-toast";

const formatDateTime = (value) =>
    value
        ? new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value))
        : "-";

export default function Show({ campaign }) {
    const processCampaign = () => {
        router.post(route("crm-campaigns.process", campaign.id), {}, {
            preserveScroll: true,
            onSuccess: () => toast.success("Campaign berhasil diproses"),
            onError: () => toast.error("Gagal memproses campaign"),
        });
    };

    const cancelCampaign = () => {
        router.post(route("crm-campaigns.cancel", campaign.id), {}, {
            preserveScroll: true,
            onSuccess: () => toast.success("Campaign dibatalkan"),
            onError: () => toast.error("Gagal membatalkan campaign"),
        });
    };

    return (
        <>
            <Head title={campaign.name} />
            <div className="mb-6">
                <Link href={route("crm-campaigns.index")} className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600">
                    <IconArrowLeft size={16} />
                    Kembali ke CRM campaigns
                </Link>
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">{campaign.name}</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            {campaign.type} • status {campaign.status} • diproses {formatDateTime(campaign.processed_at)}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {campaign.status === "draft" && (
                            <button
                                type="button"
                                onClick={processCampaign}
                                className="inline-flex items-center gap-2 rounded-xl bg-primary-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-600"
                            >
                                <IconPlayerPlay size={16} />
                                Proses Audience
                            </button>
                        )}
                        {campaign.status !== "cancelled" && campaign.status !== "processed" && (
                            <button
                                type="button"
                                onClick={cancelCampaign}
                                className="inline-flex items-center gap-2 rounded-xl bg-rose-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-rose-600"
                            >
                                <IconPlayerStop size={16} />
                                Batalkan
                            </button>
                        )}
                    </div>
                </div>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.7fr_1fr]">
                <div className="space-y-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Delivery Logs</h2>
                        <Table>
                            <Table.Thead>
                                <tr>
                                    <Table.Th>Customer</Table.Th>
                                    <Table.Th>Status</Table.Th>
                                    <Table.Th>Payload</Table.Th>
                                    <Table.Th className="w-36 text-center">Aksi</Table.Th>
                                </tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {campaign.logs.length > 0 ? (
                                    campaign.logs.map((log) => (
                                        <tr key={log.id}>
                                            <Table.Td>
                                                <Link href={log.customer ? route("customers.show", log.customer.id) : "#"} className="font-semibold text-slate-800 hover:text-primary-600 dark:text-slate-100">
                                                    {log.customer?.name || "Tanpa customer"}
                                                </Link>
                                                <p className="text-xs text-slate-500 dark:text-slate-400">{log.customer?.no_telp || "-"}</p>
                                            </Table.Td>
                                            <Table.Td>{log.status}</Table.Td>
                                            <Table.Td>
                                                <p className="line-clamp-2 text-sm text-slate-600 dark:text-slate-300">{log.payload?.message || "-"}</p>
                                            </Table.Td>
                                            <Table.Td className="text-center">
                                                <div className="flex items-center justify-center gap-2">
                                                    {log.payload?.whatsapp_url && (
                                                        <a
                                                            href={log.payload.whatsapp_url}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-green-50 text-green-600 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-300"
                                                        >
                                                            <IconBrandWhatsapp size={16} />
                                                        </a>
                                                    )}
                                                    {log.status !== "sent" && (
                                                        <button
                                                            type="button"
                                                            onClick={() => router.post(route("crm-campaign-logs.mark-sent", log.id))}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-primary-50 text-primary-600 hover:bg-primary-100 dark:bg-primary-950/30 dark:text-primary-300"
                                                        >
                                                            <IconChecks size={16} />
                                                        </button>
                                                    )}
                                                </div>
                                            </Table.Td>
                                        </tr>
                                    ))
                                ) : (
                                    <Table.Empty colSpan={4} message="Belum ada delivery log untuk campaign ini." />
                                )}
                            </Table.Tbody>
                        </Table>
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Audience Snapshot</h2>
                        <div className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/60">
                            <pre className="overflow-x-auto whitespace-pre-wrap text-xs text-slate-600 dark:text-slate-300">
                                {JSON.stringify(campaign.audience_snapshot || [], null, 2)}
                            </pre>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Template Pesan</h2>
                        <p className="whitespace-pre-wrap text-sm text-slate-600 dark:text-slate-300">
                            {campaign.message_template || "-"}
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}

Show.layout = (page) => <DashboardLayout children={page} />;
