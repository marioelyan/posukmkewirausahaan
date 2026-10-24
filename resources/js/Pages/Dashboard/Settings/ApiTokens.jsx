import React, { useEffect, useState } from "react";
import { Head, useForm, usePage, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconKey,
    IconPlus,
    IconTrash,
    IconCopy,
    IconCheck,
    IconAlertCircle,
} from "@tabler/icons-react";
import toast from "react-hot-toast";

function formatDate(value) {
    if (!value) return "-";
    try {
        return new Date(value).toLocaleString("id-ID");
    } catch {
        return value;
    }
}

export default function ApiTokens({ tokens = [], availableAbilities = [] }) {
    // Di Inertia v3, flash hidup di level page (usePage().flash), bukan di
    // props — pola usePage().props.flash di halaman lain tidak pernah
    // menerima data. Di sini harus pakai yang benar karena panel token
    // sekali-tampil adalah inti fitur ini.
    const { flash } = usePage();
    const [showNewToken, setShowNewToken] = useState(true);
    const [copied, setCopied] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        name: "",
        abilities: [],
    });

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    const toggleAbility = (ability) => {
        setData(
            "abilities",
            data.abilities.includes(ability)
                ? data.abilities.filter((a) => a !== ability)
                : [...data.abilities, ability]
        );
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route("settings.api-tokens.store"), {
            onSuccess: () => reset(),
        });
    };

    const handleDelete = (token) => {
        if (confirm(`Cabut token "${token.name}"? Token yang dicabut tidak bisa dipakai lagi.`)) {
            router.delete(route("settings.api-tokens.destroy", token.id));
        }
    };

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(flash.api_token);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error("Gagal menyalin token.");
        }
    };

    return (
        <>
            <Head title="Token API" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconKey size={28} className="text-primary-500" />
                    Token API
                </h1>
                <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Buat dan cabut token untuk integrasi pihak ketiga. Setiap token hanya
                    berlaku untuk akun ini, dan hak aksesnya tidak bisa melebihi hak akses Anda.
                </p>
            </div>

            <div className="max-w-3xl space-y-6">
                {flash?.api_token && showNewToken && (
                    <div className="rounded-2xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 p-4">
                        <div className="flex items-start gap-2">
                            <IconAlertCircle size={20} className="text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                            <div className="min-w-0 flex-1">
                                <h3 className="font-semibold text-amber-800 dark:text-amber-200">
                                    Token baru: {flash.api_token_name}
                                </h3>
                                <p className="text-sm text-amber-700 dark:text-amber-300 mt-1">
                                    Salin sekarang. Token ini hanya tampil sekali — setelah
                                    halaman ini ditinggalkan, token tidak bisa dilihat lagi.
                                </p>
                                <div className="mt-3 flex items-center gap-2">
                                    <code className="flex-1 min-w-0 truncate rounded-lg bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-800 px-3 py-2 text-sm font-mono text-slate-800 dark:text-slate-100">
                                        {flash.api_token}
                                    </code>
                                    <button
                                        type="button"
                                        onClick={handleCopy}
                                        className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium transition-colors"
                                    >
                                        {copied ? <IconCheck size={16} /> : <IconCopy size={16} />}
                                        {copied ? "Tersalin" : "Salin"}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setShowNewToken(false)}
                                        className="px-3 py-2 rounded-xl text-sm font-medium text-amber-800 dark:text-amber-200 hover:bg-amber-100 dark:hover:bg-amber-900/50 transition-colors"
                                    >
                                        Tutup
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                <form
                    onSubmit={handleSubmit}
                    className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 space-y-4"
                >
                    <h3 className="font-semibold text-slate-800 dark:text-white flex items-center gap-2">
                        <IconPlus size={18} />
                        Buat Token Baru
                    </h3>
                    <div>
                        <label className="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Nama token
                        </label>
                        <input
                            type="text"
                            value={data.name}
                            onChange={(e) => setData("name", e.target.value)}
                            placeholder="mis. Juleha"
                            className="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
                        />
                        {errors.name && (
                            <p className="text-sm text-red-600 mt-1">{errors.name}</p>
                        )}
                    </div>
                    <div>
                        <p className="text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Hak akses token
                        </p>
                        <p className="text-xs text-slate-500 dark:text-slate-400 mb-2">
                            Hanya hak akses yang Anda miliki yang bisa dipilih. Hak{" "}
                            <code>user:read</code> selalu ikut.
                        </p>
                        {availableAbilities.length > 0 ? (
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                {availableAbilities.map((ability) => (
                                    <label
                                        key={ability}
                                        className="flex items-center gap-2 rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 text-sm text-slate-700 dark:text-slate-300 cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={data.abilities.includes(ability)}
                                            onChange={() => toggleAbility(ability)}
                                            className="rounded"
                                        />
                                        <span className="font-mono text-xs break-all">{ability}</span>
                                    </label>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-slate-500 dark:text-slate-400">
                                Akun ini belum memiliki hak akses apa pun — token yang dibuat
                                hanya bisa membaca profil sendiri.
                            </p>
                        )}
                        {errors.abilities && (
                            <p className="text-sm text-red-600 mt-1">{errors.abilities}</p>
                        )}
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-primary-500 hover:bg-primary-600 disabled:opacity-50 text-white text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
                    >
                        <IconKey size={18} />
                        Buat Token
                    </button>
                </form>

                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div className="p-4 border-b border-slate-200 dark:border-slate-800">
                        <h3 className="font-semibold text-slate-800 dark:text-white">
                            Token Saya ({tokens.length})
                        </h3>
                    </div>
                    {tokens.length > 0 ? (
                        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                            {tokens.map((token) => (
                                <li key={token.id} className="p-4 flex items-start justify-between gap-4">
                                    <div className="min-w-0">
                                        <p className="font-medium text-slate-800 dark:text-white flex items-center gap-2">
                                            <IconKey size={16} className="text-slate-400 shrink-0" />
                                            {token.name}
                                        </p>
                                        <div className="flex flex-wrap gap-1 mt-2">
                                            {(token.abilities || []).map((ability) => (
                                                <span
                                                    key={ability}
                                                    className="inline-block rounded-full bg-slate-100 dark:bg-slate-800 px-2 py-0.5 font-mono text-[11px] text-slate-600 dark:text-slate-300 break-all"
                                                >
                                                    {ability}
                                                </span>
                                            ))}
                                        </div>
                                        <p className="text-xs text-slate-500 dark:text-slate-400 mt-2">
                                            Dibuat: {formatDate(token.created_at)}
                                            {" · "}
                                            Terakhir dipakai: {formatDate(token.last_used_at)}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => handleDelete(token)}
                                        className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors shrink-0"
                                    >
                                        <IconTrash size={16} />
                                        Cabut
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="p-8 text-center">
                            <IconKey size={48} className="mx-auto text-slate-300 dark:text-slate-600 mb-3" />
                            <p className="text-slate-500 dark:text-slate-400">
                                Belum ada token API
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

ApiTokens.layout = (page) => <DashboardLayout children={page} />;
