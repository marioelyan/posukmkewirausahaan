# Audit Arsitektur SaaS Multi-Business (Tenant) — KasirnyaKWU

Kembali ke indeks dokumentasi: `docs/README.md`

- **Status:** AUDIT ONLY — tidak ada kode, migration, konfigurasi, atau data yang diubah.
- **Tanggal audit:** 2026-10-10
- **Lingkup:** seluruh repository (migration, model, controller, service, middleware, route, seeder, test, frontend).
- **Metode:** pembacaan statis source code dan migration. Tidak ada query yang mengubah data.

> Semua temuan menyertakan bukti `path:line`. Nama tabel/route/status yang tidak ditemukan tidak dikarang.

---

## 1. Executive Summary

Aplikasi saat ini adalah **single-tenant, multi-outlet**, bukan multi-business.

Fakta inti (berbasis bukti repository):

- **Tidak ada tabel `businesses` dan tidak ada kolom `business_id` di seluruh migration.** Hierarki kepemilikan berhenti di `outlets → warehouses`.
- **"Business type" hanya sebuah setting global** (`store_business_type`, `SetupController.php:180`) yang dipakai sekali saat instalasi untuk mengisi nama kategori. Ia bukan entitas.
- **Role & permission bersifat global.** `config/permission.php:126` `'teams' => false`; tidak ada kolom `team_id`. `model_has_roles` hanya di-key oleh `role_id + model_id + model_type`.
- **Keanggotaan outlet terpisah dari Spatie**: pivot `user_outlets(user_id, outlet_id, is_default)` (`database/migrations/2026_09_14_000003_create_user_outlets_table.php`).
- **Master data global**: `products`, `categories`, `customers`, `suppliers`, `units`, `price_lists` tidak punya `outlet_id`.
- **Outlet aktif hanya di session** (`active_outlet_id`, `app/Http/Controllers/OutletContextController.php:21`) dan bisa "dipaksa" oleh shift kasir yang terbuka (`app/Services/OutletAccessService.php:116-138`).
- **`OutletAccessService` adalah satu-satunya choke point scoping** dan dipakai di banyak tempat (controller, service, middleware).
- **Tidak ada `app/Policies/`.** Otorisasi = `permission:` middleware + override `User::hasPermissionTo()` untuk `super-admin` (`app/Models/User.php:104-120`).

Konsekuensi: aplikasi **belum aman** untuk multi-business. Beberapa query sudah outlet/warehouse-scoped, tetapi masih ada **celah lintas-outlet terkonfirmasi** dan banyak area yang mengandalkan ID global (produk, customer, supplier) yang akan bocor antar-business jika dijalankan bersama.

---

## 2. Arsitektur Saat Ini (berbasis bukti)

### 2.1 Struktur kepemilikan aktual

```text
User ──(model_has_roles, GLOBAL)──> Role ──(role_has_permissions)──> Permission
  │
  └──(user_outlets, is_default)──> Outlet ──> Warehouse ──(product_warehouse)──> Product
                                       │
                                       └──(outlet_id, nullable)──> settings, payment_settings,
                                                                    bank_accounts, pricing_rules,
                                                                    price_lists, dine_areas,
                                                                    customer_vouchers, customer_campaigns
Operational rows scope: warehouse_id → transactions, carts, stock_mutations, cashier_shifts,
                        purchase_orders, goods_receivings, supplier_returns, stock_opnames,
                        sales_returns, product_batches, stock_transfers(source/destination)
```

Bukti: `app/Services/OutletAccessService.php:16-188`; `app/Models/User.php:127-130`; `app/Models/Outlet.php:33-41`; `app/Models/Warehouse.php:41-44`.

### 2.2 Model peran saat ini

Sumber: `database/seeders/RoleSeeder.php`.

- `super-admin` → `Permission::all()` (`RoleSeeder.php:61-62`), plus override bypass di `User::hasPermissionTo` (`app/Models/User.php:104-111`).
- `manager` → semua permission **kecuali** `users-%`, `roles-%`, `permissions-%`, `outlets-%`, `whatsapp-settings-%`, `payment-settings-update` (`RoleSeeder.php:92-103`).
- `cashier` → daftar eksplisit (`RoleSeeder.php:64-81`).
- Role lain dibuat otomatis dari nama permission via `createRoleWithPermissions` (`RoleSeeder.php:23-59`).

**Tidak ada role bisnis literal "owner".** "Owner" saat ini kira-kira = `super-admin`. Role global ini **tidak** bisa membedakan business.

### 2.3 Instalasi pertama

Sumber: `app/Http/Controllers/SetupController.php:103-185`.

Membuat: 1 user `super-admin`, outlet `PUSAT` (non-sales) + warehouse `main`, N branch outlet+warehouse, N kategori global, profil toko global, `store_business_type`, `app_setup_completed=true`. Tidak ada entitas business. Kode outlet/warehouse di-`unique` **global** (`SetupController.php:88,96`).

### 2.4 Frontend

- `resources/js/Components/Dashboard/OutletSwitcher.jsx` hanya muncul bila ≥2 outlet; mengirim `outlet_id` ke `POST /dashboard/outlet` (`route('outlet.switch')`).
- Props Inertia: `auth.currentOutlet`, `auth.outlets`, `auth.outletLocked` (`app/Http/Middleware/HandleInertiaRequests.php:237-247`).
- **Tidak ada business switcher**; grep `business` di `resources/js` hanya menemukan i18n & Setup wizard.
- Persistensi outlet murni session; default DB via `user_outlets.is_default`.

---

## 3. Diagram Arsitektur Target

```text
                        ┌──────────────────────────────────────────────┐
                        │                 Aplikasi (1x)                  │
                        │           Frontend Inertia/React (1x)          │
                        │             Database bersama (1x)              │
                        └──────────────────────────────────────────────┘
Authenticated User
      │  (business_user: role, status, business_id)
      ▼
Business (tenant root)  ──< Membership (business_user / user_business)
      │  business_id
      ├──< Outlet (business_id)  ──< Warehouse (outlet_id)
      ├──< Product / Category / Customer / Supplier / Unit / PriceList  (business_id)
      ├──< Transaction / Cart / StockMutation / Shift / PO / GR / ...    (business_id via outlet/warehouse)
      └──< Expense (business_id, outlet_id nullable)

Authorization flow (wajib di server):
Authenticated User → Verified Membership → Active Business → Outlet/Warehouse Access → Permission Check → Query scoped by business_id
```

Prinsip: `Business` adalah batas kepemilikan; `Outlet` tetap ada sebagai cabang; `business_id` ditempel **selektif** (bukan membabi buta), dengan `outlet_id`/`warehouse_id` sebagai scoping operasional yang sudah ada.

---

## 4. Peta Tabel & Relasi

Klasifikasi: 1=global aplikasi, 2=milik business, 3=milik outlet, 4=milik warehouse, 5=milik user, 6=turunan/agregat.

| Tabel | Klasifikasi | Boundary saat ini | Perubahan potensial | Risiko |
|---|---|---|---|---|
| `users` | 1 | global | + relasi membership (`business_user`) | login multi-business, default business |
| `outlets` | 2 | root tenant | + `business_id` | unik `code` global → harus unique per business |
| `warehouses` | 3 | `outlet_id` (nullable) | turunkan `business_id` via outlet | warehouse tanpa outlet (PUSAT legacy) |
| `user_outlets` | 5 | pivot user↔outlet | pertahankan; jembatan kompat | tidak membawa role/business |
| `businesses` (baru) | 2 | **tidak ada** | tabel baru tenant root | mapping data lama |
| `business_user` (baru) | 2 | **tidak ada** | membership + role/status | migrasi dari `user_outlets` |
| `roles` / `permissions` | 1 | global, teams=false | enable teams ATAU mapping role per business | perubahan besar bila teams=true |
| `model_has_roles` | 1 | global | pertimbangkan role per business | role tidak bisa beda antar business |
| `products` | 2 | global | + `business_id` | barcode/sku unique global → per business |
| `categories` | 2 | global | + `business_id` | unicite nama |
| `customers` | 2 | global | + `business_id` | member_code unique global |
| `suppliers` | 2 | global | + `business_id` | lookup global saat ini |
| `units`, `product_units`, `composite_product_items` | 2 | global | + `business_id` (units) | converter lintas business |
| `price_lists` / `price_list_items` | 2/3 | `outlet_id` | + `business_id` | — |
| `pricing_rules` (+ qty_breaks/bundle/buy_get) | 3 | `outlet_id` | + `business_id` | — |
| `customer_segments`, `customer_segment_memberships`, `customer_campaigns`, `customer_campaign_logs` | 2/3 | `outlet_id` (campaigns) | + `business_id` | audience CRM saat ini global (lihat §7) |
| `loyalty_point_histories`, `customer_vouchers` | 2/3 | outlet (vouchers) | + `business_id` | ledger global saat ini |
| `transactions` | 4 | `warehouse_id` | + `business_id` (denormalisasi) | tidak ada kolom `status` |
| `transaction_details`, `transaction_tenders`, `transaction_detail_batch_allocations` | 6 | via transaksi | ikut induk | — |
| `profits` | 6 | via transaksi | ikut induk | laba global saat ini |
| `carts` | 4/5 | `warehouse_id` + `cashier_id` | + `business_id` | cart global saat ini |
| `cashier_shifts`, `shift_cash_movements` | 4 | `warehouse_id` | + `business_id` | — |
| `stock_mutations`, `product_batches`, `stock_opnames(+items)`, `stock_transfers(+items)` | 4 | `warehouse_id` | + `business_id` | transfer antar business harus dilarang |
| `purchase_orders`, `goods_receivings`, `supplier_returns` (+items) | 4 | `warehouse_id` | + `business_id` | — |
| `receivables`, `receivable_payments`, `payables`, `payable_payments`, `customer_credits` | 2/4 | via transaksi/user | + `business_id` | payable tanpa warehouse (lihat §7) |
| `sales_returns` (+items) | 4 | `warehouse_id` | + `business_id` | — |
| `settings` | 1/2/3 | `outlet_id` (nullable=global) | + `business_id`; pisahkan setting app-instance vs business | setting global saat ini bocor antar business |
| `payment_settings`, `bank_accounts` | 3 | `outlet_id` | + `business_id` | secret gateway per business |
| `dine_areas`, `dine_tables`, `dine_orders`, `dine_order_items` | 3 | `outlet_id` (area) | + `business_id` | — |
| `audit_logs` | 1/2 | morph global | + `business_id` (context) | audit lintas business saat ini |
| `product_notification_reads`, `discount_approval_logs` | 5 | user | + `business_id` | — |
| `personal_access_tokens`, `jobs`, `cache`, `sessions`, region (`provinces…villages`) | 1 | global | tetap global (region memang global) | token tidak membawa business |

Bukti: seluruh daftar berasal dari `database/migrations/` (dipetakan lengkap; tidak ada `business_id` di manapun).

Tabel dengan `outlet_id`: `warehouses`, `user_outlets`, `settings`, `payment_settings`, `bank_accounts`, `pricing_rules`, `price_lists`, `dine_areas`, `customer_vouchers`, `customer_campaigns`.
Tabel dengan `warehouse_id`: `product_warehouse`, `product_batches`, `cashier_shifts`, `transactions`, `carts`, `stock_mutations`, `purchase_orders`, `goods_receivings`, `supplier_returns`, `stock_opnames`, `sales_returns`; `stock_transfers` memakai `source_warehouse_id`/`destination_warehouse_id`.

---

## 5. Matriks Kepemilikan Data (ringkas)

| Kategori | Contoh tabel | Implikasi tenant |
|---|---|---|
| Global aplikasi | `users`, `permissions`, `roles`, region, `jobs`, `cache`, `sessions` | tidak diberi `business_id` |
| Milik business | `products`, `categories`, `customers`, `suppliers`, `units`, `price_lists`, `settings`(app-level) | **wajib** `business_id`; unique key jadi per-business |
| Milik outlet | `pricing_rules`, `bank_accounts`, `payment_settings`, `dine_areas`, `customer_campaigns/vouchers` | sudah `outlet_id`; tambahkan `business_id` untuk query cepat |
| Milik warehouse | transaksi, stok, PO, GR, opname, transfer, sales return | sudah `warehouse_id`; tambahkan `business_id` denormal |
| Milik user | `product_notification_reads`, `discount_approval_logs`, `user_outlets` | aman |
| Turunan | `profits`, `transaction_details`, `loyalty_point_histories`, `customer_credits` | ikut induk; hindari `business_id` ganda yang bisa kontradiktif |

Prinsip: **jangan tempel `business_id` ke tabel pivot/child** yang sudah punya induk bertipe business; cukup pastikan query selalu melewati induk (`whereHas`). `business_id` denormalisasi hanya pada tabel yang sering difilter langsung (transactions, carts).

---

## 6. Model Owner–Business–Manager–Staff

Model target (konseptual, belum implementasi):

```text
Owner A ──< Business 1 ──< Membership(Manager 1, Staff 1, Staff 2)
        └──< Business 2 ──< Membership(Manager 2, Staff 3)
Owner B ──< Business 3 ──< Membership(Manager 3, Staff 4)
```

- `Owner` = user dengan membership `role=owner` pada business (bukan flag global).
- Satu user boleh punya banyak membership (business berbeda) dengan role berbeda.
- `Membership` menyimpan `business_id`, `user_id`, `role`, `status`, opsional `default_outlet_id`.
- Outlet/warehouse diakses lewat membership business, bukan lewat `user_outlets` global.

Pemetaan ke sistem sekarang:

- `super-admin` (global) → hanya untuk **operator platform/installasi**, bukan owner business.
- `manager` / `cashier` global → dipecah menjadi membership per business; permission Spatie tetap dipakai sebagai *ability* di dalam business terverifikasi.
- `user_outlets` tetap dipertahankan sebagai pembatas outlet di dalam business.

---

## 7. Tenant Isolation Risk Matrix

Kategori: **C**=terbukti (kode), **L**=risiko masuk akal, **U**=belum terverifikasi.

| # | Kat | Temuan | Bukti | Skenario cross-business |
|---|---|---|---|---|
| 1 | C | `warehousesFor` tidak memfilter bila user tak punya outlet (`if ($outletIds->isNotEmpty())`) | `app/Services/OutletAccessService.php:77-88` (baris 82) | User non-superadmin **tanpa** assignment outlet → melihat **semua** warehouse/outlet → semua data operasional |
| 2 | C | `UserController`/`UserRequest` izinkan assign outlet & role sembarang | `app/Http/Controllers/UserController.php:44-57,116,79-80,155-156`; `app/Http/Requests/UserRequest.php:36-41,57-66`; `routes/web.php:112-119` | Pemegang `users-*` memberi dirinya akses ke semua outlet + role apa pun (eskalasi lintas business) |
| 3 | C | Riwayat/statistik customer tanpa scope warehouse | `app/Http/Controllers/Apps/CustomerController.php:447-484,260-304,341-392`; `app/Http/Controllers/Apps/MemberController.php:69-71,261-298` | Customer global → user Outlet A melihat invoice/total/produk milik Outlet B |
| 4 | C | Loyalty global di insights | `app/Http/Controllers/Reports/AdvancedSalesInsightsController.php:682-740` | Daftar/total poin member seluruh outlet bocor |
| 5 | C | CRM campaign audience mengabaikan `outlet_id` | `app/Services/CrmAutomationService.php:45-104` (`Customer::query()` baris 49); `CrmCampaignController::processCampaign` tanpa arg outlet | Campaign outlet A dikirim ke customer business lain |
| 6 | C | `bank_transfer` non-tender terima `bank_account_id` mana pun | `app/Services/CheckoutService.php:84-89`; bandingkan guard outlet di `app/Services/TransactionTenderService.php:107` | Uang masuk tercatat ke rekening outlet/business lain |
| 7 | C | POS no-shift: listing produk & scan global | `app/Http/Controllers/Api/PosApiController.php:150-158,191-207` (fallback `$product->stock` baris 205) | Kasir tanpa shift melihat/menjual produk business lain |
| 8 | C | `ProductController` + `ProductResource` ekspos semua produk + stok agregat global | `app/Http/Controllers/Apps/ProductController.php:27-33,90,112-117,126,153`; `app/Http/Resources/ProductResource.php:27` | Manajemen produk lintas business |
| 9 | C | Lookup idempotensi `client_uuid` tidak ter-scope | `app/Http/Controllers/Api/PosApiController.php` (checkout/sync, lookup by `client_uuid`) | Transaksi business lain ter-anggap milik sendiri |
| 10 | L | `orWhereNull('warehouse_id')` langsung (tanpa guard single-outlet) | `app/Http/Controllers/Apps/PurchaseOrderController.php:35`; `app/Http/Controllers/Apps/GoodsReceivingController.php:56` | Baris legacy `warehouse_id=NULL` terlihat semua outlet |
| 11 | L | `PayableController::store` tanpa `warehouse_id` | `app/Http/Controllers/Apps/PayableController.php` (store) | Hutang tak terlihat / orphan di multi-outlet |
| 12 | L | Dropdown filter laporan menampilkan semua user/customer | `app/Http/Controllers/Reports/SalesReportController.php:86-87`; `app/Http/Controllers/Reports/ProfitReportController.php:79-80`; `app/Http/Controllers/Reports/AdvancedSalesInsightsController.php:90-92` | Kebocoran nama lintas business |
| 13 | L | Update/hapus produk, export produk/customer, import via barcode global | `app/Imports/ProductsImport.php:42`; `app/Exports/ProductsExport.php:15`; `app/Exports/CustomersExport.php:15`; `ProductController` (update/destroy) | Overwrite/hapus produk customer business lain |
| 14 | L | Cart POS dibaca tanpa scope eksplisit di beberapa jalur | `app/Http/Controllers/Api/PosApiController.php` (cart reads) | Keranjang lintas user/business (tergantung `cashier_id`) |
| 15 | L | Scheduled CRM/reorder memakai audience global | `routes/console.php:11-14`; `app/Services/CrmAutomationService.php:288,329,356,383`; `app/Console/Commands/GenerateReorderCommand.php` | Reminder/reorder lintas business |
| 16 | U | Webhook gateway → update transaksi by reference | `app/Http/Controllers/Api/PaymentWebhookController.php:66-99,190-220` | Perlu uji apakah reference bisa menabrak transaksi business lain |
| 17 | U | `active_outlet_id` dari session vs business aktif | `app/Http/Controllers/OutletContextController.php:21`; `OutletAccessService::activeOutlet` | Belum ada business context; perlu desain |

Catatan penting: **jangan menyimpulkan aman hanya karena ada role/permission** — permission di sini global, sehingga tidak memisahkan data antar business.

### 7.1 Area yang sudah scoped dengan benar (verified GOOD)

Stock transfer (`StockTransferService`, gate `ensureWarehouseAccess`), purchase orders / goods receiving / supplier returns / stock opname / discount approval / customer voucher / audit log / CRM campaign list / import-export (export), transaksi (`history` dan `ensureTransactionAccess`), sales return, cashier shift, cashier/report revenue metric, settings/price list/pricing rule/bank account, notifikasi Inertia, document/public portal/outlet switch. Detail bukti pada §8.

---

## 8. Daftar File/Kelas/Metode Relevan

- **Tenancy inti:** `app/Services/OutletAccessService.php` (`canUseWarehouse`, `warehousesFor`, `accessibleOutlets`, `activeOutlet`, `legacySingleOutletBypass`), `app/Http/Controllers/OutletContextController.php`, `app/Http/Middleware/HandleInertiaRequests.php:48-247`.
- **Authz:** `app/Models/User.php:99-120`, `app/Providers/AppServiceProvider.php:28-35`, `bootstrap/app.php:36-56`, `config/permission.php:126`, `config/security.php:22-29`.
- **Setup/seeder:** `app/Http/Controllers/SetupController.php:103-185`, `database/seeders/RoleSeeder.php`, `database/seeders/PermissionSeeder.php`, `database/seeders/UserSeeder.php`, `database/seeders/DemoOutletSeeder.php`, `database/seeders/DatabaseSeeder.php:31-75`.
- **Contoh scoping benar:** `app/Services/StockTransferService.php` (`ensureWarehouseAccess:261-266`), `app/Services/PurchaseOrderService.php:37`, `app/Services/GoodsReceivingService.php:47`, `app/Services/SupplierReturnService.php:43,92,171-176`, `app/Http/Controllers/DocumentController.php`, `app/Http/Controllers/PublicPortalController.php`, `app/Http/Controllers/Apps/TransactionController.php:805-812,935-939`.
- **Risiko:** file pada §7.

---

## 9. Strategi Migrasi & Rollback (data lama)

- **Apakah sudah ada konsep business yang bisa dipakai ulang?** Tidak ada `businesses`. Yang bisa dipakai ulang: hierarki `outlets → warehouses`, `store_business_type`, dan profil toko global.
- **Pemetaan data lama:** untuk instalasi single-tenant existing → buat **satu** `Business` default (dari profil toko/`store_name`), assign **semua** outlet ke business itu, backfill `business_id` pada tabel domain bisnis dengan nilai business default tersebut.
- **Relasi outlet/warehouse/produk/transaksi dipertahankan:** backfill via `outlet_id`/`warehouse_id`; transaksi/produk lama → business default. Jangan ubah PK/FK operasional.
- **Deteksi data yatim:** `warehouses.outlet_id IS NULL` (PUSAT legacy), produk tanpa pivot, `settings.outlet_id IS NULL`, `products.stock` vs `SUM(product_warehouse.stock)`. Manfaatkan `php artisan outlet:audit`, `php artisan outlet:legacy-audit`, `php artisan inventory:reconcile` (read-only, `--fix` terpisah).
- **Tabel yang berubah:** tambah `businesses`, `business_user`; tambah `business_id` selektif (§5); ubah unique `outlets.code`, `warehouses.code`, `products.barcode/sku`, `customers.member_code`, `settings(key)` dari global → per-business.
- **Urutan migration aman:** (1) create `businesses`; (2) seed business default; (3) add nullable `business_id` kolom; (4) backfill; (5) add FK+index; (6) tighten unique; (7) enable constraint non-null setelah verifikasi.
- **Backup/restore:** `mysqldump` full + verifikasi restore ke DB staging; jalankan `inventory:reconcile` & `outlet:audit` sebelum-sesudah.
- **Dry run:** kloning DB ke staging, jalankan migration + backfill + audit; bandingkan jumlah row & checksum aggregate (`COUNT`, `SUM(grand_total)` per outlet).
- **Rollback:** setiap migration punya `down()`; simpan snapshot kolom `business_id` sebelum tighten; feature-flag agar app bisa fallback bila `business_id` null.
- **Cegah operasi tanpa mapping:** middleware `setup`-style/`business.required` yang memaksa pilih business, menolak query bila `business_id` null saat multi-business aktif.

---

## 10. Strategi Laporan Owner

| Metrik | Definisi saat ini | Bukti | Target tenant |
|---|---|---|---|
| Omzet | `SUM(grand_total)` seluruh transaksi di warehouse user | `app/Http/Controllers/DashboardController.php:46,52`; `app/Http/Controllers/Reports/SalesReportController.php:53-59` | `whereIn(business_id, owner businesses)` |
| Jumlah transaksi | `COUNT(*)` **semua** status | `app/Http/Controllers/DashboardController.php:44` | count `payment_status='paid'` (lihat §11.4) |
| Transaksi completed | tidak ada kolom `status`; hanya `payment_status` | Model `Transaction`; `app/Services/CheckoutService.php:84-86` | gunakan `payment_status='paid'` |
| Refund/pembatalan | lewat `sales_returns` (status `completed`) | `app/Http/Controllers/Apps/TransactionController.php:846`; model `SalesReturn` | scope business |
| Pengeluaran | **belum ada** modul expense | grep tidak menemukan `expense` | tabel `expenses` baru (§11.2) |
| Laba kotor | `profits.total` | `app/Http/Controllers/DashboardController.php:47`; `app/Services/CheckoutService.php:210-215` | scope business |
| Ringkasan per business/outlet | belum ada; hanya per warehouse/outlet | `app/Http/Controllers/DashboardController.php:24-40` | agregasi lintas business milik owner |

Opsi update dashboard (sesuai shared hosting):

- **Direkomendasikan: query langsung dengan tenant scope** + cache singkat (`cache` table) — paling kompatibel.
- Polling ringan opsional (mis. status pembayaran) — sudah ada `transactions/{invoice}/status`.
- **Hindari asumsi** WebSocket/queue worker selalu ada; broadcasting tidak disarankan sebagai default.

---

## 11. Dampak terhadap Part 2

### 11.1 Customer di cart POS opsional

- **Sudah opsional di backend**: `app/Http/Controllers/Apps/TransactionController.php:653` (`$request->filled('customer_id') ? ... : null`), `app/Http/Controllers/Api/PosApiController.php:528-534,583`. Komentar desain: `TransactionController.php:622`; frontend `resources/js/Pages/Dashboard/Transactions/Index.jsx:670` (“walk-in tanpa pelanggan boleh; nota piutang wajib pelanggan”).
- **File terdampak:** `resources/js/Pages/Dashboard/Transactions/Index.jsx` (UI wajib/tidak), `TransactionController::store`, `PosApiController::checkout`.
- **Business boundary:** `customer_id` harus divalidasi milik business aktif (saat ini `exists:customers,id` global — lihat §7.3).
- **Risiko bila sebelum tenant isolation:** customer business lain bisa ditempel ke transaksi.
- **Dependensi:** validasi customer ter-scope business.

### 11.2 Fitur Expenses

- **Belum ada sama sekali** (tidak ada tabel/model/controller/route). Closest: `payables` (hutang supplier) & `profits`.
- **Kepemilikan business:** `expenses.business_id` (wajib) + `outlet_id` (nullable, opsional per cabang) + `created_by`.
- **File terdampak:** migration baru, model, `ExpenseController`, route di `routes/web.php`, permission (`expenses-access`), menu, laporan laba.
- **Risiko sebelum tenant isolation:** pengeluaran tercampur antar business pada laporan laba.
- **Dependensi:** `businesses` + membership.

### 11.3 Perbaikan HTTP 500

- **Tidak dapat dipastikan dari audit statis.** Kandidat yang perlu repro (bukan klaim):
  - Query menjadikan `business_id`/`outlet_id` non-null sebelum backfill.
  - `PayableController::store` tanpa warehouse (404/orphan, bukan 500).
  - Jalur POS tanpa shift (`app/Http/Controllers/Api/PosApiController.php:618`, komentar “legacy 422 ... clean JSON error”).
  - Modul baru tanpa migration (lihat gotcha repo).
- **Status: U (belum terverifikasi)** — butuh log `storage/logs` / repro nyata. Rekomendasi: kumpulkan stack trace sebelum menyentuh kode.

### 11.4 Menghitung transaksi completed saja

- **Tidak ada kolom `status` di `transactions`.** Hanya `payment_status` dengan nilai: `paid`, `unpaid`, `pending`, `pending_approval`, `failed`.
  - Bukti: `app/Services/CheckoutService.php:84-86`; `app/Http/Controllers/Apps/TransactionController.php:677,896,904`; `app/Console/Commands/ExpirePendingTransactionsCommand.php:25,96`; `app/Http/Controllers/Api/PaymentWebhookController.php:66,90,99`.
- **Saat ini hitungan memakai semua baris:** `app/Http/Controllers/DashboardController.php:44` (`count()` tanpa filter), `app/Http/Controllers/Reports/SalesReportController.php:54-58`. Yang sudah benar: `app/Services/CashierShiftService.php:88,97` (`payment_status='paid'`).
- **File terdampak:** `DashboardController`, `Reports/SalesReportController`, `Reports/ProfitReportController`, `Reports/AdvancedSalesInsightsController`, `CashierShiftService`.
- **Dependensi:** definisi resmi “completed” (usulan `payment_status='paid'`) **harus diputuskan** dulu (lihat §14).

---

## 12. Rencana Implementasi Bertahap

1. **Tahap 1 — Dokumentasi & keputusan arsitektur** (dokumen ini + jawaban §14).
2. **Tahap 2 — Desain model** `businesses` + `business_user` (membership role/status), pemetaan role bisnis.
3. **Tahap 3 — Desain migrasi & pemetaan data lama** (§9) beserta dry-run staging.
4. **Tahap 4 — Tenant context & authorization** (middleware pilih/verifikasi business, policy, service layer).
5. **Tahap 5 — Pengamanan query & relasi seluruh domain** (products, customers, suppliers, transaksi, laporan, import/export, API, jobs).
6. **Tahap 6 — Uji isolasi ≥2 business** (owner A vs owner B; manager/staff).
7. **Tahap 7 — Verifikasi POS, laporan, import/export, background jobs.**
8. **Tahap 8 — Baru lanjut Part 2** (customer opsional, expenses, 500, completed count).

Koreksi prioritas berdasarkan audit: selesaikan dulu **celah §7 #1 dan #2** (keduanya bocor bahkan di struktur saat ini) sebelum Part 2.

---

## 13. Pengujian yang Wajib Dibuat

- Isolasi owner: Owner A tidak bisa melihat/mengubah business Owner B (semua menu, laporan, API, export).
- Isolasi manager/staff per outlet/warehouse (lanjutkan pola `tests/Feature/Inventory/OutletAccessGuardTest.php`).
- `warehousesFor` untuk user tanpa outlet → harus kosong (regresi §7 #1).
- `UserController` store/update: outlet & role di luar kewenangan → 403.
- Customer/member history: transaksi outlet lain tidak muncul.
- CRM campaign audience hanya customer business terkait.
- `bank_transfer`/tender: `bank_account_id` luar business → ditolak.
- POS tanpa shift: produk hanya dari business aktif.
- Idempotensi `client_uuid` lintas business tidak saling menimpa.
- Count transaksi = hanya `payment_status='paid'`.
- Uji data lama: setelah backfill, `outlet:audit`, `inventory:reconcile`, dan checksum aggregate cocok.

---

## 14. Pertanyaan Terbuka (butuh keputusan)

1. Definisi resmi “transaksi completed”: `payment_status='paid'`? Bagaimana `pending_approval`, `unpaid` (piutang), `failed`?
2. “Owner” → role global baru, atau membership `owner` per business? Apakah `super-admin` tetap global (operator platform)?
3. Satu user multi-business: role boleh berbeda per business (butuh role per business / Spatie teams), atau role disamakan?
4. Apakah master data (produk/customer/supplier) boleh dibagi antar business, atau wajib terpisah penuh?
5. Setting mana yang global aplikasi vs milik business (mis. `store_name`, gateway)?
6. Apakah `business` baru dibuat via wizard terpisah (self-service SaaS) atau admin platform?
7. Bila sebuah instalasi existing sudah punya banyak outlet: apakah itu dianggap banyak *branch* (satu business) atau banyak *business*?
8. Shared hosting tanpa queue worker: bolehkah background job sinkron?

---

## 15. Fakta, Asumsi, Belum Terverifikasi

**Fakta (bukti repo):** tidak ada `businesses`/`business_id`; Spatie teams=false; scoping lewat outlet/warehouse; `OutletAccessService` choke point; role seeder global; tidak ada modul expense; tidak ada kolom `status` transaksi (hanya `payment_status`); customer checkout sudah nullable; `legacy_single_outlet_bypass` default `true` (`config/security.php:28`).

**Asumsi:** “completed” = `payment_status='paid'`; Owner ≈ business owner (bukan super-admin); shared hosting tanpa queue.

**Belum terverifikasi:** penyebab pasti HTTP 500 (butuh log/repro); perilaku webhook lintas business; dampak penuh `Products`/`Customers` export/import terhadap data bersama; apakah `active_outlet_id` bisa menyilangkan business tanpa validasi tambahan.

---

## Ringkasan

- Arsitektur saat ini **single-tenant multi-outlet**; belum layak multi-business.
- Ada **9 celah terkonfirmasi** dan **7 risiko** lintas-outlet/business (prioritas: `warehousesFor` tanpa assignment, eskalasi `UserController`, agregasi customer, CRM audience, bank_transfer, POS tanpa shift).
- Model target: `Business → Outlet → Warehouse`, dengan `Membership` (owner/manager/staff) dan `business_id` selektif.
- Migrasi aman: business default → backfill → tighten constraint, dengan dry-run staging + rollback.
- Part 2 (customer opsional, expenses, 500, completed count) **sebaiknya setelah** tenant isolation; expenses baru butuh `business_id` + `outlet_id`.
