/**
 * Platform branding — single source of truth.
 *
 * Dipakai untuk seluruh permukaan platform: halaman publik, auth, setup,
 * app shell (fallback), PWA/meta, dan cetak. Nama & logo toko dari store
 * profile (Settings → Store) tetap menimpa nilai ini di dalam aplikasi.
 *
 * Jangan menyalin string brand secara hardcoded di komponen — impor dari sini.
 */
export const BRAND = {
    name: "KasirnyaKWU",
    tagline: "Bergerak cepat, bertumbuh hebat",
    description:
        "Sistem kasir (POS) untuk UMKM — multi-warehouse, PPN, CRM & WhatsApp, hingga mode offline.",
    url: "https://posukmkewirausahaan.web.id",
    github: "https://github.com/marioelyan/posukmkewirausahaan",
    docs: "https://github.com/marioelyan/posukmkewirausahaan/blob/main/docs/getting-started.md",
    credit: "Mario",
    license: "MIT",
};

export default BRAND;
