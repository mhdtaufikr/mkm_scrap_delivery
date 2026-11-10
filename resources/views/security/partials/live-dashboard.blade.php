<div class="grid grid-cols-2 gap-px border-b border-border bg-border text-center">
    <div class="bg-slate-50 px-2 py-3"><strong class="block text-xl text-slate-700">{{ $counts['pending'] }}</strong><span class="text-[10px] font-semibold text-slate-700">Proses approval</span></div>
    <div class="bg-emerald-50 px-2 py-3"><strong class="block text-xl text-emerald-700">{{ $counts['ready'] }}</strong><span class="text-[10px] font-semibold text-emerald-800">Siap diperiksa</span></div>
</div>
<details class="border-b border-border bg-slate-50/70" data-security-pending>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-2.5"><span class="text-xs font-semibold text-slate-700">Lihat proses approval</span><span class="rounded-full bg-slate-200 px-2 py-1 text-[9px] font-bold text-slate-700">{{ $counts['pending'] }} dokumen</span></summary>
    <p class="border-t border-slate-200 px-4 py-2 text-[10px] text-muted">Informasi antrian saja. Jangan panggil pengemudi dulu.</p>
    @forelse($pendingDocuments as $document)
        @php($pendingLabel = $document->status === 'HRA_APPROVAL' ? 'MENUNGGU HRA' : 'MENUNGGU PROCUREMENT')
        <article class="border-t border-slate-200 px-4 py-2.5"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><strong class="font-mono text-xs text-slate-800">{{ $document->number }}</strong><span class="rounded bg-slate-200 px-1.5 py-0.5 text-[8px] font-bold text-slate-700">{{ $pendingLabel }}</span></div><p class="mt-1 truncate text-xs font-semibold text-slate-700">{{ $document->company }} &middot; {{ $document->plate_number ?: 'Nomor polisi belum diisi' }}</p></div><time class="shrink-0 text-right text-[10px] text-muted">Diajukan<br><strong class="text-slate-700">{{ $document->created_at->format('H:i') }}</strong></time></div></article>
    @empty
        <p class="border-t border-slate-200 px-4 py-3 text-center text-[11px] text-muted">Tidak ada dokumen yang sedang menunggu approval.</p>
    @endforelse
</details>
<div class="flex items-center justify-between gap-3 border-b border-border px-4 py-2.5"><div><h2 class="text-xs font-bold text-slate-800">Siap diperiksa Security</h2><p class="mt-0.5 text-[10px] text-muted">Approval selesai. Input nama pengemudi saat pemeriksaan.</p></div><span class="rounded-full bg-emerald-100 px-2 py-1 text-[9px] font-bold text-emerald-800">{{ $counts['ready'] }} dokumen</span></div>
@forelse($documents as $document)
@php($approvedAt = $document->events->last()?->created_at)
<article class="border-b border-border px-4 py-3 last:border-b-0">
    <div class="flex items-start justify-between gap-3"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><strong class="font-mono text-sm text-slate-900">{{ $document->number }}</strong><span class="rounded bg-emerald-100 px-2 py-1 text-[9px] font-bold text-emerald-900 ring-1 ring-emerald-200">SIAP DIPERIKSA</span></div><p class="mt-1 truncate text-sm font-bold">{{ $document->company }}</p><p class="mt-0.5 text-xs text-muted"><strong class="text-slate-700">{{ $document->plate_number ?: 'Nomor polisi belum diisi' }}</strong></p></div>@if($approvedAt)<time class="shrink-0 text-right text-[10px] leading-4 text-muted">Disetujui<br><strong class="text-slate-700">{{ $approvedAt->format('H:i') }}</strong></time>@endif</div>
</article>
@empty
<div class="px-4 py-10 text-center"><p class="text-sm font-bold text-slate-700">Belum ada dokumen yang siap diperiksa</p><p class="mt-1 text-xs leading-5 text-muted">Daftar akan muncul otomatis setelah persetujuan Procurement dan HRA selesai.</p></div>
@endforelse
