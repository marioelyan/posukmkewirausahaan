import DashboardLayout from "@/Layouts/DashboardLayout";
import React from "react";
import { Head, useForm, Link } from "@inertiajs/react";
import { IconArrowLeft, IconBuildingStore, IconUser } from "@tabler/icons-react";
import Button from "@/Components/Dashboard/Button";
import Input from "@/Components/Dashboard/Input";
import toast from "react-hot-toast";
import { useTranslation } from "react-i18next";

export default function Create({ users }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors } = useForm({
        name: "",
        owner_id: "",
    });

    const submit = (e) => {
        e.preventDefault();
        post(route("businesses.store"), {
            onSuccess: () => toast.success(t("business.createSuccess")),
            onError: () => toast.error(t("business.createError")),
        });
    };

    return (
        <DashboardLayout>
            <Head title={t("business.addTitle")} />

            <div className="mb-6">
                <Link
                    href={route("businesses.index")}
                    className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600"
                >
                    <IconArrowLeft size={16} />
                    {t("business.backToBusinesses")}
                </Link>
                <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                    <IconBuildingStore
                        size={28}
                        className="text-primary-500"
                    />
                    {t("business.addTitle")}
                </h1>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {t("business.addSubtitle")}
                </p>
            </div>

            <form onSubmit={submit}>
                <div className="max-w-2xl space-y-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-300">
                            {t("business.infoSection")}
                        </h3>
                        <div className="grid grid-cols-1 gap-4">
                            <Input
                                type="text"
                                label={t("business.nameLabel")}
                                placeholder={t("business.namePlaceholder")}
                                value={data.name}
                                onChange={(e) => setData("name", e.target.value)}
                                errors={errors.name}
                            />
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    {t("business.ownerOptional")}
                                </label>
                                <select
                                    value={data.owner_id}
                                    onChange={(e) =>
                                        setData("owner_id", e.target.value)
                                    }
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    <option value="">
                                        {t("business.ownerNone")}
                                    </option>
                                    {users.map((user) => (
                                        <option
                                            key={user.id}
                                            value={user.id}
                                        >
                                            {user.name} ({user.email})
                                        </option>
                                    ))}
                                </select>
                                {errors.owner_id && (
                                    <p className="mt-1 text-sm text-danger-600 dark:text-danger-400">
                                        {errors.owner_id}
                                    </p>
                                )}
                                <p className="mt-2 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                    <IconUser size={14} />
                                    {t("business.ownerHint")}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="flex justify-end gap-3">
                        <Button
                            type="link"
                            href={route("businesses.index")}
                            className="bg-slate-200 text-slate-700 hover:bg-slate-300 dark:bg-slate-800 dark:text-slate-200"
                            label={t("common.buttons.cancel")}
                        />
                        <Button
                            type="submit"
                            disabled={processing}
                            className="bg-primary-500 text-white shadow-lg shadow-primary-500/30 hover:bg-primary-600"
                            label={
                                processing
                                    ? t("business.saving")
                                    : t("business.save")
                            }
                        />
                    </div>
                </div>
            </form>
        </DashboardLayout>
    );
}