# Sales Returns

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Mengoreksi transaksi penjualan yang sudah terjadi melalui retur parsial atau penuh yang tetap menjaga stok, profit, dan piutang tetap sinkron.

## Fitur Saat Ini

- create draft sales return dari histori transaksi
- update draft retur
- complete retur
- refund tunai
- store credit
- restock ke inventory
- koreksi receivable untuk transaksi `pay_later`
- histori retur penjualan

## Halaman dan Route

- `dashboard/transactions/history`
- `dashboard/sales-returns`
- `sales-returns.create`
- `sales-returns.store`
- `sales-returns.show`
- `sales-returns.update`
- `sales-returns.complete`

## Permission

- `sales-returns-access`
- `sales-returns-create`
- `sales-returns-complete`

## Alur User

1. user membuka histori transaksi
2. jika transaksi masih punya qty yang returnable, tombol retur tampil
3. user membuat draft retur dari transaksi asal
4. user memilih qty retur, alasan, dan opsi restock
5. user menyimpan draft
6. user menyelesaikan retur
7. sistem memperbarui stok, profit, dan receivable bila relevan

## Integrasi Data

- `sales_returns`
- `sales_return_items`
- `customer_credits`
- `transactions`
- `transaction_details`
- `profits`
- `receivables`
- `stock_mutations`

## Efek Bisnis Penting

- retur completed bisa menambah stok kembali
- retur pada transaksi piutang bisa mengurangi total receivable
- overpayment dari piutang dapat berubah menjadi refund atau customer credit

## Batasan Saat Ini

- fitur ini bergantung pada migration tabel retur
- flow tukar barang tidak termasuk
- shipping cost tidak menjadi bagian nominal retur

## File Sentral

- `app/Http/Controllers/Apps/SalesReturnController.php`
- `resources/js/Pages/Dashboard/SalesReturns`
