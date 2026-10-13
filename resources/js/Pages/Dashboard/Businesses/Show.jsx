import DashboardLayout from "@/Layouts/DashboardLayout";
import React, { useMemo, useState } from "react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import {
    IconArrowLeft,
    IconBuildingStore,
    IconCirclePlus,
    IconPencil,
    IconTrash,
    IconUserPlus,
    IconUsers,
} from "@tabler/icons-react";
import Button from "@/Components/Dashboard/Button";
import Modal from "@/Components/Dashboard/Modal";
import InputSelect from "@/Components/Dashboard/InputSelect";
import toast from "react-hot-toast";
import Swal from "sweetalert2";
import { useTranslation } from "react-i18next";

const KNOWN_ROLES = ["owner", "manager", "staff"];
const KNOWN_STATUSES = ["active", "suspended", "invited", "revoked"];

function BusinessStatusBadge({ status }) {
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

function MemberStatusBadge({ status }) {
    const { t } = useTranslation();

    const styles = {
        active: "bg-success-100 text-success-700 dark:bg-success-900/50 dark:text-success-400",
        suspended: "bg-warning-100 text-warning-700 dark:bg-warning-900/50 dark:text-warning-400",
        invited: "bg-primary-100 text-primary-700 dark:bg-primary-900/50 dark:text-primary-300",
        revoked: "bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-400",
    };

    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${
                styles[status] ?? styles.revoked
            }`}
        >
            {KNOWN_STATUSES.includes(status)
                ? t(`business.status.${status}`)
                : status}
        </span>
    );
}

const selectClass =
    "w-full h-11 px-4 text-sm border rounded-xl focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 bg-white text-slate-800 border-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:border-slate-700";

function OutletPicker({ outlets, form, label = "" }) {
    const { t } = useTranslation();
    const selectedIds = form.data.outlet_ids ?? [];
    const defaultId = form.data.default_outlet_id;

    const toggle = (id) => {
        const isChecked = selectedIds.includes(id);
        const next = isChecked
            ? selectedIds.filter((value) => value !== id)
            : [...selectedIds, id];

        form.setData("outlet_ids", next);

        if (isChecked && defaultId === id) {
            form.setData("default_outlet_id", "");
        }
    };

    return (
        <div>
            <div className="mb-2 text-sm font-medium text-slate-700 dark:text-slate-300">
                {label}
            </div>
            <div className="max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                {outlets.length === 0 && (
                    <div className="px-3 py-3 text-sm text-slate-400">
                        {t("business.outlets.empty")}
                    </div>
                )}
                {outlets.map((outlet) => {
                    const checked = selectedIds.includes(outlet.id);

                    return (
                        <div
                            key={outlet.id}
                            className="flex items-center justify-between gap-3 px-3 py-2"
                        >
                            <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <input
                                    type="checkbox"
                                    checked={checked}
                                    onChange={() => toggle(outlet.id)}
                                    className="rounded-md border-slate-200 bg-white checked:bg-primary-500 dark:border-slate-800 dark:bg-slate-950"
                                />
                                <span>
                                    {outlet.code} — {outlet.name}
                                </span>
                            </label>
                            <label
                                className={`flex items-center gap-1 text-xs ${
                                    checked
                                        ? "text-slate-500 dark:text-slate-400"
                                        : "text-slate-300 dark:text-slate-600"
                                }`}
                            >
                                <input
                                    type="radio"
                                    name={`default_outlet_${label}`}
                                    disabled={!checked}
                                    checked={defaultId === outlet.id}
                                    onChange={() =>
                                        form.setData("default_outlet_id", outlet.id)
                                    }
                                />
                                {t("business.outlets.default")}
                            </label>
                        </div>
                    );
                })}
            </div>
            {form.errors.outlet_ids && (
                <small className="text-xs text-danger-500 dark:text-danger-400">
                    {form.errors.outlet_ids}
                </small>
            )}
            {form.errors.default_outlet_id && (
                <small className="text-xs text-danger-500 dark:text-danger-400">
                    {form.errors.default_outlet_id}
                </small>
            )}
        </div>
    );
}

export default function Show({ business, members, candidateUsers, outlets, statuses }) {
    const { t } = useTranslation();
    const [showAdd, setShowAdd] = useState(false);
    const [editing, setEditing] = useState(null);
    const [selectedUser, setSelectedUser] = useState(null);

    const roleLabel = (role) =>
        KNOWN_ROLES.includes(role) ? t(`business.roles.${role}`) : role;
    const statusLabel = (status) =>
        KNOWN_STATUSES.includes(status) ? t(`business.status.${status}`) : status;

    const outletMap = useMemo(
        () => Object.fromEntries(outlets.map((outlet) => [outlet.id, outlet])),
        [outlets]
    );

    const addForm = useForm({
        user_id: "",
        role: "staff",
        status: "active",
        outlet_ids: [],
        default_outlet_id: "",
    });

    const editForm = useForm({
        role: "staff",
        status: "active",
        outlet_ids: [],
        default_outlet_id: "",
    });

    const resetAdd = () => {
        addForm.reset();
        setSelectedUser(null);
    };

    const submitAdd = (event) => {
        event.preventDefault();

        addForm.transform((data) => ({
            ...data,
            user_id: selectedUser?.id ?? "",
            default_outlet_id: data.default_outlet_id || null,
        }));

        addForm.post(route("businesses.members.store", business.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t("business.memberAdded"));
                setShowAdd(false);
                resetAdd();
            },
            onError: () => toast.error(t("business.memberAddError")),
        });
    };

    const openEdit = (member) => {
        editForm.setData({
            role: member.role,
            status: member.status,
            outlet_ids: member.outlet_ids ?? [],
            default_outlet_id: member.default_outlet?.id ?? "",
        });
        editForm.clearErrors();
        setEditing(member);
    };

    const submitEdit = (event) => {
        event.preventDefault();

        editForm.transform((data) => ({
            ...data,
            default_outlet_id: data.default_outlet_id || null,
        }));

        editForm.patch(route("businesses.members.update", [business.id, editing.id]), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t("business.memberUpdated"));
                setEditing(null);
            },
            onError: () => toast.error(t("business.memberUpdateError")),
        });
    };

    const removeMember = (member) => {
        Swal.fire({
            title: t("business.memberRemoveTitle"),
            text: t("business.memberRemoveText", {
                name: member.user?.name ?? t("business.columns.member"),
                business: business.name,
            }),
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#e11d48",
            cancelButtonColor: "#64748b",
            confirmButtonText: t("business.memberRemoveConfirm"),
            cancelButtonText: t("common.buttons.cancel"),
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(
                    route("businesses.members.destroy", [business.id, member.id]),
                    {
                        preserveScroll: true,
                        onSuccess: () => toast.success(t("business.memberRemoved")),
                        onError: () => toast.error(t("business.memberRemoveError")),
                    }
                );
            }
        });
    };

    const memberOutlets = (member) =>
        (member.outlet_ids ?? [])
            .map((id) => outletMap[id])
            .filter(Boolean);

    return (
        <DashboardLayout>
            <Head title={t("business.detailTitle", { name: business.name })} />

            <div className="mb-6">
                <Link
                    href={route("businesses.index")}
                    className="mb-3 inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-primary-500 dark:text-slate-400"
                >
                    <IconArrowLeft size={16} />
                    {t("business.backToList")}
                </Link>

                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                            <IconBuildingStore
                                size={28}
                                className="text-primary-500"
                            />
                            {business.name}
                        </h1>
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                            <span>{business.slug}</span>
                            <span>•</span>
                            <BusinessStatusBadge status={business.status} />
                            <span>•</span>
                            <span>
                                {t("business.outletCount", {
                                    count: business.outlets_count,
                                })}
                            </span>
                            <span>•</span>
                            <span>
                                {t("business.memberCount", {
                                    count: business.memberships_count,
                                })}
                            </span>
                        </div>
                    </div>
                    <Button
                        type="button"
                        icon={<IconCirclePlus size={18} />}
                        label={t("business.members.add")}
                        onClick={() => setShowAdd(true)}
                        className="bg-primary-500 text-white shadow-lg shadow-primary-500/30 hover:bg-primary-600"
                    />
                </div>
            </div>

            <div className="rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div className="flex items-center gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                    <IconUsers size={18} className="text-primary-500" />
                    <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                        {t("business.members.title")}
                    </h2>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead className="bg-slate-50 dark:bg-slate-800/60">
                            <tr>
                                {[
                                    "business.columns.member",
                                    "business.columns.role",
                                    "business.columns.status",
                                    "business.columns.outlet",
                                    "business.columns.joined",
                                    "business.columns.actions",
                                ].map((heading, index) => (
                                    <th
                                        key={heading}
                                        className={`px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 ${
                                            index === 5 ? "text-right" : "text-left"
                                        }`}
                                    >
                                        {t(heading)}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {members.map((member) => (
                                <tr
                                    key={member.id}
                                    className="hover:bg-slate-50 dark:hover:bg-slate-800/40"
                                >
                                    <td className="px-4 py-3">
                                        <div className="font-medium text-slate-800 dark:text-slate-200">
                                            {member.user?.name ?? "—"}
                                            {member.is_owner && (
                                                <span className="ml-2 rounded-full bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900/50 dark:text-primary-300">
                                                    {t("business.members.ownerBadge")}
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-xs text-slate-400">
                                            {member.user?.email ?? ""}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                        {roleLabel(member.role)}
                                    </td>
                                    <td className="px-4 py-3">
                                        <MemberStatusBadge
                                            status={member.status}
                                        />
                                    </td>
                                    <td className="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                                        {memberOutlets(member).length > 0 ? (
                                            <div className="flex flex-wrap gap-1">
                                                {memberOutlets(member).map(
                                                    (outlet) => (
                                                        <span
                                                            key={outlet.id}
                                                            className={`rounded-md px-2 py-0.5 text-xs ${
                                                                member
                                                                    .default_outlet
                                                                    ?.id ===
                                                                outlet.id
                                                                    ? "bg-primary-100 text-primary-700 dark:bg-primary-900/50 dark:text-primary-300"
                                                                    : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                                                            }`}
                                                        >
                                                            {outlet.code}
                                                        </span>
                                                    )
                                                )}
                                            </div>
                                        ) : (
                                            <span className="text-xs text-slate-400">
                                                {t("business.members.noOutlets")}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">
                                        {member.joined_at
                                            ? new Date(
                                                  member.joined_at
                                              ).toLocaleDateString("id-ID")
                                            : "—"}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center justify-end gap-1">
                                            <Button
                                                type="modal"
                                                icon={<IconPencil size={15} />}
                                                className="bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300"
                                                onClick={() => openEdit(member)}
                                            />
                                            <Button
                                                type="modal"
                                                icon={<IconTrash size={15} />}
                                                className="bg-danger-100 text-danger-700 hover:bg-danger-200 dark:bg-danger-900/40 dark:text-danger-400"
                                                onClick={() =>
                                                    removeMember(member)
                                                }
                                                disabled={member.is_owner}
                                            />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {members.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-12 text-center text-sm text-slate-400"
                                    >
                                        {t("business.members.empty")}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Tambah Anggota */}
            <Modal
                show={showAdd}
                onClose={() => {
                    setShowAdd(false);
                    resetAdd();
                }}
                title={t("business.members.add")}
                maxWidth="lg"
            >
                <form onSubmit={submitAdd} className="flex flex-col gap-4">
                    <InputSelect
                        label={t("business.members.userLabel")}
                        placeholder={t("business.members.userPlaceholder")}
                        data={candidateUsers}
                        selected={selectedUser}
                        setSelected={setSelectedUser}
                        displayKey="name"
                        searchable
                        errors={addForm.errors.user_id}
                    />
                    {candidateUsers.length === 0 && (
                        <p className="-mt-2 text-xs text-slate-400">
                            {t("business.members.allAdded")}
                        </p>
                    )}

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-2">
                            <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                {t("business.columns.role")}
                            </label>
                            <select
                                value={addForm.data.role}
                                onChange={(event) =>
                                    addForm.setData("role", event.target.value)
                                }
                                className={selectClass}
                            >
                                {KNOWN_ROLES.map((role) => (
                                    <option key={role} value={role}>
                                        {roleLabel(role)}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex flex-col gap-2">
                            <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                {t("business.columns.status")}
                            </label>
                            <select
                                value={addForm.data.status}
                                onChange={(event) =>
                                    addForm.setData("status", event.target.value)
                                }
                                className={selectClass}
                            >
                                {statuses.map((status) => (
                                    <option key={status} value={status}>
                                        {statusLabel(status)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <OutletPicker
                        outlets={outlets}
                        form={addForm}
                        label={t("business.outlets.access")}
                    />

                    <div className="mt-2 flex justify-end gap-3">
                        <Button
                            type="button"
                            onClick={() => {
                                setShowAdd(false);
                                resetAdd();
                            }}
                            className="bg-slate-200 text-slate-700 hover:bg-slate-300 dark:bg-slate-800 dark:text-slate-200"
                            label={t("common.buttons.cancel")}
                        />
                        <Button
                            type="submit"
                            icon={<IconUserPlus size={18} />}
                            disabled={addForm.processing}
                            className="bg-primary-500 text-white hover:bg-primary-600"
                            label={
                                addForm.processing
                                    ? t("business.saving")
                                    : t("business.members.add")
                            }
                        />
                    </div>
                </form>
            </Modal>

            {/* Ubah Anggota */}
            <Modal
                show={editing !== null}
                onClose={() => setEditing(null)}
                title={t("business.members.edit")}
                maxWidth="lg"
            >
                {editing && (
                    <form onSubmit={submitEdit} className="flex flex-col gap-4">
                        <div className="rounded-xl bg-slate-50 px-4 py-3 text-sm dark:bg-slate-800/60">
                            <div className="font-medium text-slate-800 dark:text-slate-200">
                                {editing.user?.name}
                            </div>
                            <div className="text-xs text-slate-400">
                                {editing.user?.email}
                            </div>
                        </div>

                        {editing.is_owner && (
                            <p className="text-xs text-warning-600 dark:text-warning-400">
                                {t("business.ownerProtected")}
                            </p>
                        )}

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="flex flex-col gap-2">
                                <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                    {t("business.columns.role")}
                                </label>
                                <select
                                    value={editForm.data.role}
                                    disabled={editing.is_owner}
                                    onChange={(event) =>
                                        editForm.setData(
                                            "role",
                                            event.target.value
                                        )
                                    }
                                    className={`${selectClass} disabled:cursor-not-allowed disabled:opacity-60`}
                                >
                                    {KNOWN_ROLES.map((role) => (
                                        <option key={role} value={role}>
                                            {roleLabel(role)}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex flex-col gap-2">
                                <label className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                    {t("business.columns.status")}
                                </label>
                                <select
                                    value={editForm.data.status}
                                    disabled={editing.is_owner}
                                    onChange={(event) =>
                                        editForm.setData(
                                            "status",
                                            event.target.value
                                        )
                                    }
                                    className={`${selectClass} disabled:cursor-not-allowed disabled:opacity-60`}
                                >
                                    {statuses.map((status) => (
                                        <option key={status} value={status}>
                                            {statusLabel(status)}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <OutletPicker
                            outlets={outlets}
                            form={editForm}
                            label={t("business.outlets.access")}
                        />

                        <div className="mt-2 flex justify-end gap-3">
                            <Button
                                type="button"
                                onClick={() => setEditing(null)}
                                className="bg-slate-200 text-slate-700 hover:bg-slate-300 dark:bg-slate-800 dark:text-slate-200"
                                label={t("common.buttons.cancel")}
                            />
                            <Button
                                type="submit"
                                disabled={editForm.processing}
                                className="bg-primary-500 text-white hover:bg-primary-600"
                                label={
                                    editForm.processing
                                        ? t("business.saving")
                                        : t("common.buttons.save")
                                }
                            />
                        </div>
                    </form>
                )}
            </Modal>
        </DashboardLayout>
    );
}
