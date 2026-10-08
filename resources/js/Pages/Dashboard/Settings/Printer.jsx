import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm } from "@inertiajs/react";
import toast from "react-hot-toast";
import { IconPrinter } from "@tabler/icons-react";
import {
    requestPrinter,
    disconnectPrinter,
    printReceipt,
    kickDrawer,
} from "@/Utils/escpos";

export default function Printer({ settings }) {
    const { data, setData, post, processing, errors } = useForm({
        printer_auto_print: settings.printer_auto_print || false,
        printer_paper_size: settings.printer_paper_size || "80mm",
    });
    const [printerConnected, setPrinterConnected] = useState(false);
    const [printerBusy, setPrinterBusy] = useState(false);

    const handleConnect = async () => {
        setPrinterBusy(true);
        try {
            await requestPrinter();
            setPrinterConnected(true);
            toast.success("Printer terhubung");
        } catch (e) {
            toast.error(e.message || "Gagal menghubungkan printer");
        } finally {
            setPrinterBusy(false);
        }
    };

    const handleTestPrint = async () => {
        setPrinterBusy(true);
        try {
            await printReceipt(
                {
                    store_name: "TEST PRINT",
                    invoice: "TEST-001",
                    created_at: new Date().toLocaleString("id-ID"),
                    items: [{ qty: 1, name: "Produk Uji Cetak", price: 1000 }],
                    money: (v) => `Rp${Number(v || 0).toLocaleString("id-ID")}`,
                    subtotal: 1000,
                    discount_total: 0,
                    tax_total: 0,
                    shipping_cost: 0,
                    grand_total: 1000,
                    payment_method_label: "Tunai",
                    cash_received: 1000,
                    change: 0,
                },
                data.printer_paper_size
            );
            toast.success("Test print terkirim");
        } catch (e) {
            toast.error(e.message || "Gagal test print");
        } finally {
            setPrinterBusy(false);
        }
    };

    const handleKickDrawer = async () => {
        setPrinterBusy(true);
        try {
            await kickDrawer();
            toast.success("Drawer dikick");
        } catch (e) {
            toast.error(e.message || "Gagal kick drawer");
        } finally {
            setPrinterBusy(false);
        }
    };

    const handleDisconnect = async () => {
        await disconnectPrinter();
        setPrinterConnected(false);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route("settings.printer.update"), {
            preserveScroll: true,
            onSuccess: () => toast.success("Pengaturan printer disimpan"),
            onError: () => toast.error("Gagal menyimpan"),
        });
    };

    return (
        <>
            <Head title="Pengaturan Printer" />
            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <IconPrinter size={28} className="text-primary-500" />
                        Pengaturan Printer
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">Atur printer thermal untuk cetak struk otomatis</p>
                </div>

                <form onSubmit={submit} className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-6 max-w-lg">
                    <div>
                        <label className="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Ukuran Kertas</label>
                        <select value={data.printer_paper_size} onChange={(e) => setData("printer_paper_size", e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm">
                            <option value="80mm">80 mm</option>
                            <option value="58mm">58 mm</option>
                        </select>
                    </div>

                    <label className="flex items-center gap-3 text-sm text-slate-700 dark:text-slate-300 cursor-pointer">
                        <input type="checkbox" checked={data.printer_auto_print} onChange={(e) => setData("printer_auto_print", e.target.checked)}
                            className="rounded border-slate-300 text-primary-600 focus:ring-primary-500" />
                        Cetak otomatis setelah transaksi
                    </label>

                    <div className="pt-4 border-t border-slate-100 dark:border-slate-800 space-y-3">
                        <p className="text-sm text-slate-500">Printer thermal terhubung via WebUSB (Chrome/Edge)</p>
                        <div className="flex flex-wrap gap-2">
                            {!printerConnected ? (
                                <button type="button" onClick={handleConnect} disabled={printerBusy}
                                    className="px-4 py-2 rounded-xl border border-primary-500 text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-950/30 text-sm font-medium disabled:opacity-50">
                                    Hubungkan Printer
                                </button>
                            ) : (
                                <>
                                    <button type="button" onClick={handleTestPrint} disabled={printerBusy}
                                        className="px-4 py-2 rounded-xl border border-primary-500 text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-950/30 text-sm font-medium disabled:opacity-50">
                                        Test Print
                                    </button>
                                    <button type="button" onClick={handleKickDrawer} disabled={printerBusy}
                                        className="px-4 py-2 rounded-xl border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 text-sm font-medium disabled:opacity-50">
                                        Buka Laci (Kick)
                                    </button>
                                    <button type="button" onClick={handleDisconnect}
                                        className="px-4 py-2 rounded-xl border border-danger-300 text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-950/30 text-sm font-medium">
                                        Putuskan
                                    </button>
                                </>
                            )}
                        </div>
                        <button type="submit" disabled={processing}
                            className="px-5 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium transition-colors disabled:opacity-50">
                            {processing ? "Menyimpan..." : "Simpan Pengaturan"}
                        </button>
                    </div>
                </form>
            </div>
        </>
    );
}

Printer.layout = (page) => <DashboardLayout children={page} />;
