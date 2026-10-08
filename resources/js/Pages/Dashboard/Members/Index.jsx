import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Pagination from "@/Components/Dashboard/Pagination";
import Table from "@/Components/Dashboard/Table";
import { Head, Link, router } from "@inertiajs/react";
import {
    IconCirclePlus,
    IconCrown,
    IconFileSearch,
    IconPencil,
    IconSearch,
    IconUsers,
} from "@tabler/icons-react";

const formatCurrency = (value = 0) =>
    Number(value || 0).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

const formatDate = (value) =>
    value
        ? new Intl.DateTimeFormat("id-ID", {
              dateStyle: "medium",
          }).format(new Date(value))
        : "-";

export default function Index({ members, filters, tierOptions, summary }) {
    const handleFilterChange = (key, value) => {
        router.get(
            route("members.index"),
            { ...filters, [key]: value },
            { preserveState: true, replace: true }
        );
    };

    const summaryCards = [
        {
            label: "Total Member",
            value: summary?.total_members || 0,
            helper: "Seluruh member yang pernah terdaftar",
        },
        {
            label: "Member Aktif",
            value: summary?.active_members || 0,
            helper: "Masih menerima benefit member",
        },
        {
            label: "Omzet Member",
            value: formatCurrency(summary?.member_revenue || 0),
            helper: "Kontribusi transaksi dari member",
        },
        {
            label: "Repeat Rate",
            value: `${summary?.repeat_rate || 0}%`,
            helper:
                summary?.top_member?.name
                    ? `Top member: ${summary.top_member.name}`
                    : "Belum ada top member",
        },
    ];

    return (
        <>
            <Head title="Member" />

            <div className="w-full">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                            Member
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Kelola pendaftaran, status, dan performa member tanpa memisahkan data dari customer inti.
                        </p>
                    </div>
                    <Link
                        href={route("members.create")}
                        className="inline-flex items-center gap-2 rounded-xl bg-primary-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-primary-500/30 transition hover:bg-primary-600"
                    >
                        <IconCirclePlus size={18} />
                        Daftarkan Member
                    </Link>
                </div>

                <div className="mb-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {summaryCards.map((card) => (
                        <div
                            key={card.label}
                            className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"
                        >
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {card.label}
                            </p>
                            <p className="mt-2 text-2xl font-bold text-slate-900 dark:text-white">
                                {card.value}
                            </p>
                            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                {card.helper}
                            </p>
                        </div>
                    ))}
                </div>

                <div className="mb-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-3 md:grid-cols-4">
                        <div className="relative md:col-span-2">
                            <input
                                type="text"
                                value={filters.search || ""}
                                onChange={(event) =>
                                    handleFilterChange("search", event.target.value)
                                }
                                placeholder="Cari nama member atau nomor anggota..."
                                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 pr-11 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                            />
                            <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400">
                                <IconSearch size={18} />
                            </div>
                        </div>

                        <select
                            value={filters.tier || ""}
                            onChange={(event) =>
                                handleFilterChange("tier", event.target.value)
                            }
                            className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        >
                            <option value="">Semua Tier</option>
                            {tierOptions.map((tier) => (
                                <option key={tier.value} value={tier.value}>
                                    {tier.label}
                                </option>
                            ))}
                        </select>

                        <select
                            value={filters.status || "active"}
                            onChange={(event) =>
                                handleFilterChange("status", event.target.value)
                            }
                            className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        >
                            <option value="active">Member Aktif</option>
                            <option value="inactive">Member Nonaktif</option>
                            <option value="all">Semua Status</option>
                        </select>
                    </div>
                </div>

                <Table.Card title="Daftar Member">
                    <Table>
                        <Table.Thead>
                            <tr>
                                <Table.Th>Member</Table.Th>
                                <Table.Th>Tier</Table.Th>
                                <Table.Th>Poin</Table.Th>
                                <Table.Th>Total Belanja</Table.Th>
                                <Table.Th>Transaksi</Table.Th>
                                <Table.Th>Terakhir Belanja</Table.Th>
                                <Table.Th className="w-28 text-center">
                                    Aksi
                                </Table.Th>
                            </tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {members.data.length > 0 ? (
                                members.data.map((member) => (
                                    <tr key={member.id}>
                                        <Table.Td>
                                            <Link
                                                href={route("members.show", member.id)}
                                                className="font-semibold text-slate-800 hover:text-primary-600 dark:text-slate-100"
                                            >
                                                {member.name}
                                            </Link>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                                {member.member_code || "Belum ada nomor anggota"}
                                            </p>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                                {member.no_telp || "-"}
                                            </p>
                                        </Table.Td>
                                        <Table.Td>
                                            <span className="inline-flex rounded-full bg-primary-100 px-2.5 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">
                                                {member.loyalty_tier || "regular"}
                                            </span>
                                            <p className="mt-2 text-[11px] text-slate-500 dark:text-slate-400">
                                                {member.is_loyalty_member
                                                    ? "Aktif"
                                                    : "Nonaktif"}
                                            </p>
                                        </Table.Td>
                                        <Table.Td>{member.loyalty_points || 0}</Table.Td>
                                        <Table.Td>
                                            {formatCurrency(
                                                member.loyalty_total_spent || 0
                                            )}
                                        </Table.Td>
                                        <Table.Td>
                                            {member.loyalty_transaction_count || 0}
                                        </Table.Td>
                                        <Table.Td>
                                            {formatDate(member.last_purchase_at)}
                                        </Table.Td>
                                        <Table.Td className="text-center">
                                            <Link
                                                href={route("members.edit", member.id)}
                                                className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-amber-200 bg-amber-50 text-amber-600 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
                                            >
                                                <IconPencil size={16} />
                                            </Link>
                                        </Table.Td>
                                    </tr>
                                ))
                            ) : (
                                <Table.Empty
                                    colSpan={7}
                                    message="Belum ada member yang sesuai dengan filter."
                                >
                                    <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                        <IconUsers size={28} className="text-slate-400" />
                                    </div>
                                </Table.Empty>
                            )}
                        </Table.Tbody>
                    </Table>
                </Table.Card>

                {summary?.top_member ? (
                    <div className="mt-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center gap-3">
                            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-100 text-primary-600 dark:bg-primary-950/40 dark:text-primary-300">
                                <IconCrown size={20} />
                            </div>
                            <div>
                                <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                    Top Member by Spending
                                </p>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    {summary.top_member.name} • {formatCurrency(summary.top_member.total_spent)}
                                </p>
                            </div>
                        </div>
                    </div>
                ) : null}

                {members.last_page > 1 ? <Pagination links={members.links} /> : null}

                <div className="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900/60">
                    <div className="flex items-start gap-3">
                        <div className="rounded-xl bg-white p-2 text-slate-500 dark:bg-slate-800 dark:text-slate-300">
                            <IconFileSearch size={18} />
                        </div>
                        <div>
                            <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                Bantuan cepat
                            </p>
                            <p className="mt-1 text-xs leading-6 text-slate-500 dark:text-slate-400">
                                Daftarkan member baru dari halaman ini atau langsung dari POS. Untuk upgrade pelanggan biasa menjadi member, gunakan tombol upgrade di detail pelanggan atau picker pelanggan di POS.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
