# Multi-Business Data Ownership Matrix — KasirnyaKWU

- **Status:** PERENCANAAN / DOKUMENTASI ONLY. Tidak ada perubahan skema, data, atau kode.
- **Basis:** `database/migrations/` + model + controller yang diverifikasi pada 2026-10-10.
- **Label:** **FAKTA** (terverifikasi di kode), **REKOMENDASI**, **ASUMSI**, **PERLU KEPUTUSAN**.
- Dokumen terkait: `multi-business-backend-implementation-plan.md`, `multi-business-security-test-plan.md`, `multi-business-open-decisions.md`.

> **Peringatan working tree.** Beberapa file DineIn sedang **dihapus** di working tree (mis. `app/Http/Controllers/Apps/DineOrderController.php`, `resources/js/Pages/Dashboard/DineIn/*`). Migration & model DineIn masih ada. Status modul DineIn **tidak stabil** (lihat `multi-business-open-decisions.md`); perlakukan kepemilikannya sebagai **PERLU KEPUTUSAN**.

---

## 1. Cara Membaca Matriks

Kolom:

- **Tabel** — nama tabel actual.
- **Tujuan** — fungsi data.
- **Scope saat ini** — kunci isolasi yang ada sekarang (FAKTA).
- **Strategi target** — rekomendasi penempatan `business_id`.
- **Aturan backfill** — cara mengisi untuk instalasi existing.
- **Unique per business** — constraint yang perlu disesuaikan.
- **Risiko** — dampak bila salah.

Kode strategi:

- `GLOBAL` — tanpa `business_id`.
- `BUSINESS` — `business_id` langsung di tabel.
- `OUTLET` — `outlet_id` (tambah `business_id` denormal untuk filter).
- `WAREHOUSE` — `warehouse_id` (tambah `business_id` denormal).
- `CHILD` — ikut induk, tanpa `business_id` sendiri.

---

## 2. Tabel Platform (Global)

| Tabel | Tujuan | Scope saat ini | Strategi target | Backfill | Catatan |
|---|---|---|---|---|---|
| `users` | akun login | global | GLOBAL | — | keanggotaan bisnis via `business_user` |
| `password_reset_tokens`, `sessions` | auth | global | GLOBAL | — | — |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | infra | global | GLOBAL | — | **REKOMENDASI** bawa `business_id` di payload job |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | Spatie | global (teams off) | GLOBAL (atau teams) | — | `config/permission.php:126` |
| `personal_access_tokens` | Sanctum | global | GLOBAL | — | abilities dari permission |
| `provinces`, `cities`, `districts`, `villages` | wilayah Indonesia | global | GLOBAL | — | referensi bersama |
| `settings` | konfigurasi toko | **sebagian** `outlet_id` nullable | **PERLU KEPUTUSAN** | lihat §3 | ada global + outlet |
| `audit_logs` | jejak audit | global (tanpa business) | GLOBAL + kolom `business_id` | isi best-effort | **REKOMENDASI** tambah `business_id` |
| `product_notification_reads` | tanda baca notifikasi | per user/produk | GLOBAL atau BUSINESS | — | ikut produk jika produk per business |

**FAKTA.** `settings` sudah mendapat `outlet_id` (`2026_09_14_000004_add_outlet_id_to_settings_table.php`) dan store defaults (`2025_12_31_010000_add_store_settings_defaults.php`). Key-level global vs per-business belum jelas → **PERLU KEPUTUSAN** (Q5).

---

## 3. Master Data — Kandidat `business_id` Langsung

**REKOMENDASI.** Semua master data berikut dimiliki **business** (bukan outlet), dengan `business_id` di tabel dan unique per business.

| Tabel | Tujuan | Scope saat ini | Strategi | Backfill | Unique per business | Risiko |
|---|---|---|---|---|---|---|
| `products` | katalog barang | global | BUSINESS | business default | `barcode`, `sku` | POS tidak menemukan produk; stok agregat salah |
| `categories` | kategori produk | global | BUSINESS | business default | `name`/`slug` | duplikat nama antar business wajar |
| `customers` | pelanggan | global | BUSINESS | business default | `member_code`, `no_telp` (opsional) | riwayat & loyalty bocor |
| `suppliers` | pemasok | global | BUSINESS | business default | `name`/`code` | PO lintas business |
| `units` | satuan | global | BUSINESS | business default | `code` | konversi salah |
| `price_lists` | daftar harga | global | BUSINESS | business default | `name` | harga lintas business |
| `product_units` | konversi produk | ikut produk | CHILD | ikut produk | — | — |
| `product_batches` | batch/expiry | per produk/warehouse | BUSINESS/WAREHOUSE | ikut produk | — | lihat §4 |
| `composite_products` | produk komposit | global | CHILD produk | ikut produk | — | — |
| `expenses` (**belum ada**) | pengeluaran | — | BUSINESS | — | — | modul baru |

**PERLU KEPUTUSAN.** Apakah master data benar-benar terisolasi penuh, atau boleh **dibagikan** lintas business (mis. katalog global platform). Default usulan: **terisolasi penuh** (Q4).

---

## 4. Scope Outlet

**FAKTA.** `outlets` (`2026_09_14_000001_create_outlets_table.php`) adalah batas operasional; beberapa tabel sudah punya `outlet_id` (`2026_09_14_000005`, `2026_09_14_000006`, `2026_09_15_000001`).

| Tabel | Tujuan | Scope saat ini | Strategi | Backfill | Catatan |
|---|---|---|---|---|---|
| `outlets` | cabang | root operasional | **BUSINESS** (`business_id` parent) | business default | tambah `business_id` |
| `warehouses` | gudang/lokasi stok | `outlet_id` nullable | OUTLET + `business_id` | via outlet; PUSAT legacy khusus | `orWhereNull` risk |
| `user_outlets` | assignment user→outlet | pivot | OUTLET | — | validasi outlet milik business |
| `bank_accounts` | rekening | `outlet_id` | OUTLET + `business_id` | via outlet | dipakai `CheckoutService` |
| `payment_settings` | gateway | `outlet_id` | OUTLET + `business_id` | via outlet | rahasia sudah dienkripsi |
| `pricing_rules` | promosi | `outlet_id` | OUTLET + `business_id` | via outlet | — |
| `pricing_rule_qty_breaks`, `pricing_rule_bundle_items`, `pricing_rule_buy_get_items` | detail promosi | ikut rule | CHILD | ikut rule | — |
| `dine_areas`, `dine_tables` | area meja | `outlet_id` | OUTLET | via outlet | modul tidak stabil |
| `customer_campaigns` | kampanye | `outlet_id` | OUTLET + `business_id` | via outlet | audience global (risiko) |
| `customer_vouchers` | voucher | `outlet_id`/customer | OUTLET | via outlet | — |
| `customer_segments` | segmen | `outlet_id` (cek) | OUTLET/BUSINESS | via outlet | dipakai `CrmAutomationService` |

**Catatan PUSAT.** `2026_09_15_000002_make_pusat_central_only.php` menjadikan `PUSAT` gudang pusat non-omzet. Pastikan business default punya tepat satu PUSAT; business baru mendapat struktur serupa saat onboarding.

---

## 5. Scope Warehouse (Operasional)

**FAKTA.** `warehouse_id` ditambahkan ke transaksi/cart/mutasi (`2026_06_22_000004`), purchasing (`2026_06_22_000005`), dan `cashier_shifts` (`2026_06_22_000003`).

| Tabel | Tujuan | Scope saat ini | Strategi | Backfill |
|---|---|---|---|---|
| `transactions` | transaksi penjualan | `warehouse_id`, `outlet_id`? | WAREHOUSE + `business_id` | via warehouse→outlet→business |
| `transaction_details` | baris transaksi | ikut transaksi | CHILD | ikut transaksi |
| `transaction_tenders` | detail pembayaran | ikut transaksi | CHILD | ikut transaksi |
| `transaction_detail_batch_allocations` | alokasi batch | ikut detail | CHILD | ikut |
| `profits` | laba per transaksi | ikut transaksi | CHILD | ikut |
| `carts` | keranjang | `warehouse_id` | WAREHOUSE + `business_id` | via warehouse |
| `stock_mutations` | mutasi stok | `warehouse_id` | WAREHOUSE + `business_id` | via warehouse |
| `product_warehouse` (pivot) | stok per gudang | `warehouse_id` | WAREHOUSE | via warehouse |
| `cashier_shifts` | shift kasir | `warehouse_id` | WAREHOUSE + `business_id` | via warehouse |
| `shift_cash_movements` | kas masuk/keluar | ikut shift | CHILD | ikut shift |
| `purchase_orders` | PO | `warehouse_id` | WAREHOUSE + `business_id` | via warehouse |
| `goods_receivings` | penerimaan | `warehouse_id` | WAREHOUSE + `business_id` | via warehouse |
| `stock_opnames` (+ item) | opname | `warehouse_id` | WAREHOUSE | via warehouse |
| `stock_transfers` | transfer antar gudang | from/to warehouse | WAREHOUSE + `business_id` | via warehouse; **validasi kedua gudang satu business** |
| `sales_returns` (+ item) | retur jual | `warehouse_id` | WAREHOUSE | via warehouse |
| `supplier_returns` (+ item) | retur beli | `warehouse_id` | WAREHOUSE | via warehouse |
| `dine_orders`, `dine_order_items` | pesanan dine-in | `outlet_id` | OUTLET | modul tidak stabil |

**FAKTA.** Transaksi tidak punya kolom `status`; hanya `payment_status` (`2025_11_19_172346...:16`) dan `client_uuid` (`2026_09_10_000001`). `client_uuid` harus **unik per business** (lihat catatan idempotency §7).

---

## 6. Turunan Keuangan & Loyalty

| Tabel | Tujuan | Scope saat ini | Strategi | Catatan |
|---|---|---|---|---|
| `receivables` | piutang | customer/transaksi | CHILD transaksi + `business_id` denormal | aging |
| `receivable_payments` | bayar piutang | ikut receivable | CHILD | — |
| `payables` | utang | supplier/PO | BUSINESS/WAREHOUSE | `store()` belum isi warehouse (`PayableController.php:70-86`) |
| `payable_payments` | bayar utang | ikut payable | CHILD | — |
| `loyalty_point_histories` | poin loyalty | customer | CHILD customer | `AdvancedSalesInsightsController.php:688` global |
| `customer_credits` | kredit pelanggan | customer | CHILD | — |
| `payment_settings`, `bank_accounts` | lihat §4 | | | |

**REKOMENDASI.** Jangan duplikasi `business_id` di tabel anak bila bisa diturunkan; cukup tambahkan pada induk tempat query langsung (mis. `receivables`, `payables`) untuk performa laporan.

---

## 7. Tabel yang Membutuhkan Investigasi / Keputusan

| Tabel / area | Isu | Bukti | Perlu keputusan |
|---|---|---|---|
| `settings` | global vs per-outlet/per-business | `2026_09_14_000004`; `2025_12_25_200000` | key mana global, key mana per business |
| `audit_logs` | belum ada business | `2026_05_02_120000` | tambah `business_id`? |
| `transactions.client_uuid` | idempotency global | `PosApiController.php:743,878` | unik per business |
| `payment_settings` | rahasia per outlet/business | `2026_05_02_120000_encrypt...` | scope + rotasi kunci |
| `webhook` lookup | referensi lintas business | `PaymentWebhookController.php:66-99,190-220` | verifikasi via relasi |
| `product_notification_reads` | ikut produk atau user | model | per business |
| DineIn | modul dihapus sebagian | working tree | lanjut/hapus/pertahankan |

---

## 8. Aturan Backfill Umum (REKOMENDASI)

1. Buat **satu business default** dari `Setting::get('store_name')` (atau nama generik).
2. Assign `business_id` tabel master langsung ke business default.
3. Untuk tabel ber-`outlet_id`/`warehouse_id`: turunkan `business_id` mengikuti rantai `warehouse → outlet → business`.
4. Untuk baris tanpa outlet (mis. `warehouses.outlet_id IS NULL` PUSAT legacy): assign ke business default, tandai luaran audit.
5. Untuk tabel turunan: turunkan dari induk.
6. Verifikasi checksum `COUNT`/`SUM` per outlet sebelum vs sesudah.
7. Baru lakukan `NOT NULL` + FK + unique setelah 100% terisi.

**REKOMENDASI.** Setiap langkah backfill dijalankan dalam transaksi dan idempoten (dapat diulang) agar aman saat gagal di tengah.

---

## 9. Ringkasan Keputusan Data

Lihat `multi-business-open-decisions.md`. Keputusan data yang menghambat:

- **Q4** Isolasi master data penuh vs berbagi.
- **Q5** Setting global vs per business.
- **Q7** Outlet existing → satu business atau dipisah.
- **Q9** Data existing dengan ownership tak jelas (PUSAT legacy, settings tanpa outlet).

Tidak ada keputusan data yang berstatus "approved" pada dokumen ini.
