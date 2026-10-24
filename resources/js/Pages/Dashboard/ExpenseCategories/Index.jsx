import React, { useEffect } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, usePage } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Search from "@/Components/Dashboard/Search";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import toast from "react-hot-toast";
import {
    IconCirclePlus,
    IconDatabaseOff,
    IconPencilCog,
    IconTrash,
    IconFolder,
} from "@tabler/icons-react";
import { useAuthorization } from "@/Utils/authorization";

export default function Index({ categories }) {
    const { flash } = usePage();
    const { can } = useAuthorization();
    const canCreate = can("expense-categories-create");
    const canEdit = can("expense-categories-edit");
    const canDelete = can("expense-categories-delete");

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    return (
        <>
            <Head title="Kategori Beban" />

            <div className="mb-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconFolder size={28} className="text-primary-500" />
                    Kategori Beban
                </h1>
                {canCreate && (
                    <Link
                        href={route("expense-categories.create")}
                        className="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700"
                    >
                        <IconCirclePlus size={18} />
                        Tambah Kategori
                    </Link>
                )}
            </div>

            <div className="mb-4 w-full sm:w-80">
                <Search
                    url={route("expense-categories.index")}
                    placeholder="Cari kategori..."
                />
            </div>

            <Table.Card icon={<IconFolder size={18} />} title="Daftar Kategori Beban">
                <Table>
                    <Table.Thead>
                        <tr>
                            <Table.Th>Nama</Table.Th>
                            <Table.Th>Kode Akun</Table.Th>
                            <Table.Th>Status</Table.Th>
                            <Table.Th>Jumlah Beban</Table.Th>
                            {(canEdit || canDelete) && <Table.Th>Aksi</Table.Th>}
                        </tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {categories.data?.length ? (
                            categories.data.map((category) => (
                                <tr key={category.id} className="border-b border-slate-100 last:border-0 dark:border-slate-800">
                                    <Table.Td className="font-medium">{category.name}</Table.Td>
                                    <Table.Td>{category.account_code || "-"}</Table.Td>
                                    <Table.Td>
                                        <span
                                            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
                                                category.is_active
                                                    ? "bg-success-100 text-success-700 dark:bg-success-950/40 dark:text-success-400"
                                                    : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400"
                                            }`}
                                        >
                                            {category.is_active ? "Aktif" : "Nonaktif"}
                                        </span>
                                    </Table.Td>
                                    <Table.Td>{category.expenses_count ?? 0}</Table.Td>
                                    {(canEdit || canDelete) && (
                                        <Table.Td>
                                            <div className="flex items-center gap-2">
                                                {canEdit && (
                                                    <Link
                                                        href={route("expense-categories.edit", category.id)}
                                                        className="p-2 rounded-xl text-warning-600 hover:bg-warning-50"
                                                    >
                                                        <IconPencilCog size={18} />
                                                    </Link>
                                                )}
                                                {canDelete && (
                                                    <Button
                                                        type={"delete"}
                                                        icon={<IconTrash size={18} />}
                                                        className={"p-2 rounded-xl text-danger-600 hover:bg-danger-50"}
                                                        url={route("expense-categories.destroy", category.id)}
                                                    />
                                                )}
                                            </div>
                                        </Table.Td>
                                    )}
                                </tr>
                            ))
                        ) : (
                            <Table.Empty colSpan={5} message="Belum ada kategori beban.">
                                <IconDatabaseOff size={48} className="text-slate-300 dark:text-slate-600 mx-auto" />
                            </Table.Empty>
                        )}
                    </Table.Tbody>
                </Table>
            </Table.Card>

            <div className="mt-6">
                <Pagination links={categories.links} />
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
