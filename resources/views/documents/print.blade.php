<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $document->number }} - {{ ['smb'=>'SMB','delivery'=>'Surat Jalan','release'=>'Surat Pengeluaran Barang'][$template] }}</title>@vite(['resources/css/app.css','resources/js/app.js'])
@if($template === 'release')<style>@page { size: A4 portrait; margin: 10mm; }</style>@endif
@include('pwa.head')
</head><body class="document-print-page">
<div class="no-print mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-2 p-3"><a href="{{ route('documents.show',$document) }}" class="btn btn-secondary">Kembali ke dokumen</a><div class="flex flex-wrap gap-2">@foreach(['smb'=>'SMB','delivery'=>'Surat Jalan','release'=>'Surat Pengeluaran Barang'] as $key=>$label)<a class="btn btn-secondary" href="{{ route('documents.print',[$document,'template'=>$key]) }}" @if($template === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach<button type="button" class="btn" data-print>Cetak / Simpan PDF</button></div></div>
<p class="no-print mx-auto max-w-5xl px-3 pb-3 text-xs text-muted">{{ $document->status === 'COMPLETED' ? 'Dokumen selesai. Nama dan waktu persetujuan berasal dari catatan proses dokumen.' : 'Pratinjau dokumen. Proses pengeluaran barang belum selesai.' }} Kolom tanda tangan disediakan untuk pihak yang terkait.</p>
<div class="paper-preview-scroll"><main class="paper-sheet paper-print-sheet {{ $template === 'release' ? 'paper-letter' : '' }} {{ count($document->items ?? []) > 10 ? 'paper-density-high' : '' }}">
@php
$details = $document->form_details ?? [];
$items = $document->items ?: [['name'=>$document->goods_description,'quantity'=>'','unit'=>'']];
$line = fn($key) => data_get($details,$key) ?: '................................';
$approval = fn($role) => $document->printedApproval($role);
$printStatus = match($document->status) {
    'DRAFT' => 'Draf',
    'DRIVER_HANDOVER' => 'Menunggu tanda tangan pengemudi',
    'SECURITY' => 'Menunggu pemeriksaan Security',
    'COMPLETED' => 'Selesai',
    default => 'Dalam proses persetujuan',
};
@endphp
@if($document->status !== 'COMPLETED')<div class="paper-draft-mark">DRAF - BELUM SAH UNTUK PENGELUARAN BARANG</div>@endif
<div class="paper-brand"><img src="{{ asset('assets/img/mms.png') }}" alt="MKM"><div><strong>PT MITSUBISHI KRAMA YUDHA MOTORS AND MANUFACTURING</strong>@if($template !== 'release')<h1>{{ $template === 'smb' ? 'SURAT MUAT BARANG' : 'SURAT JALAN NON PRODUKSI' }}</h1>@endif</div>@if($documentQrDataUri)<div class="paper-security-qr"><img src="{{ $documentQrDataUri }}" alt="Kode pindai dokumen"><small>{{ $documentQrLabel }}</small><strong>{{ $documentQrValue }}</strong></div>@endif</div>
@if($template === 'release')
<div class="paper-letter-meta"><span>{{ $line('letter_city') }}, {{ $document->document_date->format('d M Y') }}</span><span>NO: {{ data_get($details,'letter_number') ?: $document->number }}</span></div>
<p>Kepada Yth.,</p><table class="paper-recipient"><tr><td>1.</td><td>HR &amp; GA Safety Section Dept.</td><td>u.p.: {{ $line('recipient_hr') }}</td></tr><tr><td>2.</td><td>PPC Dept.</td><td>u.p.: {{ $line('recipient_ppc') }}</td></tr></table>
<p>Dengan hormat,</p><h1 class="paper-letter-subject">Perihal : &nbsp; Pengeluaran Barang</h1>
<p class="paper-letter-reference">Sesuai dengan permohonan penjualan no: <strong>{{ $line('sales_reference') }}</strong><br>Tertanggal: {{ $line('sales_reference_date') }} &nbsp;&nbsp; dari {{ $line('origin_department') }} Dept.</p>
<p>Maka dengan ini kami beritahukan rencana penjualan yang akan kami laksanakan sebagai berikut:</p>
<table class="paper-letter-details"><tr><td>Nama Barang</td><td>:</td><td>@foreach($items as $item)<div>{{ $loop->iteration }}. {{ $item['name'] }} @if($item['quantity'] ?? null): {{ $item['quantity'] }} {{ $item['unit'] ?? '' }}@endif</div>@endforeach</td></tr>
@foreach(['Tanggal Penghancuran'=>$line('disposal_date'), 'Tanggal Penjualan'=>$line('sale_date'), 'Waktu'=>$line('sale_time'), 'Pembeli'=>$document->company, 'Tempat'=>data_get($details,'location') ?: $document->destination] as $label=>$value)<tr><td>{{ $label }}</td><td>:</td><td>{{ $value }}</td></tr>@endforeach</table>
<p>Mohon bantuan didalam pelaksanaan.</p><p>Terima kasih.</p>
<div class="paper-letter-sign"><p>Procurement Dept.</p>@php($event=$approval('PROC_DH'))<div class="paper-approval-name">@include('documents.approval-mark', ['event'=>$event])</div><p>Dept. Head / Delegate In Charge</p></div>
<div class="paper-letter-copy">1 &nbsp; {{ $document->company }} &nbsp;&nbsp;&nbsp;&nbsp; U.P.: {{ $line('buyer_contact') }}</div>
@else
@if($template === 'delivery')<div class="paper-revision"><span>No. Dokumen: LD-GA-0020</span><span>Tgl. Efektif: 01 April 2019</span><span>Rev: 0</span></div>@endif
<div class="paper-heading-fields">
@foreach(($template === 'smb' ? ['NAMA PENGEMUDI/KENDARAAN'=>$document->driver_name,'PERUSAHAAN'=>$document->company,'NO. KTP'=>$line('identity_number'),'KENDARAAN'=>$document->vehicle,'TUJUAN'=>$document->destination,'NO. POL'=>$document->plate_number,'TANGGAL'=>$document->document_date->format('d-m-Y'),'JAM'=>$document->departure_at?->format('H:i'),'NO. SMB'=>$document->number,'LOT'=>$line('lot')] : ['NO. SURAT JALAN'=>($document->type === 'DISPOSAL' ? $document->number : $line('delivery_number')),'TANGGAL'=>$document->document_date->format('d-m-Y'),'PERUSAHAAN / DEPT.'=>$document->company.' / '.(data_get($details,'department') ?: '-'),'JAM'=>$document->departure_at?->format('H:i'),'TUJUAN'=>$document->destination,'KENDARAAN'=>$document->vehicle,'PEMBAWA'=>$document->driver_name,'NO. POL'=>$document->plate_number,'NO. KTP/SIM'=>$line('identity_number')]) as $label=>$value)
<div><strong>{{ $label }}</strong><span>: {{ $value ?: '........................' }}</span></div>
@endforeach</div>
@if($document->type === 'DISPOSAL')<div class="paper-heading-fields"><div><strong>BERAT BERSIH</strong><span>: {{ data_get($details,'net_weight') ?: '........................' }} KG</span></div><div><strong>BERAT KOTOR</strong><span>: {{ data_get($details,'gross_weight') ?: '........................' }} KG</span></div><div><strong>SELISIH</strong><span>: {{ data_get($details,'gross_weight') !== null && data_get($details,'net_weight') !== null ? data_get($details,'gross_weight') - data_get($details,'net_weight') : '........................' }} KG</span></div></div>@endif
<p class="paper-goods-caption">{{ $template === 'smb' ? 'BARANG YANG DIMUAT ADALAH:' : 'UNTUK MEMBAWA BARANG BERUPA:' }}</p>
<table class="paper-table paper-goods"><thead><tr><th style="width:5%">NO</th>@if($document->type === 'DISPOSAL')<th>NAMA BARANG</th><th>BERAT BERSIH (KG)</th><th>BERAT KOTOR (KG)</th><th>SELISIH (KG)</th>@else<th style="width:13%">KODE</th><th>NAMA BARANG</th><th style="width:16%">JUMLAH</th>@if($template === 'smb')<th style="width:10%">PALLET</th><th style="width:10%">TIANG</th>@endif<th style="width:18%">{{ $template === 'smb' ? 'CATATAN' : 'KETERANGAN' }}</th>@endif</tr></thead><tbody>
@for($i=0;$i<max($template === 'smb' ? 10 : 8,count($items));$i++)
@php($item=$items[$i] ?? [])<tr><td>{{ isset($items[$i]) ? $i+1 : '' }}</td>@if($document->type === 'DISPOSAL')<td>{{ $item['name'] ?? '' }}</td><td>{{ $item['net_weight'] ?? '' }}</td><td>{{ $item['gross_weight'] ?? '' }}</td><td>{{ isset($item['gross_weight'], $item['net_weight']) ? $item['gross_weight'] - $item['net_weight'] : '' }}</td>@else<td>{{ $item['code'] ?? '' }}</td><td>{{ $item['name'] ?? '' }}</td><td>{{ $item['quantity'] ?? '' }} {{ $item['unit'] ?? '' }}</td>@if($template === 'smb')<td>{{ $item['pallet'] ?? '' }}</td><td>{{ $item['posts'] ?? '' }}</td>@endif<td>{{ $item['remarks'] ?? '' }}</td>@endif</tr>
@endfor</tbody></table>
<div class="paper-document-footer">@if($template === 'smb')<div class="paper-smb-bottom"><div class="paper-signature"><strong>DIPERIKSA OLEH</strong>@php($event=$approval('SECURITY'))<div class="paper-approval-name">@include('documents.approval-mark', ['event'=>$event, 'stacked'=>true])</div></div><div class="paper-signature"><strong>PENERIMA &amp; PEMBAWA BARANG</strong><div class="paper-driver-signature">@if($driverSignatureDataUri)<img src="{{ $driverSignatureDataUri }}" alt="Tanda tangan pengemudi">@endif<span>{{ $document->driver_signed_name ?: $document->driver_name }}</span>@if($document->driver_signed_at)<small>{{ $document->driver_signed_at->format('d/m/Y H:i') }} WIB</small>@endif</div></div><div class="paper-smb-notes"><p>DEPT/SHOP: {{ $line('department') }}</p><p>YANG MEMUAT: {{ data_get($details,'loader_name') ?: '—' }}</p><p>ASLI DIBAWA KE KANTOR UNTUK MENDAPAT SURAT JALAN</p><p>LAMPIRAN: {{ data_get($details,'attachments') === 'attached' ? 'ADA' : 'TIDAK ADA' }}</p></div></div>
@else
<table class="paper-table paper-signatures"><thead><tr><th>KEPALA DEPT. /<br>PENERIMA DELEGASI</th><th>KEPALA HR &amp; ADM /<br>PENERIMA DELEGASI</th><th>PEMBAWA<br>BARANG</th><th>PENERIMA<br>BARANG</th><th>SECURITY<br>PT MKM</th></tr></thead><tbody><tr>@foreach(['PROC_DH','HRD','carrier','recipient','SECURITY'] as $role)<td>@php($event=$approval($role))<div class="paper-approval-name">@if(in_array($role,['carrier','recipient']) && $driverSignatureDataUri)<img class="paper-signature-image" src="{{ $driverSignatureDataUri }}" alt="Tanda tangan pengemudi">@else @include('documents.approval-mark', ['event'=>$event, 'showName'=>false]) @endif</div><div class="paper-sign-line">( {{ $role === 'carrier' ? ($document->driver_signed_name ?: $document->driver_name) : ($role === 'recipient' ? ($document->receiver_name ?: $document->driver_signed_name) : ($event?->actual_actor_name ?: $event?->user?->name)) }} )</div></td>@endforeach</tr></tbody></table>
<div class="paper-copy-colors"><span>Lembar: Asli/Putih - Dept. Pemilik Barang</span><span>Merah - Pengemudi / Vendor</span><span>Kuning - Security</span></div>
@endif
@endif
<div class="paper-audit-foot">{{ $document->number }} | Status: {{ $printStatus }} | Dicetak {{ now()->translatedFormat('d M Y H:i') }} WIB</div></div>
</main></div></body></html>
