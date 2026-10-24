<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Slip Security - {{ $document->number }}</title>
    <style>
        @page { size: 80mm auto; margin: 2mm; }
        * { box-sizing: border-box; }
        body { width: 76mm; margin: 0 auto; color: #151515; font-family: Arial, Helvetica, sans-serif; font-size: 11px; }
        .no-print { padding: 12px; text-align: center; font-family: Arial, sans-serif; }
        .no-print button { padding: 8px 12px; border: 0; background: #075985; color: #fff; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .slip { padding: 2.5mm 2mm; }
        .center { text-align: center; }
        .logo { display: block; width: 22mm; max-height: 10mm; margin: 0 auto .5mm; object-fit: contain; }
        .company { margin: 0; font-size: 12px; line-height: 1.1; font-weight: 800; }
        .rule { border: 0; border-top: 1.5px solid #161616; margin: 2.5mm 0; }
        .slip-title { margin: 0; font-size: 17px; line-height: 1.05; font-weight: 900; letter-spacing: .2px; }
        .subtitle { margin: .5mm 0 0; font-size: 11px; font-weight: 500; }
        .details { width: 100%; border-collapse: collapse; font-size: 10px; }
        .details td { padding: 1.1mm 1mm; vertical-align: top; border-bottom: 1px solid #c6cbd0; }
        .details tr:last-child td { border-bottom: 0; }
        .details td:first-child { width: 33%; font-weight: 700; }
        .details td:nth-child(2) { width: 5%; text-align: center; font-weight: bold; }
        .details td:last-child { font-weight: 700; }
        .dash { border: 0; border-top: 1px dashed #60666d; margin: 2mm 0; }
        .qr { display: block; width: 32mm; height: 32mm; margin: 0 auto; object-fit: contain; image-rendering: pixelated; }
        .code-box { margin-top: 1.5mm; padding: 1.5mm 1mm; border: 1px solid #151515; text-align: center; }
        .code-label { margin: 0 0 .5mm; font-size: 8px; font-weight: 700; }
        .code { margin: 0; font-family: 'Courier New', monospace; font-size: 15px; line-height: 1.05; font-weight: 900; letter-spacing: 0; white-space: nowrap; }
        .instruction { display: grid; grid-template-columns: 6mm 1fr; gap: 1.5mm; align-items: center; font-size: 8px; line-height: 1.22; }
        .instruction-icon { display: grid; place-items: center; width: 5mm; height: 5mm; border: 1px solid #151515; border-radius: 50%; font-size: 9px; font-weight: 800; }
        .instruction p { margin: 0; border-left: 1px solid #151515; padding-left: 1.5mm; }
        .printed { margin: 0; font-size: 8px; }
        @media print { .no-print { display: none; } body { width: 76mm; } }
    </style>
</head>
<body>
<div class="no-print"><button type="button" onclick="window.print()">Cetak slip thermal</button></div>
<main class="slip">
    <header class="center"><img class="logo" src="{{ asset('assets/img/mms.png') }}" alt="Logo MKM"><p class="company">PT. Mitsubishi Krama Yudha<br>Motors &amp; Manufacturing</p></header>
    <hr class="rule">
    <div class="center"><h1 class="slip-title">SLIP POS SECURITY</h1><p class="subtitle">Surat Muat Barang</p></div>
    <hr class="rule">
    <table class="details">
        <tr><td>No. SMB</td><td>:</td><td>{{ $document->number }}</td></tr>
        <tr><td>Jenis</td><td>:</td><td>{{ $document->type === 'DISPOSAL' ? 'Disposal' : 'Scrap' }}</td></tr>
        <tr><td>Pengemudi</td><td>:</td><td>{{ $document->driver_name }}</td></tr>
        <tr><td>Kendaraan</td><td>:</td><td>{{ $document->vehicle }}</td></tr>
        <tr><td>No. Polisi</td><td>:</td><td>{{ $document->plate_number }}</td></tr>
        <tr><td>Tujuan</td><td>:</td><td>{{ $document->destination }}</td></tr>
    </table>
    <hr class="dash">
    <div class="center">
        @if($documentQrDataUri)<img class="qr" src="{{ $documentQrDataUri }}" alt="QR nomor unik Security">@endif
    </div>
    <div class="code-box"><p class="code-label">NOMOR UNIK SECURITY</p><p class="code">{{ $document->securityCode() }}</p></div>
    <hr class="dash">
    <div class="instruction"><span class="instruction-icon">i</span><p>Bawa slip ini ke Pos Security. Security memindai QR atau memasukkan nomor unik untuk membuka data SMB.</p></div>
    <hr class="dash">
    <p class="printed"><strong>Dicetak:</strong> {{ now()->translatedFormat('d M Y H:i') }} WIB</p>
</main>
</body>
</html>
