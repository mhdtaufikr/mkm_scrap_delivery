@if($event)
<span class="paper-approval-check" aria-label="Disetujui">✓</span>
<span class="paper-approval-meta {{ ($stacked ?? false) ? 'paper-approval-meta-stacked' : '' }}">@if($showName ?? true)<strong>{{ $event->actual_actor_name ?: $event->security_officer_name ?: ($event->user?->name ?: 'Pejabat tercatat') }}</strong>@unless($stacked ?? false)<span class="paper-approval-separator" aria-hidden="true">|</span>@endunless @endif<small>{{ $event->created_at->format('d/m/Y H:i') }} WIB</small></span>
@if($event->action === 'delegate')<small>Disetujui oleh delegasi</small>@endif
@if($event->action === 'delegate' && $event->notes)<small class="paper-delegation-reason">Catatan: {{ \Illuminate\Support\Str::limit($event->notes, 180) }}</small>@endif
@endif
