<?php

namespace Database\Seeders;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Setting;
use App\Models\ShiftCashMovement;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\BusinessBootstrap;
use App\Support\Tenancy\BusinessMembershipProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data dummy REFERENSI PRODUKSI — Kantin UKM Kewirausahaan.
 *
 * Berbeda dari DemoSeeder (toko generik), seeder ini membentuk dataset yang
 * meniru database produksi per 2026-10-23:
 *  - katalog produk ASLI (79 produk: nama, SKU, barcode, harga modal/jual,
 *    stok akhir) — data katalog usaha sendiri, bukan data pribadi;
 *  - 16 kategori, 2 outlet, 2 gudang dengan nama yang sama seperti produksi;
 *  - volume & pola produksi: 13 pengguna (2 super-admin, 11 manager),
 *    48 pelanggan, 11 shift tertutup, 147 transaksi tunai lunas dalam
 *    5 hari terakhir, 9 kas keluar belanja bahan.
 *
 * Semua DATA ORANG adalah rekaan: nama pelanggan & pengguna fiktif, tanpa
 * nomor telepon/alamat (sama seperti pola isian produksi), email memakai
 * domain dummy.test, kata sandi "password". Transaksi dibuat acak dengan
 * seed tetap (deterministik) mengikuti distribusi produksi: 1-3 item per
 * transaksi, qty 1-3, semua tunai, ~2% transaksi berkustomer, 2 transaksi
 * berdiskon.
 *
 * Cara pakai (JANGAN di produksi):
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=KantinReferensiSeeder --force
 *
 * Menyegarkan referensi: ekspor ulang database produksi, perbarui daftar
 * katalog pada method katalogProduk() dari ekspor tersebut, lalu naikkan
 * tanggal pada docblock ini.
 */
class KantinReferensiSeeder extends Seeder
{
    /** Seed acak tetap: dataset yang dihasilkan selalu sama. */
    private const SEED_ACAK = 20261023;

    private const NAMA_KATEGORI = [
        'Food', 'Beverages', 'Coffee & Tea', 'Snacks', 'Air Mineral',
        'Roti & Kue', 'Snack', 'Minuman Serbuk', 'Gorengan & Jajanan', 'Kopi',
        'Mie Instan', 'Susu', 'Makanan', 'Permen', 'Teh', 'Minuman Siap Saji',
    ];

    private const NAMA_PENGGUNA = [
        // [nama, email, peran] — 2 super-admin + 11 manager (pola produksi)
        ['Budi Santoso', 'superadmin1@kantin.dummy', 'super-admin'],
        ['Dewi Lestari', 'superadmin2@kantin.dummy', 'super-admin'],
        ['Agus Wijaya', 'manager01@kantin.dummy', 'manager'],
        ['Rina Amelia', 'manager02@kantin.dummy', 'manager'],
        ['Joko Prasetyo', 'manager03@kantin.dummy', 'manager'],
        ['Sri Wahyuni', 'manager04@kantin.dummy', 'manager'],
        ['Andi Kurniawan', 'manager05@kantin.dummy', 'manager'],
        ['Fitri Rahmawati', 'manager06@kantin.dummy', 'manager'],
        ['Bambang Sutrisno', 'manager07@kantin.dummy', 'manager'],
        ['Ani Susanti', 'manager08@kantin.dummy', 'manager'],
        ['Dedi Supriadi', 'manager09@kantin.dummy', 'manager'],
        ['Maya Putri', 'manager10@kantin.dummy', 'manager'],
        ['Rudi Hartono', 'manager11@kantin.dummy', 'manager'],
    ];

    private const NAMA_PELANGGAN = [
        'Ahmad Fauzi', 'Siti Nurhaliza', 'Rudi Saputra', 'Fitri Handayani',
        'Eko Purnomo', 'Ratna Sari', 'Dedi Kurniawan', 'Yuni Astuti',
        'Hendra Gunawan', 'Lilis Suryani', 'Bambang Riyanto', 'Dewi Anggraini',
        'Agus Salim', 'Rina Marlina', 'Tono Wibowo', 'Sari Utami',
        'Fajar Ramadhan', 'Nur Hidayah', 'Rizki Pratama', 'Wulan Dari',
        'Iwan Setiawan', 'Tia Rahmawati', 'Dedi Supriyanto', 'Lina Kusuma',
        'Rahmat Hidayat', 'Anisa Rahma', 'Bimo Aditama', 'Citra Lestari',
        'Dian Permata', 'Erwin Saputra', 'Farah Nabila', 'Galih Permana',
        'Hana Safitri', 'Indra Kusuma', 'Jihan Aulia', 'Kevin Hartanto',
        'Laras Ayu', 'Miko Fernando', 'Nadia Putri', 'Oscar Wijaya',
        'Putri Maharani', 'Qori Sandioriva', 'Reza Firmansyah', 'Shinta Dewi',
        'Taufik Hidayat', 'Umar Bakri', 'Vina Melati', 'Wahyu Nugroho',
    ];

    private const CATATAN_KAS_KELUAR = [
        'beli es batu', 'beli es', 'es batu', 'risol dan dimsum', 'dimsum',
        'beli gas elpiji', 'beli bahan gorengan', 'plastik dan kemasan',
        'beli gula dan kopi',
    ];

    private const NOMINAL_KAS_KELUAR = [5000, 7500, 10000, 12000, 15000, 20000, 25000, 30000, 33500];

    private const MODAL_BUKA_SHIFT = [0, 0, 10000, 10000, 15000, 15000, 20000, 25000, 30000];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('KantinReferensiSeeder adalah data dummy — ditolak jalan di production.');

            return;
        }

        mt_srand(self::SEED_ACAK);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->call([PermissionSeeder::class, RoleSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Menyiapkan data dummy referensi produksi (Kantin)...');

        [$outletGudang, $outletKantin, $gudangKantin] = $this->seedOutletGudang();
        $pengguna = $this->seedPengguna($outletGudang, $outletKantin);
        $kategori = $this->seedKategori();
        $pelanggan = $this->seedPelanggan();
        $rencana = $this->susunRencanaPenjualan();
        $produk = $this->seedProduk($kategori, $gudangKantin, $rencana['terjual']);
        $this->seedShiftDanTransaksi($rencana, $pengguna, $produk, $pelanggan, $gudangKantin);

        // Pola yang sama dengan DemoBusinessSeeder: buat bisnis lalu petakan
        // semua baris yang belum ber-business_id + provision membership.
        app(BusinessBootstrap::class)->bootstrap('Kantin');
        $hasil = app(BusinessMembershipProvisioner::class)->apply();

        Setting::set('store_name', 'Kantin');
        Setting::set('app_setup_completed', true);

        $this->command?->info("Membership bisnis: {$hasil['created']} dibuat, {$hasil['skipped']} sudah ada, {$hasil['unmapped']} tak terpetakan.");
        $this->command?->info('Data dummy Kantin selesai. Login: superadmin1@kantin.dummy / password (super-admin), manager01@kantin.dummy / password (manager).');
    }

    /**
     * Outlet & gudang mengikuti produksi: "Gudang Pusat" (bukan penjualan)
     * dan "Kantin" (penjualan). Outlet/gudang PUSAT dari DatabaseSeeder
     * dipakai ulang sebagai gudang pusatnya, supaya jumlah outlet/gudang
     * sama persis dengan produksi (2 outlet, 2 gudang).
     *
     * @return array{0: Outlet, 1: Outlet, 2: Warehouse} outlet gudang, outlet kantin, gudang kantin
     */
    private function seedOutletGudang(): array
    {
        $outletGudang = Outlet::where('code', 'PUSAT')->first()
            ?? Outlet::updateOrCreate(
                ['code' => 'PUSAT'],
                ['name' => 'Gudang Pusat', 'is_active' => true, 'is_sales_enabled' => false],
            );

        $outletKantin = Outlet::updateOrCreate(
            ['code' => 'KANTIN'],
            ['name' => 'Kantin', 'is_active' => true, 'is_sales_enabled' => true],
        );
        $gudangKantin = Warehouse::updateOrCreate(
            ['code' => 'WH-KANTIN'],
            ['outlet_id' => $outletKantin->id, 'name' => 'Kantin', 'type' => 'branch', 'is_active' => true, 'sort_order' => 2],
        );

        return [$outletGudang, $outletKantin, $gudangKantin];
    }

    /**
     * @return Collection<int, User>
     */
    private function seedPengguna(Outlet $outletGudang, Outlet $outletKantin)
    {
        $semuaIzin = Permission::all();

        return collect(self::NAMA_PENGGUNA)->map(function (array $baris, int $i) use ($outletGudang, $outletKantin, $semuaIzin) {
            [$nama, $email, $peran] = $baris;

            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $nama, 'password' => Hash::make('password')],
            );
            $user->syncRoles([$peran]);
            if ($peran === 'super-admin') {
                $user->syncPermissions($semuaIzin);
            }
            $user->markEmailAsVerified();

            // Pola produksi: semua pengguna terhubung ke outlet Kantin;
            // 8 dari 13 juga terhubung ke outlet gudang.
            $outlets = [$outletKantin->id => ['is_default' => true]];
            if ($i < 8) {
                $outlets[$outletGudang->id] = ['is_default' => false];
            }
            $user->outlets()->sync($outlets);

            return $user;
        });
    }

    /**
     * @return Collection<string, Category> terindeks nama
     */
    private function seedKategori()
    {
        return collect(self::NAMA_KATEGORI)
            ->map(fn (string $nama) => Category::updateOrCreate(['name' => $nama], ['description' => null]))
            ->keyBy('name');
    }

    /**
     * @return Collection<int, Customer>
     */
    private function seedPelanggan()
    {
        return collect(self::NAMA_PELANGGAN)->map(function (string $nama, int $i) {
            // Pola produksi: telepon & alamat kosong; 4 dari 48 anggota loyalitas.
            $anggota = $i < 4;

            return Customer::updateOrCreate(
                ['name' => $nama],
                [
                    'no_telp' => null,
                    'address' => null,
                    'is_loyalty_member' => $anggota,
                    'member_code' => $anggota ? sprintf('MBR-%05d', $i + 1) : null,
                    'loyalty_points' => $anggota ? 25 * ($i + 1) : 0,
                ],
            );
        });
    }

    /**
     * Susun rencana 11 shift / 147 transaksi selama 5 hari terakhir sebelum
     * data ditulis, supaya stok awal produk bisa ditambah jumlah yang akan
     * terjual (stok akhir hasil seeding = stok referensi produksi).
     *
     * @return array{shift: array<int, array<string, mixed>>, terjual: array<int, int>}
     */
    private function susunRencanaPenjualan(): array
    {
        $katalog = collect($this->katalogProduk())->values();
        // Bobot kemunculan produk mengikuti komposisi katalog (kategori besar
        // otomatis lebih sering terpilih), sama seperti pola produksi.
        $kantong = [];
        foreach ($katalog as $idx => $p) {
            $kantong = array_merge($kantong, array_fill(0, $this->bobotProduk($p), $idx));
        }

        $perShift = [13, 14, 13, 14, 13, 13, 14, 13, 14, 13, 13]; // total 147
        $jendela = [
            ['07:00', '12:30'], ['12:30', '18:00'], ['07:00', '12:30'],
            ['12:30', '18:00'], ['07:00', '12:30'], ['12:30', '18:00'],
            ['07:00', '12:30'], ['12:30', '18:00'], ['07:00', '12:30'],
            ['12:30', '18:00'], ['18:00', '21:00'],
        ];
        $hari = [4, 4, 3, 3, 2, 2, 1, 1, 0, 0, 0]; // hari ke belakang dari hari ini

        $shifts = [];
        $terjual = [];

        foreach ($perShift as $s => $jumlahTx) {
            $tanggal = now()->startOfDay()->subDays($hari[$s]);
            [$jamBuka, $jamTutup] = $jendela[$s];
            $buka = $tanggal->copy()->setTimeFromTimeString($jamBuka);
            $tutup = $tanggal->copy()->setTimeFromTimeString($jamTutup);

            $transaksi = [];
            for ($t = 0; $t < $jumlahTx; $t++) {
                $jumlahItem = $this->pilihBerbobot([1 => 75, 2 => 20, 3 => 5]);
                $terpilih = [];
                while (count($terpilih) < $jumlahItem) {
                    $idx = $kantong[mt_rand(0, count($kantong) - 1)];
                    if (! isset($terpilih[$idx])) {
                        $terpilih[$idx] = $this->pilihBerbobot([1 => 80, 2 => 15, 3 => 5]);
                    }
                }

                $items = [];
                foreach ($terpilih as $idx => $qty) {
                    $items[] = ['produk' => $idx, 'qty' => $qty];
                    $terjual[$idx] = ($terjual[$idx] ?? 0) + $qty;
                }

                $menit = mt_rand(0, max(1, (int) $buka->diffInMinutes($tutup) - 5));
                $transaksi[] = [
                    'waktu' => $buka->copy()->addMinutes($menit),
                    'items' => $items,
                ];
            }

            usort($transaksi, fn ($a, $b) => $a['waktu'] <=> $b['waktu']);

            $shifts[] = [
                'buka' => $buka,
                'tutup' => $tutup,
                'modal_buka' => self::MODAL_BUKA_SHIFT[mt_rand(0, count(self::MODAL_BUKA_SHIFT) - 1)],
                'transaksi' => $transaksi,
            ];
        }

        return ['shift' => $shifts, 'terjual' => $terjual];
    }

    private function bobotProduk(array $p): int
    {
        // Produk murah (<= Rp3.000) adalah perputaran utama kantin.
        return $p['jual'] <= 3000 ? 3 : ($p['jual'] <= 5000 ? 2 : 1);
    }

    /**
     * @param  array<int, int>  $terjual  total qty terjual per indeks katalog
     * @return Collection<int, Product>
     */
    private function seedProduk($kategori, Warehouse $gudangKantin, array $terjual)
    {
        return collect($this->katalogProduk())->values()->map(function (array $p, int $idx) use ($kategori, $gudangKantin, $terjual) {
            $stokAwal = $p['stok'] + ($terjual[$idx] ?? 0);

            $product = Product::updateOrCreate(
                ['barcode' => $p['barcode']],
                [
                    'category_id' => $kategori->get($p['kategori'])?->id,
                    'image' => null,
                    'sku' => $p['sku'],
                    'title' => $p['title'],
                    'description' => null,
                    'buy_price' => $p['modal'],
                    'sell_price' => $p['jual'],
                    'stock' => $stokAwal,
                    'tax_type' => 'exclusive',
                    'tax_rate' => 0,
                    'min_stock' => 0,
                    'max_stock' => 0,
                    'is_composite' => false,
                ],
            );

            ProductWarehouse::updateOrCreate(
                ['product_id' => $product->id, 'warehouse_id' => $gudangKantin->id],
                ['stock' => $stokAwal, 'min_stock' => 0, 'max_stock' => 0],
            );

            return $product;
        });
    }

    private function seedShiftDanTransaksi(array $rencana, $pengguna, $produk, $pelanggan, Warehouse $gudangKantin): void
    {
        $kasir = $pengguna->values();
        $diskonTerpakai = 0;
        $berkustomerTerpakai = 0;
        $kasKeluarTerpakai = 0;

        foreach ($rencana['shift'] as $s => $shiftRencana) {
            $user = $kasir[$s % $kasir->count()];

            $shift = CashierShift::create([
                'user_id' => $user->id,
                'warehouse_id' => $gudangKantin->id,
                'opened_by' => $user->id,
                'closed_by' => $user->id,
                'opened_at' => $shiftRencana['buka'],
                'closed_at' => $shiftRencana['tutup'],
                'opening_cash' => $shiftRencana['modal_buka'],
                'status' => 'closed',
            ]);

            $totalPenjualan = 0;

            foreach ($shiftRencana['transaksi'] as $txRencana) {
                $items = collect($txRencana['items'])->map(function (array $item) use ($produk) {
                    $p = $produk[$item['produk']];

                    return ['produk' => $p, 'qty' => $item['qty'], 'total' => $p->sell_price * $item['qty']];
                });
                $gross = $items->sum('total');

                $diskon = 0;
                if ($diskonTerpakai < 2 && $gross > 5000) {
                    $diskon = 1000;
                    $diskonTerpakai++;
                }
                $grandTotal = $gross - $diskon;

                $customer = null;
                if ($berkustomerTerpakai < 3 && mt_rand(1, 100) <= 4) {
                    $customer = $pelanggan[mt_rand(0, $pelanggan->count() - 1)];
                    $berkustomerTerpakai++;
                }

                $cash = $this->uangPas($grandTotal);

                $transaction = Transaction::create([
                    'cashier_id' => $user->id,
                    'cashier_shift_id' => $shift->id,
                    'customer_id' => $customer?->id,
                    'invoice' => 'TRX-'.Str::upper(Str::random(10)),
                    'cash' => $cash,
                    'change' => $cash - $grandTotal,
                    'discount' => $diskon,
                    'grand_total' => $grandTotal,
                    'payment_method' => 'cash',
                    'payment_status' => 'paid',
                    'order_type' => 'in_store',
                    'warehouse_id' => $gudangKantin->id,
                    'created_at' => $txRencana['waktu'],
                    'updated_at' => $txRencana['waktu'],
                ]);

                foreach ($items as $item) {
                    $transaction->details()->create([
                        'product_id' => $item['produk']->id,
                        'qty' => $item['qty'],
                        'base_unit_price' => $item['produk']->sell_price,
                        'unit_price' => $item['produk']->sell_price,
                        'price' => $item['total'],
                    ]);
                    $transaction->profits()->create([
                        'total' => ($item['produk']->sell_price - $item['produk']->buy_price) * $item['qty'],
                        'created_at' => $txRencana['waktu'],
                        'updated_at' => $txRencana['waktu'],
                    ]);

                    ProductWarehouse::query()
                        ->where('product_id', $item['produk']->id)
                        ->where('warehouse_id', $gudangKantin->id)
                        ->decrement('stock', $item['qty']);
                    $item['produk']->update([
                        'stock' => (int) $item['produk']->warehouses()->sum('product_warehouse.stock'),
                    ]);
                }

                $totalPenjualan += $grandTotal;
            }

            // 9 kas keluar tersebar di 9 shift pertama (pola produksi).
            $kasKeluar = 0;
            if ($kasKeluarTerpakai < 9) {
                $kasKeluar = self::NOMINAL_KAS_KELUAR[$kasKeluarTerpakai];
                ShiftCashMovement::create([
                    'cashier_shift_id' => $shift->id,
                    'type' => 'out',
                    'amount' => $kasKeluar,
                    'note' => self::CATATAN_KAS_KELUAR[$kasKeluarTerpakai],
                    'user_id' => $user->id,
                    'created_at' => $shiftRencana['buka']->copy()->addHours(2),
                    'updated_at' => $shiftRencana['buka']->copy()->addHours(2),
                ]);
                $kasKeluarTerpakai++;
            }

            $expected = $shiftRencana['modal_buka'] + $totalPenjualan - $kasKeluar;
            $shift->update([
                'cash_sales_total' => $totalPenjualan,
                'transactions_count' => count($shiftRencana['transaksi']),
                'cash_in_total' => 0,
                'cash_out_total' => $kasKeluar,
                'expected_cash' => $expected,
                'actual_cash' => $expected,
                'cash_difference' => 0,
            ]);
        }
    }

    /** Uang tunai yang dibayarkan pelanggan: dibulatkan ke atas ke pecahan wajar. */
    private function uangPas(int $total): int
    {
        if ($total <= 0) {
            return 0;
        }
        foreach ([1000, 2000, 5000, 10000, 20000, 50000, 100000] as $pecahan) {
            if ($total <= $pecahan) {
                return $pecahan;
            }
        }

        return (int) (ceil($total / 50000) * 50000);
    }

    /**
     * @param  array<int, int>  $bobot  nilai => bobot
     */
    private function pilihBerbobot(array $bobot): int
    {
        $total = array_sum($bobot);
        $acak = mt_rand(1, $total);
        foreach ($bobot as $nilai => $b) {
            $acak -= $b;
            if ($acak <= 0) {
                return $nilai;
            }
        }

        return array_key_first($bobot);
    }

    /**
     * Katalog produk referensi produksi (ekspor 2026-10-23): nama, SKU,
     * barcode, harga modal/jual, dan stok akhir produksi saat ekspor.
     * Data katalog usaha — bukan data pribadi pelanggan.
     *
     * @return array<int, array<string, mixed>>
     */
    private function katalogProduk(): array
    {
        return [
            ['kategori' => 'Snacks', 'title' => 'Tango Waffle', 'sku' => 'SNK-021', 'barcode' => '8999000000451', 'modal' => 1666, 'jual' => 3000, 'stok' => 9],
            ['kategori' => 'Air Mineral', 'title' => 'Airos Pure Water 330 ml', 'sku' => 'AMR-001', 'barcode' => '8999000000016', 'modal' => 1500, 'jual' => 3000, 'stok' => 40],
            ['kategori' => 'Air Mineral', 'title' => 'Airos Pure Water 500 ml', 'sku' => 'AMR-002', 'barcode' => '8999000000023', 'modal' => 1875, 'jual' => 4000, 'stok' => 45],
            ['kategori' => 'Air Mineral', 'title' => 'Amanah Besar', 'sku' => 'RTK-001', 'barcode' => '8999000000030', 'modal' => 3500, 'jual' => 4000, 'stok' => 0],
            ['kategori' => 'Air Mineral', 'title' => 'Amanah Kecil', 'sku' => 'RTK-002', 'barcode' => '8999000000047', 'modal' => 2500, 'jual' => 3000, 'stok' => 15],
            ['kategori' => 'Roti & Kue', 'title' => 'Aoka Besar', 'sku' => 'RTK-003', 'barcode' => '8999000000054', 'modal' => 2000, 'jual' => 3000, 'stok' => 5],
            ['kategori' => 'Roti & Kue', 'title' => 'Aoka Kecil', 'sku' => 'RTK-004', 'barcode' => '8999000000061', 'modal' => 890, 'jual' => 1500, 'stok' => 0],
            ['kategori' => 'Roti & Kue', 'title' => 'Mooncake', 'sku' => 'RTK-005', 'barcode' => '8999000000467', 'modal' => 2000, 'jual' => 3000, 'stok' => 1],
            ['kategori' => 'Roti & Kue', 'title' => 'Padimas', 'sku' => 'RTK-006', 'barcode' => '8999000000511', 'modal' => 1800, 'jual' => 2500, 'stok' => 1],
            ['kategori' => 'Roti & Kue', 'title' => 'Roti Abon', 'sku' => 'RTK-007', 'barcode' => '8999000000573', 'modal' => 4500, 'jual' => 5000, 'stok' => 1],
            ['kategori' => 'Roti & Kue', 'title' => 'Roti Bulat', 'sku' => 'RTK-008', 'barcode' => '8999000000580', 'modal' => 4500, 'jual' => 5000, 'stok' => 1],
            ['kategori' => 'Roti & Kue', 'title' => 'Roti Isi', 'sku' => 'RTK-009', 'barcode' => '8999000000597', 'modal' => 2200, 'jual' => 3000, 'stok' => 4],
            ['kategori' => 'Roti & Kue', 'title' => 'Roti Ribut', 'sku' => 'RTK-010', 'barcode' => '8999000000603', 'modal' => 450, 'jual' => 750, 'stok' => 12],
            ['kategori' => 'Roti & Kue', 'title' => 'Roti Sosis', 'sku' => 'RTK-011', 'barcode' => '8999000000610', 'modal' => 4500, 'jual' => 5000, 'stok' => 1],
            ['kategori' => 'Snack', 'title' => 'Arden', 'sku' => 'SNK-001', 'barcode' => '8999000000078', 'modal' => 1750, 'jual' => 3000, 'stok' => 0],
            ['kategori' => 'Snack', 'title' => 'Beng Beng', 'sku' => 'SNK-002', 'barcode' => '8999000000085', 'modal' => 2050, 'jual' => 3000, 'stok' => 3],
            ['kategori' => 'Snack', 'title' => 'Chocolatos', 'sku' => 'SNK-003', 'barcode' => '8999000000092', 'modal' => 437, 'jual' => 1000, 'stok' => 3],
            ['kategori' => 'Snack', 'title' => 'Chumie', 'sku' => 'SNK-004', 'barcode' => '8999000000122', 'modal' => 800, 'jual' => 1500, 'stok' => 34],
            ['kategori' => 'Snack', 'title' => 'Cocorio Dark Choco', 'sku' => 'SNK-005', 'barcode' => '8999000000146', 'modal' => 1143, 'jual' => 2000, 'stok' => 3],
            ['kategori' => 'Snack', 'title' => 'Coklat Dubai', 'sku' => 'SNK-006', 'barcode' => '8999000000153', 'modal' => 2500, 'jual' => 3000, 'stok' => 3],
            ['kategori' => 'Snack', 'title' => 'Dilan', 'sku' => 'SNK-007', 'barcode' => '8999000000177', 'modal' => 1666, 'jual' => 3000, 'stok' => 4],
            ['kategori' => 'Snack', 'title' => 'Kalpa', 'sku' => 'SNK-009', 'barcode' => '8999000000306', 'modal' => 1700, 'jual' => 3000, 'stok' => 1],
            ['kategori' => 'Snack', 'title' => 'Kerang Winda', 'sku' => 'SNK-010', 'barcode' => '8999000000320', 'modal' => 2000, 'jual' => 3000, 'stok' => 1],
            ['kategori' => 'Snack', 'title' => 'Rambak', 'sku' => 'SNK-011', 'barcode' => '8999000000337', 'modal' => 1700, 'jual' => 2500, 'stok' => 4],
            ['kategori' => 'Snack', 'title' => 'Latio Kiss Your Mouth', 'sku' => 'SNK-012', 'barcode' => '8999000000344', 'modal' => 450, 'jual' => 1000, 'stok' => 1],
            ['kategori' => 'Snack', 'title' => 'Latio Panjang', 'sku' => 'SNK-013', 'barcode' => '8999000000351', 'modal' => 1000, 'jual' => 2000, 'stok' => 12],
            ['kategori' => 'Snack', 'title' => 'Latio Sachet', 'sku' => 'SNK-014', 'barcode' => '8999000000368', 'modal' => 450, 'jual' => 1000, 'stok' => 3],
            ['kategori' => 'Snack', 'title' => 'Makaroni Bantet', 'sku' => 'SNK-015', 'barcode' => '8999000000375', 'modal' => 2000, 'jual' => 3000, 'stok' => 12],
            ['kategori' => 'Snack', 'title' => 'Makaroni Keju', 'sku' => 'SNK-016', 'barcode' => '8999000000382', 'modal' => 2000, 'jual' => 3000, 'stok' => 1],
            ['kategori' => 'Snack', 'title' => 'Makaroni Naskun', 'sku' => 'SNK-017', 'barcode' => '8999000000399', 'modal' => 1500, 'jual' => 2000, 'stok' => 1],
            ['kategori' => 'Snack', 'title' => 'Momogi', 'sku' => 'SNK-018', 'barcode' => '8999000000450', 'modal' => 440, 'jual' => 1000, 'stok' => 6],
            ['kategori' => 'Snack', 'title' => 'Opak', 'sku' => 'SNK-019', 'barcode' => '8999000000504', 'modal' => 2500, 'jual' => 3000, 'stok' => 14],
            ['kategori' => 'Snack', 'title' => 'Popcorn', 'sku' => 'SNK-020', 'barcode' => '8999000000559', 'modal' => 2000, 'jual' => 2500, 'stok' => 1],
            ['kategori' => 'Snack', 'title' => 'Top Delfi Coklat', 'sku' => 'KOP-012', 'barcode' => '8999000000726', 'modal' => 762, 'jual' => 1500, 'stok' => 7],
            ['kategori' => 'Minuman Serbuk', 'title' => 'Chocolatos Coklat Drink', 'sku' => 'MSR-001', 'barcode' => '8999000000108', 'modal' => 2683, 'jual' => 4000, 'stok' => 0],
            ['kategori' => 'Minuman Serbuk', 'title' => 'Chocolatos Matcha', 'sku' => 'MSR-002', 'barcode' => '8999000000115', 'modal' => 2743, 'jual' => 4000, 'stok' => 1],
            ['kategori' => 'Minuman Serbuk', 'title' => 'Drink Beng Beng', 'sku' => 'MSR-003', 'barcode' => '8999000000191', 'modal' => 2993, 'jual' => 4000, 'stok' => 1],
            ['kategori' => 'Minuman Serbuk', 'title' => 'Energen', 'sku' => 'MSR-004', 'barcode' => '8999000000207', 'modal' => 3243, 'jual' => 5000, 'stok' => 5],
            ['kategori' => 'Minuman Serbuk', 'title' => 'Finto', 'sku' => 'SNK-008', 'barcode' => '8999000000214', 'modal' => 1143, 'jual' => 2000, 'stok' => 3],
            ['kategori' => 'Minuman Serbuk', 'title' => 'Marimas', 'sku' => 'MSR-005', 'barcode' => '8999000000405', 'modal' => 1143, 'jual' => 2000, 'stok' => 15],
            ['kategori' => 'Minuman Serbuk', 'title' => 'Nutrisari', 'sku' => 'MSR-006', 'barcode' => '8999000000498', 'modal' => 2073, 'jual' => 4000, 'stok' => 17],
            ['kategori' => 'Gorengan & Jajanan', 'title' => 'Cibay', 'sku' => 'GRJ-001', 'barcode' => '8999000000139', 'modal' => 1500, 'jual' => 2000, 'stok' => 1],
            ['kategori' => 'Gorengan & Jajanan', 'title' => 'Daging Crispy', 'sku' => 'GRJ-002', 'barcode' => '8999000000160', 'modal' => 2500, 'jual' => 3000, 'stok' => 1],
            ['kategori' => 'Gorengan & Jajanan', 'title' => 'Dimsum Keju', 'sku' => 'GRJ-003', 'barcode' => '8999000000184', 'modal' => 2500, 'jual' => 3000, 'stok' => 5],
            ['kategori' => 'Gorengan & Jajanan', 'title' => 'Risol', 'sku' => 'GRJ-004', 'barcode' => '8999000000566', 'modal' => 2500, 'jual' => 3000, 'stok' => 0],
            ['kategori' => 'Gorengan & Jajanan', 'title' => 'Sosis Sonice Besar', 'sku' => 'GRJ-005', 'barcode' => '8999000000627', 'modal' => 1700, 'jual' => 2500, 'stok' => 25],
            ['kategori' => 'Gorengan & Jajanan', 'title' => 'Sosis Sonice Kecil', 'sku' => 'GRJ-006', 'barcode' => '8999000000634', 'modal' => 800, 'jual' => 1500, 'stok' => 1],
            ['kategori' => 'Kopi', 'title' => 'Golda', 'sku' => 'KOP-001', 'barcode' => '8999000000221', 'modal' => 3225, 'jual' => 4000, 'stok' => 1],
            ['kategori' => 'Kopi', 'title' => 'Gooday Chocolate', 'sku' => 'KOP-002', 'barcode' => '8999000000238', 'modal' => 2263, 'jual' => 4000, 'stok' => 3],
            ['kategori' => 'Kopi', 'title' => 'Gooday Coolin', 'sku' => 'KOP-003', 'barcode' => '8999000000245', 'modal' => 2263, 'jual' => 4000, 'stok' => 9],
            ['kategori' => 'Kopi', 'title' => 'Gooday Freeze', 'sku' => 'KOP-004', 'barcode' => '8999000000252', 'modal' => 3043, 'jual' => 5000, 'stok' => 9],
            ['kategori' => 'Kopi', 'title' => 'Gooday Granola', 'sku' => 'KOP-005', 'barcode' => '8999000000269', 'modal' => 2793, 'jual' => 5000, 'stok' => 7],
            ['kategori' => 'Kopi', 'title' => 'Gooday Mocacino', 'sku' => 'KOP-006', 'barcode' => '8999000000276', 'modal' => 2263, 'jual' => 4000, 'stok' => 6],
            ['kategori' => 'Kopi', 'title' => 'Gooday Vanila Latte', 'sku' => 'KOP-007', 'barcode' => '8999000000283', 'modal' => 2263, 'jual' => 4000, 'stok' => 2],
            ['kategori' => 'Kopi', 'title' => 'Indocafe', 'sku' => 'KOP-008', 'barcode' => '8999000000290', 'modal' => 2533, 'jual' => 4000, 'stok' => 0],
            ['kategori' => 'Kopi', 'title' => 'Kapal Api', 'sku' => 'KOP-009', 'barcode' => '8999000000313', 'modal' => 2493, 'jual' => 4000, 'stok' => 16],
            ['kategori' => 'Kopi', 'title' => 'Nesacafe', 'sku' => 'KOP-010', 'barcode' => '8999000000481', 'modal' => 2143, 'jual' => 4000, 'stok' => 9],
            ['kategori' => 'Kopi', 'title' => 'Top Cappucino', 'sku' => 'KOP-011', 'barcode' => '8999000000719', 'modal' => 2713, 'jual' => 5000, 'stok' => 1],
            ['kategori' => 'Kopi', 'title' => 'Zenlatte Dark Chocolate', 'sku' => 'KOP-013', 'barcode' => '8999000000740', 'modal' => 2793, 'jual' => 5000, 'stok' => 11],
            ['kategori' => 'Kopi', 'title' => 'Zenlatte Matcha', 'sku' => 'KOP-014', 'barcode' => '8999000000757', 'modal' => 2793, 'jual' => 5000, 'stok' => 17],
            ['kategori' => 'Mie Instan', 'title' => 'Mie Gelas', 'sku' => 'MIE-001', 'barcode' => '8999000000412', 'modal' => 1483, 'jual' => 2000, 'stok' => 2],
            ['kategori' => 'Mie Instan', 'title' => 'Mie Goreng Sedap', 'sku' => 'MIE-002', 'barcode' => '8999000000429', 'modal' => 3905, 'jual' => 7000, 'stok' => 1],
            ['kategori' => 'Mie Instan', 'title' => 'Mie Kuah Sedap', 'sku' => 'MIE-003', 'barcode' => '8999000000436', 'modal' => 3805, 'jual' => 7000, 'stok' => 1],
            ['kategori' => 'Mie Instan', 'title' => 'Pop Mie', 'sku' => 'MIE-004', 'barcode' => '8999000000542', 'modal' => 5160, 'jual' => 7000, 'stok' => 3],
            ['kategori' => 'Susu', 'title' => 'Milku', 'sku' => 'SSM-001', 'barcode' => '8999000000443', 'modal' => 3025, 'jual' => 4000, 'stok' => 8],
            ['kategori' => 'Susu', 'title' => 'Susu Coklat', 'sku' => 'SSM-002', 'barcode' => '8999000000641', 'modal' => 1613, 'jual' => 4000, 'stok' => 0],
            ['kategori' => 'Susu', 'title' => 'Susu Putih', 'sku' => 'SSM-003', 'barcode' => '8999000000658', 'modal' => 1613, 'jual' => 4000, 'stok' => 4],
            ['kategori' => 'Susu', 'title' => 'Susu Jahe', 'sku' => 'SSM-004', 'barcode' => '8999000000665', 'modal' => 2413, 'jual' => 4000, 'stok' => 1],
            ['kategori' => 'Makanan', 'title' => 'Naskun Nasgor', 'sku' => 'MKN-001', 'barcode' => '8999000000474', 'modal' => 5000, 'jual' => 6000, 'stok' => 5],
            ['kategori' => 'Makanan', 'title' => 'Pizza', 'sku' => 'MKN-002', 'barcode' => '8999000000535', 'modal' => 5500, 'jual' => 6000, 'stok' => 1],
            ['kategori' => 'Makanan', 'title' => 'Wow Spagheti', 'sku' => 'MKN-003', 'barcode' => '8999000000733', 'modal' => 4105, 'jual' => 6000, 'stok' => 3],
            ['kategori' => 'Permen', 'title' => 'Permen', 'sku' => 'PRM-001', 'barcode' => '8999000000528', 'modal' => 144, 'jual' => 250, 'stok' => 1],
            ['kategori' => 'Teh', 'title' => 'Teajus Apel', 'sku' => 'TEH-001', 'barcode' => '8999000000672', 'modal' => 1143, 'jual' => 2000, 'stok' => 44],
            ['kategori' => 'Teh', 'title' => 'Teajus Gula Batu', 'sku' => 'TEH-002', 'barcode' => '8999000000689', 'modal' => 1143, 'jual' => 2000, 'stok' => 43],
            ['kategori' => 'Teh', 'title' => 'Teajus Lemon', 'sku' => 'TEH-003', 'barcode' => '8999000000696', 'modal' => 1143, 'jual' => 2000, 'stok' => 28],
            ['kategori' => 'Teh', 'title' => 'Teajus Melati', 'sku' => 'TEH-004', 'barcode' => '8999000000702', 'modal' => 1143, 'jual' => 2000, 'stok' => 57],
            ['kategori' => 'Teh', 'title' => 'Zenlatte Teh Tarik', 'sku' => 'TEH-005', 'barcode' => '8999000000764', 'modal' => 2793, 'jual' => 5000, 'stok' => 16],
            ['kategori' => 'Teh', 'title' => 'Zenlatte Thai Tea', 'sku' => 'TEH-006', 'barcode' => '8999000000771', 'modal' => 2793, 'jual' => 5000, 'stok' => 25],
            ['kategori' => 'Minuman Siap Saji', 'title' => 'Es Teh', 'sku' => 'MSJ-001', 'barcode' => '8999000000788', 'modal' => 1025, 'jual' => 2000, 'stok' => 1],
        ];
    }
}
