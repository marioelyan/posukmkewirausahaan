import React from "react";
import { Head, useForm } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import { IconArrowLeft, IconBroadcast, IconDeviceFloppy } from "@tabler/icons-react";

export default function Form({ mode = "create", campaign = null, audienceOptions }) {
    const isEdit = mode === "edit";
    const { data, setData, post, put, processing } = useForm({
        name: campaign?.name ?? "",
        type: campaign?.type ?? "promo_broadcast",
        channel: campaign?.channel ?? "whatsapp_link",
        message_template: campaign?.message_template ?? "Halo {{name}}, ada promo spesial untuk Anda.",
        save_as_draft: true,
        audience_filters: {
            segment_ids: campaign?.audience_filters?.segment_ids ?? [],
            customer_type: campaign?.audience_filters?.customer_type ?? "all",
            receivable_status: campaign?.audience_filters?.receivable_status ?? "all",
            voucher_filter: campaign?.audience_filters?.voucher_filter ?? "all",
        },
    });

    const setAudienceFilter = (key, value) => {
        setData("audience_filters", { ...data.audience_filters, [key]: value });
    };

    const submit = (event) => {
        event.preventDefault();

        if (isEdit) {
            put(route("crm-campaigns.update", campaign.id));
            return;
        }

        post(route("crm-campaigns.store"));
    };

    return (
        <>
            <Head title={isEdit ? "Edit CRM Campaign" : "Buat CRM Campaign"} />
            <div className="w-full">
                <div className="mb-6">
                    <Button
                        type="link"
                        href={route("crm-campaigns.index")}
                        icon={<IconArrowLeft size={18} />}
                        className="mb-3 border-none bg-transparent px-0 text-slate-500 shadow-none hover:bg-transparent hover:text-primary-600 dark:text-slate-400"
                        label="Kembali ke CRM campaigns"
                    />
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                        {isEdit ? "Edit CRM Campaign" : "Buat CRM Campaign"}
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Bangun audience dari segment dan siapkan campaign WhatsApp/manual follow-up.
                    </p>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center gap-3">
                            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-100 text-primary-600 dark:bg-primary-950/40 dark:text-primary-300">
                                <IconBroadcast size={22} />
                            </div>
                            <div>
                                <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Informasi Campaign</h2>
                                <p className="text-sm text-slate-500 dark:text-slate-400">Campaign disimpan sebagai draft dan dapat diproses menjadi audience nyata.</p>
                            </div>
                        </div>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Nama Campaign</label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={(event) => setData("name", event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                            </div>
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Tipe Campaign</label>
                                <select
                                    value={data.type}
                                    onChange={(event) => setData("type", event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    <option value="promo_broadcast">Promo Broadcast</option>
                                    <option value="due_date_reminder">Due Date Reminder</option>
                                    <option value="repeat_order_reminder">Repeat Order Reminder</option>
                                </select>
                            </div>
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Channel</label>
                                <select
                                    value={data.channel}
                                    onChange={(event) => setData("channel", event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    <option value="internal">Internal</option>
                                    <option value="whatsapp_link">WhatsApp Link</option>
                                </select>
                            </div>
                            <label className="inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800">
                                <input
                                    type="checkbox"
                                    checked={data.save_as_draft}
                                    onChange={(event) => setData("save_as_draft", event.target.checked)}
                                />
                                <span className="text-sm font-medium text-slate-700 dark:text-slate-300">Simpan sebagai draft</span>
                            </label>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Audience Builder</h2>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="md:col-span-2">
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Segment Customer</label>
                                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    {audienceOptions.segment_options.map((segment) => {
                                        const checked = data.audience_filters.segment_ids.includes(segment.value);
                                        return (
                                            <label key={segment.value} className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={(event) => {
                                                        const nextValues = event.target.checked
                                                            ? [...data.audience_filters.segment_ids, segment.value]
                                                            : data.audience_filters.segment_ids.filter((value) => value !== segment.value);
                                                        setAudienceFilter("segment_ids", nextValues);
                                                    }}
                                                />
                                                <span>{segment.label}</span>
                                            </label>
                                        );
                                    })}
                                </div>
                            </div>

                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Customer Type</label>
                                <select
                                    value={data.audience_filters.customer_type}
                                    onChange={(event) => setAudienceFilter("customer_type", event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    {audienceOptions.customer_types.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Status Piutang</label>
                                <select
                                    value={data.audience_filters.receivable_status}
                                    onChange={(event) => setAudienceFilter("receivable_status", event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    {audienceOptions.receivable_statuses.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">Voucher Filter</label>
                                <select
                                    value={data.audience_filters.voucher_filter}
                                    onChange={(event) => setAudienceFilter("voucher_filter", event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    {audienceOptions.voucher_filters.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Template Pesan</h2>
                        <textarea
                            rows="5"
                            value={data.message_template}
                            onChange={(event) => setData("message_template", event.target.value)}
                            className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        />
                    </div>

                    <div className="flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                        <Button
                            type="link"
                            href={route("crm-campaigns.index")}
                            className="border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                            label="Batal"
                        />
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-500 px-5 py-2.5 font-medium text-white hover:bg-primary-600 disabled:opacity-50"
                        >
                            <IconDeviceFloppy size={18} />
                            {processing ? "Menyimpan..." : "Simpan Campaign"}
                        </button>
                    </div>
                </form>
            </div>
        </>
    );
}
