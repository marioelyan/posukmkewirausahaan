import React, { useMemo, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import { useAuthorization } from "@/Utils/authorization";
import {
    IconCashBanknote,
    IconClockHour4,
    IconEye,
    IconHistory,
    IconPrinter,
    IconUser,
} from "@tabler/icons-react";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const formatDateTime = (value) => {
    if (!value) return "-";

    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "medium",
        timeStyle: "short",
    }).format(new Date(value));
};

const kindOptions = {
    in: [
        { value: "modal", label: "Modal" },
        { value: "setoran_masuk", label: "Setoran masuk" },
        { value: "talangan_masuk", label: "Talangan masuk" },
        { value: "lainnya", label: "Lainnya" },
    ],
    out: [
        { value: "beban", label: "Beban (jadi pengeluaran)" },
        { value: "setoran", label: "Setoran" },
        { value: "talangan", label: "Talangan" },
        { value: "pelunasan_hutang", label: "Pelunasan hutang" },
        { value: "lainnya", label: "Lainnya" },
    ],
};

export default function Index({
    shifts,
    filters,
    cashiers = [],
    activeShift = null,
    warehouses = [],
    expenseCategories = [],
    bankAccounts = [],
    openPayables = [],
}) {
    const { auth, errors } = usePage().props;
    const { can } = useAuthorization();
    const [openingCash, setOpeningCash] = useState("");
    const [notes, setNotes] = useState("");
    const [warehouseId, setWarehouseId] = useState(warehouses.length > 0 ? warehouses[0].id : "");
    const [cashModal, setCashModal] = useState(null); // 'in' | 'out' | null
    const [cashAmount, setCashAmount] = useState("");
    const [cashNote, setCashNote] = useState("");
    const [cashKind, setCashKind] = useState("lainnya");
    const [cashCategoryId, setCashCategoryId] = useState("");
    const [cashPayableId, setCashPayableId] = useState("");
    const [cashSetoranDestination, setCashSetoranDestination] = useState("");
    const [cashBankAccountId, setCashBankAccountId] = useState("");

    const openCashModal = (type) => {
        setCashKind("lainnya");
        setCashCategoryId("");
        setCashPayableId("");
        setCashSetoranDestination("");
        setCashBankAccountId("");
        setCashModal(type);
    };
    const canOpenShift = can("cashier-shifts-open");

    const currentFilters = useMemo(
        () => ({
            cashier_id: filters?.cashier_id || "",
            status: filters?.status || "",
            opened_from: filters?.opened_from || "",
            opened_to: filters?.opened_to || "",
        }),
        [filters]
    );

    const handleFilterChange = (key, value) => {
        router.get(
            route("cashier-shifts.index"),
            {
                ...currentFilters,
                [key]: value,
            },
            {
                preserveState: true,
                replace: true,
            }
        );
    };

    const handleOpenShift = (event) => {
        event.preventDefault();

        const payload = {
            opening_cash: Number(openingCash || 0),
            notes,
        };
        if (warehouseId) {
            payload.warehouse_id = warehouseId;
        }

        router.post(route("cashier-shifts.store"), payload);
    };

    const handleCashMovement = (event) => {
        event.preventDefault();

        const payload = {
            type: cashModal,
            kind: cashKind,
            amount: Number(cashAmount || 0),
            note: cashNote,
        };
        if (cashModal === "out" && cashKind === "beban" && cashCategoryId) {
            payload.expense_category_id = cashCategoryId;
        }
        if (cashModal === "out" && cashKind === "pelunasan_hutang" && cashPayableId) {
            payload.payable_id = cashPayableId;
        }
        if (cashModal === "out" && cashKind === "setoran" && cashSetoranDestination) {
            payload.setoran_destination = cashSetoranDestination;
        }
        if (
            cashModal === "out" &&
            cashKind === "setoran" &&
            cashSetoranDestination === "bank" &&
            cashBankAccountId
        ) {
            payload.bank_account_id = cashBankAccountId;
        }

        router.post(
            route("cashier-shifts.cash-movements.store", activeShift.id),
            payload,
            {
                onSuccess: () => {
                    setCashModal(null);
                    setCashAmount("");
                    setCashNote("");
                    setCashKind("lainnya");
                    setCashCategoryId("");
                    setCashPayableId("");
                    setCashSetoranDestination("");
                    setCashBankAccountId("");
                },
            }
        );
    };

    return (
        <>
            <Head title="Shift Kasir" />

            <div className="space-y-6">
                <div
                    data-tour="shifts-header"
                    className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
                >
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900 dark:text-white">
                            <IconHistory size={28} className="text-primary-500" />
                            Shift Kasir
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Buka shift, pantau shift aktif, dan review cash closing.
                        </p>
                    </div>
                    {activeShift && (
                        <Link
                            href={route("cashier-shifts.show", activeShift.id)}
                            className="inline-flex items-center gap-2 rounded-xl bg-primary-500 px-4 py-2.5 text-sm font-medium text-white shadow-lg shadow-primary-500/30 transition-colors hover:bg-primary-600"
                        >
                            <IconEye size={18} />
                            <span>Lihat Shift Aktif</span>
                        </Link>
                    )}
                </div>

                {!activeShift && canOpenShift && (
                    <div
                        data-tour="shifts-open"
                        className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
                    >
                        <div className="mb-4">
                            <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
                                Buka Shift Baru
                            </h2>
                            <p className="text-sm text-slate-500 dark:text-slate-400">
                                Shift aktif diperlukan sebelum kasir dapat memproses transaksi.
                            </p>
                        </div>

                        <form onSubmit={handleOpenShift} className="grid gap-4 md:grid-cols-4">
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Modal Awal
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    value={openingCash}
                                    onChange={(event) => setOpeningCash(event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                    placeholder="0"
                                />
                                {errors?.opening_cash && (
                                    <p className="mt-2 text-xs text-danger-500">{errors.opening_cash}</p>
                                )}
                            </div>
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Gudang / Cabang
                                </label>
                                <select
                                    value={warehouseId}
                                    onChange={(event) => setWarehouseId(event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                >
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.code} — {w.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="md:col-span-2">
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Catatan
                                </label>
                                <input
                                    type="text"
                                    value={notes}
                                    onChange={(event) => setNotes(event.target.value)}
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                    placeholder="Opsional"
                                />
                            </div>
                            <div className="md:col-span-4">
                                <button
                                    type="submit"
                                    className="inline-flex items-center gap-2 rounded-xl bg-primary-500 px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-primary-600"
                                >
                                    <IconCashBanknote size={18} />
                                    <span>Buka Shift</span>
                                </button>
                            </div>
                        </form>
                    </div>
                )}

                {activeShift && (
                    <div data-tour="shifts-active" className="grid gap-4 md:grid-cols-4">
                        <div className="rounded-2xl border border-success-200 bg-success-50 p-4 dark:border-success-900/50 dark:bg-success-950/30">
                            <p className="text-xs font-semibold uppercase tracking-wide text-success-700 dark:text-success-300">
                                Shift Aktif
                            </p>
                            <p className="mt-2 text-lg font-semibold text-success-900 dark:text-success-100">
                                {activeShift.user?.name}
                            </p>
                            <p className="mt-1 text-sm text-success-700 dark:text-success-300">
                                {activeShift.warehouse?.name || formatDateTime(activeShift.opened_at)}
                            </p>
                            {activeShift.warehouse && (
                                <p className="text-xs text-success-600 dark:text-success-400">
                                    {activeShift.warehouse.code}
                                </p>
                            )}
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                Modal Awal
                            </p>
                            <p className="mt-2 text-lg font-semibold text-slate-900 dark:text-white">
                                {formatCurrency(activeShift.opening_cash)}
                            </p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                Expected Cash
                            </p>
                            <p className="mt-2 text-lg font-semibold text-slate-900 dark:text-white">
                                {formatCurrency(activeShift.expected_cash)}
                            </p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                Total Transaksi
                            </p>
                            <p className="mt-2 text-lg font-semibold text-slate-900 dark:text-white">
                                {activeShift.transactions_count}
                            </p>
                        </div>
                        <div className="flex items-center justify-end gap-2 md:col-span-4">
                            <button
                                type="button"
                                onClick={() =>
                                    window.open(
                                        route("cashier-shifts.report", {
                                            cashierShift: activeShift.id,
                                            type: "x",
                                        }),
                                        "_blank"
                                    )
                                }
                                className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                <IconPrinter size={18} />
                                <span>Cetak Laporan X</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => openCashModal("in")}
                                className="inline-flex items-center gap-2 rounded-xl border border-success-200 px-4 py-2.5 text-sm font-medium text-success-700 transition-colors hover:bg-success-50 dark:border-success-900/50 dark:text-success-300 dark:hover:bg-success-950/30"
                            >
                                <IconCashBanknote size={18} />
                                <span>Kas Masuk</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => openCashModal("out")}
                                className="inline-flex items-center gap-2 rounded-xl border border-danger-200 px-4 py-2.5 text-sm font-medium text-danger-700 transition-colors hover:bg-danger-50 dark:border-danger-900/50 dark:text-danger-300 dark:hover:bg-danger-950/30"
                            >
                                <IconCashBanknote size={18} />
                                <span>Kas Keluar</span>
                            </button>
                        </div>
                    </div>
                )}

                {cashModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                        <div
                            className="absolute inset-0 bg-slate-900/60"
                            onClick={() => setCashModal(null)}
                        />
                        <form
                            onSubmit={handleCashMovement}
                            className="relative w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl dark:bg-slate-900"
                        >
                            <h3 className="text-lg font-bold text-slate-800 dark:text-white">
                                {cashModal === "in" ? "Catat Kas Masuk" : "Catat Kas Keluar"}
                            </h3>
                            <div className="mt-4 space-y-3">
                                <div>
                                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Jenis
                                    </label>
                                    <select
                                        value={cashKind}
                                        onChange={(event) => setCashKind(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                        required
                                    >
                                        {kindOptions[cashModal].map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    {errors.kind && (
                                        <p className="mt-1 text-sm text-danger-600">{errors.kind}</p>
                                    )}
                                </div>
                                {cashModal === "out" && cashKind === "pelunasan_hutang" && (
                                    <div>
                                        <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                            Hutang yang Dibayar
                                        </label>
                                        <select
                                            value={cashPayableId}
                                            onChange={(event) => setCashPayableId(event.target.value)}
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                            required
                                        >
                                            <option value="">Pilih hutang</option>
                                            {openPayables.map((payable) => (
                                                <option key={payable.id} value={payable.id}>
                                                    {payable.supplier?.name} — {payable.document_number} (sisa {new Intl.NumberFormat("id-ID").format(payable.remaining ?? payable.total - payable.paid)})
                                                </option>
                                            ))}
                                        </select>
                                        {errors.payable_id && (
                                            <p className="mt-1 text-sm text-danger-600">{errors.payable_id}</p>
                                        )}
                                        <p className="mt-1 text-xs text-slate-400">
                                            Uang keluar dari laci sekaligus mengurangi sisa hutang ini. Bukan beban, jadi laba tidak berubah.
                                        </p>
                                    </div>
                                )}
                                {cashModal === "out" && cashKind === "setoran" && (
                                    <>
                                        <div>
                                            <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                                Tujuan Setoran
                                            </label>
                                            <select
                                                value={cashSetoranDestination}
                                                onChange={(event) =>
                                                    setCashSetoranDestination(event.target.value)
                                                }
                                                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                                required
                                            >
                                                <option value="">Pilih tujuan</option>
                                                <option value="bank">Rekening Bank</option>
                                                <option value="owner">Pemilik (Prive)</option>
                                            </select>
                                            {errors.setoran_destination && (
                                                <p className="mt-1 text-sm text-danger-600">
                                                    {errors.setoran_destination}
                                                </p>
                                            )}
                                        </div>
                                        {cashSetoranDestination === "bank" && (
                                            <div>
                                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                                    Rekening Bank
                                                </label>
                                                <select
                                                    value={cashBankAccountId}
                                                    onChange={(event) =>
                                                        setCashBankAccountId(event.target.value)
                                                    }
                                                    className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                                    required
                                                >
                                                    <option value="">Pilih rekening</option>
                                                    {bankAccounts.map((account) => (
                                                        <option key={account.id} value={account.id}>
                                                            {account.bank_name} — {account.account_number} (
                                                            {account.account_name})
                                                        </option>
                                                    ))}
                                                </select>
                                                {errors.bank_account_id && (
                                                    <p className="mt-1 text-sm text-danger-600">
                                                        {errors.bank_account_id}
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                    </>
                                )}
                                {cashModal === "out" && cashKind === "beban" && (
                                    <div>
                                        <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                            Kategori Beban
                                        </label>
                                        <select
                                            value={cashCategoryId}
                                            onChange={(event) => setCashCategoryId(event.target.value)}
                                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                            required
                                        >
                                            <option value="">Pilih kategori beban</option>
                                            {expenseCategories.map((category) => (
                                                <option key={category.id} value={category.id}>
                                                    {category.name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.expense_category_id && (
                                            <p className="mt-1 text-sm text-danger-600">{errors.expense_category_id}</p>
                                        )}
                                        <p className="mt-1 text-xs text-slate-400">
                                            Beban dari laci langsung tercatat sebagai pengeluaran pada kategori ini.
                                        </p>
                                    </div>
                                )}
                                <div>
                                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Nominal
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        value={cashAmount}
                                        onChange={(event) => setCashAmount(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                        placeholder="0"
                                        required
                                        autoFocus
                                    />
                                </div>
                                <div>
                                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Keterangan (opsional)
                                    </label>
                                    <input
                                        type="text"
                                        value={cashNote}
                                        onChange={(event) => setCashNote(event.target.value)}
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                        placeholder={
                                            cashModal === "in"
                                                ? "Misal: setoran tambahan modal"
                                                : "Misal: beli galon, kirim uang ke kasir pusat"
                                        }
                                        maxLength={255}
                                    />
                                </div>
                            </div>
                            <div className="mt-6 flex gap-2">
                                <button
                                    type="button"
                                    onClick={() => setCashModal(null)}
                                    className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    className={`flex-1 rounded-xl py-2.5 text-sm font-medium text-white transition-colors ${
                                        cashModal === "in"
                                            ? "bg-success-500 hover:bg-success-600"
                                            : "bg-danger-500 hover:bg-danger-600"
                                    }`}
                                >
                                    Simpan
                                </button>
                            </div>
                        </form>
                    </div>
                )}

                <div
                    data-tour="shifts-filters"
                    className="grid grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900 md:grid-cols-4"
                >
                    {cashiers.length > 1 ? (
                        <select
                            value={currentFilters.cashier_id}
                            onChange={(event) => handleFilterChange("cashier_id", event.target.value)}
                            className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                        >
                            <option value="">Semua Kasir</option>
                            {cashiers.map((cashier) => (
                                <option key={cashier.id} value={cashier.id}>
                                    {cashier.name}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <div className="flex h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                            <IconUser size={18} className="mr-2" />
                            {cashiers[0]?.name || auth?.user?.name}
                        </div>
                    )}
                    <select
                        value={currentFilters.status}
                        onChange={(event) => handleFilterChange("status", event.target.value)}
                        className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    >
                        <option value="">Semua Status</option>
                        <option value="open">Open</option>
                        <option value="closed">Closed</option>
                        <option value="force_closed">Force Closed</option>
                    </select>
                    <input
                        type="date"
                        value={currentFilters.opened_from}
                        onChange={(event) => handleFilterChange("opened_from", event.target.value)}
                        className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    />
                    <input
                        type="date"
                        value={currentFilters.opened_to}
                        onChange={(event) => handleFilterChange("opened_to", event.target.value)}
                        className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm text-slate-800 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    />
                </div>

                <div data-tour="shifts-history">
                    <Table.Card title="Histori Shift Kasir">
                    <Table>
                        <Table.Thead>
                            <tr>
                                <Table.Th>Kasir</Table.Th>
                                <Table.Th>Status</Table.Th>
                                <Table.Th>Buka</Table.Th>
                                <Table.Th>Tutup</Table.Th>
                                <Table.Th>Expected Cash</Table.Th>
                                <Table.Th>Kas Masuk</Table.Th>
                                <Table.Th>Kas Keluar</Table.Th>
                                <Table.Th>Selisih</Table.Th>
                                <Table.Th className="w-24 text-center">Aksi</Table.Th>
                            </tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {shifts.data.length > 0 ? (
                                shifts.data.map((shift) => (
                                    <tr key={shift.id} className="transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                        <Table.Td>
                                            <div>
                                                <p className="font-semibold text-slate-800 dark:text-slate-200">
                                                    {shift.user?.name || "-"}
                                                </p>
                                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                                    Modal {formatCurrency(shift.opening_cash)}
                                                </p>
                                            </div>
                                        </Table.Td>
                                        <Table.Td>
                                            <span
                                                className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${
                                                    shift.status === "open"
                                                        ? "bg-success-100 text-success-700 dark:bg-success-950/40 dark:text-success-400"
                                                        : shift.status === "force_closed"
                                                          ? "bg-danger-100 text-danger-700 dark:bg-danger-950/40 dark:text-danger-400"
                                                          : "bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                                                }`}
                                            >
                                                {shift.status === "open"
                                                    ? "Open"
                                                    : shift.status === "force_closed"
                                                      ? "Force Closed"
                                                      : "Closed"}
                                            </span>
                                        </Table.Td>
                                        <Table.Td>{formatDateTime(shift.opened_at)}</Table.Td>
                                        <Table.Td>{formatDateTime(shift.closed_at)}</Table.Td>
                                        <Table.Td>{formatCurrency(shift.expected_cash)}</Table.Td>
                                        <Table.Td>{formatCurrency(shift.cash_in_total)}</Table.Td>
                                        <Table.Td>{formatCurrency(shift.cash_out_total)}</Table.Td>
                                        <Table.Td>
                                            {shift.cash_difference === null
                                                ? "-"
                                                : formatCurrency(shift.cash_difference)}
                                        </Table.Td>
                                        <Table.Td className="text-center">
                                            <Link
                                                href={route("cashier-shifts.show", shift.id)}
                                                className="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-2 text-slate-600 transition hover:border-primary-300 hover:text-primary-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-primary-700 dark:hover:text-primary-400"
                                            >
                                                <IconEye size={18} />
                                            </Link>
                                        </Table.Td>
                                    </tr>
                                ))
                            ) : (
                                <Table.Empty
                                    colSpan={9}
                                    message={
                                        <div className="text-slate-500 dark:text-slate-400">
                                            Belum ada histori shift kasir.
                                        </div>
                                    }
                                >
                                    <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                        <IconClockHour4 size={28} className="text-slate-400" />
                                    </div>
                                </Table.Empty>
                            )}
                        </Table.Tbody>
                    </Table>
                </Table.Card>
                </div>

                {shifts.last_page > 1 && <Pagination links={shifts.links} />}
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
