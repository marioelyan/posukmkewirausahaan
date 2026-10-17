# Multi-Business Security Test Plan — KasirKwu

- **Status:** PERENCANAAN / DOKUMENTASI ONLY. Tidak ada test yang dibuat atau dijalankan dalam tugas ini.
- **Basis:** celah terverifikasi di kode pada 2026-10-10 + konvensi test repo (`AGENTS.md`).
- **Label:** **FAKTA**, **REKOMENDASI**, **ASUMSI**, **PERLU KEPUTUSAN**.
- Dokumen terkait: `multi-business-backend-implementation-plan.md`, `multi-business-data-ownership-matrix.md`, `multi-business-open-decisions.md`.

---

## 1. Prinsip & Tujuan

Semua test di bawah **belum ada**. Plan ini mendefinisikan test yang harus dibuat saat implementasi Fase 2-7 untuk membuktikan isolasi tenant.

Prinsip:

- Setiap test membuktikan **negatif** (akses ditolak) dan **positif** (pemilik diizinkan).
- Test harus gagal pada kode saat ini sebelum perbaikan (membuktikan celah), lalu lulus setelah perbaikan.
- Semua test HTTP menerapkan `RefreshDatabase`, seed `PermissionSeeder → RoleSeeder → UserSeeder`, dan memanggil `markEmailAsVerified()` sebelum `actingAs()`.
- Untuk API: `Sanctum::actingAs($user, ['*'])` atau token eksplisit.
- Produk test wajib `tax_rate=0` untuk menghindari PPN.

---

## 2. Konvensi Test (FAKTA dari AGENTS.md)

- `RefreshDatabase` di setiap kelas.
- Admin `arya@gmail.com`; manager `manager@gmail.com`; cashier `cashier@gmail.com`.
- Admin permissions paling lengkap.
- Warehouse `PUSAT` `type='main'`, `is_active=true`, `sort_order=0`.
- Buka shift: `app(CashierShiftService::class)->openShift(...)`.
- PHPUnit 12: tanpa `$faker`; pakai counter/`uniqid()`.
- **PHPUnit 12 deprecated**: perluas `Tests\TestCase` (existing convention).

---

## 3. Matriks Skenario → Celah Terverifikasi

| ID | Skenario | Celah acuan | Prioritas |
|---|---|---|---|
| SEC-01 | User tanpa outlet melihat semua warehouse | `app/Services/OutletAccessService.php:77-88` | P0 |
| SEC-02 | Eskalasi role saat buat user | `UserController.php:79-80`; `UserRequest.php:36-41` | P0 |
| SEC-03 | Eskalasi outlet saat update user | `UserController.php:155-156`; `UserRequest.php:57-66` | P0 |
| SEC-04 | Statistik pelanggan lintas outlet | `CustomerController.php:449,460,476` | P1 |
| SEC-05 | Loyalty global di insights | `AdvancedSalesInsightsController.php:685,688` | P1 |
| SEC-06 | Audience CRM global | `CrmAutomationService.php:49` | P1 |
| SEC-07 | Bank account lintas outlet di checkout | `CheckoutService.php:87-89` | P1 |
| SEC-08 | POS no-shift: produk/scan global | `PosApiController.php:147-158,188` | P1 |
| SEC-09 | Idempotency `client_uuid` global | `PosApiController.php:743,878` | P1 |
| SEC-10 | `orWhereNull('warehouse_id')` | `PurchaseOrderController.php:35`; `GoodsReceivingController.php:56` | P2 |
| SEC-11 | Payable tanpa warehouse | `PayableController.php:70-86` | P2 |
| SEC-12 | Dropdown/laporan global | `Reports/*` | P2 |
| SEC-13 | Import/export produk & customer global | `app/Imports`, `app/Exports` | P2 |
| SEC-14 | Cart dibaca tanpa scope | `PosApiController` (cart) | P2 |
| SEC-15 | Scheduler CRM/reorder global | `routes/console.php:11-14`; `CrmAutomationService.php` | P2 |
| SEC-16 | Webhook lintas business | `PaymentWebhookController.php:66-99,190-220` | PERLU UJI |
| SEC-17 | `active_outlet_id` vs business aktif | `OutletContextController.php`; `OutletAccessService::activeOutlet` | P1 |
| SEC-18 | Ganti `business_id` via payload | desain context | P0 |
| SEC-19 | FK ke record business lain (outlet/warehouse/produk) | desain validasi FK | P0 |
| SEC-20 | Owner A melihat business Owner B | desain laporan owner | P1 |
| SEC-21 | User multi-business role berbeda | desain membership | P1 |
| SEC-22 | Regresi POS baseline | semua | P0 |

---

## 4. Detail Skenario Test

Format tiap skenario: **Tujuan → Setup → Langkah → Hasil diharapkan (negatif & positif) → Tingkat**.

### SEC-01 — User tanpa outlet
- **Tujuan:** membuktikan user non-super-admin tanpa assignment outlet tidak melihat warehouse.
- **Setup:** buat 2 outlet + warehouse masing-masing; user dengan permission akses gudang tetapi **tanpa** `user_outlets`.
- **Langkah:** panggil daftar warehouse/index produk POS.
- **Harapan (negatif):** daftar kosong / 403, **bukan** semua warehouse. **Positif:** setelah assign outlet, hanya warehouse outlet itu terlihat.
- **Tingkat:** HTTP + service (`OutletAccessService::warehousesFor`).

- **FAKTA kondisi sekarang:** karena `if ($outletIds->isNotEmpty())` di `app/Services/OutletAccessService.php:82`, user tanpa outlet **tidak** mendapat filter → test ini **gagal** pada kode sekarang.

### SEC-02 — Eskalasi role saat buat user
- **Setup:** aktor `manager` di outlet X; target baru.
- **Langkah:** kirim `selectedRoles=[super-admin]` (atau role global yang tidak boleh dimiliki manager).
- **Harapan:** 403/validasi; target dibuat hanya dengan role yang diizinkan.

### SEC-03 — Eskalasi outlet saat update user
- **Setup:** aktor `manager` bisnis A; target di bisnis A.
- **Langkah:** kirim `outlet_ids` milik bisnis B (ambiguitas `exists` global).
- **Harapan:** ditolak; assignment hanya outlet dalam business aktif.

### SEC-04 — Statistik pelanggan
- **Setup:** customer C di bisnis A dan transaksi di bisnis B dengan `customer_id` sama (atau customer dengan id milik A diakses dari konteks B).
- **Harapan:** `buildStats`/`recentTransactions`/`frequentProducts` hanya menghitung transaksi business aktif.

### SEC-05 — Loyalty insights
- **Harapan:** `loyaltyPerformance()` hanya menghitung customer & point history business aktif (`AdvancedSalesInsightsController.php:685,688`).

### SEC-06 — CRM audience
- **Harapan:** `buildAudience()` hanya memilih customer business aktif meski tanpa filter outlet (`CrmAutomationService.php:49`).

### SEC-07 — Bank account lintas outlet
- **Setup:** outlet A punya bank account; user outlet B.
- **Langkah:** checkout `bank_transfer` dengan `bank_account_id` milik A (jalur non-tender `CheckoutService.php:87-89`).
- **Harapan:** ditolak; hanya bank account outlet/business sendiri.

### SEC-08 — POS no-shift produk/scan
- **Harapan:** daftar produk (`where('stock','>',0)` di `PosApiController.php:158`) dan endpoint `scan` (`:188`) hanya menampilkan produk business aktif dan stok warehouse user.

### SEC-09 — Idempotency `client_uuid`
- **Setup:** transaksi bisnis A dengan `client_uuid=U`.
- **Langkah:** dari konteks bisnis B, POST transaksi dengan `client_uuid=U`.
- **Harapan:** tidak mengembalikan/mengubah transaksi A; lookup ter-scope (`PosApiController.php:743,878`).

### SEC-10 — `orWhereNull('warehouse_id')`
- **Harapan:** `PurchaseOrderController.php:35` & `GoodsReceivingController.php:56` tidak membocorkan PO/GR lintas business lewat baris `warehouse_id IS NULL`.

### SEC-11 — Payable tanpa warehouse
- **Harapan:** `PayableController::store()` mengisi/memvalidasi kepemilikan; payable tidak lintas business.

### SEC-12 — Dropdown/laporan global
- **Harapan:** dropdown filter (outlet/warehouse/kategori/customer) & laporan hanya data business aktif (`Reports/SalesReportController`, `ProfitReportController`, `AdvancedSalesInsightsController`).

### SEC-13 — Import/export
- **Harapan:** `ProductsImport`, `CustomersImport`, `ProductsExport`, `CustomersExport`, `TransactionsExport` ter-scope business; impor tidak menimpa data business lain (barcode/SKU unique per business).

### SEC-14 — Cart
- **Harapan:** pembacaan cart hanya cart milik user + business aktif.

### SEC-15 — Scheduler
- **Harapan:** `crm:sync-segments`, `crm:generate-reminders`, `reorder:generate`, `transactions:expire` berjalan per business; tidak menyentuh data business lain (`routes/console.php:11-14`).

### SEC-16 — Webhook
- **Setup:** transaksi bisnis A dengan referensi gateway.
- **Langkah:** kirim webhook menargetkan referensi bisnis A/B.
- **Harapan:** webhook hanya mengubah transaksi yang benar; verifikasi tanda tangan tetap; lintas business ditolak.
- **Catatan:** `PaymentWebhookController.php:66-99,190-220` perlu diaudit lebih dalam (perilaku sign-off untuk demo mode).

### SEC-17 — `active_outlet_id` vs business aktif
- **Harapan:** mengganti business aktif mengganti/menyetel ulang outlet aktif; `active_outlet_id` yang tidak termasuk business → ditolak.

### SEC-18 — Ganti `business_id` via payload
- **Harapan:** body/query `business_id`/`outlet_id`/`warehouse_id` yang bukan milik user ditolak; context hanya dari server.

### SEC-19 — FK lintas business
- **Harapan:** membuat transaksi/produk/PO dengan `outlet_id`/`warehouse_id`/`product_id`/`supplier_id` milik business lain → ditolak.

### SEC-20 — Owner report
- **Harapan:** owner A tidak bisa melihat/menggabungkan business B; laporan gabungan hanya business milik owner.

### SEC-21 — Multi-business role
- **Harapan:** user dengan role `manager` di A dan `staff` di B mendapat izin berbeda per konteks; tidak bocor.

### SEC-22 — Regresi POS
- **Harapan:** seluruh alur POS existing (cart, hold/resume, checkout, tender, shift) tetap lulus baseline test.

---

## 5. Template Kode Test (REKOMENDASI, belum ditulis)

Contoh kerangka (perlu disesuaikan dengan `tests/TestCase.php` existing):

```php
// tests/Feature/MultiBusiness/OutletIsolationTest.php
use App\Models\{User, Outlet, Warehouse};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutletIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            \Database\Seeders\PermissionSeeder::class,
            \Database\Seeders\RoleSeeder::class,
        ]);
    }

    public function test_user_without_outlet_sees_no_warehouse_after_fix(): void
    {
        // arrange: 2 outlet, 2 warehouse, user tanpa user_outlets
        // act: panggil service/endpoint
        // assert: daftar kosong / 403 (BUKAN semua)
    }
}
```

**ASUMSI.** Struktur `tests/` sedang berubah di working tree; verifikasi path final sebelum menulis test pada implementasi.

---

## 6. Strategi Eksekusi

- **Urutan:** SEC-01/02/03/18/19 (P0) dulu, lalu P1, lalu P2.
- **Pendekatan:** untuk celah P0, tulis test yang **gagal** di kode sekarang (red), implementasi perbaikan, lalu test lulus (green).
- **Gate:** PR tidak boleh merge bila ada SEC P0/P1 yang belum lulus.
- **Otomatisasi:** jalankan lewat `php artisan test --filter=MultiBusiness` atau per kelas.
- **Data:** buat helper factory membership bisnis + outlet + warehouse untuk mengurangi duplikasi.

---

## 7. Kriteria Kelulusan

- Semua SEC-01..SEC-22 lulus.
- Tidak ada SEC yang hanya diuji positif tanpa negatif.
- Regression suite existing tetap hijau (`php artisan test`).
- Tidak ada test yang bergantung fitur khusus MySQL (SQLite in-memory).
