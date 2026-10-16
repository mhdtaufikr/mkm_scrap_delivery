<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $document->number }} - {{ ['smb'=>'SMB','delivery'=>'Delivery Note','release'=>'Goods Release Letter'][$template] }}</title>@vite(['resources/css/app.css','resources/js/app.js'])@if($template === 'release')<style>@page { size: A4 portrait; margin: 10mm; }</style>@endif@include('pwa.head')
</head><body class="document-print-page">
<div class="no-print mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-2 p-3"><a href="{{ route('documents.show',$document) }}" class="btn btn-secondary">Back to document</a><div class="flex flex-wrap gap-2">@foreach(['smb'=>'SMB','delivery'=>'Delivery note','release'=>'Release letter'] as $key=>$label)<a class="btn btn-secondary" href="{{ route('documents.print',[$document,'template'=>$key]) }}" @if($template === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach<button type="button" class="btn" data-print>Print / Save PDF</button></div></div>
<p class="no-print mx-auto max-w-5xl px-3 pb-3 text-xs text-muted">{{ $document->status === 'COMPLETED' ? 'Completed document. Approval names and timestamps come from the application audit trail.' : 'Preview only. This document has not completed the goods release workflow.' }} Signature spaces are provided for the actual signatories.</p>
<div class="paper-preview-scroll"><main class="paper-sheet paper-print-sheet {{ $template === 'release' ? 'paper-letter' : '' }}">
@php
$details = $document->form_details ?? [];
$items = $document->items ?: [['name'=>$document->goods_description,'quantity'=>'','unit'=>'']];
$line = fn($key) => data_get($details,$key) ?: '................................';
$approval = fn($role) => $document->printedApproval($role);
@endphp
@if($document->status !== 'COMPLETED')<div class="paper-draft-mark">DRAFT - NOT FOR GOODS RELEASE</div>@endif
<div class="paper-brand"><img src="{{ asset('assets/img/mms.png') }}" alt="MKM"><div><strong>PT MITSUBISHI KRAMA YUDHA MOTORS AND MANUFACTURING</strong>@if($template !== 'release')<h1>{{ $template === 'smb' ? 'SURAT MUAT BARANG' : 'SURAT JALAN NON PRODUKSI' }}</h1>@endif</div></div>
@if($template === 'release')
<div class="paper-letter-meta"><span>{{ $line('letter_city') }}, {{ $document->document_date->format('d M Y') }}</span><span>NO: {{ data_get($details,'letter_number') ?: $document->number }}</span></div>
<p>Kepada Yth.,</p><table class="paper-recipient"><tr><td>1.</td><td>HR &amp; GA Safety Section Dept.</td><td>u.p.: {{ $line('recipient_hr') }}</td></tr><tr><td>2.</td><td>PPC Dept.</td><td>u.p.: {{ $line('recipient_ppc') }}</td></tr></table>
<p>Dengan hormat,</p><h1 class="paper-letter-subject">Perihal : &nbsp; Pengeluaran Barang</h1>
<p class="paper-letter-reference">Sesuai dengan permohonan penjualan no: <strong>{{ $line('sales_reference') }}</strong><br>Tertanggal: {{ $line('sales_reference_date') }} &nbsp;&nbsp; dari {{ $line('origin_department') }} Dept.</p>
<p>Maka dengan ini kami beritahukan rencana penjualan yang akan kami laksanakan sebagai berikut:</p>
<table class="paper-letter-details"><tr><td>Nama Barang</td><td>:</td><td>@foreach($items as $item)<div>{{ $loop->iteration }}. {{ $item['name'] }} @if($item['quantity'] ?? null): {{ $item['quantity'] }} {{ $item['unit'] ?? '' }}@endif</div>@endforeach</td></tr>
@foreach(['Tanggal Penghancuran'=>$line('disposal_date'), 'Tanggal Penjualan'=>$line('sale_date'), 'Waktu'=>$line('sale_time'), 'Pembeli'=>$document->company, 'Tempat'=>data_get($details,'location') ?: $document->destination] as $label=>$value)<tr><td>{{ $label }}</td><td>:</td><td>{{ $value }}</td></tr>@endforeach</table>
<p>Mohon bantuan didalam pelaksanaan.</p><p>Terima kasih.</p>
<div class="paper-letter-sign"><p>Procurement Dept.</p>@php($event=$approval('PROC_DH'))<div class="paper-approval-name">@include('documents.approval-mark', ['event'=>$event])</div><p>{{ $event?->action === 'delegate' ? 'Procurement Staff - Delegated approval' : 'Dept. Head' }}</p></div>
<div class="paper-letter-copy">1 &nbsp; {{ $document->company }} &nbsp;&nbsp;&nbsp;&nbsp; U.P.: {{ $line('buyer_contact') }}</div>
@else
@if($template === 'delivery')<div class="paper-revision"><span>No. Dokumen: LD-GA-0020</span><span>Tgl. Efektif: 01 April 2019</span><span>Rev: 0</span></div>@endif
<div class="paper-heading-fields">
@foreach(($template === 'smb' ? ['NAMA PENGEMUDI/KENDARAAN'=>$document->driver_name,'PERUSAHAAN'=>$document->company,'NO. KTP'=>$line('identity_number'),'KENDARAAN'=>$document->vehicle,'TUJUAN'=>$document->destination,'NO. POL'=>$document->plate_number,'TANGGAL'=>$document->document_date->format('d-m-Y'),'JAM'=>$document->departure_at?->format('H:i'),'NO. SMB'=>$document->number,'LOT'=>$line('lot')] : ['NO. SURAT JALAN'=>$line('delivery_number'),'TANGGAL'=>$document->document_date->format('d-m-Y'),'PERUSAHAAN / DEPT.'=>$document->company.' / '.(data_get($details,'department') ?: '-'),'JAM'=>$document->departure_at?->format('H:i'),'TUJUAN'=>$document->destination,'KENDARAAN'=>$document->vehicle,'PEMBAWA'=>$document->driver_name,'NO. POL'=>$document->plate_number,'NO. KTP/SIM'=>$line('identity_number')]) as $label=>$value)
<div><strong>{{ $label }}</strong><span>: {{ $value ?: '........................' }}</span></div>
@endforeach</div>
<p class="paper-goods-caption">{{ $template === 'smb' ? 'BARANG YANG DIMUAT ADALAH:' : 'UNTUK MEMBAWA BARANG BERUPA:' }}</p>
<table class="paper-table paper-goods"><thead><tr><th style="width:5%">NO</th><th style="width:13%">KODE</th><th>NAMA BARANG</th><th style="width:16%">JUMLAH</th>@if($template === 'smb')<th style="width:10%">PALLET</th><th style="width:10%">TIANG</th>@endif<th style="width:18%">{{ $template === 'smb' ? 'CATATAN' : 'KETERANGAN' }}</th></tr></thead><tbody>
@for($i=0;$i<max($template === 'smb' ? 10 : 8,count($items));$i++)
@php($item=$items[$i] ?? [])<tr><td>{{ isset($items[$i]) ? $i+1 : '' }}</td><td>{{ $item['code'] ?? '' }}</td><td>{{ $item['name'] ?? '' }}</td><td>{{ $item['quantity'] ?? '' }} {{ $item['unit'] ?? '' }}</td>@if($template === 'smb')<td>{{ $item['pallet'] ?? '' }}</td><td>{{ $item['posts'] ?? '' }}</td>@endif<td>{{ $item['remarks'] ?? '' }}</td></tr>
@endfor</tbody></table>
@if($template === 'smb')<div class="paper-smb-bottom"><div class="paper-signature"><strong>DIPERIKSA OLEH,</strong>@php($event=$approval('SECURITY'))<div class="paper-approval-name">@include('documents.approval-mark', ['event'=>$event])</div></div><div class="paper-signature"><strong>YANG MEMUAT</strong><div class="paper-approval-name">{{ data_get($details,'loader_name') }}</div></div><div><p>DEPT/SHOP: {{ $line('department') }}</p><p>ASLI DIBAWA KE KANTOR UNTUK MENDAPAT SURAT JALAN</p><p>LAMPIRAN: {{ data_get($details,'attachments') === 'attached' ? 'ADA' : 'TIDAK ADA' }}</p></div></div>
@else
<table class="paper-table paper-signatures"><thead><tr><th>DEPT HEAD<br>IN CHARGE</th><th>HR &amp; ADM<br>DEPT HEAD</th><th>PEMBAWA<br>BARANG</th><th>PENERIMA<br>BARANG</th><th>SECURITY<br>PT MKM</th></tr></thead><tbody><tr>@foreach(['PROC_DH','HRD','carrier','recipient','SECURITY'] as $role)<td>@php($event=$approval($role))<div class="paper-approval-name">@include('documents.approval-mark', ['event'=>$event])</div><div class="paper-sign-line">( {{ $role === 'carrier' ? $document->driver_name : ($role === 'recipient' ? $document->receiver_name : $event?->user?->name) }} )</div></td>@endforeach</tr></tbody></table>
<div class="paper-copy-colors"><span>Lembar: Asli/Putih - Dept Pemilik Barang</span><span>Merah - Driver / Vendor</span><span>Kuning - Security</span></div>
@endif
@endif
<div class="paper-audit-foot">{{ $document->number }} | {{ $document->statusLabel() }} | Generated {{ now()->format('d M Y H:i') }} WIB</div>
</main></div></body></html>
