# Audit Kode — Advanced Sales Insights

`GET /dashboard/reports/insights` → `Reports\AdvancedSalesInsightsController@index`
Permission: `reports-access`. Halaman: `resources/js/Pages/Dashboard/Reports/Insights.jsx`.

## 0. Status implementasi (update 2026-10-17)

Sudah dikerjakan:

- **A1** — jendela default 30 hari + tombol "Tampilkan semua waktu"/"Kembali ke 30 hari"
  (`DEFAULT_WINDOW_DAYS`, prop `range`, tombol `all_time`). Form tanggal tetap kosong
  saat default dipakai.
- **A2** — `pluck('id')` + dua `whereIn` diganti subquery agregat; `orders_count`
  dipakai ulang dari query summary (`summaryMetrics()`).
- **A8** — pemanggilan `warehousesFor()` ganda dihapus (`warehouseIdsFor()`).
- **B2** — kartu/badge segment disembunyikan saat `multiBusinessEnabled`.
- **B3** — cabang `out_of_stock` yang tak terjangkau dihapus; `coverageStatus()`
  kini hanya menurunkan empat bucket yang benar-benar bisa terjadi.
- **B5** — dropdown kasir dibatasi ke anggota aktif business + super-admin saat tenancy aktif.
- **C2** — migrasi `2026_10_17_000001_add_payment_status_index_to_transactions`
  menambahkan index komposit `(payment_status, warehouse_id, created_at)`.
- **Baru** — export XLSX multi-sheet (`reports.insights.export`, `App\Exports\InsightsExport`).
- **Baru** — delta vs periode pembanding di kartu ringkasan (`summary.comparison`).

Belum dikerjakan (masih terbuka): A3–A7 (agregasi ke SQL), B1 (semantik filter
kategori), B4 (`recent_audits`), Tier 3 lain (day-of-week, dead stock, insight
naratif, draft PO, WhatsApp), C1 (`Insights.jsx` masih satu komponen besar),
C3 (test semantik filter kategori).

## 1. Inventaris fungsi

Satu action `index()` menjalankan **12 blok metrik** (tanpa cache, tanpa pagination):

| Blok | Method | Sumber |
|---|---|---|
| Summary (omzet, profit, item, diskon manual, AOV) | `index()` | `transactions`, `transaction_details`, `profits` |
| Top Selling Products (10) | `topSellingProducts()` | `transaction_details` + `product_warehouse` |
| Low Performing Products (10) | `lowPerformingProducts()` | `products` LEFT JOIN sales |
| Margin by Product (10) | `marginByProduct()` | `transaction_details` |
| Margin by Category | `marginByCategory()` | `transaction_details` |
| Sales by Hour (24 bucket) | `salesByHour()` | `transactions` |
| Sales by Day | `salesByDay()` | `transactions` |
| Cashier Performance | `cashierPerformance()` | 3 subquery gabungan |
| Repeat Customer Metrics | `repeatCustomerMetrics()` | `transactions` + `customers` |
| Stock Coverage Analysis | `stockCoverageAnalysis()` | `products` + sales |
| Promo Monitor | `promoMonitor()` | `pricing_rules`, `audit_logs` |
| Loyalty Performance | `loyaltyPerformance()` | `customers`, `loyalty_point_histories`, `customer_vouchers` |
| CRM Operations | `crmOperations()` | `customer_segments`, `customer_campaigns`, `customer_campaign_logs` |

Filter: `start_date`, `end_date`, `cashier_id`, `customer_id`, `category_id` + scope warehouse/outlet. Hanya transaksi `payment_status = paid` (register §13.25).

Yang sudah bagus: scoping tenancy fail-closed (`activeBusinessOutletIds()`), pemisahan `include_legacy`, dan `ReportIsolationTest` (14 test) menutup kebocoran lintas business untuk metrik utama.

---

## 2. Temuan

### A. Performa / skalabilitas

**A1. Tidak ada rentang tanggal default → halaman membuka data sepanjang masa.**
`Insights.jsx:190-193` memulai dengan `start_date: ""`/`end_date: ""`; controller (`:52-62`) memakai nilai itu apa adanya. Akibatnya semua 12 blok + `salesWindowDays()` (`:603-624`) berjalan tanpa batas. Velocity stok di `stockCoverageAnalysis` pun dibagi jumlah hari seluruh riwayat.

**A2. Semua ID transaksi ditarik ke PHP lalu dipakai `whereIn`.**
`index():69` `$transactionIds = (clone $transactionQuery)->pluck('id')`, lalu dipakai 2× (`:77-86`) untuk `itemsSold` dan `profitTotal`. Untuk rentang panjang ini: (a) memori besar, (b) berisiko menabrak batas parameter/`max_allowed_packet`, (c) 3 round-trip yang tidak perlu. Padahal `$transactionCount` (`:70`) = `orders_count` yang sudah dihitung di `:73`.

**A3. `stockCoverageAnalysis()` memuat SELURUH produk berstok lalu sortir di PHP.**
`:540` `->get()` tanpa limit; sorting + `take(10)` di PHP (`:573-592`). Untuk katalog besar (ribuan SKU) ini query terberat di halaman.

**A4. `crmOperations()` memuat seluruh log kampanye.**
`:807` `$logsQuery->get()` — jumlah baris = jumlah penerima kampanye. Blast 10k penerima = 10k model dimuat hanya untuk menghitung 4 status.

**A5. `loyaltyPerformance()` memuat seluruh member (2×).**
`:715-717` dan `:761-766`: `Customer::where('is_loyalty_member', true)->get()` dua kali, lalu `count()`/`sum()`/group tier di PHP.

**A6. `repeatCustomerMetrics()` memuat seluruh pelanggan bertransaksi.**
`:458` `->get()` lalu filter repeat/new/member share di PHP (`:474-479`). Bisa diganti `HAVING COUNT(*) > 1`.

**A7. `promoMonitor()` menghitung status rule di PHP** (`:683-692`, `:694-706`) — memuat semua rule lalu `filter()`.

**A8. `warehousesFor()` dipanggil dua kali** (`:45` dan `:48`).

### B. Konsistensi / potensi salah tafsir

**B1. Semantik filter kategori tidak seragam (perlu keputusan produk).**
`applyTransactionFilters():138-140` memfilter di level **transaksi** (`whereHas` → semua transaksi yang *mengandung* kategori itu), sedangkan `detailMetricsQuery():161` memfilter di level **baris** (`p.category_id = X`).
Akibat saat filter kategori aktif: kartu "Pendapatan"/"Profit"/"Item Terjual" = total transaksi utuh, sedangkan "Top Selling"/"Margin by Product/Category" = hanya baris kategori itu. Kedua angka tidak akan pernah rekonsiliasi.

**B2. Panel segment selalu 0 saat tenancy ON.**
`crmOperations():786-788` mengembalikan `collect()` bila tenancy aktif (fail-closed, konsisten dengan keputusan "segments tetap 404"), tetapi UI tetap merender "Segment Aktif 0", "Manual Segment 0", "Auto Segment 0", "Memberships 0" (`Insights.jsx:1194-1261`) — terbaca sebagai "tidak ada segment", bukan "tidak tersedia".

**B3. `coverageStatus()` punya cabang `out_of_stock` yang tidak pernah tercapai** (`:628-630`), karena query hanya mengambil stok > 0 (`:522-529`), dan `summary` tidak menghitung `out_of_stock` (`:566-571`).

**B4. `recent_audits` selalu kosong saat tenancy ON** (`:666-679`, disengaja) tanpa penanda di UI.

**B5. Dropdown Kasir global (P2 terdokumentasi).**
`index():103` `User::select(...)->get()` — `User` tidak memakai `BelongsToBusiness`, jadi daftar kasir menampilkan user dari business lain. (Plan multi-business item #12 "Dropdown laporan global" masih terbuka.) Dropdown Customer/Category aman karena modelnya business-scoped.

### C. Kesehatan kode

**C1. `Insights.jsx` 1.308 baris / 68 KB** dalam satu komponen — sulit diuji dan diubah.
**C2. Index DB:** `transactions` punya index `(warehouse_id, created_at)` (`2026_09_18_000001`), tetapi **`payment_status` tidak terindex** padahal setiap query menyaring `payment_status = 'paid'`.
**C3. Cakupan test:** 7 test di `AdvancedSalesInsightsTest` + isolasi tenancy. Belum ada test untuk semantik filter kategori, rentang default, dan `out_of_stock`.

---

## 3. Rekomendasi penambahan (biaya kecil, dampak terukur)

### Tier 1 — cepat, tanpa migrasi (masing-masing 1 file)

| # | Rekomendasi | Cara | Dampak |
|---|---|---|---|
| 1 | **Default rentang 30 hari** + opsi eksplisit "Semua waktu" | set default di controller saat `start_date`/`end_date` kosong; kirim flag ke UI | memangkas beban 12 blok; AOV & velocity jadi relevan |
| 2 | **Hapus `pluck('id')`**; agregat item & profit lewat 1 query join; `orders_count` dari `$summaryRaw` | ganti `:69-86` | −3 query, hilang risiko parameter limit |
| 3 | **Scope dropdown kasir ke business aktif** | `BusinessUser::forBusiness($businessId)` / `BusinessMembershipResolver` | menutup kebocoran lintas business (B5) |
| 4 | **Sembunyikan kartu segment saat tenancy ON** (atau label "tidak tersedia") | pakai shared prop `multiBusinessEnabled` | hilangkan angka 0 yang menyesatkan (B2) |
| 5 | **Tuntaskan cabang `out_of_stock`** — tampilkan sebagai kartu, atau hapus | pilih salah satu | hilangkan inkonsistensi (B3) |
| 6 | Hapus pemanggilan `warehousesFor()` ganda | `:45-51` | 1 query lebih ringan |

### Tier 2 — pindahkan agregasi ke SQL (1 file + test)

| # | Rekomendasi | Dampak |
|---|---|---|
| 7 | `stockCoverageAnalysis`: velocity + urutan + `LIMIT` di SQL | hindari memuat ribuan produk |
| 8 | `crmOperations`: `groupBy('status')` untuk log & campaign | hindari memuat puluhan ribu log |
| 9 | `loyaltyPerformance`: `count()/sum()/groupBy('loyalty_tier')` | hindari memuat semua member |
| 10 | `repeatCustomerMetrics`: `HAVING COUNT(*) > 1` | akurasi + memori |
| 11 | `promoMonitor`: count status via SQL | konsistensi pola |

### Tier 3 — fitur baru yang murah (nilai tinggi)

| # | Fitur | Berbasis pada | Catatan biaya |
|---|---|---|---|
| 12 | **Export Insights (CSV/XLSX)** | infra Maatwebsite + pola `ImportExportController` & `app/Exports/*` yang sudah ada | ~1 export class + 1 route + tombol; hormati filter & scope |
| 13 | **Delta vs periode sebelumnya** di 4 summary card (▲▼ %) | `applyTransactionFilters()` dengan rentang digeser | 1 query tambahan |
| 14 | **Day-of-week & "jam/hari tersibuk"** | data `salesByDay`/`salesByHour` sudah dikirim | 0 query tambahan (agregasi di JS) |
| 15 | **Panel dead stock** (produk `no_movement` + stok > 0) | data `stockCoverage` sudah ada | 0 query tambahan |
| 16 | **Insight naratif otomatis** ("Omzet naik 12% vs periode lalu", "3 produk stok kritis") | angka yang sudah dihitung | 0 query tambahan |
| 17 | **Aksi cepat "Buat Draft PO"** dari panel stok kritis | `ReorderService` / `reorder:generate` sudah ada | 1 endpoint tipis |
| 18 | **Kirim rekap ringkas ke WhatsApp** (harian/mingguan) | `WhatsAppService` sudah ada | reuse service yang ada |

### Tier 4 — perawatan

| # | Item |
|---|---|
| 19 | Pecah `Insights.jsx` jadi subkomponen per panel + hook filter |
| 20 | Tambah index `payment_status` (idealnya komposit `(payment_status, created_at, warehouse_id)`) |
| 21 | Test: semantik filter kategori, rentang default, empty state, dan `out_of_stock` |
| 22 | Putuskan B1 (kategori = transaksi atau baris) dan tuliskan di UI |

---

## 4. Urutan eksekusi yang disarankan

1. **Tier 1 (1, 2, 3, 4)** — perbaikan perilaku + kebocoran, tanpa risiko skema.
2. **Tier 3 #12 + #13** — fitur yang paling terasa manfaatnya (export & perbandingan periode).
3. **Tier 2 (7-9)** — setelah ada data produksi nyata, agar optimasi tepat sasaran.
4. **Tier 4** — perawatan berkelanjutan.
