import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, router } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Table from "@/Components/Dashboard/Table";
import { useAuthorization } from "@/Utils/authorization";
import {
    IconArrowLeft,
    IconCheck,
    IconCircleX,
    IconTruckReturn,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const formatDateTime = (value) =>
    value
        ? new Intl.DateTimeFormat("id-ID", {
              dateStyle: "medium",
              timeStyle: "short",
          }).format(new Date(value))
        : "-";

const statusBadge = (status) => {
    const base = "inline-flex rounded-full px-2.5 py-1 text-xs font-semibold";
    const map = {
        draft: "bg-warning-100 text-warning-700 dark:bg-warning-950/30 dark:text-warning-400",
        completed: "bg-success-100 text-success-700 dark:bg-success-950/30 dark:text-success-400",
        cancelled: "bg-rose-100 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400",
    };
    const labels = {
        draft: "Draft",
        completed: "Selesai",
        cancelled: "Dibatalkan",
    };
    return <span className={`${base} ${map[status] || map.draft}`}>{labels[status] || status}</span>;
};

export default function Show({ return: ret }) {
    const { can } = useAuthorization();
    const canEdit = can("supplier-returns-update");

    const completeReturn = () => {
        router.post(route("supplier-returns.complete", ret.id), {}, {
            preserveScroll: true,
            onSuccess: () => toast.success("Retur supplier berhasil diselesaikan"),
            onError: () => toast.error("Gagal menyelesaikan retur"),
        });
    };

    const cancelReturn = () => {
        router.post(route("supplier-returns.cancel", ret.id), {}, {
            preserveScroll: true,
            onSuccess: () => toast.success("Retur supplier dibatalkan"),
            onError: () => toast.error("Gagal membatalkan retur"),
        });
    };

    const total = ret.items?.reduce((sum, item) => sum + item.qty_returned * item.unit_price, 0) || 0;

    return (
        <>
            <Head title={ret.document_number} />
            <div className="mb-6">
                <Link
                    href={route("supplier-returns.index")}
                    className="mb-3 inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke daftar retur
                </Link>
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div className="mb-2 flex items-center gap-2">
                            <h1 className="text-2xl font-bold text-slate-900 dark:text-white">{ret.document_number}</h1>
                            {statusBadge(ret.status)}
                        </div>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Supplier: {ret.supplier?.name || "-"}
                            &bull; Dibuat oleh {ret.creator?.name || "-"}
                            &bull; {formatDateTime(ret.created_at)}
                        </p>
                        {ret.returned_at && (
                            <p className="text-sm text-slate-500">
                                Diselesaikan: {formatDateTime(ret.returned_at)}
                            </p>
                        )}
                    </div>
                    <div className="flex gap-2">
                        {ret.status === "draft" && canEdit && (
                            <>
                                <Button
                                    type="button"
                                    icon={<IconCheck size={18} />}
                                    className="bg-success-500 hover:bg-success-600 text-white"
                                    label="Selesaikan Retur"
                                    onClick={completeReturn}
                                />
                                <Button
                                    type="button"
                                    icon={<IconCircleX size={18} />}
                                    className="bg-rose-500 hover:bg-rose-600 text-white"
                                    label="Batalkan"
                                    onClick={cancelReturn}
                                />
                            </>
                        )}
                    </div>
                </div>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.7fr_1fr]">
                <div className="space-y-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Item Retur</h2>
                        <Table>
                            <Table.Thead>
                                <tr>
                                    <Table.Th>Produk</Table.Th>
                                    <Table.Th>Qty Retur</Table.Th>
                                    <Table.Th>Harga</Table.Th>
                                    <Table.Th>Subtotal</Table.Th>
                                    <Table.Th>Alasan</Table.Th>
                                </tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {ret.items?.length > 0 ? (
                                    ret.items.map((item) => (
                                        <tr key={item.id} className="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                            <Table.Td>
                                                <p className="font-medium text-slate-800 dark:text-slate-200">
                                                    {item.product?.title || "Produk #" + item.product_id}
                                                </p>
                                                <p className="text-xs text-slate-500">{item.product?.sku || "-"}</p>
                                            </Table.Td>
                                            <Table.Td className="font-semibold">{item.qty_returned}</Table.Td>
                                            <Table.Td>{formatCurrency(item.unit_price)}</Table.Td>
                                            <Table.Td className="font-semibold">
                                                {formatCurrency(item.qty_returned * item.unit_price)}
                                            </Table.Td>
                                            <Table.Td className="text-xs text-slate-500">{item.reason || "-"}</Table.Td>
                                        </tr>
                                    ))
                                ) : (
                                    <Table.Empty colSpan={5} message={
                                        <div className="text-slate-500 dark:text-slate-400">Tidak ada item.</div>
                                    }>
                                        <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                            <IconTruckReturn size={28} className="text-slate-400" />
                                        </div>
                                    </Table.Empty>
                                )}
                            </Table.Tbody>
                        </Table>
                        {ret.items?.length > 0 && (
                            <div className="mt-4 flex justify-end border-t border-slate-100 pt-4 dark:border-slate-800">
                                <div className="text-right">
                                    <p className="text-sm font-semibold text-slate-500">Total Retur</p>
                                    <p className="text-xl font-bold text-danger-600">{formatCurrency(total)}</p>
                                </div>
                            </div>
                        )}
                    </div>
                </div>

                <div className="space-y-6">
                    {ret.notes && (
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                            <h2 className="mb-3 text-lg font-semibold text-slate-900 dark:text-white">Catatan</h2>
                            <p className="text-sm text-slate-600 dark:text-slate-400">{ret.notes}</p>
                        </div>
                    )}

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-3 text-lg font-semibold text-slate-900 dark:text-white">Informasi</h2>
                        <div className="space-y-2 text-sm">
                            <div className="flex justify-between">
                                <span className="text-slate-500">Dokumen</span>
                                <span className="font-semibold text-slate-800 dark:text-white">{ret.document_number}</span>
                            </div>
                            {ret.goodsReceiving && (
                                <div className="flex justify-between">
                                    <span className="text-slate-500">GR Referensi</span>
                                    <Link
                                        href={route("goods-receivings.show", ret.goodsReceiving.id)}
                                        className="font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                    >
                                        {ret.goodsReceiving.document_number}
                                    </Link>
                                </div>
                            )}
                            {ret.payable && (
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Hutang Supplier</span>
                                    <Link
                                        href={route("payables.show", ret.payable.id)}
                                        className="font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                    >
                                        {formatCurrency(ret.payable.total)} (Sisa: {formatCurrency(ret.payable.total - ret.payable.paid)})
                                    </Link>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <span className="text-slate-500">Supplier</span>
                                <span className="font-semibold text-slate-800 dark:text-white">{ret.supplier?.name || "-"}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-slate-500">Tanggal Dibuat</span>
                                <span className="text-slate-800 dark:text-white">{formatDateTime(ret.created_at)}</span>
                            </div>
                            {ret.returned_at && (
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Tanggal Selesai</span>
                                    <span className="text-slate-800 dark:text-white">{formatDateTime(ret.returned_at)}</span>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

Show.layout = (page) => <DashboardLayout children={page} />;
