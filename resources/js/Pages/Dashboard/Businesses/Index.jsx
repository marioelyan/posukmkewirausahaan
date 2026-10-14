import DashboardLayout from "@/Layouts/DashboardLayout";
import React, { useState } from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import {
    IconBuildingStore,
    IconCirclePlus,
    IconPencil,
    IconPlayerPause,
    IconPlayerPlay,
    IconUsers,
} from "@tabler/icons-react";
import Button from "@/Components/Dashboard/Button";
import Input from "@/Components/Dashboard/Input";
import toast from "react-hot-toast";
import Swal from "sweetalert2";
import { useTranslation } from "react-i18next";

function StatusBadge({ status }) {
    const { t } = useTranslation();

    if (status === "active") {
        return (
            <span className="inline-flex items-center rounded-full bg-success-100 px-2.5 py-1 text-xs font-medium text-success-700 dark:bg-success-900/50 dark:text-success-400">
                {t("business.status.active")}
            </span>
        );
    }
    if (status === "suspended") {
        return (
            <span className="inline-flex items-center rounded-full bg-warning-100 px-2.5 py-1 text-xs font-medium text-warning-700 dark:bg-warning-900/50 dark:text-warning-400">
                {t("business.status.suspended")}
            </span>
        );
    }
    return (
        <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-400">
            {status}
        </span>
    );
}

export default function Index({ businesses, multiBusinessEnabled }) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(null);
    const rename = useForm({ name: "" });
    const statusForm = useForm({ status: "active" });

    const openRename = (business) => {
        rename.setData("name", business.name);
        setEditing(business);
    };

    const submitRename = (e) => {
        e.preventDefault();
        rename.patch(route("businesses.update", editing.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t("business.renameSuccess"));
                setEditing(null);
            },
            onError: () => toast.error(t("business.renameError")),
        });
    };

    const toggleStatus = (business) => {
        const next = business.status === "active" ? "suspended" : "active";
        const isSuspend = next === "suspended";

        Swal.fire({
            title: isSuspend
                ? t("business.status.suspendTitle")
                : t("business.status.activateTitle"),
            text: isSuspend
                ? t("business.status.suspendText", { name: business.name })
                : t("business.status.activateText", { name: business.name }),
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: isSuspend ? "#d68910" : "#1f9d6b",
            cancelButtonColor: "#64748b",
            confirmButtonText: t("common.labels.yes"),
            cancelButtonText: t("common.buttons.cancel"),
        }).then((result) => {
            if (result.isConfirmed) {
                statusForm.setData("status", next);
                statusForm.patch(route("businesses.updateStatus", business.id), {
                    preserveScroll: true,
                    onSuccess: () =>
                        toast.success(
                            isSuspend
                                ? t("business.status.suspendSuccess")
                                : t("business.status.activateSuccess"),
                            { icon: "✅" }
                        ),
                    onError: () =>
                        toast.error(t("business.status.updateError")),
                });
            }
        });
    };

    return (
        <DashboardLayout>
            <Head title={t("business.title")} />

            <div className="mb-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                            <IconBuildingStore
                                size={28}
                                className="text-primary-500"
                            />
                            {t("business.title")}
                        </h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {multiBusinessEnabled
                                ? t("business.subtitleEnabled")
                                : t("business.subtitleDisabled")}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button
                            type="link"
                            href={route("businesses.create")}
                            icon={<IconCirclePlus size={18} />}
                            className="bg-primary-500 text-white shadow-lg shadow-primary-500/30 hover:bg-primary-600"
                            label={t("business.add")}
                        />
                    </div>
                </div>
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead className="bg-slate-50 dark:bg-slate-800/60">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    {t("business.columns.business")}
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    {t("business.columns.status")}
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    {t("business.columns.owner")}
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    {t("business.columns.outlets")}
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    {t("business.columns.members")}
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    {t("business.columns.actions")}
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {businesses.map((business) => (
                                <tr
                                    key={business.id}
                                    className="hover:bg-slate-50 dark:hover:bg-slate-800/40"
                                >
                                    <td className="px-4 py-3">
                                        <Link
                                            href={route(
                                                "businesses.show",
                                                business.id
                                            )}
                                            className="font-medium text-slate-800 hover:text-primary-500 dark:text-slate-200"
                                        >
                                            {business.name}
                                        </Link>
                                        <div className="text-xs text-slate-400">
                                            {business.slug}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusBadge
                                            status={business.status}
                                        />
                                    </td>
                                    <td className="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                        {business.owner ? (
                                            <div>
                                                <div>{business.owner.name}</div>
                                                <div className="text-xs text-slate-400">
                                                    {business.owner.email}
                                                </div>
                                            </div>
                                        ) : (
                                            <span className="text-xs text-slate-400">
                                                {t("business.platform")}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                        {business.outlets_count}
                                    </td>
                                    <td className="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                        {business.memberships_count}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center justify-end gap-1">
                                            <Button
                                                type="edit"
                                                href={route(
                                                    "businesses.show",
                                                    business.id
                                                )}
                                                icon={<IconUsers size={15} />}
                                                className="bg-primary-100 text-primary-700 hover:bg-primary-200 dark:bg-primary-900/40 dark:text-primary-300"
                                            />
                                            <Button
                                                type="button"
                                                icon={<IconPencil size={15} />}
                                                className="bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300"
                                                onClick={() =>
                                                    openRename(business)
                                                }
                                            />
                                            <Button
                                                type="button"
                                                icon={
                                                    business.status === "active" ? (
                                                        <IconPlayerPause
                                                            size={15}
                                                        />
                                                    ) : (
                                                        <IconPlayerPlay
                                                            size={15}
                                                        />
                                                    )
                                                }
                                                className={`${
                                                    business.status === "active"
                                                        ? "bg-warning-100 text-warning-700 hover:bg-warning-200 dark:bg-warning-900/40 dark:text-warning-400"
                                                        : "bg-success-100 text-success-700 hover:bg-success-200 dark:bg-success-900/40 dark:text-success-400"
                                                }`}
                                                onClick={() =>
                                                    toggleStatus(business)
                                                }
                                            />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {businesses.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-12 text-center text-sm text-slate-400"
                                    >
                                        {t("business.empty")}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {editing && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
                    <form
                        onSubmit={submitRename}
                        className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-900"
                    >
                        <h3 className="mb-1 flex items-center gap-2 text-lg font-semibold text-slate-800 dark:text-slate-200">
                            <IconBuildingStore
                                size={20}
                                className="text-primary-500"
                            />
                            {t("business.renameTitle")}
                        </h3>
                        <p className="mb-4 text-sm text-slate-500 dark:text-slate-400">
                            {editing.slug}
                        </p>
                        <Input
                            type="text"
                            label={t("business.nameLabel")}
                            value={rename.data.name}
                            onChange={(e) =>
                                rename.setData("name", e.target.value)
                            }
                            errors={rename.errors.name}
                        />
                        <div className="mt-5 flex justify-end gap-3">
                            <Button
                                type="button"
                                onClick={() => setEditing(null)}
                                className="bg-slate-200 text-slate-700 hover:bg-slate-300 dark:bg-slate-800 dark:text-slate-200"
                                label={t("common.buttons.cancel")}
                            />
                            <Button
                                type="submit"
                                disabled={rename.processing}
                                className="bg-primary-500 text-white hover:bg-primary-600"
                                label={
                                    rename.processing
                                        ? t("business.saving")
                                        : t("common.buttons.save")
                                }
                            />
                        </div>
                    </form>
                </div>
            )}
        </DashboardLayout>
    );
}
