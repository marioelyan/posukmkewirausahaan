# ADR-03 — Tenant-Scoped Uniqueness (Q4)

- **Status:** Proposed — **menunggu persetujuan pemilik proyek**; satu koreksi aplikasi yang aman telah diimplementasikan.
- **Tanggal audit:** 2026-10-11
- **Basis bukti:** index unik aktual di `database/migrations` + validasi aplikasi aktual.
- **Terkait:** `multi-business-open-decisions.md` (Q4), `docs/architecture/multi-business-data-ownership-matrix.md`.
- **Catatan:** Tidak ada migration yang dibuat/dijalankan. Tidak ada index lama yang dihapus.

## 1. Konteks

Sebagian besar index unik di aplikasi bersifat **global** (satu nilai unik di seluruh instalasi). Setelah pemisahan data per business, beberapa field seharusnya unik **per business** (mis. barcode, SKU), sementara yang lain tetap global (mis. `users.email`, `businesses.slug`, kode wilayah). ADR ini memetakan setiap field dan menentukan mana yang aman diperbaiki di level aplikasi sekarang, dan mana yang memerlukan migrasi + persetujuan.

## 2. Kondisi aktual yang ditemukan (FAKTA)

Index unik global yang relevan (dari `database/migrations`):

| Field | Tabel | Index DB | Kepemilikan tabel (tenancy) | Validasi aplikasi |
|---|---|---|---|---|
| `barcode` | `products` | unique (global) | `business_id` (direct) | `required|unique:products,barcode` (web + API) |
| `sku` | `products` | unique nullable (global) | `business_id` (direct) | `required|unique:products,sku` (web + API) |
| `slug` | `price_lists` | unique (global) | `business_id` (direct) | `unique:price_lists,slug` (web) |
| `slug` | `customer_segments` | unique (global) | **tanpa ownership** | — |
| `code` | `customer_vouchers` | unique (global) | `outlet_id` (outlet-scoped) | ada di API |
| `code` | `units` | unique (global) | `business_id` (direct) | `unique:units,code` (web) |
| `code` | `warehouses` | unique (global) | via outlet/business | `unique:warehouses,code` (web) |
| `code` | `outlets` | unique (global) | `business_id` (direct) | `unique:outlets,code` (web) |
| `member_code` | `customers` | unique nullable (global) | `business_id` (direct) | di-generate server (`LoyaltyService`) |
| `name` | `categories` | **tidak ada** | `business_id` (direct) | `Rule::unique('categories','name')` (API) |
| `no_telp` | `customers` | **tidak ada** | `business_id` (direct) | `TenantUnique` (web + API, sejak Build 4D) |
| `context_key` | `customer_campaigns` | unique nullable (global) | `outlet_id` (outlet-scoped) | — |
| `document_number` | `purchase_orders`, `goods_receivings`, `stock_transfers`, `supplier_returns` | unique (global) | warehouse-scoped | di-generate server |
| `code` | `sales_returns`, `stock_opnames` | unique (global) | warehouse-scoped | di-generate server |
| `slug` | `businesses` | unique (global) | root | — |
| `email` | `users` | unique (global) | global | `unique:users,email` |
| `name`+`guard_name` | `roles`/`permissions` | unique (global) | global | — |
| `code` | provinsi/kota/kecamatan/kelurahan | unique (global) | referensi global | — |
| `client_uuid` | `transactions` | unique nullable (global) | warehouse-scoped | idempotency offline |
| `access_token` | `transactions` | unique nullable (global) | warehouse-scoped | share link |
| `batch_number` | `product_batches` | unique (product,warehouse,batch) | warehouse-scoped | — |
| `token`/`access_token` | `dine_tables`/`dine_orders` | unique global | — | — |
| `uuid` | `jobs` | unique global | platform | — |
| `token` | `personal_access_tokens` | unique global | platform | — |
| `key`+`outlet_id` | `settings` | unique (key, outlet_id) | global/outlet | — |

## 3. Matriks keputusan per field

| Field | Global vs per-business | Rekomendasi | Aman diimplementasikan sekarang? |
|---|---|---|---|
| `products.barcode` | per-business | composite `(business_id, barcode)` | **Tidak** — butuh migration (Q4) |
| `products.sku` | per-business | composite `(business_id, sku)` | **Tidak** — butuh migration (Q4) |
| `price_lists.slug` | per-business | composite `(business_id, slug)` | **Tidak** — butuh migration (Q4) |
| `customer_segments.slug` | per-business | composite setelah ownership segmen ditentukan | **Tidak** — blocked ownership (F11) |
| `customer_vouchers.code` | per-business/outlet | composite | **Tidak** — butuh migration (Q4) |
| `units.code` | per-business | composite `(business_id, code)` | **Tidak** — butuh migration (Q4) |
| `warehouses.code` | per-business | composite `(business_id, code)` | **Tidak** — butuh migration (Q4) |
| `outlets.code` | per-business | composite `(business_id, code)` | **Tidak** — butuh migration (Q4) |
| `customers.member_code` | per-business | composite `(business_id, member_code)` | **Tidak** — butuh migration (Q4) |
| `categories.name` | per-business | validasi aplikasi tenant-scoped | **Ya — diimplementasikan** |
| `customers.no_telp` | per-business | validasi aplikasi tenant-scoped | **Sudah (Build 4D)** |
| `document_number` (PO/GR/transfer/return) | per-business (opsional) | tetap global agar tidak collide; per-business butuh composite | **Tidak** — butuh keputusan |
| `sales_returns.code`, `stock_opnames.code` | per-business (opsional) | sama seperti document_number | **Tidak** — butuh keputusan |
| `businesses.slug` | global | tetap global | Tidak perlu |
| `users.email`, `roles.name`, kode wilayah | global | tetap global | Tidak perlu |
| `transactions.client_uuid`/`access_token`, `product_batches.batch_number`, token dine-in, `jobs.uuid`, PAT token | global/teknis | tetap global | Tidak perlu |

## 4. Rancangan unique index (DRAFT — tidak dibuat)

Untuk field yang direkomendasikan per-business, desain migrasi (draft, **belum dijalankan**):

```
-- Contoh products
ALTER TABLE products DROP INDEX products_barcode_unique;
ALTER TABLE products ADD UNIQUE products_business_barcode_unique (business_id, barcode);
-- sku: unique komposit (business_id, sku) dengan NULL handling
```

Catatan penting:
- `business_id` boleh `NULL` untuk data legacy pra-backfill. Pada MySQL, composite unique memperlakukan `NULL` sebagai distinct → duplikat `NULL,barcode=X` bisa terjadi. Karena itu **urutan wajib**: backfill `business_id` dulu, lalu buat composite unique.
- Jangan drop index lama sebelum: (1) kebijakan disetujui, (2) duplikat diinventarisasi, (3) rencana rekonsiliasi disetujui, (4) migration & rollback ditinjau.

## 5. Strategi penanganan duplicate existing

1. Query inventaris per field, mis. `SELECT barcode, COUNT(*) FROM products GROUP BY barcode HAVING COUNT(*) > 1` (harus kosong untuk barcode karena global unique — jadi **tidak** ada duplikat existing untuk field yang sudah global unique).
2. Untuk field tanpa index DB (`categories.name`), inventaris duplikat per business.
3. Composite index hanya bisa dibuat setelah backfill `business_id` dijalankan.
4. Simpan laporan duplikat sebelum migrasi.

## 6. Kebutuhan validasi aplikasi

- **Dilakukan:** `categories.name` di API memakai `TenantUnique` (setara scoping `business_id`) — lihat §8.
- **Dilakukan (Build 4D):** `customers.no_telp` memakai `TenantUnique` di web + API.
- **Menunggu:** validasi `barcode`/`sku`/`slug`/`code` di web + API masih memakai `unique:` global **karena index DB-nya global**. Mengubah validasi saja tanpa mengubah index akan menyebabkan error DB `UNIQUE constraint failed` saat business berbeda memakai nilai yang sama (validasi lolos, DB menolak). Karena itu **harus** menunggu penghapusan/composite index (Q4).

## 7. Prosedur rollout (usulan, menunggu persetujuan)

1. Setujui Q4 (field mana per-business — rekomendasi: barcode, sku, price_lists.slug, units.code, warehouses.code, outlets.code, member_code, customer_vouchers.code, categories.name).
2. Backfill `business_id` untuk master data (sudah ada dari Build 3; verifikasi tidak ada `NULL`).
3. Inventaris duplikat.
4. Tambah composite unique, hapus global unique (per field).
5. Ubah validasi aplikasi menjadi tenant-scoped.
6. Update import/upsert agar upsert key menyertakan business.
7. Rollback plan: drop composite, restore global unique.

## 8. Koreksi aman yang diimplementasikan pada fase ini

**File:** `app/Http/Controllers/Api/CategoryController.php` (`store` + `update`).

- **Bukti masalah:** `Rule::unique('categories','name')` berjalan langsung ke database (bypass global scope). `categories` memiliki `business_id` + global scope, tetapi index unik `name` **tidak ada** di DB. Saat tenancy aktif, business A tidak bisa membuat kategori bernama sama dengan kategori business B (padahal data terpisah), dan status "name taken" membocorkan keberadaan data business lain (probe). Ini inkonsisten dengan pola `TenantUnique` yang sudah dipakai untuk `customers.no_telp`.
- **Perbaikan:** bungkus dengan `TenantUnique::for('categories','name')` (store) dan `TenantUnique::for('categories','name', $category->id)` (update). Saat flag off, `TenantUnique` mengembalikan `Rule::unique(...)` polos → perilaku legacy identik.
- **Tanpa migration:** tidak ada index unik `categories.name`, sehingga tidak ada konflik DB.
- **Trade-off:** dua business dapat memiliki nama kategori sama saat tenancy aktif (konsisten dengan Q4 "data terpisah"); celah race kecil (dua request simetris) tetap ada karena app-level uniqueness — sama seperti `customers.no_telp`.
- **Tes:** `tests/Feature/Business/...` (lihat §9).

## 9. Tes

Ditambahkan/diperluas pada `tests/Feature/Business/` untuk:
- business A boleh membuat kategori `Elektronik` walaupun business B sudah punya → sukses saat tenancy aktif.
- business A menolak membuat kategori `Elektronik` duplikat di dalam business-nya sendiri → 422.
- saat flag nonaktif, duplikat lintas global tetap ditolak (perilaku legacy).
- update: nama milik business lain tidak dianggap duplikat; nama milik sendiri (kecuali record sendiri) tetap ditolak.

## 10. Status implementasi pada fase ini

- Implementasi aman: **`categories.name` → `TenantUnique`** (API store/update).
- Migration composite index: **draft di dokumen ini, tidak dibuat, tidak dijalankan**.
- Index global lama: **tidak dihapus**.
- Field lain: menunggu Q4.
