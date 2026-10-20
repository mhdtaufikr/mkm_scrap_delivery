@if($event)
<span class="paper-approval-check" aria-label="Disetujui">✓</span>
<strong>{{ $event->actual_actor_name ?: $event->security_officer_name ?: ($event->user?->name ?: 'Pejabat tercatat') }}</strong>
@if($event->action === 'delegate')<small>Disetujui oleh delegasi</small>@endif
@if($event->security_device_name)<small>Pemeriksaan gerbang PT MKM</small>@endif
<small>{{ $event->created_at->format('d/m/Y H:i') }} WIB</small>
@if($event->action === 'delegate' && $event->notes)<small class="paper-delegation-reason">Catatan: {{ \Illuminate\Support\Str::limit($event->notes, 180) }}</small>@endif
@endif
