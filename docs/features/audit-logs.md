# Audit Logs

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Menyediakan jejak aktivitas untuk perubahan penting di modul sensitif agar developer dan admin bisa melakukan penelusuran perubahan.

## Fitur Saat Ini

- list audit log
- filter berdasarkan user, module, event, tanggal, dan keyword
- detail audit log
- before / after payload
- meta tambahan
- ip address dan user agent

## Halaman dan Route

- `dashboard/audit-logs`
- `audit-logs.show`

## Permission

- `audit-logs-access`

## Modul yang Sudah Terintegrasi

Saat ini audit log digunakan pada beberapa aksi penting seperti:

- payment settings update
- cashier shift open / close / force close
- sales return create / update / complete
- konfirmasi pembayaran transaksi
- dan modul admin lain yang memanggil `AuditLogService`

## Integrasi Data

- `audit_logs`
- relasi ke `users`
- relasi polymorphic ke model yang diaudit bila tersedia

## Efek Bisnis Penting

- audit log bukan pengganti authorization
- audit log membantu analisis perubahan, terutama pada konfigurasi, transaksi, dan inventory-adjacent flow

## Batasan Saat Ini

- cakupan event bergantung pada controller/service yang secara eksplisit memanggil `AuditLogService`
- tidak semua CRUD otomatis tercatat

## File Sentral

- `app/Http/Controllers/Apps/AuditLogController.php`
- `app/Services/AuditLogService.php`
