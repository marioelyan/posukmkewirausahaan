import DashboardLayout from "@/Layouts/DashboardLayout";
import React, { useState } from "react";
import { Head, Link, useForm } from "@inertiajs/react";
import {
    IconBuildingStore,
    IconCirclePlus,
    IconPencil,
    IconPlayerPause,
    IconPlayerPlay,
} from "@tabler/icons-react";
import Button from "@/Components/Dashboard/Button";
import Input from "@/Components/Dashboard/Input";
import toast from "react-hot-toast";
import Swal from "sweetalert2";

function StatusBadge({ status }) {
    if (status === "active") {
        return (
            <span className="inline-flex items-center rounded-full bg-success-100 px-2.5 py-1 text-xs font-medium text-success-700 dark:bg-success-900/50 dark:text-success-400">
                Aktif
            </span>
        );
    }
    if (status === "suspended") {
        return (
            <span className="inline-flex items-center rounded-full bg-warning-100 px-2.5 py-1 text-xs font-medium text-warning-700 dark:bg-warning-900/50 dark:text-warning-400">
                Ditangguhkan
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
                toast.success("Nama business diperbarui");
                setEditing(null);
            },
            onError: () => toast.error("Gagal memperbarui nama"),
        });
    };

    const toggleStatus = (business) => {
        const next = business.status === "active" ? "suspended" : "active";
        const verb = next === "suspended" ? "menangguhkan" : "mengaktifkan";

        Swal.fire({
            title: `${verb.charAt(0).toUpperCase() + verb.slice(1)} business?`,
            text: `Business "${business.name}" akan ${verb}.`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: next === "suspended" ? "#f59e0b" : "#10b981",
            cancelButtonColor: "#64748b",
            confirmButtonText: "Ya",
            cancelButtonText: "Batal",
        }).then((result) => {
            if (result.isConfirmed) {
                statusForm.setData("status", next);
                statusForm.patch(route("businesses.updateStatus", business.id), {
                    preserveScroll: true,
                    onSuccess: () =>
                        toast.success(`Business ${verb}`, { icon: "✅" }),
                    onError: () =>
                        toast.error(
                            "Tidak dapat mengubah status business ini"
                        ),
                });
            }
        });
    };

    return (
        <DashboardLayout>
            <Head title="Business" />

            <div className="mb-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                            <IconBuildingStore
                                size={28}
                                className="text-primary-500"
                            />
                            Business
                        </h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {multiBusinessEnabled
                                ? "Kelola business multi-tenant Anda."
                                : "Business dikelola platform admin. Fitur multi-business belum diaktifkan."}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button
                            type="link"
                            href={route("businesses.create")}
                            icon={<IconCirclePlus size={18} />}
                            className="bg-primary-500 text-white shadow-lg shadow-primary-500/30 hover:bg-primary-600"
                            label="Tambah Business"
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
                                    Business
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    Status
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    Owner
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    Outlet
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    Member
                                </th>
                                <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                    Aksi
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
                                        <div className="font-medium text-slate-800 dark:text-slate-200">
                                            {business.name}
                                        </div>
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
                                                Platform
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
                                        Belum ada business. Klik "Tambah
                                        Business" untuk membuat business pertama.
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
                            Ubah Nama Business
                        </h3>
                        <p className="mb-4 text-sm text-slate-500 dark:text-slate-400">
                            {editing.slug}
                        </p>
                        <Input
                            type="text"
                            label="Nama Business"
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
                                label="Batal"
                            />
                            <Button
                                type="submit"
                                disabled={rename.processing}
                                className="bg-primary-500 text-white hover:bg-primary-600"
                                label={
                                    rename.processing
                                        ? "Menyimpan..."
                                        : "Simpan"
                                }
                            />
                        </div>
                    </form>
                </div>
            )}
        </DashboardLayout>
    );
}