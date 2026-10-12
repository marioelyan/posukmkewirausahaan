# Multi-Business — Phase 1 Implementation Notes

- **Status:** Fondasi Fase 1 selesai pada level kode; **belum diaktifkan untuk pengguna nyata**.
- **Basis keputusan:** `multi-business-backend-implementation-plan.md` (REKOMENDASI), `multi-business-open-decisions.md`.
- **Tanggal:** 2026-10-10
- **Peringatan:** Dokumen ini mendokumentasikan apa yang benar-benar diimplementasikan dan apa yang **belum**. Aplikasi **belum** aman multi-tenant penuh.

---

## 1. Yang Diimplementasikan

### Model & Membership
- Tabel baru: `businesses`, `business_user` (nama sesuai arsitektur, singular pivot).
- Kolom additive: `outlets.business_id` (nullable, FK `nullOnDelete`, index) — **tanpa backfill**.
- Model: `App\Models\Business`, `App\Models\BusinessUser`.
- Relasi: `User::memberships()`, `User::businesses()`, `User::membershipsInActiveState()`, `Outlet::business()`.
- Factory: `BusinessFactory`, `BusinessUserFactory`.
- Role bisnis disimpan di `business_user.role` (owner/manager/staff); status di `business_user.status`. Mengikuti **Opsi A** dokumen arsitektur. Spatie teams **tidak** diaktifkan.

### Tenant Context
- `App\Services\BusinessContext` (binding `scoped` → reset per request).
- `App\Services\BusinessMembershipResolver` (validasi membership aktif + business aktif).
- Middleware `business` (`ResolveBusinessContext`) dan `business.required` (`EnsureActiveBusiness`), hanya didaftarkan sebagai alias — **tidak** dipasang ke route lama.

Aturan context:
- Sumber hanya sesi `active_business_id` (web) yang **diverifikasi membership**; parameter/query `business_id` **tidak dipercaya**.
- Auto-resolve hanya bila user punya tepat **satu** membership aktif.
- `super-admin` **tidak** otomatis menjadi owner semua business; hanya boleh memilih business yang ada secara eksplisit.
- Context di-clear di awal setiap request oleh middleware; binding `scoped`.

### Perbaikan Keamanan
- `OutletAccessService::warehousesFor()`: user non-super-admin **tanpa** outlet kini mendapat daftar kosong di instalasi multi-outlet (dan tetap kompatibel untuk single-outlet via `legacy_single_outlet_bypass`). Menutup temuan P0 audit.
- `UserRequest`: role harus ada di tabel `roles`; aktor non-super-admin tidak boleh menetapkan `super-admin`; outlet yang ditetapkan harus berada dalam jangkauan `OutletAccessService::accessibleOutlets()` aktor.

---

## 2. Yang Belum Diimplementasikan (Sengaja)

- Backfill `business_id` untuk data lama dan penetapan business default.
- Middleware bisnis **tidak** dipasang ke route dashboard/POS mana pun.
- Isolasi master data (produk, customer, supplier, kategori, unit, price list) — belum.
- Isolasi transaksi, stok, shift, PO/GR, retur, bank account, expense — belum.
- Laporan lintas business / definisi transaksi "completed" — belum.
- Business switcher (frontend), onboarding business, undangan email — belum.
- Global scope Eloquent untuk `business_id` — belum (sesuai keputusan: eksplisit dulu).
- Spatie teams, billing/subscription, queue/infra baru, deployment — belum.
- Modul DineIn sedang dihapus sebagian di working tree — tidak disentuh.

**Konsekuensi:** sampai Fase 2+, seluruh query aplikasi **belum** terisolasi per business, sehingga multi-business **belum boleh** diaktifkan untuk pengguna nyata.

---

## 3. Keputusan Terbuka yang Masih Menghambat

- Q2/Q3 (owner & role per business), Q5 (setting global vs per business), Q6 (pembuatan business), Q7 (instalasi existing = satu business), Q9 (data lama tanpa ownership jelas), Q10 (DineIn), Q12 (audit_logs.business_id), Q13 (global scope).
- Implementasi Fase 1 memakai **REKOMENDASI** dokumen arsitektur (Opsi A). Bila arah keputusan berubah, penyesuaian masih murah karena belum ada data produksi yang memakai `business_id`.

---

## 4. Perintah Migrasi untuk Staging (JANGAN dijalankan di produksi)

```bash
# pada staging dengan backup terverifikasi
php artisan migrate --force
# rollback bila perlu
php artisan migrate:rollback --step=3   # business_user, outlets.business_id, businesses
```

Catatan: migrasi hanya **additive**; tidak mengubah/menghapus kolom existing.

---

## 5. Langkah Berikutnya (Fase 2+)

1. Backfill business default + pemetaan outlet (setelah Q7/Q9 dikonfirmasi).
2. Pasang middleware `business` pada route yang relevan + policy + validasi FK.
3. Isolasi master data, lalu POS/transaksi/stok, lalu laporan.
4. Isolasi import/export, CRM, job, API, webhook.
5. Business switcher + onboarding (setelah Q6).
