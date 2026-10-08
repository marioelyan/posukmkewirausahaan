import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, router } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Search from "@/Components/Dashboard/Search";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import {
    IconCirclePlus,
    IconCreditCard,
    IconDatabaseOff,
    IconPencilCog,
    IconTrash,
} from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";

const formatPrice = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

const statusBadge = (voucher) => {
    if (voucher.is_used) {
        return {
            label: "Sudah Dipakai",
            className:
                "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
        };
    }

    const startsAt = voucher.starts_at ? new Date(voucher.starts_at) : null;
    const expiresAt = voucher.expires_at ? new Date(voucher.expires_at) : null;
    const now = new Date();

    if (!voucher.is_active) {
        return {
            label: "Nonaktif",
            className:
                "bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300",
        };
    }

    if (startsAt && startsAt > now) {
        return {
            label: "Terjadwal",
            className:
                "bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300",
        };
    }

    if (expiresAt && expiresAt < now) {
        return {
            label: "Expired",
            className:
                "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300",
        };
    }

    return {
        label: "Aktif",
        className:
            "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300",
    };
};

export default function Index({ vouchers, filters = {} }) {
    const { can } = useAuthorization();
    const hasData = vouchers.data.length > 0;

    const handleFilterChange = (key, value) => {
        router.get(
            route("customer-vouchers.index"),
            { ...filters, [key]: value },
            { preserveState: true, replace: true }
        );
    };

    return (
        <>
            <Head title="Voucher Customer" />

            <div className="w-full">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                            Voucher Customer
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Voucher personal untuk promosi retensi dan reward pelanggan.
                        </p>
                    </div>
                    {can("customer-vouchers-create") && (
                        <Button
                            type="link"
                            href={route("customer-vouchers.create")}
                            icon={<IconCirclePlus size={18} />}
                            className="bg-primary-500 text-white hover:bg-primary-600 shadow-lg shadow-primary-500/30"
                            label="Buat Voucher"
                        />
                    )}
                </div>

                <div className="mb-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px]">
                        <Search
                            url={route("customer-vouchers.index")}
                            placeholder="Cari kode, voucher, pelanggan..."
                            query={filters.search || ""}
                        />
                        <select
                            value={filters.status || ""}
                            onChange={(event) =>
                                handleFilterChange("status", event.target.value)
                            }
                            className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        >
                            <option value="">Semua Status</option>
                            <option value="active">Aktif</option>
                            <option value="scheduled">Terjadwal</option>
                            <option value="expired">Expired</option>
                            <option value="used">Sudah Dipakai</option>
                            <option value="inactive">Nonaktif</option>
                        </select>
                    </div>
                </div>

                <Table.Card title="Daftar Voucher">
                    <Table>
                        <Table.Thead>
                            <tr>
                                <Table.Th>Kode</Table.Th>
                                <Table.Th>Pelanggan</Table.Th>
                                <Table.Th>Benefit</Table.Th>
                                <Table.Th>Status</Table.Th>
                                <Table.Th>Kedaluwarsa</Table.Th>
                                <Table.Th className="w-28 text-center">Aksi</Table.Th>
                            </tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {hasData ? (
                                vouchers.data.map((voucher) => (
                                    <tr
                                        key={voucher.id}
                                        className="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50"
                                    >
                                        <Table.Td>
                                            <div className="flex items-center gap-2">
                                                <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-100 text-primary-600 dark:bg-primary-950/40 dark:text-primary-300">
                                                    <IconCreditCard size={18} />
                                                </div>
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                                                        {voucher.code}
                                                    </p>
                                                    <p className="text-xs text-slate-500 dark:text-slate-400">
                                                        {voucher.name}
                                                    </p>
                                                </div>
                                            </div>
                                        </Table.Td>
                                        <Table.Td>
                                            <div>
                                                <p className="text-sm font-medium text-slate-800 dark:text-slate-100">
                                                    {voucher.customer?.name}
                                                </p>
                                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                                    {voucher.customer?.no_telp || "-"}
                                                </p>
                                            </div>
                                        </Table.Td>
                                        <Table.Td>
                                            <p className="text-sm text-slate-700 dark:text-slate-300">
                                                {voucher.discount_type === "percentage"
                                                    ? `${voucher.discount_value}%`
                                                    : formatPrice(voucher.discount_value)}
                                            </p>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                                Min. {formatPrice(voucher.minimum_order)}
                                            </p>
                                        </Table.Td>
                                        <Table.Td>
                                            {(() => {
                                                const badge = statusBadge(voucher);

                                                return (
                                            <span
                                                className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${badge.className}`}
                                            >
                                                {badge.label}
                                            </span>
                                                );
                                            })()}
                                        </Table.Td>
                                        <Table.Td>
                                            <span className="text-sm text-slate-600 dark:text-slate-400">
                                                {voucher.expires_at
                                                    ? new Date(voucher.expires_at).toLocaleString("id-ID")
                                                    : "-"}
                                            </span>
                                        </Table.Td>
                                        <Table.Td className="text-center">
                                            <div className="flex items-center justify-center gap-2">
                                                {can("customer-vouchers-update") && (
                                                    <Link
                                                        href={route("customer-vouchers.edit", voucher.id)}
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-amber-200 bg-amber-50 text-amber-600 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
                                                    >
                                                        <IconPencilCog size={16} />
                                                    </Link>
                                                )}
                                                {can("customer-vouchers-delete") && (
                                                    <Button
                                                        type="delete"
                                                        url={route("customer-vouchers.destroy", voucher.id)}
                                                        icon={<IconTrash size={16} />}
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
                                                    />
                                                )}
                                            </div>
                                        </Table.Td>
                                    </tr>
                                ))
                            ) : (
                                <Table.Empty colSpan={6} message="Belum ada voucher customer.">
                                    <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                        <IconDatabaseOff
                                            size={28}
                                            className="text-slate-400"
                                        />
                                    </div>
                                </Table.Empty>
                            )}
                        </Table.Tbody>
                    </Table>
                </Table.Card>

                {vouchers.last_page > 1 && <Pagination links={vouchers.links} />}
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
