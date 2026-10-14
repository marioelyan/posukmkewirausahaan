# Multi-Business — Architecture Decisions & Implementation (Phase ADR)

- **Status:** **Complete with approval blockers.**
- **Tanggal:** 2026-10-11
- **Feature flag:** `multi_business.enabled` **tetap nonaktif**.
- **Migration:** tidak ada yang dibuat maupun dijalankan.
- **Backfill / data produksi:** tidak ada yang diubah.
- **Deployment:** tidak ada.
- **Basis:** audit kode, skema, route, tes, dan dokumentasi aktual repository.

## 1. Daftar ADR

| ADR | Topik | Q | Status keputusan | Implementasi fase ini |
|---|---|---|---|---|
| [ADR-01](adr/ADR-01-membership-role-authorization.md) | Membership, role, authorization | Q2/Q3/Q14 | Menunggu persetujuan | Tidak ada (model sudah ada) |
| [ADR-02](adr/ADR-02-payables-receivables-legacy-ownership.md) | Legacy ownership payable/receivable | Q7/Q9/Q15 | Menunggu persetujuan | Tidak ada (fail-closed sudah ada) |
| [ADR-03](adr/ADR-03-tenant-scoped-uniqueness.md) | Tenant-scoped uniqueness | Q4 | Menunggu persetujuan + 1 koreksi aman | **Ya** — `categories.name` |
| [ADR-04](adr/ADR-04-business-settings-store-profile.md) | Business settings & store profile | Q5 | Menunggu persetujuan | Tidak ada |

## 2. Temuan dari repository aktual

1. **Model membership sudah ada** (`BusinessUser`, `business_user`, role `owner|manager|staff`, status `invited|active|suspended|revoked`). Resolver menolak business/membership non-active (fail closed). **Role membership tidak dipakai untuk otorisasi** — permission Spatie tetap global (inti Q2/Q3/Q14).
2. **Payable/receivable tanpa `business_id`/`warehouse_id`.** Ownership diturunkan dari induk (`purchase_order_id`/`transaction_id`) via `TenantOwnership::VIA_PARENT_TABLES`; record tanpa induk **fail closed** saat tenancy aktif. Jalur baca list/aging memakai fallback manual `Outlet::active()->count() <= 1` (gap sempit, terikat kebijakan legacy bypass Q7/Q9/Q15).
3. **Index unik mayoritas global.** `products.barcode`, `products.sku`, `price_lists.slug`, `units.code`, `warehouses.code`, `outlets.code`, `customer_vouchers.code`, `customers.member_code`, `customer_segments.slug`, `document_number` → memerlukan composite `(business_id, …)` (Q4, butuh migrasi). `categories.name` **tidak** punya index DB → dapat diperbaiki di level aplikasi sekarang.
4. **Settings tanpa `business_id`.** Unique komposit `(key, outlet_id)`. Sebagian key ditulis global (`store_npwp`, `store_nib`, `tax_default_rate`, `discount_approval_*`, `loyalty_*`) → berpotensi lintas-business (Q5).
5. **Gap implementasi yang diperbaiki pada fase ini:** `categories.name` uniqueness lintas-business; web cashier-shift open menerima warehouse asing saat ≤1 outlet aktif.

## 3. Keputusan yang dapat ditetapkan tanpa persetujuan baru (dan sudah diimplementasikan)

| # | Perubahan | Kategori | Alasan |
|---|---|---|---|
| 1 | `Api\CategoryController` `store`/`update` memakai `TenantUnique::for('categories','name', …)` | Approved by existing architecture | Mengikuti pola `TenantUnique` yang sudah dipakai (`customers.no_telp`); `categories` punya `business_id`; **tidak ada** index unik DB `categories.name` sehingga tanpa migrasi. Flag off → identik legacy. |
| 2 | `StoreCashierShiftRequest` `warehouse_id` + `BelongsToActiveBusiness('warehouses')` | Safe technical correction | Menyamakan jalur web dengan jalur API (`PosApiController::openShift`) yang sudah memakai rule ini; mencegah warehouse asing diterima (dan shift terbuka tanpa warehouse) via legacy single-outlet bypass. Flag off → no-op. |

## 4. Keputusan yang menunggu persetujuan

| ID | Pertanyaan | Menghambat |
|---|---|---|
| Q2 | Owner sebagai membership vs super-admin global | Penegakan role |
| Q3 | Role berbeda per business | Penegakan otorisasi per-business |
| Q14 | Spatie teams vs membership-role | Model otorisasi |
| Q7 | Strategi backfill business untuk data legacy | Payable/receivable & settings |
| Q9 | Kebijakan record manual (payable) | Ownership manual |
| Q15 | Perlakuan record legacy tanpa induk | Ownership legacy |
| Q4 | Field mana yang unik per business | Composite unique index |
| Q5 | Settings global vs per-business/per-outlet | Isolasi settings & store profile |

## 5. Implementasi yang telah dilakukan

- `app/Http/Controllers/Api/CategoryController.php` — ganti `Rule::unique('categories','name')` → `TenantUnique::for(...)`.
- `app/Http/Requests/StoreCashierShiftRequest.php` — tambah `BelongsToActiveBusiness('warehouses')`.
- `tests/Feature/Business/UniquenessAndShiftBoundaryTest.php` — 8 tes baru (uniqueness + shift boundary).
- `tests/Feature/Business/StoreSettingsIsolationTest.php` — 1 tes isolasi store profile (jalur outlet-scoped).
- `docs/architecture/adr/ADR-01..04` + dokumen ini.

## 6. Migration draft yang disiapkan tetapi belum dijalankan

- ADR-02: `payables.business_id`, `receivables.business_id` + backfill dari induk.
- ADR-03: composite unique `(business_id, barcode|sku|slug|code|member_code)` + hapus global unique terkait.
- ADR-04: `settings.business_id` + unique `(key, business_id, outlet_id)` + backfill.
- Spatie teams (ADR-01, opsi B): `team_id` pada tabel permission.

Semua hanya berupa **draft di dokumen**. Tidak ada file migration yang dibuat maupun dijalankan.

## 7. Risiko data legacy

- Master data (`products`, `categories`, `customers`, `suppliers`, `units`, `price_lists`, `warehouses`) dapat memiliki `business_id` NULL pra-backfill → composite unique tidak boleh dibuat sebelum backfill.
- Payable/receivable manual tanpa induk tidak dapat dipetakan otomatis; perlu inventaris manual (Q7/Q9/Q15).
- Settings global dari instalasi legacy perlu dipetakan ke business default (Q7) sebelum isolasi.

## 8. Tes yang benar-benar dijalankan

| Perintah | Hasil |
|---|---|
| `php artisan test tests/Feature/Business/UniquenessAndShiftBoundaryTest.php --compact` (dengan fix) | 8 passed (20 assertions) |
| (sama, tanpa fix / revert sementara) | 3 failed, 5 passed — membuktikan tes gagal sebelum perbaikan |
| `php artisan test tests/Feature/Business/StoreSettingsIsolationTest.php --compact` | 1 passed (18 assertions) |
| `php artisan test tests/Feature/Business tests/Feature/Tenancy --compact` | 133 passed (486 assertions) |
| `php artisan test tests/Feature/Api tests/Feature/Auth tests/Feature/Authorization tests/Feature/Security tests/Feature/CashierShifts tests/Feature/Products tests/Feature/Http --compact` | 200 passed (842 assertions) |
| Baseline sebelum perubahan (`tests/Feature/Business tests/Feature/Tenancy`) | 124 passed (448 assertions) |
| `php artisan test --compact` (seluruh suite) | 589 passed (2555 assertions) — baseline sebelum fase: 580/2517 |
| `vendor/bin/pint` (4 file diubah) | passed |

**Catatan tes settings:** skenario “store settings tidak bocor lintas bisnis” hanya dapat diuji untuk **jalur outlet-scoped** (store profile), yang memang sudah terisolasi via `activeOutlet` yang dibatasi business. Isolasi untuk key **global** (`store_npwp`, `store_nib`, `tax_default_rate`, `discount_approval_*`, `loyalty_*`) **tidak dapat dibuat sebagai tes hijau** tanpa mengubah kebijakan penyimpanan setting (Q5) — karena implementasi saat ini memang berbagi satu baris global. Tes yang mengharapkan kebocoran **tidak** dibuat (tidak boleh mengunci perilaku bocor sebagai benar). Keterbatasan ini didokumentasikan di ADR-04.

## 9. Blocker sebelum Jobs/Scheduler build

- **Jobs/Scheduler isolation** belum dapat dibangun karena:
  1. Per-business settings & ownership (Q5/Q7) belum ada → job per-business tidak punya cara aman menetapkan konteks.
  2. Command terjadwal (`reorder:generate`, `crm:sync-segments`, `crm:generate-reminders`) saat ini berjalan tanpa konteks tenant; perlu pola “iterate per business → set context → jalankan” yang bergantung pada Q5/Q7.
  3. Otorisasi per-business (Q2/Q3/Q14) belum ditetapkan.

## 10. Prasyarat sebelum Multi-Business dapat diaktifkan

1. Backfill `business_id` master data + verifikasi tidak ada NULL.
2. Membership setiap user terisi (Q2/Q3/Q14).
3. Keputusan Q5 (settings) & Q4 (uniqueness) disetujui dan migration dijalankan.
4. Resolusi ownership legacy payable/receivable (Q7/Q9/Q15).
5. Jobs/Scheduler isolation selesai.
6. Uji end-to-end dengan flag aktif di lingkungan non-produksi.
