<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 10mm; }
        body { font-family: 'Inter', 'Helvetica', 'Arial', sans-serif; margin:0; background:#f8fafc; color:#0f172a; }
        .sheet { max-width: 900px; margin: 0 auto; background:white; border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; }
        .header { display:flex; justify-content: space-between; align-items: flex-start; gap:16px; border-bottom:1px solid #e2e8f0; padding-bottom:12px; }
        .store { display:flex; gap:12px; align-items:center; flex:1; min-width:0; }
        .logo { width:52px; height:52px; display:flex; align-items:center; justify:center; overflow:hidden; }
        .logo img { max-width:100%; max-height:100%; object-fit:contain; }
        .store-info { line-height:1.4; }
        .store-name { font-weight:700; font-size:17px; }
        .muted { color:#475569; font-size:12px; }
        .badge { padding:4px 10px; border-radius:999px; background:#dcfce7; color:#15803d; font-weight:700; font-size:12px; text-align:right; }
        .doc { text-align:right; min-width:180px; }
        .doc-number { font-size:17px; font-weight:800; margin:4px 0; }
        .section { border:1px solid #e2e8f0; border-radius:12px; padding:12px; margin-top:12px; }
        .section-title { font-size:11px; text-transform: uppercase; color:#64748b; letter-spacing:0.5px; font-weight:700; margin-bottom:6px; }
        .grid-2 { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); gap:12px; }
        .stat { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px; }
        .stat-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.4px; }
        .stat-value { font-size:16px; font-weight:800; margin-top:4px; color:#0f172a; }
        .stat-positive { color:#16a34a; }
        .stat-warning { color:#c2410c; }
        table { width:100%; border-collapse: collapse; margin-top:6px; }
        th, td { padding:6px 0; font-size:13px; text-align:left; }
        th { color:#64748b; text-transform: uppercase; letter-spacing:0.4px; font-size:11px; border-bottom:1px solid #e2e8f0; }
        tr + tr td { border-top:1px solid #e2e8f0; }
        .footer { margin-top:14px; display:flex; justify-content: space-between; align-items:center; gap:10px; }
        .barcode img { height: 42px; }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="header">
            <div class="store">
                <div class="logo">
                    @if($store['logo'])
                        <img src="{{ $store['logo'] }}" alt="{{ $store['name'] }}">
                    @else
                        <strong>{{ substr($store['name'],0,2) }}</strong>
                    @endif
                </div>
                <div class="store-info">
                    <div class="store-name">{{ $store['name'] }}</div>
                    @if($store['address'])<div class="muted">{{ $store['address'] }}</div>@endif
                    <div class="muted">{{ $store['phone'] ? 'Telp: '.$store['phone'].' • ' : '' }}{{ $store['email'] }}</div>
                </div>
            </div>
            <div class="doc">
                <div class="badge">Hutang</div>
                <div class="doc-number">{{ $payable->document_number }}</div>
                <div class="muted">Jatuh tempo: {{ $payable->due_date ?? '-' }}</div>
            </div>
        </div>

        <div class="section grid-2">
            <div>
                <div class="section-title">Supplier</div>
                <div style="font-weight:700; font-size:14px;">{{ $payable->supplier->name ?? '-' }}</div>
                @if($payable->supplier?->phone)<div class="muted">{{ $payable->supplier->phone }}</div>@endif
            </div>
            <div class="grid-2" style="gap:10px;">
                <div class="stat">
                    <div class="stat-label">Total</div>
                    <div class="stat-value">{{ number_format($payable->total,0,',','.') }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Terbayar</div>
                    <div class="stat-value stat-positive">{{ number_format($payable->paid,0,',','.') }}</div>
                </div>
                <div class="stat" style="grid-column: span 2;">
                    <div class="stat-label">Sisa</div>
                    <div class="stat-value stat-warning">
                        {{ number_format(max(0, $payable->total - $payable->paid),0,',','.') }}
                    </div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Riwayat Pembayaran</div>
            <table>
                <thead>
                    <tr>
                        <th style="width:35%;">Tanggal</th>
                        <th style="width:35%;">Metode</th>
                        <th style="text-align:right;">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($payable->payments as $pay)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($pay->paid_at)->format('d M Y') }}</td>
                        <td>{{ strtoupper($pay->method ?? '-') }}</td>
                        <td style="text-align:right;">{{ number_format($pay->amount,0,',','.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" style="color:#94a3b8; text-align:center;">Belum ada pembayaran</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="footer">
            <div class="muted" style="font-size:11px;">Dicetak pada {{ now()->format('d M Y') }}</div>
            <div class="barcode" style="text-align:right;">
                <img src="{{ $barcode }}" alt="barcode">
                <div style="font-size:10px; text-align:right;">{{ $payable->document_number }}</div>
            </div>
        </div>
    </div>
</body>
</html>
