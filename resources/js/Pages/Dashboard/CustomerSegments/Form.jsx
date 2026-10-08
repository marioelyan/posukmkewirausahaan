import React from "react";
import { Head, useForm } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import { IconArrowLeft, IconDeviceFloppy, IconUsersGroup } from "@tabler/icons-react";

function InputError({ message }) {
    if (!message) return null;

    return <p className="mt-1 text-xs text-rose-500">{message}</p>;
}

export default function Form({ mode = "create", segment = null }) {
    const isEdit = mode === "edit";
    const { data, setData, post, put, processing, errors } = useForm({
        name: segment?.name ?? "",
        type: segment?.type ?? "manual",
        is_active: Boolean(segment?.is_active ?? true),
        description: segment?.description ?? "",
        auto_rule_type: segment?.auto_rule_type ?? "spending",
        rule_config: {
            min_total_spent: String(segment?.rule_config?.min_total_spent ?? 1500000),
            min_transaction_count: String(segment?.rule_config?.min_transaction_count ?? 5),
            recent_days: String(segment?.rule_config?.recent_days ?? 45),
            inactivity_days_min: String(segment?.rule_config?.inactivity_days_min ?? 30),
            require_outstanding_receivable: Boolean(segment?.rule_config?.require_outstanding_receivable ?? true),
            overdue_only: Boolean(segment?.rule_config?.overdue_only ?? false),
        },
    });

    const submit = (event) => {
        event.preventDefault();

        if (isEdit) {
            put(route("customer-segments.update", segment.id));
            return;
        }

        post(route("customer-segments.store"));
    };

    const setRuleConfig = (key, value) => {
        setData("rule_config", {
            ...data.rule_config,
            [key]: value,
        });
    };

    return (
        <>
            <Head title={isEdit ? "Edit Segment Customer" : "Buat Segment Customer"} />

            <div className="w-full">
                <div className="mb-6">
                    <Button
                        type="link"
                        href={route("customer-segments.index")}
                        icon={<IconArrowLeft size={18} />}
                        className="mb-3 border-none bg-transparent px-0 text-slate-500 shadow-none hover:bg-transparent hover:text-primary-600 dark:text-slate-400"
                        label="Kembali ke segment customer"
                    />
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                        {isEdit ? "Edit Segment Customer" : "Buat Segment Customer"}
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                        Kelompokkan customer secara manual atau otomatis berdasarkan perilaku bisnis.
                    </p>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center gap-3">
                            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-100 text-primary-600 dark:bg-primary-950/40 dark:text-primary-300">
                                <IconUsersGroup size={22} />
                            </div>
                            <div>
                                <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                                    Informasi Segment
                                </h2>
                                <p className="text-sm text-slate-500 dark:text-slate-400">
                                    Segment manual bisa diatur per customer, segment otomatis dihitung oleh sistem.
                                </p>
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Nama Segment
                                </label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={(event) => setData("name", event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Tipe Segment
                                </label>
                                <select
                                    value={data.type}
                                    onChange={(event) => setData("type", event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    <option value="manual">Manual Tag</option>
                                    <option value="auto">Auto Segment</option>
                                </select>
                            </div>
                            <div className="md:col-span-2">
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Deskripsi
                                </label>
                                <textarea
                                    rows="3"
                                    value={data.description}
                                    onChange={(event) => setData("description", event.target.value)}
                                    className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                />
                            </div>
                            <label className="inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800">
                                <input
                                    type="checkbox"
                                    checked={data.is_active}
                                    onChange={(event) => setData("is_active", event.target.checked)}
                                />
                                <span className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Segment aktif
                                </span>
                            </label>
                        </div>
                    </div>

                    {data.type === "auto" && (
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">
                                Rule Auto Segment
                            </h2>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Rule Type
                                    </label>
                                    <select
                                        value={data.auto_rule_type}
                                        onChange={(event) => setData("auto_rule_type", event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                    >
                                        <option value="spending">Spending</option>
                                        <option value="purchase_frequency">Purchase Frequency</option>
                                        <option value="receivable_behavior">Receivable Behavior</option>
                                    </select>
                                </div>

                                {data.auto_rule_type === "spending" && (
                                    <div>
                                        <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                            Minimum Total Belanja
                                        </label>
                                        <input
                                            type="number"
                                            min="0"
                                            value={data.rule_config.min_total_spent}
                                            onChange={(event) => setRuleConfig("min_total_spent", event.target.value)}
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                        />
                                    </div>
                                )}

                                {data.auto_rule_type === "purchase_frequency" && (
                                    <>
                                        <div>
                                            <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                                Minimum Jumlah Transaksi
                                            </label>
                                            <input
                                                type="number"
                                                min="0"
                                                value={data.rule_config.min_transaction_count}
                                                onChange={(event) => setRuleConfig("min_transaction_count", event.target.value)}
                                                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                            />
                                        </div>
                                        <div>
                                            <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                                Recent Days / Inactivity Days
                                            </label>
                                            <input
                                                type="number"
                                                min="1"
                                                value={
                                                    data.name.toLowerCase().includes("inactive")
                                                        ? data.rule_config.inactivity_days_min
                                                        : data.rule_config.recent_days
                                                }
                                                onChange={(event) =>
                                                    setRuleConfig(
                                                        data.name.toLowerCase().includes("inactive")
                                                            ? "inactivity_days_min"
                                                            : "recent_days",
                                                        event.target.value
                                                    )
                                                }
                                                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                            />
                                        </div>
                                    </>
                                )}

                                {data.auto_rule_type === "receivable_behavior" && (
                                    <div className="md:col-span-2 space-y-3">
                                        <label className="inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800">
                                            <input
                                                type="checkbox"
                                                checked={data.rule_config.require_outstanding_receivable}
                                                onChange={(event) => setRuleConfig("require_outstanding_receivable", event.target.checked)}
                                            />
                                            <span className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                                Harus punya piutang outstanding
                                            </span>
                                        </label>
                                        <label className="inline-flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800">
                                            <input
                                                type="checkbox"
                                                checked={data.rule_config.overdue_only}
                                                onChange={(event) => setRuleConfig("overdue_only", event.target.checked)}
                                            />
                                            <span className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                                Hanya piutang overdue
                                            </span>
                                        </label>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                        <Button
                            type="link"
                            href={route("customer-segments.index")}
                            className="border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                            label="Batal"
                        />
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-500 px-5 py-2.5 font-medium text-white hover:bg-primary-600 disabled:opacity-50"
                        >
                            <IconDeviceFloppy size={18} />
                            {processing ? "Menyimpan..." : "Simpan Segment"}
                        </button>
                    </div>
                </form>
            </div>
        </>
    );
}
