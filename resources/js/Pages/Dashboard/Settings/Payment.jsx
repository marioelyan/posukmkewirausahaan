import React, { useEffect, useState } from "react";
import { Head, useForm, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Input from "@/Components/Dashboard/Input";
import Checkbox from "@/Components/Dashboard/Checkbox";
import { useAuthorization } from "@/Utils/authorization";
import {
    IconCreditCard,
    IconDeviceFloppy,
    IconBrandStripe,
    IconCash,
    IconQrcode,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

export default function Payment({
    setting,
    paymentSettingSources = {},
    supportedGateways = [],
    webhookUrls = {},
    webhookWarnings = [],
}) {
    const { flash } = usePage().props;
    const { can } = useAuthorization();
    const canUpdatePaymentSettings = can("payment-settings-update");

    const { data, setData, post, errors, processing } = useForm({
        _method: "PUT",
        default_gateway: setting?.default_gateway ?? "cash",
        bank_transfer_enabled: setting?.bank_transfer_enabled ?? false,
        qris_enabled: setting?.qris_enabled ?? false,
        qris_merchant_name: setting?.qris_merchant_name ?? "",
        qris_image: null,
        remove_qris_image: false,
        midtrans_enabled: setting?.midtrans_enabled ?? false,
        midtrans_server_key: "",
        midtrans_client_key: setting?.midtrans_client_key ?? "",
        midtrans_production: setting?.midtrans_production ?? false,
        xendit_enabled: setting?.xendit_enabled ?? false,
        xendit_secret_key: "",
        xendit_public_key: setting?.xendit_public_key ?? "",
        xendit_callback_token: "",
        xendit_production: setting?.xendit_production ?? false,
    });

    const [qrisPreview, setQrisPreview] = useState(
        setting?.qris_image_url ?? null
    );

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    useEffect(() => {
        setQrisPreview(setting?.qris_image_url ?? null);
    }, [setting?.qris_image_url]);

    const handleQrisImageChange = (event) => {
        const file = event.target.files?.[0] ?? null;

        setData({
            ...data,
            qris_image: file,
            remove_qris_image: false,
        });

        setQrisPreview(
            file ? URL.createObjectURL(file) : setting?.qris_image_url ?? null
        );
    };

    const handleRemoveQrisImage = () => {
        setData({
            ...data,
            qris_image: null,
            remove_qris_image: true,
        });
        setQrisPreview(null);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        // Inertia 3 sends a real PUT for `put()`; PHP does not parse a multipart
        // body on PUT, so a QRIS image upload would reach the controller empty.
        // POST with `_method = PUT` keeps the multipart body parseable.
        post(route("settings.payments.update"), { preserveScroll: true });
    };

    const isGatewaySelectable = (gateway) => {
        if (gateway === "cash") return true;
        if (gateway === "qris") {
            return Boolean(
                data.qris_enabled && (data.qris_image || qrisPreview)
            );
        }
        if (gateway === "midtrans") return data.midtrans_enabled;
        if (gateway === "xendit") return data.xendit_enabled;
        return false;
    };

    const renderSecretHint = (field, keepMessage) => {
        const source = paymentSettingSources?.[field];

        if (!source) {
            return null;
        }

        if (source.managed_by_environment) {
            return (
                <p className="text-xs text-warning-600 dark:text-warning-400">
                    Secret dikelola oleh environment dan tidak bisa diubah dari dashboard.
                </p>
            );
        }

        if (source.configured) {
            return (
                <p className="text-xs text-slate-500 dark:text-slate-400">
                    Tersimpan: <span className="font-medium">{source.masked}</span>. {keepMessage}
                </p>
            );
        }

        return null;
    };

    return (
        <>
            <Head title="Pengaturan Payment" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconCreditCard size={28} className="text-primary-500" />
                    Pengaturan Payment Gateway
                </h1>
                <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Konfigurasi metode pembayaran dan gateway
                </p>
            </div>

            <form onSubmit={handleSubmit} className="max-w-3xl space-y-6">
                {/* Default Gateway */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-4 flex items-center gap-2">
                        <IconCash size={18} />
                        Gateway Default
                    </h3>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mb-4">
                        Gateway pembayaran default yang digunakan kasir saat
                        membuka halaman transaksi.
                    </p>
                    {!canUpdatePaymentSettings && (
                        <div className="mb-4 rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-900/60 dark:bg-warning-950/30 dark:text-warning-300">
                            Anda hanya memiliki akses lihat. Perubahan payment settings memerlukan permission update dan konfirmasi password ulang.
                        </div>
                    )}
                    <div>
                        <label htmlFor="default_gateway" className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                            Pilih Gateway
                        </label>
                        <select
                            id="default_gateway"
                            value={data.default_gateway}
                            onChange={(e) =>
                                setData("default_gateway", e.target.value)
                            }
                            disabled={!canUpdatePaymentSettings}
                            className="w-full h-11 px-4 text-sm rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
                        >
                            {supportedGateways.map((gw) => (
                                <option
                                    key={gw.value}
                                    value={gw.value}
                                    disabled={!isGatewaySelectable(gw.value)}
                                >
                                    {gw.label}
                                    {!isGatewaySelectable(gw.value) &&
                                        " (nonaktif)"}
                                </option>
                            ))}
                        </select>
                        {errors?.default_gateway && (
                            <small className="text-xs text-danger-500 mt-1">
                                {errors.default_gateway}
                            </small>
                        )}
                    </div>
                </div>

                {/* Bank Transfer */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                            🏦 Transfer Bank
                        </h3>
                        <label
                            className={`flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all ${
                                data.bank_transfer_enabled
                                    ? "bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400"
                                    : "bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                            }`}
                        >
                            <Checkbox
                                checked={data.bank_transfer_enabled}
                                onChange={(e) =>
                                    setData(
                                        "bank_transfer_enabled",
                                        e.target.checked
                                    )
                                }
                                disabled={!canUpdatePaymentSettings}
                            />
                            {data.bank_transfer_enabled ? "Aktif" : "Nonaktif"}
                        </label>
                    </div>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mb-4">
                        Pembayaran manual via transfer bank. Kasir akan
                        memasukkan transaksi dengan status pending, kemudian
                        admin mengkonfirmasi setelah dana diterima.
                    </p>
                        <a
                            href={route("settings.bank-accounts.index")}
                        className="inline-flex items-center gap-2 text-sm text-primary-500 hover:text-primary-600 font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 rounded"
                    >
                        Kelola Rekening Bank →
                    </a>
                </div>

                {/* Static QRIS */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                            <IconQrcode size={18} />
                            QRIS Statis
                        </h3>
                        <label
                            className={`flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all ${
                                data.qris_enabled
                                    ? "bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400"
                                    : "bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                            }`}
                        >
                            <Checkbox
                                checked={data.qris_enabled}
                                onChange={(e) =>
                                    setData("qris_enabled", e.target.checked)
                                }
                                disabled={!canUpdatePaymentSettings}
                            />
                            {data.qris_enabled ? "Aktif" : "Nonaktif"}
                        </label>
                    </div>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mb-4">
                        Unggah gambar QRIS merchant milik toko. Pembayaran QRIS
                        dianggap lunas langsung saat checkout, tanpa memanggil
                        gateway dan tanpa webhook.
                    </p>
                    <div
                        className={`space-y-4 ${
                            !data.qris_enabled
                                ? "opacity-50 pointer-events-none"
                                : ""
                        }`}
                    >
                        <Input
                            label="Nama Merchant (opsional)"
                            type="text"
                            value={data.qris_merchant_name}
                            onChange={(e) =>
                                setData("qris_merchant_name", e.target.value)
                            }
                            errors={errors?.qris_merchant_name}
                            placeholder="Nama toko pada QRIS"
                            disabled={!canUpdatePaymentSettings}
                        />
                        <div>
                            <label
                                htmlFor="qris_image"
                                className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2"
                            >
                                Gambar QRIS Merchant
                            </label>
                            <input
                                id="qris_image"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={handleQrisImageChange}
                                disabled={!canUpdatePaymentSettings}
                                className="block w-full text-sm text-slate-600 dark:text-slate-400 file:mr-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary-700 hover:file:bg-primary-100 dark:file:bg-primary-950/40 dark:file:text-primary-300 disabled:opacity-60 disabled:cursor-not-allowed"
                            />
                            {errors?.qris_image && (
                                <small className="text-xs text-danger-500 mt-1">
                                    {errors.qris_image}
                                </small>
                            )}
                        </div>
                        {qrisPreview && (
                            <div className="flex items-center gap-4 rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                                <img
                                    src={qrisPreview}
                                    alt="QRIS Merchant"
                                    className="h-36 w-36 rounded-lg border border-slate-200 bg-white object-contain p-2 dark:border-slate-700"
                                />
                                {canUpdatePaymentSettings && (
                                    <button
                                        type="button"
                                        onClick={handleRemoveQrisImage}
                                        className="text-sm font-medium text-danger-600 hover:text-danger-700 dark:text-danger-400"
                                    >
                                        Hapus gambar
                                    </button>
                                )}
                            </div>
                        )}
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            Gunakan gambar QRIS yang jelas dan hanya milik toko
                            ini. Pastikan pembayaran pelanggan sudah masuk
                            sebelum transaksi disimpan.
                        </p>
                    </div>
                </div>

                {/* Midtrans */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                            <IconBrandStripe size={18} />
                            Midtrans Snap
                        </h3>
                        <label
                            className={`flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all ${
                                data.midtrans_enabled
                                    ? "bg-success-100 dark:bg-success-900/50 text-success-700 dark:text-success-400"
                                    : "bg-slate-100 dark:bg-slate-800 text-slate-500"
                            }`}
                        >
                            <Checkbox
                                checked={data.midtrans_enabled}
                                onChange={(e) =>
                                    setData(
                                        "midtrans_enabled",
                                        e.target.checked
                                    )
                                }
                                disabled={!canUpdatePaymentSettings}
                            />
                            {data.midtrans_enabled ? "Aktif" : "Nonaktif"}
                        </label>
                    </div>
                    <div
                        className={`space-y-4 ${
                            !data.midtrans_enabled
                                ? "opacity-50 pointer-events-none"
                                : ""
                        }`}
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Server Key"
                                type="password"
                                value={data.midtrans_server_key}
                                onChange={(e) =>
                                    setData(
                                        "midtrans_server_key",
                                        e.target.value
                                    )
                                }
                                errors={errors?.midtrans_server_key}
                                placeholder={
                                    paymentSettingSources?.midtrans_server_key?.configured
                                        ? "Kosongkan untuk mempertahankan nilai saat ini"
                                        : "SB-Mid-server-xxx"
                                }
                                disabled={
                                    !canUpdatePaymentSettings ||
                                    paymentSettingSources?.midtrans_server_key?.managed_by_environment
                                }
                            />
                            <Input
                                label="Client Key"
                                type="text"
                                value={data.midtrans_client_key}
                                onChange={(e) =>
                                    setData(
                                        "midtrans_client_key",
                                        e.target.value
                                    )
                                }
                                errors={errors?.midtrans_client_key}
                                placeholder="SB-Mid-client-xxx"
                                disabled={!canUpdatePaymentSettings}
                            />
                        </div>
                        {renderSecretHint(
                            "midtrans_server_key",
                            "Isi ulang hanya jika ingin mengganti secret."
                        )}
                        <label className="flex items-center gap-2 cursor-pointer">
                            <Checkbox
                                checked={data.midtrans_production}
                                onChange={(e) =>
                                    setData(
                                        "midtrans_production",
                                        e.target.checked
                                    )
                                }
                                disabled={!canUpdatePaymentSettings}
                            />
                            <span className="text-sm text-slate-600 dark:text-slate-400">
                                Mode Produksi
                            </span>
                        </label>
                    </div>
                </div>

                {/* Xendit */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
                            <IconCreditCard size={18} />
                            Xendit Invoice
                        </h3>
                        <label
                            className={`flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-medium cursor-pointer transition-all ${
                                data.xendit_enabled
                                    ? "bg-success-100 dark:bg-success-900/50 text-success-700 dark:text-success-400"
                                    : "bg-slate-100 dark:bg-slate-800 text-slate-500"
                            }`}
                        >
                            <Checkbox
                                checked={data.xendit_enabled}
                                onChange={(e) =>
                                    setData("xendit_enabled", e.target.checked)
                                }
                                disabled={!canUpdatePaymentSettings}
                            />
                            {data.xendit_enabled ? "Aktif" : "Nonaktif"}
                        </label>
                    </div>
                    <div
                        className={`space-y-4 ${
                            !data.xendit_enabled
                                ? "opacity-50 pointer-events-none"
                                : ""
                        }`}
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            <Input
                                label="Secret Key"
                                type="password"
                                value={data.xendit_secret_key}
                                onChange={(e) =>
                                    setData("xendit_secret_key", e.target.value)
                                }
                                errors={errors?.xendit_secret_key}
                                placeholder={
                                    paymentSettingSources?.xendit_secret_key?.configured
                                        ? "Kosongkan untuk mempertahankan nilai saat ini"
                                        : "xnd_development_xxx"
                                }
                                disabled={
                                    !canUpdatePaymentSettings ||
                                    paymentSettingSources?.xendit_secret_key?.managed_by_environment
                                }
                            />
                            <Input
                                label="Public Key"
                                type="text"
                                value={data.xendit_public_key}
                                onChange={(e) =>
                                    setData("xendit_public_key", e.target.value)
                                }
                                errors={errors?.xendit_public_key}
                                placeholder="xnd_public_development_xxx"
                                disabled={!canUpdatePaymentSettings}
                            />
                        </div>
                        {renderSecretHint(
                            "xendit_secret_key",
                            "Isi ulang hanya jika ingin mengganti secret."
                        )}
                        <Input
                            label="Callback Token"
                            type="password"
                            value={data.xendit_callback_token}
                            onChange={(e) =>
                                setData("xendit_callback_token", e.target.value)
                            }
                            errors={errors?.xendit_callback_token}
                            placeholder={
                                paymentSettingSources?.xendit_callback_token?.configured
                                    ? "Kosongkan untuk mempertahankan nilai saat ini"
                                    : "xendit-callback-token"
                            }
                            disabled={
                                !canUpdatePaymentSettings ||
                                paymentSettingSources?.xendit_callback_token?.managed_by_environment
                            }
                        />
                        {renderSecretHint(
                            "xendit_callback_token",
                            "Isi ulang hanya jika ingin mengganti token."
                        )}
                        <label className="flex items-center gap-2 cursor-pointer">
                            <Checkbox
                                checked={data.xendit_production}
                                onChange={(e) =>
                                    setData(
                                        "xendit_production",
                                        e.target.checked
                                    )
                                }
                                disabled={!canUpdatePaymentSettings}
                            />
                            <span className="text-sm text-slate-600 dark:text-slate-400">
                                Mode Produksi
                            </span>
                        </label>
                    </div>
                </div>

                {/* Webhook URLs Info */}
                <div className="bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-200 dark:border-slate-700 p-6">
                    <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-4 flex items-center gap-2">
                        🔗 Webhook URLs
                    </h3>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mb-4">
                        Salin URL berikut dan paste ke dashboard Midtrans/Xendit
                        sebagai Notification/Callback URL.
                    </p>
                    {webhookWarnings.length > 0 && (
                        <div className="mb-4 space-y-2">
                            {webhookWarnings.map((warning) => (
                                <div
                                    key={warning}
                                    className="rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-900/60 dark:bg-warning-950/30 dark:text-warning-300"
                                >
                                    {warning}
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="space-y-3">
                        <div>
                            <label htmlFor="webhook_midtrans" className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                                Midtrans Notification URL
                            </label>
                            <div className="flex items-center gap-2">
                                <input
                                    id="webhook_midtrans"
                                    aria-label="Midtrans Notification URL"
                                    type="text"
                                    readOnly
                                    value={webhookUrls.midtrans || ""}
                                    className="flex-1 h-10 px-3 text-sm rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                                />
                                <button
                                    type="button"
                                    onClick={() => {
                                        navigator.clipboard.writeText(
                                            webhookUrls.midtrans || ""
                                        );
                                        toast.success("URL disalin!");
                                    }}
                                    className="px-3 h-10 rounded-lg border border-slate-200 dark:border-slate-700 text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
                                >
                                    Salin
                                </button>
                            </div>
                        </div>
                        <div>
                            <label htmlFor="webhook_xendit" className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">
                                Xendit Callback URL
                            </label>
                            <div className="flex items-center gap-2">
                                <input
                                    id="webhook_xendit"
                                    aria-label="Xendit Callback URL"
                                    type="text"
                                    readOnly
                                    value={webhookUrls.xendit || ""}
                                    className="flex-1 h-10 px-3 text-sm rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 focus:outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
                                />
                                <button
                                    type="button"
                                    onClick={() => {
                                        navigator.clipboard.writeText(
                                            webhookUrls.xendit || ""
                                        );
                                        toast.success("URL disalin!");
                                    }}
                                    className="px-3 h-10 rounded-lg border border-slate-200 dark:border-slate-700 text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
                                >
                                    Salin
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Submit */}
                <div className="flex justify-end">
                    <button
                        type="submit"
                        disabled={processing || !canUpdatePaymentSettings}
                        className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium transition-colors disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
                    >
                        <IconDeviceFloppy size={18} />
                        {processing ? "Menyimpan..." : "Simpan Konfigurasi"}
                    </button>
                </div>
            </form>
        </>
    );
}

Payment.layout = (page) => <DashboardLayout children={page} />;
